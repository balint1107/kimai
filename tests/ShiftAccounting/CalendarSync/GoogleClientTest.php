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

    /** Missing server configuration disables authorization without affecting the rest of Kimai. */
    public function testMissingConfiguration(): void
    {
        $client = new GoogleClient(new MockHttpClient(), $this->createMock(EntityManagerInterface::class), '', '', '');
        self::assertFalse($client->configured());
        $this->expectException(\RuntimeException::class);
        $client->authorizationUrl('state', 'challenge', 'https://example.com');
    }
}
