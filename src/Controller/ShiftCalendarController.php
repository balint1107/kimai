<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\ShiftCalendarConnection;
use App\Entity\ShiftCalendarLink;
use App\ShiftAccounting\CalendarSync\CalendarSync;
use App\ShiftAccounting\CalendarSync\GoogleClient;
use App\ShiftAccounting\ShiftService;
use App\Utils\PageSetup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Existing-session Google settings; every mutation requires CSRF and every record is owner-checked. */
#[Route(path: '/shifts/google')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ShiftCalendarController extends AbstractController
{
    /** Injects persistence $em, Google transport $google, reconciler $sync and existing workplace/local adapters $shifts/$local plus $mapper for readable comparisons. */
    public function __construct(private EntityManagerInterface $em, private GoogleClient $google, private CalendarSync $sync, private ShiftService $shifts, private \App\ShiftAccounting\CalendarSync\LocalCalendar $local, private \App\ShiftAccounting\CalendarSync\EventMapper $mapper)
    {
    }

    /** Renders safe connection state/review records for the current user; never passes credential ciphertext to Twig. */
    #[Route(path: '', name: 'shift_google', methods: ['GET'])]
    public function index(): Response
    {
        $connection = $this->connection();
        $calendars = [];
        if ($connection->credentials !== null) {
            try {
                $pageToken = null;
                do {
                    $page = $this->google->request($connection, 'GET', 'users/me/calendarList', null, ['maxResults' => 250, 'pageToken' => $pageToken]);
                    foreach ($page['items'] ?? [] as $calendar) {
                        if (\in_array($calendar['accessRole'] ?? '', ['owner', 'writer'], true)) {
                            $calendars[] = ['id' => $calendar['id'], 'name' => $calendar['summary'] ?? $calendar['id']];
                        }
                    }
                    $pageToken = $page['nextPageToken'] ?? null;
                } while ($pageToken !== null);
            } catch (\RuntimeException) {
                $this->addFlash('error', 'shift.google_connection_error');
            }
        }
        $aliases = [];
        foreach ($connection->settings['aliases'] as $alias => $name) {
            $aliases[] = $alias . ' = ' . $name;
        }
        $links = $this->em->getRepository(ShiftCalendarLink::class)->findBy(['connection' => $connection], ['id' => 'DESC']);

        $localRecords = $this->local->records($this->getUser());
        $comparisons = [];
        foreach ($links as $link) {
            try {
                $incoming = $link->remote === [] || ($link->remote['status'] ?? '') === 'cancelled' ? null : $this->mapper->incoming($link->remote, $this->shifts->workplaces(), $connection->settings['aliases'], $link->baseline, $connection->settings['plain_events'] ?? false, ($connection->settings['holiday_calendar'] ?? '') === '' ? 'mixed' : ($link->calendarId === $connection->settings['holiday_calendar'] ? 'holiday' : 'work'));
            } catch (\Exception) {
                $incoming = null;
            }
            $candidates = [];
            if ($incoming !== null && $link->localKey === null) {
                foreach ($localRecords as $key => $record) {
                    if ($record['kind'] === $incoming['kind'] && $record['begin'] === $incoming['begin'] && $record['end'] === $incoming['end']) {
                        $candidates[$key] = $record;
                    }
                }
            }
            $comparisons[$link->id] = ['remote' => $incoming, 'candidates' => $candidates];
        }

        return $this->render('shifts/google.html.twig', ['page_setup' => new PageSetup('shift.google'), 'tab' => 'google', 'configured' => $this->google->configured(), 'connected' => $connection->credentials !== null, 'enabled' => $connection->enabled, 'settings' => $connection->settings, 'last_success' => $connection->lastSuccess, 'sync_error' => $connection->error, 'calendars' => $calendars, 'aliases' => implode("\n", $aliases), 'links' => $links, 'comparisons' => $comparisons, 'place_names' => array_column($this->shifts->workplaces(), 'name', 'id'), 'local_records' => $localRecords, 'pending_count' => \count(array_filter($links, fn ($l) => $l->pending !== null)), 'review_count' => \count(array_filter($links, fn ($l) => \in_array($l->state, ['review', 'conflict'], true)))]);
    }

    /** Shows one owner-scoped review selected by $request, preserving rejected editor fields; no Google or accounting writes occur. */
    #[Route(path: '/review', name: 'shift_google_review', methods: ['GET'])]
    public function review(Request $request): Response
    {
        $connection = $this->connection();
        $links = array_values(array_filter($this->em->getRepository(ShiftCalendarLink::class)->findBy(['connection' => $connection], ['id' => 'ASC']), fn ($l) => \in_array($l->state, ['review', 'conflict'], true) || $l->problem !== null));
        $selected = null;
        $position = 0;
        foreach ($links as $index => $link) {
            if ($selected === null || $link->id === $request->query->getInt('id')) {
                $selected = $link;
                $position = $index;
            }
            if ($link->id === $request->query->getInt('id')) {
                break;
            }
        }
        $records = $this->local->records($this->getUser());
        $places = $this->shifts->workplaces();
        $value = [];
        $input = [];
        $candidates = [];
        if ($selected !== null) {
            try {
                $value = $this->mapper->incoming($selected->remote, $places, $connection->settings['aliases'], $selected->baseline, true, ($connection->settings['holiday_calendar'] ?? '') === '' ? 'mixed' : ($selected->calendarId === $connection->settings['holiday_calendar'] ? 'holiday' : 'work')) ?? [];
            } catch (\Exception) {
                // Raw Google fields remain editable when normalization fails.
            }
            $value = $records[$selected->localKey ?? ''] ?? $selected->pending['value'] ?? $value;
            $kind = $value['kind'] ?? ($selected->calendarId === ($connection->settings['holiday_calendar'] ?? '') ? 'time_off' : 'shift');
            $allDay = $value['all_day'] ?? isset($selected->remote['start']['date']);
            $input = ['kind' => $kind, 'all_day' => $allDay ? '1' : '', 'title' => $value['title'] ?? $selected->remote['summary'] ?? '', 'note' => $value['note'] ?? $selected->remote['description'] ?? '', 'workplace' => (string) ($value['workplace'] ?? ''), 'category' => $value['category'] ?? '', 'rate' => isset($value['rate']) ? (string) $value['rate'] : '', 'color' => $value['color'] ?? '#ae3ec9', 'cancelled' => !empty($value['cancelled']) ? '1' : ''];
            if ($value === [] && preg_match('/^\s*(EFO|Sz[aá]mla)\b[ \t]*(?:[,.:;|][ \t]*)?(\d+(?:[ \x{00a0}]\d{3})*)(?:\s*(?:Ft|HUF)(?:\s*\/\s*(?:óra|ora|h))?)?[ \t]*(?:\R|$)/iu', $input['note'], $header) === 1) {
                $input['category'] = $this->mapper->normalize($header[1]) === 'efo' ? 'EFO' : 'Számla';
                $input['rate'] = str_replace([' ', "\u{00a0}"], '', $header[2]);
                $input['note'] = trim(substr($input['note'], \strlen($header[0])));
            }
            foreach (['begin' => 'start', 'end' => 'end'] as $field => $remoteField) {
                $raw = $value[$field] ?? $selected->remote[$remoteField]['dateTime'] ?? $selected->remote[$remoteField]['date'] ?? '';
                try {
                    $date = new \DateTimeImmutable(ctype_digit((string) $raw) ? '@' . $raw : (string) $raw, new \DateTimeZone('Europe/Budapest'));
                    $date = $date->setTimezone(new \DateTimeZone('Europe/Budapest'));
                    $input[$field] = $raw === '' ? '' : ($allDay ? ($field === 'end' ? $date->modify('-1 day') : $date)->format('Y-m-d') : $date->format('Y-m-d\\TH:i'));
                } catch (\Exception) {
                    $input[$field] = '';
                }
            }
            if ($selected->localKey === null) {
                foreach ($records as $key => $record) {
                    if (isset($selected->remote['start']['dateTime'], $selected->remote['end']['dateTime']) && $record['begin'] === (string) strtotime($selected->remote['start']['dateTime']) && $record['end'] === (string) strtotime($selected->remote['end']['dateTime'])) {
                        $candidates[$key] = $record;
                    }
                }
            }
            $submitted = $request->attributes->get('_review_input', []);
            $input = array_replace($input, \is_array($submitted) ? array_filter($submitted, 'is_string') : []);
        }

        return $this->render('shifts/google_review.html.twig', ['page_setup' => new PageSetup('shift.google_review'), 'tab' => 'google_review', 'links' => $links, 'selected' => $selected, 'position' => $position, 'input' => $input, 'places' => $places, 'candidates' => $candidates, 'current' => $selected === null ? null : ($records[$selected->localKey ?? ''] ?? null), 'revision' => $selected === null ? '' : $this->sync->revision($selected), 'review_error' => $request->attributes->get('_review_error')]);
    }

    /** Starts offline OAuth only after a CSRF-protected POST; session state is owner-bound, expiring and PKCE-protected. */
    #[Route(path: '/connect', name: 'shift_google_connect', methods: ['POST'])]
    public function connect(Request $request): Response
    {
        $this->csrf($request);
        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $request->getSession()->set('shift_google_oauth', ['state' => $state, 'verifier' => $verifier, 'owner' => $this->getUser()->getId(), 'expires' => time() + 600]);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return $this->redirect($this->google->authorizationUrl($state, $challenge, $this->callback()));
    }

    /** Consumes one-use OAuth state from $request; rejects missing/expired/foreign responses without storing tokens. */
    #[Route(path: '/callback', name: 'shift_google_callback', methods: ['GET'])]
    public function authorized(Request $request): Response
    {
        $saved = $request->getSession()->remove('shift_google_oauth');
        if (!\is_array($saved) || !\is_string($saved['state'] ?? null) || !\is_string($saved['verifier'] ?? null) || $saved['expires'] < time() || $saved['owner'] !== $this->getUser()->getId() || !hash_equals($saved['state'], (string) $request->query->get('state', ''))) {
            throw $this->createAccessDeniedException('Invalid or expired calendar authorization');
        }
        try {
            if (!$request->query->has('code')) {
                throw new \RuntimeException('Google authorization was declined');
            }
            $connection = $this->connection();
            // Reauthorization never resumes writes automatically.
            $connection->enabled = false;
            $this->google->authorize($connection, (string) $request->query->get('code'), $saved['verifier'], $this->callback());
            $this->addFlash('success', 'shift.google_connected');
        } catch (\RuntimeException) {
            $this->addFlash('error', 'shift.google_connection_error');
        }

        return $this->redirectToRoute('shift_google');
    }

    /** Applies validated settings/actions from $request; saving policy (including ordinary-event opt-in) pauses writes and resets cursors for a new preview. */
    #[Route(path: '/settings', name: 'shift_google_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->csrf($request);
        $connection = $this->connection();
        try {
            switch ($request->request->get('action')) {
                case 'disconnect':
                    $connection->credentials = null;
                    $connection->enabled = false;
                    $connection->error = null;
                    break;
                case 'pause':
                    $connection->enabled = false;
                    break;
                case 'enable':
                    if ($connection->credentials === null || empty($connection->settings['calendars']) || empty($connection->settings['previewed']) || !$request->request->getBoolean('confirm')) {
                        throw new \InvalidArgumentException('Preview and approve the connection first');
                    }
                    $connection->enabled = true;
                    break;
                case 'save':
                    $selected = $request->request->all('calendars');
                    if (array_filter($selected, fn ($id) => !\is_string($id)) !== []) {
                        throw new \InvalidArgumentException('Invalid calendar selection');
                    }
                    /** @var list<string> $selected */
                    $destination = (string) $request->request->get('destination', '');
                    $holiday = (string) $request->request->get('holiday_calendar', '');
                    if ($holiday !== '' && ($holiday === $destination || !\in_array($holiday, $selected, true))) {
                        throw new \InvalidArgumentException('Select a separate holiday calendar among synchronized calendars');
                    }
                    if ($selected === [] || !\in_array($destination, $selected, true)) {
                        throw new \InvalidArgumentException('Select calendars and an export destination');
                    }
                    // Server-side validation prevents clients selecting calendars outside granted write access.
                    $available = [];
                    $pageToken = null;
                    do {
                        $page = $this->google->request($connection, 'GET', 'users/me/calendarList', null, ['pageToken' => $pageToken, 'maxResults' => 250]);
                        foreach ($page['items'] ?? [] as $calendar) {
                            if (\in_array($calendar['accessRole'] ?? '', ['owner', 'writer'], true)) {
                                $available[] = $calendar['id'] ?? '';
                            }
                        }
                        $pageToken = $page['nextPageToken'] ?? null;
                    } while ($pageToken !== null);
                    if (array_diff($selected, $available) !== []) {
                        throw new \InvalidArgumentException('Selected calendar is not writable');
                    }
                    $dates = [];
                    foreach (['from', 'to'] as $field) {
                        $value = (string) $request->request->get($field, '');
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                        if ($date === false || $date->format('Y-m-d') !== $value) {
                            throw new \InvalidArgumentException('Invalid date range');
                        }
                        $dates[$field] = $value;
                    }
                    if ($dates['to'] < $dates['from'] || (new \DateTimeImmutable($dates['from']))->diff(new \DateTimeImmutable($dates['to']))->days > 3660) {
                        throw new \InvalidArgumentException('Choose a range of at most ten years');
                    }
                    $names = array_column($this->shifts->workplaces(), 'name');
                    $aliases = [];
                    foreach (preg_split('/\R/', (string) $request->request->get('aliases', '')) ?: [] as $line) {
                        if (trim($line) === '') {
                            continue;
                        }
                        $parts = array_map(trim(...), explode('=', $line, 2));
                        if (\count($parts) !== 2 || $parts[0] === '' || !\in_array($parts[1], $names, true)) {
                            throw new \InvalidArgumentException('Use alias = existing workplace name');
                        }
                        $aliases[$parts[0]] = $parts[1];
                    }
                    $connection->enabled = false;
                    $connection->settings = ['calendars' => array_values(array_unique($selected)), 'destination' => $destination, 'aliases' => $aliases, 'from' => $dates['from'], 'to' => $dates['to'], 'cursors' => [], 'previewed' => false, 'plain_events' => $request->request->getBoolean('plain_events'), 'holiday_calendar' => $holiday];
                    break;
                default:
                    throw new \InvalidArgumentException('Unknown calendar action');
            }
            $this->em->flush();
        } catch (\RuntimeException | \InvalidArgumentException) {
            $this->addFlash('error', 'shift.google_settings_error');
        }

        return $this->redirectToRoute('shift_google');
    }

    /** Runs CSRF-protected preview/manual sync from $request; JSON callers receive success or HTTP 409, while normal forms redirect; paused connections never send writes. */
    #[Route(path: '/sync', name: 'shift_google_sync', methods: ['POST'])]
    public function synchronize(Request $request): Response
    {
        $this->csrf($request);
        $connection = $this->connection();
        $preview = $request->request->get('action') === 'preview';
        $failed = false;
        try {
            if ($connection->credentials === null || $connection->settings['calendars'] === [] || (!$preview && !$connection->enabled)) {
                throw new \RuntimeException('Connect and select a calendar first');
            }
            $this->sync->run($connection, $preview);
            if ($preview) {
                $connection->settings['previewed'] = true;
                $this->em->flush();
            }
        } catch (\Throwable) {
            $failed = true;
            if ($request->getPreferredFormat() !== 'json') {
                $this->addFlash('error', 'shift.google_sync_error');
            }
        }
        if ($request->getPreferredFormat() === 'json') {
            return $this->json(['success' => !$failed], $failed ? 409 : 200);
        }

        return $this->redirectToRoute('shift_google');
    }

    /** Resolves only the current owner's $id with CSRF protection; record attachment is checked again in the service. */
    #[Route(path: '/resolve/{id}', name: 'shift_google_resolve', methods: ['POST'])]
    public function resolve(Request $request, int $id): Response
    {
        $this->csrf($request);
        $link = $this->em->find(ShiftCalendarLink::class, $id);
        if ($link === null || $link->connection->owner->getId() !== $this->getUser()->getId()) {
            throw $this->createNotFoundException();
        }
        try {
            $this->sync->resolve($link, (string) $request->request->get('choice'), (string) $request->request->get('local_key', ''), $request->request->get('choice') === 'correct' ? $request->request->all() : null, (string) $request->request->get('revision', ''));
            if ($request->request->getBoolean('review_panel')) {
                $this->addFlash('success', 'shift.google_review_saved');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            if ($request->request->getBoolean('review_panel')) {
                $request->query->set('id', $id);
                $request->attributes->set('_review_input', $request->request->all());
                $request->attributes->set('_review_error', $e->getMessage());

                return $this->review($request);
            }
            $this->addFlash('error', 'shift.google_resolution_error');
        }

        return $this->redirectToRoute($request->request->getBoolean('review_panel') ? 'shift_google_review' : 'shift_google');
    }

    /** Returns/persists a paused connection belonging only to the current authenticated user. */
    private function connection(): ShiftCalendarConnection
    {
        $connection = $this->em->getRepository(ShiftCalendarConnection::class)->findOneBy(['owner' => $this->getUser()]);
        if ($connection === null) {
            $connection = new ShiftCalendarConnection($this->getUser());
            $this->em->persist($connection);
            $this->em->flush();
        }

        return $connection;
    }

    /** Returns the exact canonical prefixed OAuth callback; host is configured rather than taken from an arbitrary redirect parameter. */
    private function callback(): string
    {
        return 'https://bgamgee.ddns.net/kimai/hu/shifts/google/callback';
    }

    /** Requires $request's existing shift CSRF token before any state mutation. */
    private function csrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('shifts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
    }
}
