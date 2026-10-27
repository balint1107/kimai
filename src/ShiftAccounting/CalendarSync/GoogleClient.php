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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Restricted Google HTTPS transport and encrypted offline OAuth credentials; never logs provider responses.
 * @phpstan-import-type GoogleEvent from EventMapper
 * @phpstan-type GooglePage array{accessRole?:string,id?:string,etag?:string,status?:string,summary?:string,description?:string,location?:string,start?:array{date?:string,dateTime?:string,timeZone?:string},end?:array{date?:string,dateTime?:string,timeZone?:string},recurrence?:list<string>,recurringEventId?:string,htmlLink?:string,extendedProperties?:array{private?:array<string,string>,shared?:array<string,string>},items?:list<GoogleEvent>,nextPageToken?:string,nextSyncToken?:string}
 * @phpstan-type Tokens array{access_token?:string,refresh_token?:string,expires_at?:int,expires_in?:int,scope?:string}
 */
final class GoogleClient
{
    public const SYNC_SCOPES = 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.calendarlist.readonly';
    public const SCOPES = self::SYNC_SCOPES . ' https://www.googleapis.com/auth/calendar.app.created';

    /** Injects $http/$em and optional deployment credentials; $key is a base64-encoded 32-byte encryption key. */
    public function __construct(
        private HttpClientInterface $http,
        private EntityManagerInterface $em,
        #[Autowire('%env(GOOGLE_CALENDAR_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(GOOGLE_CALENDAR_CLIENT_SECRET)%')]
        private string $clientSecret,
        #[Autowire('%env(GOOGLE_CALENDAR_KEY)%')]
        private string $key,
    ) {
    }

    /** Returns whether deployment has all credentials; no secrets are exposed. */
    public function configured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && \strlen(base64_decode($this->key, true) ?: '') === 32;
    }

    /** Builds Google's fixed authorization URL using caller-owned $state, PKCE $challenge and exact $callback. */
    public function authorizationUrl(string $state, string $challenge, string $callback): string
    {
        if (!$this->configured()) {
            throw new \RuntimeException('Google OAuth is not configured on the server');
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $this->clientId, 'redirect_uri' => $callback, 'response_type' => 'code', 'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256']);
    }

    /** Exchanges one-use $code/$verifier for $connection at exact $callback, saving authenticated encrypted credentials with explicit calendar-creation consent. */
    public function authorize(ShiftCalendarConnection $connection, string $code, string $verifier, string $callback): void
    {
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => $callback]);
        if (empty($tokens['refresh_token'])) {
            throw new \RuntimeException('Google did not issue offline access; reconnect and approve all requested calendar permissions');
        }
        $this->saveTokens($connection, $tokens);
    }

    /** Calls a fixed Calendar API $path for $connection, with optional $body/$query and conditional $etag; HTTP failures carry only numeric status.
     * @param array<string, mixed>|null $body
     * @param array<string, mixed> $query
     * @return GooglePage
     */
    public function request(ShiftCalendarConnection $connection, string $method, string $path, ?array $body = null, array $query = [], ?string $etag = null): array
    {
        if ($connection->credentials === null) {
            throw new \RuntimeException('Connect Google Calendar first');
        }
        $tokens = $this->decrypt($connection->credentials);
        if (($tokens['expires_at'] ?? 0) <= time() + 60) {
            $tokens = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]) + $tokens;
            $this->saveTokens($connection, $tokens);
        }
        $options = ['auth_bearer' => $tokens['access_token'], 'query' => $query, 'timeout' => 30, 'max_redirects' => 0, 'headers' => $etag === null ? [] : ['If-Match' => $etag]];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $response = $this->http->request($method, 'https://www.googleapis.com/calendar/v3/' . ltrim($path, '/'), $options);
        $status = $response->getStatusCode();
        if ($status >= 400) {
            throw new \RuntimeException('Google Calendar HTTP ' . $status, $status);
        }

        /** @var GooglePage $data */
        $data = $status === 204 ? [] : $response->toArray(false);

        return $data;
    }

    /** Authenticates and encrypts JSON $tokens using a fresh nonce; returns ciphertext safe to persist.
     * @param array<string, mixed> $tokens
     */
    public function encrypt(array $tokens): string
    {
        $key = base64_decode($this->key, true);
        if ($key === false || \strlen($key) !== 32) {
            throw new \RuntimeException('Invalid Google Calendar encryption key');
        }
        $nonce = random_bytes(12);
        $cipher = openssl_encrypt(json_encode($tokens, JSON_THROW_ON_ERROR), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Cannot encrypt calendar credentials');
        }

        return base64_encode($nonce . $tag . $cipher);
    }

    /** Authenticates/decrypts saved $ciphertext; fails closed for a wrong key or tampered payload.
     * @return array<string, mixed>
     */
    public function decrypt(string $ciphertext): array
    {
        $raw = base64_decode($ciphertext, true);
        $key = base64_decode($this->key, true);
        if ($raw === false || \strlen($raw) < 29 || $key === false || \strlen($key) !== 32) {
            throw new \RuntimeException('Invalid encrypted calendar credentials');
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('Calendar credential authentication failed');
        }

        $data = json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \RuntimeException('Invalid calendar credential document');
        }
        /** @var array<string, mixed> $document */
        $document = $data;

        return $document;
    }

    /** Exchanges fixed OAuth $fields without following redirects; validates all scopes on new consent and sync-only scopes on refresh so existing connections keep working.
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function token(array $fields): array
    {
        $response = $this->http->request('POST', 'https://oauth2.googleapis.com/token', ['body' => $fields + ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret], 'timeout' => 30, 'max_redirects' => 0]);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Google authorization expired or failed; reconnect the account', 401);
        }
        $tokens = $response->toArray(false);
        if (empty($tokens['access_token'])) {
            throw new \RuntimeException('Google returned no access token', 401);
        }
        if (isset($tokens['scope'])) {
            foreach (explode(' ', ($fields['grant_type'] ?? '') === 'authorization_code' ? self::SCOPES : self::SYNC_SCOPES) as $scope) {
                if (!\in_array($scope, explode(' ', $tokens['scope']), true)) {
                    throw new \RuntimeException('Approve all requested Google Calendar permissions', 401);
                }
            }
        }
        $tokens['expires_at'] = time() + (int) ($tokens['expires_in'] ?? 3600);

        return $tokens;
    }

    /** Persists only encrypted $tokens on $connection; cleartext is never written to logs or templates.
     * @param array<string, mixed> $tokens
     */
    private function saveTokens(ShiftCalendarConnection $connection, array $tokens): void
    {
        $connection->credentials = $this->encrypt($tokens);
        $connection->error = null;
        $this->em->persist($connection);
        $this->em->flush();
    }
}
