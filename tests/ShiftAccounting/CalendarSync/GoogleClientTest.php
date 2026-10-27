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
use App\ShiftAccounting\CalendarSync\GoogleClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Exercises ciphertext integrity, PKCE consent parameters, refresh behavior and conditional transport. */
class GoogleClientTest extends TestCase
{
    /** Ensures encrypted tokens round-trip and tampered authenticated ciphertext fails closed. */
    public function testEncryptionAndConsent(): void
    {
        $client = new GoogleClient(new MockHttpClient(), $this->createMock(EntityManagerInterface::class), 'client', 'secret', base64_encode(str_repeat('k', 32)));
        self::assertTrue($client->configured());
        $tokens = ['refresh_token' => 'private refresh token'];
        $encrypted = $client->encrypt($tokens);
        self::assertStringNotContainsString('private refresh token', $encrypted);
        self::assertSame($tokens, $client->decrypt($encrypted));
        self::assertNotSame($encrypted, $client->encrypt($tokens));
        $url = $client->authorizationUrl('state', 'challenge', 'https://example.com/kimai/callback');
        self::assertStringContainsString('code_challenge_method=S256', $url);
        self::assertStringContainsString('access_type=offline', $url);
        $wrong = new GoogleClient(new MockHttpClient(), $this->createMock(EntityManagerInterface::class), 'client', 'secret', base64_encode(str_repeat('x', 32)));
        $this->expectException(\RuntimeException::class);
        $wrong->decrypt($encrypted);
    }

    /** Verifies expired access refreshes offline and an ETag precondition failure is exposed without provider body leakage. */
    public function testRefreshAndPrecondition(): void
    {
        $http = new MockHttpClient([new MockResponse('{"access_token":"fresh","expires_in":3600}'), new MockResponse('{"sensitive":"never expose"}', ['http_code' => 412])]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $client = new GoogleClient($http, $em, 'client', 'secret', base64_encode(str_repeat('k', 32)));
        $connection = new ShiftCalendarConnection(new User());
        $connection->credentials = $client->encrypt(['access_token' => 'expired', 'refresh_token' => 'refresh', 'expires_at' => 0]);
        try {
            $client->request($connection, 'PATCH', 'calendars/primary/events/fixture', ['summary' => 'New'], [], 'old-etag');
            self::fail('Expected conditional failure');
        } catch (\RuntimeException $e) {
            self::assertSame(412, $e->getCode());
            self::assertStringNotContainsString('sensitive', $e->getMessage());
        }
        self::assertSame('fresh', $client->decrypt($connection->credentials)['access_token']);
        self::assertSame('refresh', $client->decrypt($connection->credentials)['refresh_token']);
        self::assertSame(2, $http->getRequestsCount());
    }

    /** Legacy two-scope grants can refresh without requiring the new optional setup capability. */
    public function testLegacyRefreshScopesRemainValid(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'fresh', 'expires_in' => 3600, 'scope' => GoogleClient::SYNC_SCOPES], JSON_THROW_ON_ERROR)), new MockResponse('{"items":[]}')]);
        $client = new GoogleClient($http, $this->createMock(EntityManagerInterface::class), 'client', 'secret', base64_encode(str_repeat('k', 32)));
        $connection = new ShiftCalendarConnection(new User());
        $connection->credentials = $client->encrypt(['access_token' => 'expired', 'refresh_token' => 'refresh', 'scope' => GoogleClient::SYNC_SCOPES, 'expires_at' => 0]);
        self::assertSame([], $client->request($connection, 'GET', 'users/me/calendarList')['items'] ?? null);
        self::assertSame(GoogleClient::SYNC_SCOPES, $client->decrypt($connection->credentials)['scope']);
    }

    /** New consent requires explicit approval of creation permission before any provisioning can occur. */
    public function testNewConsentRequiresCalendarCreationScope(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'fresh', 'refresh_token' => 'refresh', 'scope' => GoogleClient::SYNC_SCOPES], JSON_THROW_ON_ERROR))]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $client = new GoogleClient($http, $em, 'client', 'secret', base64_encode(str_repeat('k', 32)));
        $this->expectException(\RuntimeException::class);
        $client->authorize(new ShiftCalendarConnection(new User()), 'code', 'verifier', 'https://example.com/callback');
    }

    /** Approved three-scope consent is encrypted and includes the secondary-calendar creation grant. */
    public function testNewConsentStoresCreationScope(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'fresh', 'refresh_token' => 'refresh', 'scope' => GoogleClient::SCOPES], JSON_THROW_ON_ERROR)), new MockResponse('{"id":"owner@example.test"}')]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));
        $client = new GoogleClient($http, $em, 'client', 'secret', base64_encode(str_repeat('k', 32)));
        $connection = new ShiftCalendarConnection(new User());
        $client->authorize($connection, 'code', 'verifier', 'https://example.com/callback');
        self::assertNotNull($connection->credentials);
        self::assertSame(GoogleClient::SCOPES, $client->decrypt($connection->credentials)['scope']);
        self::assertStringContainsString('calendar.app.created', $client->authorizationUrl('state', 'challenge', 'https://example.com/callback'));
    }

    /** Legacy grants bind their canonical primary identity once using existing permissions. */
    public function testLegacyAccountBinding(): void
    {
        $http = new MockHttpClient([new MockResponse('{"id":"owner@example.test"}')]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($this->createMock(\Doctrine\ORM\EntityRepository::class));
        $em->expects(self::once())->method('flush');
        $client = new GoogleClient($http, $em, 'client', 'secret', base64_encode(str_repeat('k', 32)));
        $connection = new ShiftCalendarConnection(new User());
        $connection->credentials = $client->encrypt(['access_token' => 'legacy', 'expires_at' => time() + 3600]);
        self::assertSame('owner@example.test', $client->bindAccount($connection));
        self::assertSame('owner@example.test', $client->bindAccount($connection));
        self::assertSame(1, $http->getRequestsCount());
    }

    /** Different-account consent must not replace the old grant or retained sync identity. */
    public function testAccountSwitchRejected(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode(['access_token' => 'fresh', 'refresh_token' => 'refresh', 'scope' => GoogleClient::SCOPES], JSON_THROW_ON_ERROR)), new MockResponse('{"id":"other@example.test"}')]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $client = new GoogleClient($http, $em, 'client', 'secret', base64_encode(str_repeat('k', 32)));
        $connection = new ShiftCalendarConnection(new User());
        $connection->accountId = 'owner@example.test';
        $connection->credentials = $client->encrypt(['refresh_token' => 'original']);
        $original = $connection->credentials;
        try {
            $client->authorize($connection, 'code', 'verifier', 'https://example.com/callback');
            self::fail('Different Google account was accepted');
        } catch (\RuntimeException) {
            self::assertSame($original, $connection->credentials);
            self::assertSame('owner@example.test', $connection->accountId);
        }
    }

    /** Missing server configuration disables authorization without affecting the rest of Kimai. */
    public function testMissingConfiguration(): void
    {
        $client = new GoogleClient(new MockHttpClient(), $this->createMock(EntityManagerInterface::class), '', '', '');
        self::assertFalse($client->configured());
        $this->expectException(\RuntimeException::class);
        $client->authorizationUrl('state', 'challenge', 'https://example.com');
    }
}
