<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting\CalendarSync;

use App\Entity\ShiftCalendarConnection;
use App\Entity\ShiftCalendarLink;
use Doctrine\ORM\EntityManagerInterface;

/** Durable, owner-scoped reconciliation with explicit preview, three-way conflicts and conditional Google writes.
 * @phpstan-import-type Value from EventMapper
 * @phpstan-import-type Place from EventMapper
 * @phpstan-import-type GoogleEvent from EventMapper
 */
final class CalendarSync
{
    /** Injects persistence $em, restricted $google transport, native $local adapter and deterministic $mapper. */
    public function __construct(private EntityManagerInterface $em, private GoogleClient $google, private LocalCalendar $local, private EventMapper $mapper, private \App\ShiftAccounting\ShiftService $shifts)
    {
    }

    /** Reconciles $connection under a database advisory lock; $preview fetches/stages changes without local or remote writes. */
    public function run(ShiftCalendarConnection $connection, bool $preview = false): void
    {
        if ($connection->credentials === null || (!$connection->enabled && !$preview)) {
            return;
        }
        $db = $this->em->getConnection();
        $lock = 'kimai-google-' . $connection->id;
        $acquired = $db->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]);
        if ($acquired !== 1 && $acquired !== '1') {
            throw new \RuntimeException('Synchronization is already running');
        }
        try {
            $places = $this->places();
            foreach ($connection->settings['calendars'] as $calendar) {
                $this->pull($connection, $calendar, $preview, $places);
            }
            $this->discover($connection, $preview);
            if (!$preview) {
                $this->push($connection);
            }
            $connection->error = null;
            $connection->lastSuccess = new \DateTimeImmutable();
            $this->em->flush();
        } catch (\Throwable $e) {
            $connection->error = $e instanceof \RuntimeException && $e->getCode() === 401 ? 'Reconnect Google Calendar' : 'Synchronization failed; changes retained for retry';
            if ($e->getCode() === 401) {
                $connection->enabled = false;
            }
            $this->em->flush();
            throw $e;
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Stages/applies one Google $event in $calendar for $connection; preview never changes accounting records.
     * @param GoogleEvent $event
     * @param list<Place> $places
     */
    public function receive(ShiftCalendarConnection $connection, string $calendar, array $event, array $places, bool $preview = false): void
    {
        $db = $this->em->getConnection();
        $db->beginTransaction();
        try {
            $this->reconcileEvent($connection, $calendar, $event, $places, $preview);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Reconciles validated $event/$places in the caller's transaction; $preview stages reviews only.
     * @param GoogleEvent $event
     * @param list<Place> $places
     */
    private function reconcileEvent(ShiftCalendarConnection $connection, string $calendar, array $event, array $places, bool $preview): void
    {
        if (!\in_array($calendar, $connection->settings['calendars'], true)) {
            throw new \InvalidArgumentException('Calendar is not selected');
        }
        if (empty($event['id'])) {
            throw new \InvalidArgumentException('Google event has no identity');
        }
        $link = $this->em->getRepository(ShiftCalendarLink::class)->findOneBy(['connection' => $connection, 'calendarId' => $calendar, 'eventId' => $event['id']]);
        if ($link?->state === 'ignored' || $link?->state === 'deleted') {
            return;
        }
        // A corrected invalid source must reach Google before parsing that unchanged source again.
        if ($link !== null && !empty($link->pending['reviewed'])) {
            if (($event['etag'] ?? null) !== ($link->remote['etag'] ?? null) || ($event['status'] ?? '') === 'cancelled') {
                $link->remote = $event;
                $link->state = 'conflict';
                $link->problem = 'Google changed after this correction; review again';
                $this->em->flush();
            }

            return;
        }
        if (($event['status'] ?? '') === 'cancelled') {
            if ($link === null) {
                return;
            }
            $link->remote = $event;
            $records = $this->local->records($connection->owner);
            $current = $link->localKey === null ? null : ($records[$link->localKey] ?? null);
            if ($preview || ($current !== null && $current !== $link->baseline)) {
                $link->state = 'conflict';
                $link->problem = 'Google deletion conflicts with local changes; choose a version';
            } else {
                if ($link->localKey !== null) {
                    $this->local->remoteDelete($connection->owner, $link->localKey);
                }
                $link->state = 'deleted';
                $link->pending = null;
            }
            $this->em->flush();

            return;
        }
        try {
            $value = $this->mapper->incoming($event, $places, $connection->settings['aliases'], $link !== null ? $link->baseline : [], $connection->settings['plain_events'] ?? false, $this->calendarRole($connection, $calendar));
        } catch (\Exception $e) {
            $link ??= new ShiftCalendarLink($connection, $calendar, $event['id']);
            $link->remote = $event;
            $link->state = 'review';
            $link->problem = $e->getMessage();
            $this->em->persist($link);
            $this->em->flush();

            return;
        }
        if ($value === null || ($link === null && !$this->inRange($connection, $value))) {
            return;
        }
        $records = $this->local->records($connection->owner);
        if ($link !== null && $link->state === 'linked' && $value === $link->baseline && ($records[$link->localKey ?? ''] ?? null) === $link->baseline) {
            $link->remote = $event;
            $this->em->flush();

            return;
        }
        $new = $link === null;
        $link ??= new ShiftCalendarLink($connection, $calendar, $event['id']);
        $link->remote = $event;
        $this->em->persist($link);
        // Existing workbook/manual records must be explicitly linked, never silently duplicated or adopted.
        $duplicates = [];
        foreach ($records as $key => $record) {
            if ($record['kind'] === $value['kind'] && $record['begin'] === $value['begin'] && $record['end'] === $value['end'] && ($record['workplace'] ?? null) === ($value['workplace'] ?? null) && $key !== $link->localKey) {
                $duplicates[] = $key;
            }
        }
        if ($preview || ($new && $duplicates !== []) || (!$new && \in_array($link->state, ['review', 'conflict'], true))) {
            $link->state = $link->state === 'conflict' ? 'conflict' : 'review';
            $link->problem = $duplicates === [] ? 'Review incoming event before applying' : 'Possible existing records: ' . implode(', ', $duplicates);
            $this->em->flush();

            return;
        }
        $current = $link->localKey === null ? null : ($records[$link->localKey] ?? null);
        if ($link->localKey !== null && $current === null) {
            if ($value !== $link->baseline) {
                $link->state = 'conflict';
                $link->problem = 'Local deletion conflicts with Google changes';
            } else {
                $link->pending = ['delete' => true];
            }
        } else {
            $merge = $current === null ? ['value' => $value, 'conflicts' => []] : $this->mapper->merge($link->baseline !== [] ? $link->baseline : $current, $current, $value);
            if ($merge['conflicts'] !== []) {
                $link->state = 'conflict';
                $link->problem = 'Conflicting fields: ' . implode(', ', $merge['conflicts']);
            } else {
                $link->localKey = $this->local->save($connection->owner, $merge['value'], $link->localKey, $current);
                $link->baseline = $value;
                $link->state = 'linked';
                $link->problem = null;
                $link->pending = $merge['value'] !== $value ? ['value' => $merge['value']] : null;
                if ($link->pending === null) {
                    $link->attempts = 0;
                    $link->retryAt = null;
                }
            }
        }
        $this->em->flush();
    }

    /** Resolves owner-checked $link using $choice (remote/local/ignore), optionally attaching a confirmed existing $localKey; $correction/$revision validate explicit editor input against its displayed snapshot.
     * @throws \InvalidArgumentException|\RuntimeException when the choice, correction or concurrency checks fail
     * @param array<string, mixed>|null $correction
     */
    public function resolve(ShiftCalendarLink $link, string $choice, ?string $localKey = null, ?array $correction = null, ?string $revision = null): void
    {
        $db = $this->em->getConnection();
        $lock = 'kimai-google-' . $link->connection->id;
        $acquired = $db->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]);
        if ($acquired !== 1 && $acquired !== '1') {
            throw new \InvalidArgumentException('Synchronization is running; retry after it finishes');
        }
        $db->beginTransaction();
        try {
            $this->em->refresh($link);
            if ($choice === 'correct') {
                $this->correctVersion($link, $correction ?? [], $revision ?? '', $localKey);
            } else {
                $this->resolveVersion($link, $choice, $localKey);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Returns a change token covering $link and its current native record; detects edits made while a review form is open. */
    public function revision(ShiftCalendarLink $link): string
    {
        return hash('sha256', json_encode([$link->state, $link->remote, $link->baseline, $link->pending, $this->local->records($link->connection->owner)[$link->localKey ?? ''] ?? null], JSON_THROW_ON_ERROR));
    }

    /** Applies validated $input to refreshed $link under the resolution lock; $revision rejects stale forms and $localKey explicitly attaches an owned duplicate.
     * @param array<string, mixed> $input
     */
    private function correctVersion(ShiftCalendarLink $link, array $input, string $revision, ?string $localKey): void
    {
        if (!\in_array($link->state, ['review', 'conflict'], true) && $link->problem === null) {
            throw new \InvalidArgumentException('This entry no longer needs review');
        }
        if (!hash_equals($this->revision($link), $revision)) {
            throw new \InvalidArgumentException('This entry changed; reload before saving');
        }
        if (($link->remote['status'] ?? '') === 'cancelled') {
            throw new \InvalidArgumentException('Resolve the Google deletion using a version choice');
        }
        if ($link->remote !== []) {
            $latest = $this->google->request($link->connection, 'GET', 'calendars/' . rawurlencode($link->calendarId) . '/events/' . rawurlencode($link->eventId));
            if (($latest['etag'] ?? null) !== ($link->remote['etag'] ?? null) || ($latest['status'] ?? '') === 'cancelled') {
                throw new \InvalidArgumentException('Google changed; synchronize and reload before saving');
            }
        }
        $records = $this->local->records($link->connection->owner);
        $key = $link->localKey;
        if ($localKey !== null && $localKey !== '' && $localKey !== $key) {
            if ($key !== null || !isset($records[$localKey]) || $this->em->getRepository(ShiftCalendarLink::class)->findOneBy(['connection' => $link->connection, 'localKey' => $localKey]) !== null) {
                throw new \InvalidArgumentException('Select an unlinked record belonging to this account');
            }
            $key = $localKey;
        }
        $value = $this->mapper->reviewed($input, $this->places(), $key === null ? [] : ($records[$key] ?? $link->baseline), $this->calendarRole($link->connection, $link->calendarId));
        if ($key === null) {
            foreach ($records as $record) {
                if ($record['kind'] === $value['kind'] && $record['begin'] === $value['begin'] && $record['end'] === $value['end'] && ($record['workplace'] ?? null) === ($value['workplace'] ?? null)) {
                    throw new \InvalidArgumentException('A matching record exists; select it instead of creating a duplicate');
                }
            }
        }
        $link->localKey = $this->local->save($link->connection->owner, $value, $key, $key === null ? null : ($records[$key] ?? null));
        $link->baseline = $value;
        $link->pending = ['value' => $value, 'reviewed' => true];
        $link->state = 'linked';
        $link->problem = null;
        $link->attempts = 0;
        $link->retryAt = null;
        $this->em->flush();
    }

    /** Applies $choice to refreshed $link inside the resolution lock/transaction; $localKey optionally confirms an existing record. */
    private function resolveVersion(ShiftCalendarLink $link, string $choice, ?string $localKey): void
    {
        if ($choice === 'ignore') {
            $link->state = 'ignored';
            $link->pending = null;
        } elseif ($choice === 'remote') {
            if ($localKey !== null && $localKey !== '') {
                $records = $this->local->records($link->connection->owner);
                if (!isset($records[$localKey])) {
                    throw new \InvalidArgumentException('Unknown owned record');
                }
                $other = $this->em->getRepository(ShiftCalendarLink::class)->findOneBy(['connection' => $link->connection, 'localKey' => $localKey]);
                if ($other !== null && $other !== $link) {
                    throw new \InvalidArgumentException('Record is already linked');
                }
                $link->localKey = $localKey;
            }
            if (($link->remote['status'] ?? '') === 'cancelled') {
                if ($link->localKey !== null) {
                    $this->local->remoteDelete($link->connection->owner, $link->localKey);
                }
                $link->state = 'deleted';
                $link->pending = null;
            } else {
                $value = $this->mapper->incoming($link->remote, $this->places(), $link->connection->settings['aliases'], $link->baseline, $link->connection->settings['plain_events'] ?? false, $this->calendarRole($link->connection, $link->calendarId));
                if ($value === null) {
                    throw new \InvalidArgumentException('Event cannot be recognized; edit it in Google and preview again');
                }
                if ($link->localKey !== null && explode(':', $link->localKey)[0] !== $value['kind']) {
                    throw new \InvalidArgumentException('Existing record has a different event type');
                }
                $link->localKey = $this->local->save($link->connection->owner, $value, $link->localKey);
                $link->baseline = $value;
                $link->pending = null;
                $link->state = 'linked';
            }
        } elseif ($choice === 'local' && $link->localKey !== null) {
            $value = $this->local->records($link->connection->owner)[$link->localKey] ?? null;
            if (($link->remote['status'] ?? '') === 'cancelled' && $value !== null) {
                // Recreating after confirmed deletion uses a fresh retry-stable Google ID.
                $link->eventId = bin2hex(random_bytes(16));
                $link->remote = [];
            }
            $link->pending = $value === null ? ['delete' => true] : ['value' => $value];
            $link->state = 'linked';
        } else {
            throw new \InvalidArgumentException('Choose an available conflict resolution');
        }
        $link->problem = null;
        $link->attempts = 0;
        $link->retryAt = null;
        $this->em->flush();
    }

    /** Returns managed workplace defaults for recognition.
     * @return list<Place>
     */
    private function places(): array
    {
        return $this->shifts->workplaces();
    }

    /** Checks normalized $value against the configured inclusive Budapest import/export range.
     * @param Value $value
     */
    private function inRange(ShiftCalendarConnection $connection, array $value): bool
    {
        $date = $value['all_day'] ? $value['begin'] : (new \DateTimeImmutable('@' . $value['begin']))->setTimezone(new \DateTimeZone('Europe/Budapest'))->format('Y-m-d');

        return $date >= $connection->settings['from'] && $date <= $connection->settings['to'];
    }

    /** Pulls pages before advancing $calendar's cursor; cancelled masters are retired and active series expand inside the configured range.
     * @param list<Place> $places
     */
    private function pull(ShiftCalendarConnection $connection, string $calendar, bool $preview, array $places): void
    {
        $path = 'calendars/' . rawurlencode($calendar) . '/events';
        $query = ['singleEvents' => 'false', 'showDeleted' => 'true', 'maxResults' => 250];
        if (!$preview && !empty($connection->settings['cursors'][$calendar])) {
            $query['syncToken'] = $connection->settings['cursors'][$calendar];
        }
        $fullSync = !isset($query['syncToken']);
        $seenEvents = [];
        $masters = $connection->settings['masters'][$calendar] ?? [];
        do {
            try {
                $page = $this->google->request($connection, 'GET', $path, null, $query);
            } catch (\RuntimeException $e) {
                if ($e->getCode() !== 410 || !isset($query['syncToken'])) {
                    throw $e;
                }
                unset($query['syncToken'], $query['pageToken'], $page);
                $fullSync = true;
                continue;
            }
            foreach ($page['items'] ?? [] as $event) {
                if (isset($event['id'])) {
                    $seenEvents[$event['id']] = true;
                }
                if (($event['status'] ?? '') === 'cancelled' && (isset($masters[$event['id']]) || isset($event['recurrence']))) {
                    unset($masters[$event['id']]);
                    foreach ($this->links($connection) as $link) {
                        if ($link->calendarId === $calendar && ($link->remote['recurringEventId'] ?? null) === $event['id']) {
                            $this->receive($connection, $calendar, ['id' => $link->eventId, 'status' => 'cancelled'], $places, $preview);
                        }
                    }
                } elseif (isset($event['recurrence'])) {
                    try {
                        if ($this->mapper->incoming($event, $places, $connection->settings['aliases'], [], $connection->settings['plain_events'] ?? false, $this->calendarRole($connection, $calendar)) !== null) {
                            $masters[$event['id']] = true;
                        }
                    } catch (\Exception) {
                        $masters[$event['id']] = true;
                    }
                } else {
                    $this->receive($connection, $calendar, $event, $places, $preview);
                }
            }
            if (isset($page['nextPageToken'])) {
                $query['pageToken'] = $page['nextPageToken'];
            } else {
                unset($query['pageToken']);
            }
        } while (isset($query['pageToken']) || !isset($page));
        if ($fullSync) {
            // Deleted event tombstones can disappear before a cursor rebuild; confirm missing linked singles individually.
            foreach ($this->links($connection) as $link) {
                if ($link->calendarId !== $calendar || $link->state !== 'linked' || $link->remote === [] || isset($seenEvents[$link->eventId]) || isset($link->remote['recurringEventId'])) {
                    continue;
                }
                try {
                    $fresh = $this->google->request($connection, 'GET', $path . '/' . rawurlencode($link->eventId));
                } catch (\RuntimeException $e) {
                    if (!\in_array($e->getCode(), [404, 410], true)) {
                        throw $e;
                    }
                    $fresh = ['id' => $link->eventId, 'status' => 'cancelled'];
                }
                $this->receive($connection, $calendar, $fresh, $places, $preview);
            }
        }
        // Expand recognized series every cycle: this covers rolling ranges, exceptions and shortened series.
        foreach (array_keys($masters) as $master) {
            $query = ['showDeleted' => 'true', 'maxResults' => 250, 'timeMin' => $connection->settings['from'] . 'T00:00:00' . (new \DateTimeImmutable($connection->settings['from'], new \DateTimeZone('Europe/Budapest')))->format('P'), 'timeMax' => (new \DateTimeImmutable($connection->settings['to'], new \DateTimeZone('Europe/Budapest')))->modify('+1 day')->format(\DateTimeInterface::ATOM)];
            $seen = [];
            do {
                $instances = $this->google->request($connection, 'GET', $path . '/' . rawurlencode((string) $master) . '/instances', null, $query);
                foreach ($instances['items'] ?? [] as $event) {
                    $seen[$event['id']] = true;
                    $this->receive($connection, $calendar, $event, $places, $preview);
                }
                $query['pageToken'] = $instances['nextPageToken'] ?? null;
            } while ($query['pageToken'] !== null);
            foreach ($this->links($connection) as $link) {
                if ($link->calendarId === $calendar && ($link->remote['recurringEventId'] ?? null) === $master && !isset($seen[$link->eventId]) && $link->baseline !== [] && $this->inRange($connection, $link->baseline)) {
                    $this->receive($connection, $calendar, ['id' => $link->eventId, 'status' => 'cancelled'], $places, $preview);
                }
            }
        }
        $connection->settings['masters'][$calendar] = $masters;
        if (!$preview && isset($page['nextSyncToken'])) {
            $connection->settings['cursors'][$calendar] = $page['nextSyncToken'];
        }
        $this->em->flush();
    }

    /** Stages unlinked records to their type-specific destination and detects native-route changes/deletions; operations persist before any network writes. */
    private function discover(ShiftCalendarConnection $connection, bool $preview): void
    {
        $records = $this->local->records($connection->owner);
        $known = [];
        foreach ($this->links($connection) as $link) {
            if ($link->localKey === null) {
                continue;
            }
            $known[$link->localKey] = true;
            if ($link->state !== 'linked') {
                continue;
            }
            $current = $records[$link->localKey] ?? null;
            if ($current !== $link->baseline) {
                if ($preview) {
                    $link->state = 'review';
                    $link->problem = 'Review local changes before exporting';
                } else {
                    $link->pending = ($current === null ? ['delete' => true] : ['value' => $current]) + (!empty($link->pending['reviewed']) ? ['reviewed' => true] : []);
                }
            }
        }
        foreach ($records as $key => $record) {
            if (isset($known[$key]) || !$this->inRange($connection, $record) || ($record['cancelled'] ?? false) || empty($connection->settings['destination'])) {
                continue;
            }
            // Existing remote review candidates can represent this record; avoid exporting a duplicate before reconciliation.
            $reviewCandidates = array_filter($this->links($connection), fn ($l) => $l->localKey === null && \in_array($l->state, ['review', 'conflict'], true));
            if ($reviewCandidates !== []) {
                continue;
            }
            $destination = $record['kind'] === 'time_off' ? ($connection->settings['holiday_calendar'] ?? $connection->settings['destination']) : $connection->settings['destination'];
            $link = new ShiftCalendarLink($connection, $destination !== '' ? $destination : $connection->settings['destination'], bin2hex(random_bytes(16)));
            $link->localKey = $key;
            $link->state = $preview ? 'review' : 'linked';
            $link->problem = $preview ? 'Approve export of this local record' : null;
            $link->pending = ['value' => $record];
            $this->em->persist($link);
        }
        $this->em->flush();
    }

    /** Flushes pending operations with ETag checks, explicit whole-day/timed field clearing, stable insert IDs and bounded backoff. */
    private function push(ShiftCalendarConnection $connection): void
    {
        $places = array_column($this->places(), 'name', 'id');
        foreach ($this->links($connection) as $link) {
            if (!\in_array($link->calendarId, $connection->settings['calendars'], true) || $link->pending === null || $link->state !== 'linked' || ($link->retryAt !== null && $link->retryAt > new \DateTimeImmutable())) {
                continue;
            }
            $path = 'calendars/' . rawurlencode($link->calendarId) . '/events';
            try {
                if ($link->remote !== []) {
                    // Read latest remote before a delete/update, including retry after uncertain network completion.
                    try {
                        $fresh = $this->google->request($connection, 'GET', $path . '/' . rawurlencode($link->eventId));
                    } catch (\RuntimeException $e) {
                        if (\in_array($e->getCode(), [404, 410], true)) {
                            $fresh = ['id' => $link->eventId, 'status' => 'cancelled'];
                        } else {
                            throw $e;
                        }
                    }
                    if (($fresh['etag'] ?? null) !== ($link->remote['etag'] ?? null) || ($fresh['status'] ?? '') === 'cancelled') {
                        $this->receive($connection, $link->calendarId, $fresh, $this->places());
                        if (!$this->ready($link)) {
                            continue;
                        }
                    }
                }
                if (isset($link->pending['delete'])) {
                    if (($link->remote['status'] ?? '') !== 'cancelled' && $link->remote !== []) {
                        $this->google->request($connection, 'DELETE', $path . '/' . rawurlencode($link->eventId), null, [], $link->remote['etag'] ?? null);
                    }
                    $link->state = 'deleted';
                } else {
                    $value = $link->pending['value'] ?? null;
                    if ($value === null) {
                        throw new \RuntimeException('Invalid pending operation');
                    }
                    $payload = $this->mapper->outgoing($value, $places[$value['workplace'] ?? 0] ?? '', (string) $link->id);
                    if ($link->remote === []) {
                        $payload['id'] = $link->eventId;
                        try {
                            $link->remote = $this->google->request($connection, 'POST', $path, $payload, ['sendUpdates' => 'none']);
                        } catch (\RuntimeException $e) {
                            if ($e->getCode() !== 409) {
                                throw $e;
                            }
                            $fresh = $this->google->request($connection, 'GET', $path . '/' . rawurlencode($link->eventId));
                            if (($fresh['extendedProperties']['private']['kimai_link'] ?? '') !== (string) $link->id) {
                                throw new \RuntimeException('Google event identity collision');
                            }
                            $link->remote = $fresh;
                            $link->baseline = $value;
                            $this->receive($connection, $link->calendarId, $fresh, $this->places());
                            $this->em->flush();
                            continue;
                        }
                    } else {
                        $payload['extendedProperties'] = ['private' => ($payload['extendedProperties']['private'] ?? []) + ($link->remote['extendedProperties']['private'] ?? [])];
                        $link->remote = $this->google->request($connection, 'PATCH', $path . '/' . rawurlencode($link->eventId), $this->mapper->patchDates($payload, $link->remote), ['sendUpdates' => 'none'], $link->remote['etag'] ?? null);
                    }
                    $link->baseline = $value;
                }
                $link->pending = null;
                $link->attempts = 0;
                $link->retryAt = null;
                $link->problem = null;
            } catch (\RuntimeException $e) {
                if ($e->getCode() === 412) {
                    $link->state = 'conflict';
                    $link->problem = 'Google changed during synchronization; preview and resolve';
                } elseif ($e->getCode() === 401) {
                    throw $e;
                } else {
                    ++$link->attempts;
                    $link->retryAt = new \DateTimeImmutable('+' . min(3600, 30 * (2 ** min(7, $link->attempts))) . ' seconds');
                    $link->problem = 'Google update failed; queued for retry';
                }
            }
            $this->em->flush();
        }
    }

    /** Re-reads mutable $link state after reconciliation; only active nonempty operations may be sent. */
    private function ready(ShiftCalendarLink $link): bool
    {
        return $link->state === 'linked' && $link->pending !== null;
    }

    /** Returns $calendar's role under $connection; legacy connections keep mixed title-based recognition.
     * @return 'mixed'|'work'|'holiday'
     */
    private function calendarRole(ShiftCalendarConnection $connection, string $calendar): string
    {
        $holiday = $connection->settings['holiday_calendar'] ?? '';

        return $holiday === '' ? 'mixed' : ($calendar === $holiday ? 'holiday' : 'work');
    }

    /** Returns persistent event links for $connection, including ignored/deleted tombstones.
     * @return list<ShiftCalendarLink>
     */
    private function links(ShiftCalendarConnection $connection): array
    {
        return $this->em->getRepository(ShiftCalendarLink::class)->findBy(['connection' => $connection], ['id' => 'ASC']);
    }
}
