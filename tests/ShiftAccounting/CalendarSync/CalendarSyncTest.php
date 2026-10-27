<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting\CalendarSync;

use App\Entity\ShiftCalendarConnection;
use App\Entity\ShiftCalendarLink;
use App\Entity\User;
use App\Kernel;
use App\ShiftAccounting\CalendarSync\CalendarSync;
use App\ShiftAccounting\CalendarSync\EventMapper;
use App\ShiftAccounting\CalendarSync\GoogleClient;
use App\ShiftAccounting\CalendarSync\LocalCalendar;
use App\ShiftAccounting\ShiftService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Database-backed sync acceptance with a stateful Google fake; explicitly restricted to a disposable database.
 * @phpstan-import-type GoogleEvent from EventMapper
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
class CalendarSyncTest extends TestCase
{
    /** Exercises preview, phone/local edits, echoes, rate preservation, conflict resolution, cancellation and deletion atomically. */
    public function testTwoWayLifecycle(): void
    {
        if (getenv('CALENDAR_SYNC_TEST_DATABASE') !== 'kimai_test') {
            self::markTestSkipped('Requires explicitly selected disposable kimai_test database');
        }
        $kernel = new Kernel('dev', true);
        $kernel->boot();
        $container = $kernel->getContainer();
        $registry = $container->get('doctrine');
        self::assertInstanceOf(\Doctrine\Persistence\ManagerRegistry::class, $registry);
        $em = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertSame('kimai_test', $em->getConnection()->getDatabase());
        $loader = $container->get('console.command_loader');
        self::assertInstanceOf(\Symfony\Component\Console\CommandLoader\CommandLoaderInterface::class, $loader);
        $command = $loader->get('app:shifts:calendar-sync');
        if ($command instanceof LazyCommand) {
            $command = (new \ReflectionMethod($command, 'getCommand'))->invoke($command);
        }
        self::assertInstanceOf(\App\Command\ShiftCalendarCommand::class, $command);
        $original = (new \ReflectionProperty($command, 'sync'))->getValue($command);
        self::assertInstanceOf(CalendarSync::class, $original);
        /** @var LocalCalendar $local */
        $local = (new \ReflectionProperty($original, 'local'))->getValue($original);
        /** @var ShiftService $shifts */
        $shifts = (new \ReflectionProperty($original, 'shifts'))->getValue($original);
        $tokens = (new \ReflectionProperty($command, 'tokens'))->getValue($command);
        self::assertInstanceOf(\Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface::class, $tokens);
        $previous = $tokens->getToken();
        $db = $em->getConnection();
        $db->beginTransaction();
        try {
            $owner = new User();
            $owner->setUsername('calendar-test-' . bin2hex(random_bytes(4)));
            $owner->setEmail($owner->getUsername() . '@example.test');
            $owner->setPassword('test-only-not-a-login');
            $owner->setRoles(['ROLE_SUPER_ADMIN']);
            $owner->setTimezone('Europe/Budapest');
            $em->persist($owner);
            $em->flush();
            $tokens->setToken(new UsernamePasswordToken($owner, 'secured_area', $owner->getRoles()));
            $place = $shifts->workplace('Calendar integration fixture', 3500, 'EFO');
            /** @var array<string, GoogleEvent> $remote */
            $remote = ['phone1' => ['id' => 'phone1', 'etag' => 'v1', 'summary' => 'Calendar integration fixture EFO', 'description' => 'Phone note', 'start' => ['dateTime' => '2027-02-03T08:00:00+01:00'], 'end' => ['dateTime' => '2027-02-03T10:00:00+01:00']]];
            /** @var array<string, list<GoogleEvent>> $instances */
            $instances = [];
            /** @var \ArrayObject<string, bool> $faults */
            $faults = new \ArrayObject(['expireToken' => false, 'uncertainInsert' => false, 'race' => false]);
            $writes = 0;
            $http = new MockHttpClient(function ($method, $url, $options) use (&$remote, &$writes, &$instances, $faults): MockResponse {
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($method === 'GET' && str_ends_with($path, '/instances')) {
                    return new MockResponse(json_encode(['items' => $instances[basename(\dirname($path))] ?? []], JSON_THROW_ON_ERROR));
                }
                if ($method === 'GET' && str_ends_with($path, '/events')) {
                    if ($faults['expireToken']) {
                        $faults['expireToken'] = false;

                        return new MockResponse('{}', ['http_code' => 410]);
                    }

                    return new MockResponse(json_encode(['items' => array_values($remote), 'nextSyncToken' => 'next'], JSON_THROW_ON_ERROR));
                }
                $id = basename($path);
                if ($method === 'GET') {
                    return new MockResponse(json_encode($remote[$id] ?? [], JSON_THROW_ON_ERROR), ['http_code' => isset($remote[$id]) ? 200 : 404]);
                }
                ++$writes;
                if ($method === 'PATCH' && $faults['race']) {
                    $faults['race'] = false;

                    return new MockResponse('{}', ['http_code' => 412]);
                }
                if ($method === 'DELETE') {
                    $remote[$id] = ['id' => $id, 'status' => 'cancelled'];

                    return new MockResponse('', ['http_code' => 204]);
                }
                /** @var GoogleEvent $body */
                $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                if ($method === 'POST') {
                    $id = $body['id'] ?? '';
                }
                /** @var GoogleEvent $stored */
                $stored = $remote[$id] ?? [];
                $remote[$id] = array_replace($stored, $body, ['id' => $id, 'etag' => 'write-' . $writes]);

                if ($method === 'POST' && $faults['uncertainInsert']) {
                    $faults['uncertainInsert'] = false;

                    return new MockResponse('{}', ['http_code' => 503]);
                }

                return new MockResponse(json_encode($remote[$id], JSON_THROW_ON_ERROR));
            });
            $google = new GoogleClient($http, $em, 'client', 'secret', base64_encode(str_repeat('k', 32)));
            $sync = new CalendarSync($em, $google, $local, new EventMapper(), $shifts);
            $connection = new ShiftCalendarConnection($owner);
            $connection->settings = ['calendars' => ['primary'], 'destination' => 'primary', 'from' => '2027-01-01', 'to' => '2027-12-31', 'aliases' => [], 'cursors' => []];
            $connection->credentials = $google->encrypt(['access_token' => 'fake', 'expires_at' => time() + 3600]);
            $em->persist($connection);
            $em->flush();
            $sync->run($connection, true);
            self::assertSame([], array_keys($local->records($owner)));
            self::assertSame(0, $writes);
            $link = $em->getRepository(ShiftCalendarLink::class)->findOneBy(['connection' => $connection, 'eventId' => 'phone1']);
            self::assertNotNull($link);
            $sync->resolve($link, 'remote');
            $connection->enabled = true;
            $em->flush();
            self::assertNotNull($link->localKey);
            $record = $local->records($owner)[$link->localKey];
            self::assertSame(3500, $record['rate'] ?? null);
            self::assertCount(1, $local->records($owner));
            $sync->run($connection);
            self::assertSame(0, $http->getRequestsCount() - 2, 'Preview and initial sync only read Google');
            $local->save($owner, array_replace($record, ['rate' => 4200, 'category' => 'Számla']), $link->localKey);
            $sync->run($connection);
            self::assertStringContainsString('Órabér: 4200', ($remote['phone1']['description'] ?? ''));
            self::assertSame(4200, $local->records($owner)[$link->localKey]['rate'] ?? null);
            // Independent edits merge, retaining a saved rate despite changed workplace defaults.
            $shifts->workplace($place->getName() ?? '', 9999, 'EFO', null, $place->getId());
            $remote['phone1']['description'] = str_replace('Phone note', 'Edited on phone', ($remote['phone1']['description'] ?? ''));
            $remote['phone1']['etag'] = 'phone-edit';
            $sync->run($connection);
            self::assertSame('Edited on phone', $local->records($owner)[$link->localKey]['note']);
            self::assertSame(4200, $local->records($owner)[$link->localKey]['rate'] ?? null);
            // Same-field changes must stop without overwriting either side.
            $local->save($owner, array_replace($local->records($owner)[$link->localKey], ['rate' => 5000]), $link->localKey);
            $remote['phone1']['description'] = str_replace('Órabér: 4200', 'Órabér: 6000', $remote['phone1']['description']);
            $remote['phone1']['etag'] = 'conflicting-phone-edit';
            $sync->run($connection);
            self::assertSame('conflict', $link->state);
            self::assertSame(5000, $local->records($owner)[$link->localKey]['rate'] ?? null);
            $sync->resolve($link, 'remote');
            self::assertSame(6000, $local->records($owner)[$link->localKey]['rate'] ?? null);
            // Repeated remote deletion preserves a single cancelled historical shift.
            $remote['phone1'] = ['id' => 'phone1', 'status' => 'cancelled'];
            $sync->run($connection);
            self::assertSame('deleted', $link->state);
            self::assertTrue($local->records($owner)[$link->localKey]['cancelled'] ?? false);
            $sync->run($connection);
            self::assertCount(1, $local->records($owner));
            // Local event creation/export, then native deletion, propagates without re-creation.
            $value = ['kind' => 'event', 'begin' => (string) strtotime('2027-02-04T09:00:00+01:00'), 'end' => (string) strtotime('2027-02-04T10:00:00+01:00'), 'all_day' => false, 'title' => 'Local general event', 'note' => '', 'color' => '#123abc'];
            $key = $local->save($owner, $value, null);
            $sync->run($connection);
            $out = $em->getRepository(ShiftCalendarLink::class)->findOneBy(['connection' => $connection, 'localKey' => $key]);
            self::assertNotNull($out);
            self::assertSame('Local general event', $remote[$out->eventId]['summary'] ?? null);
            $local->remoteDelete($owner, $key);
            $sync->run($connection);
            self::assertSame('cancelled', array_column($remote, 'status', 'id')[$out->eventId] ?? null);
            self::assertSame('deleted', $out->state);
            // Exact fall-back instants remain valid even when the end's wall-clock display is earlier.
            $dst = array_replace($value, ['begin' => (string) strtotime('2027-10-31T02:45:00+02:00'), 'end' => (string) strtotime('2027-10-31T02:15:00+01:00')]);
            $dstKey = $local->save($owner, $dst, null);
            self::assertSame(1800, (int) $local->records($owner)[$dstKey]['end'] - (int) $local->records($owner)[$dstKey]['begin']);
            // Refreshing an invalidated cursor must not duplicate accounting history.
            $before = \count($local->records($owner));
            $faults['expireToken'] = true;
            $sync->run($connection);
            self::assertCount($before, $local->records($owner));
            // A remotely successful insert with an uncertain response is recovered by stable identity.
            $retryKey = $local->save($owner, array_replace($value, ['title' => 'Retry fixture', 'begin' => (string) strtotime('2027-04-01T09:00:00+02:00'), 'end' => (string) strtotime('2027-04-01T10:00:00+02:00')]), null);
            $faults['uncertainInsert'] = true;
            $sync->run($connection);
            $retry = $em->getRepository(ShiftCalendarLink::class)->findOneBy(['connection' => $connection, 'localKey' => $retryKey]);
            self::assertNotNull($retry);
            self::assertNotNull($retry->pending);
            $remoteCount = \count($remote);
            $sync->run($connection);
            self::assertCount($remoteCount, $remote);
            self::assertNull($retry->pending);
            $faults['race'] = true;
            $local->save($owner, array_replace($local->records($owner)[$retryKey], ['note' => 'Local race']), $retryKey);
            $sync->run($connection);
            self::assertSame('conflict', $retry->state);
            $sync->resolve($retry, 'local');
            $sync->run($connection);
            self::assertSame('linked', $retry->state);
            // A token rebuild confirms absent events by ID instead of silently retaining vanished tombstones.
            unset($remote[$retry->eventId]);
            $faults['expireToken'] = true;
            $sync->run($connection);
            self::assertSame('deleted', $retry->state);
            self::assertArrayNotHasKey($retryKey, $local->records($owner));
            // Recurring instances retain their identity when moved, and shortened series cancel removed work.
            $remote['series'] = ['id' => 'series', 'etag' => 'series-v1', 'summary' => 'Calendar integration fixture EFO', 'recurrence' => ['RRULE:FREQ=DAILY;COUNT=1'], 'start' => ['dateTime' => '2027-05-01T08:00:00+02:00'], 'end' => ['dateTime' => '2027-05-01T10:00:00+02:00']];
            $instances['series'] = [array_replace($remote['series'], ['id' => 'occurrence', 'recurringEventId' => 'series'])];
            unset($instances['series'][0]['recurrence']);
            $sync->run($connection);
            $occurrence = $em->getRepository(ShiftCalendarLink::class)->findOneBy(['connection' => $connection, 'eventId' => 'occurrence']);
            self::assertNotNull($occurrence);
            self::assertNotNull($occurrence->localKey);
            $instances['series'][0]['start']['dateTime'] = '2027-05-01T08:30:00+02:00';
            $instances['series'][0]['etag'] = 'moved-instance';
            $sync->run($connection);
            self::assertSame((string) strtotime('2027-05-01T08:30:00+02:00'), $local->records($owner)[$occurrence->localKey]['begin']);
            $instances['series'] = [];
            $sync->run($connection);
            self::assertSame('deleted', $occurrence->state);
            self::assertTrue($local->records($owner)[$occurrence->localKey]['cancelled'] ?? false);
        } finally {
            $db->rollBack();
            $tokens->setToken($previous);
            $em->clear();
            $kernel->shutdown();
        }
    }
}
