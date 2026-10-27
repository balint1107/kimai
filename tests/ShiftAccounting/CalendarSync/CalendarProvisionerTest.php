<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting\CalendarSync;

use App\Entity\ShiftCalendarConnection;
use App\Entity\User;
use App\ShiftAccounting\CalendarSync\CalendarProvisioner;
use App\ShiftAccounting\CalendarSync\EventMapper;
use App\ShiftAccounting\CalendarSync\GoogleClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Verifies first-connection provisioning, owned-calendar reuse and safe recovery from uncertain external writes. */
class CalendarProvisionerTest extends TestCase
{
    /** Returns a connected fixture with $responses and an advisory $lock result; no real Google or database access occurs.
     * @param list<MockResponse>|callable(string, string, array<string, mixed>): MockResponse $responses
     * @return array{CalendarProvisioner, ShiftCalendarConnection, MockHttpClient}
     */
    private function fixture(array|callable $responses, int $lock = 1): array
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $db = $this->createMock(Connection::class);
        $db->method('fetchOne')->willReturn($lock);
        $em->method('getConnection')->willReturn($db);
        $http = new MockHttpClient($responses);
        $google = new GoogleClient($http, $em, 'client', 'secret', base64_encode(str_repeat('k', 32)));
        $connection = new ShiftCalendarConnection(new User());
        $connection->id = 1;
        $connection->credentials = $google->encrypt(['access_token' => 'fake', 'expires_at' => time() + 3600]);

        return [new CalendarProvisioner($google, $em, new EventMapper(), new \App\ShiftAccounting\CalendarSync\CalendarOwnership($em)), $connection, $http];
    }

    /** Creates each Budapest secondary calendar once, selects destinations and requires preview before event sync. */
    public function testCreatesPairOnce(): void
    {
        $names = [];
        [$setup, $connection, $http] = $this->fixture(function (string $method, string $url, array $options) use (&$names): MockResponse {
            if ($method === 'GET') {
                return new MockResponse('{"items":[]}');
            }
            self::assertSame('https://www.googleapis.com/calendar/v3/calendars', $url);
            self::assertIsString($options['body']);
            /** @var array{timeZone:string,summary:string} $body */
            $body = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('Europe/Budapest', $body['timeZone']);
            $names[] = $body['summary'];

            return new MockResponse(json_encode(['id' => 'created-' . \count($names)], JSON_THROW_ON_ERROR));
        });
        self::assertTrue($setup->prepare($connection));
        self::assertSame(['Helyettesítések', 'Szabadság'], $names);
        self::assertSame(['created-1', 'created-2'], $connection->settings['calendars']);
        self::assertSame('created-1', $connection->settings['destination']);
        self::assertSame('created-2', $connection->settings['holiday_calendar'] ?? null);
        self::assertFalse($connection->enabled);
        self::assertFalse($connection->settings['previewed'] ?? null);
        self::assertTrue($connection->settings['plain_events'] ?? null);
        self::assertArrayNotHasKey('pending_calendar_creation', $connection->settings);
        self::assertFalse($setup->prepare($connection));
        self::assertSame(3, $http->getRequestsCount());
    }

    /** Reads all pages and reuses accent-free owned names, excluding primary and shared-writer calendars. */
    public function testReusesOwnedPaginatedCalendars(): void
    {
        [$setup, $connection, $http] = $this->fixture([
            new MockResponse('{"items":[{"id":"primary","primary":true,"accessRole":"owner","summary":"Helyettesítések"},{"id":"shared","accessRole":"writer","summary":"Szabadság"}],"nextPageToken":"next"}'),
            new MockResponse('{"items":[{"id":"work","accessRole":"owner","summary":"helyettesites"},{"id":"holiday","accessRole":"owner","summary":"SZABADSAG"}]}'),
        ]);
        self::assertTrue($setup->prepare($connection));
        self::assertSame(['work', 'holiday'], $connection->settings['calendars']);
        self::assertSame(2, $http->getRequestsCount());
    }

    /** Existing selections and running-state policy are not overwritten by provisioning on reconnection. */
    public function testPreservesExistingSelection(): void
    {
        [$setup, $connection, $http] = $this->fixture([]);
        $connection->settings['calendars'] = ['custom'];
        $connection->settings['destination'] = 'custom';
        $connection->enabled = true;
        $settings = $connection->settings;
        self::assertFalse($setup->prepare($connection));
        self::assertSame($settings, $connection->settings);
        self::assertTrue($connection->enabled);
        self::assertSame(0, $http->getRequestsCount());
    }

    /** Ambiguous owned matches stop before creating or adopting an arbitrary calendar. */
    public function testAmbiguousNamesRequireManualChoice(): void
    {
        [$setup, $connection, $http] = $this->fixture([new MockResponse('{"items":[{"id":"one","accessRole":"owner","summary":"Helyettesítések"},{"id":"two","accessRole":"owner","summary":"Helyettesitesek"}]}')]);
        try {
            $setup->prepare($connection);
            self::fail('Ambiguous calendars were adopted');
        } catch (\RuntimeException) {
            self::assertSame([], $connection->settings['calendars']);
            self::assertSame(1, $http->getRequestsCount());
        }
    }

    /** A lost insert response never causes a second blind insert; a later owned-name match allows safe completion. */
    public function testUncertainInsertRecovery(): void
    {
        [$setup, $connection, $http] = $this->fixture([
            new MockResponse('{"items":[]}'), new MockResponse('{}', ['http_code' => 503]),
            new MockResponse('{"items":[]}'),
            new MockResponse('{"items":[{"id":"recovered","accessRole":"owner","summary":"Helyettesítések"}]}'), new MockResponse('{"id":"holiday"}'),
        ]);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $setup->prepare($connection);
                self::fail('Uncertain creation should require reconciliation');
            } catch (\RuntimeException) {
                self::assertSame('work', $connection->settings['pending_calendar_creation'] ?? null);
            }
        }
        self::assertSame(3, $http->getRequestsCount());
        self::assertTrue($setup->prepare($connection));
        self::assertSame(['recovered', 'holiday'], $connection->settings['calendars']);
        self::assertSame(5, $http->getRequestsCount());
    }

    /** An acknowledged first calendar survives failure creating the second and is never recreated. */
    public function testPartialSetupRecovery(): void
    {
        [$setup, $connection, $http] = $this->fixture([
            new MockResponse('{"items":[]}'), new MockResponse('{"id":"work"}'), new MockResponse('{}', ['http_code' => 503]),
            new MockResponse('{"items":[{"id":"work","accessRole":"owner","summary":"Renamed workplace calendar"},{"id":"holiday","accessRole":"owner","summary":"Szabadság"}]}'),
        ]);
        try {
            $setup->prepare($connection);
            self::fail('Expected partial setup failure');
        } catch (\RuntimeException) {
            self::assertSame('work', $connection->settings['setup_calendars']['work'] ?? null);
            self::assertSame('holiday', $connection->settings['pending_calendar_creation'] ?? null);
        }
        self::assertTrue($setup->prepare($connection));
        self::assertSame(['work', 'holiday'], $connection->settings['calendars']);
        self::assertSame(4, $http->getRequestsCount());
    }

    /** A definite permission rejection clears uncertainty so newly granted consent can retry setup. */
    public function testPermissionRejectionCanRetry(): void
    {
        [$setup, $connection, $http] = $this->fixture([
            new MockResponse('{"items":[]}'), new MockResponse('{}', ['http_code' => 403]),
            new MockResponse('{"items":[]}'), new MockResponse('{"id":"work"}'), new MockResponse('{"id":"holiday"}'),
        ]);
        try {
            $setup->prepare($connection);
            self::fail('Expected permission rejection');
        } catch (\RuntimeException) {
            self::assertArrayNotHasKey('pending_calendar_creation', $connection->settings);
        }
        self::assertTrue($setup->prepare($connection));
        self::assertSame(5, $http->getRequestsCount());
    }

    /** Concurrent sync/setup holds the same lock, preventing a competing calendar insert. */
    public function testBusySetupCannotCreate(): void
    {
        [$setup, $connection] = $this->fixture([], 0);
        $this->expectException(\RuntimeException::class);
        $setup->prepare($connection);
    }
}
