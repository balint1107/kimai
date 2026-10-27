<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting\CalendarSync;

use App\Entity\ShiftCalendarConnection;
use Doctrine\ORM\EntityManagerInterface;

/** First-connection calendar setup; owned calendars are reused and uncertain inserts are never blindly retried.
 * @phpstan-import-type GoogleEvent from EventMapper
 */
final class CalendarProvisioner
{
    /** Uses $google for owner-authorized HTTPS, $em for durable checkpoints/serialization $mapper for accent-insensitive names and $ownership for exclusive reservations. */
    public function __construct(private GoogleClient $google, private EntityManagerInterface $em, private EventMapper $mapper, private CalendarOwnership $ownership)
    {
    }

    /** Prepares two calendars for persisted $connection; returns false if selections already exist, true when defaults are ready. New defaults stay paused.
     * @throws \RuntimeException setup is ambiguous, busy or incomplete; credentials and completed checkpoints remain available
     */
    public function prepare(ShiftCalendarConnection $connection): bool
    {
        if ($connection->settings['calendars'] !== [] || $connection->settings['destination'] !== '') {
            return false;
        }
        if ($connection->id === null || $connection->credentials === null) {
            throw new \RuntimeException('Connect Google before calendar setup');
        }
        $db = $this->em->getConnection();
        $lock = 'kimai-google-' . $connection->id;
        if (!\in_array($db->fetchOne('SELECT GET_LOCK(?, 0)', [$lock]), [1, '1'], true)) {
            throw new \RuntimeException('Calendar setup is busy; try again later');
        }
        try {
            $this->em->refresh($connection);
            if ($connection->settings['calendars'] !== [] || $connection->settings['destination'] !== '') {
                return false;
            }
            $owned = [];
            $pageToken = null;
            do {
                $page = $this->google->request($connection, 'GET', 'users/me/calendarList', null, ['maxResults' => 250, 'showHidden' => 'true', 'minAccessRole' => 'owner', 'pageToken' => $pageToken]);
                foreach ($page['items'] ?? [] as $calendar) {
                    if (($calendar['accessRole'] ?? '') === 'owner' && empty($calendar['primary']) && !empty($calendar['id'])) {
                        $owned[$calendar['id']] = $calendar;
                    }
                }
                $pageToken = $page['nextPageToken'] ?? null;
            } while ($pageToken !== null);
            $work = $this->calendar($connection, 'work', 'Helyettesítések', $owned);
            $holiday = $this->calendar($connection, 'holiday', 'Szabadság', $owned);
            if ($work === $holiday) {
                throw new \RuntimeException('Choose separate shift and holiday calendars');
            }
            $this->ownership->claim($connection, [$work, $holiday]);
            $connection->settings = array_replace($connection->settings, ['calendars' => [$work, $holiday], 'destination' => $work, 'holiday_calendar' => $holiday, 'plain_events' => true, 'cursors' => [], 'previewed' => false]);
            unset($connection->settings['setup_calendars'], $connection->settings['pending_calendar_creation']);
            $connection->enabled = false;
            $connection->error = null;
            $this->em->flush();

            return true;
        } finally {
            $db->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Reuses a checkpoint or one owned name match for $role/$name, otherwise creates once; $owned excludes primary/shared calendars.
     * @param 'work'|'holiday' $role
     * @param array<string, GoogleEvent> $owned
     * @return string google calendar ID; successful partial work is flushed before the next network write
     */
    private function calendar(ShiftCalendarConnection $connection, string $role, string $name, array $owned): string
    {
        $saved = $connection->settings['setup_calendars'][$role] ?? null;
        if ($saved !== null) {
            if (!isset($owned[$saved])) {
                throw new \RuntimeException('Previously prepared calendar is no longer owned; choose calendars manually');
            }

            return $saved;
        }
        $names = $role === 'work' ? ['helyettesites', 'helyettesitesek'] : ['szabadsag'];
        $matches = array_filter($owned, fn ($calendar) => \in_array($this->mapper->normalize($calendar['summary'] ?? ''), $names, true));
        if (\count($matches) > 1) {
            throw new \RuntimeException('Several owned calendars match; choose calendars manually');
        }
        if ($matches !== []) {
            $id = (string) array_key_first($matches);
        } else {
            if (($connection->settings['pending_calendar_creation'] ?? '') === $role) {
                throw new \RuntimeException('Previous calendar creation is uncertain; check Google and choose calendars manually');
            }
            $connection->settings['pending_calendar_creation'] = $role;
            $this->em->flush();
            try {
                $created = $this->google->request($connection, 'POST', 'calendars', ['summary' => $name, 'timeZone' => 'Europe/Budapest', 'description' => 'Kimai — ' . $name]);
            } catch (\RuntimeException $e) {
                // Definite rejections can be retried after consent; timeouts/5xx retain the uncertain-insert checkpoint.
                if ($e->getCode() >= 400 && $e->getCode() < 500 && $e->getCode() !== 408) {
                    unset($connection->settings['pending_calendar_creation']);
                    $this->em->flush();
                }
                throw $e;
            }
            $id = $created['id'] ?? '';
            if ($id === '' || $id === 'primary') {
                throw new \RuntimeException('Google returned no secondary calendar identity');
            }
        }
        $connection->settings['setup_calendars'][$role] = $id;
        if (($connection->settings['pending_calendar_creation'] ?? '') === $role) {
            unset($connection->settings['pending_calendar_creation']);
        }
        $this->em->flush();

        return $id;
    }
}
