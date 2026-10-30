<?php

namespace App\Tests\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AutheliaAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/** Verifies fixed account bindings and rejection before any repository lookup on forged requests. */
class AutheliaAuthenticatorTest extends TestCase
{
    /** Clears test-only environment variables to avoid influencing other tests. */
    protected function tearDown(): void
    {
        foreach (['MONITOR_SSO_ENABLED', 'MONITOR_SSO_SECRET', 'MONITOR_SSO_USERS'] as $name) {
            putenv($name);
        }
    }

    /** An enabled adapter refuses missing proxy identity before querying an account. */
    public function testMissingSecretIsRejected(): void
    {
        putenv('MONITOR_SSO_ENABLED=1');
        putenv('MONITOR_SSO_SECRET=' . str_repeat('a', 64));
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::never())->method('find');
        $authenticator = new AutheliaAuthenticator($repository);
        self::assertTrue($authenticator->supports(new Request()));
        $this->expectException(AuthenticationException::class);
        $authenticator->authenticate(new Request());
    }

    /** A valid proxy identity loads only the configured existing record, preserving the user's object and roles. */
    public function testExplicitExistingUserMapping(): void
    {
        putenv('MONITOR_SSO_SECRET=' . str_repeat('a', 64));
        putenv('MONITOR_SSO_USERS={"balint":5}');
        $user = new User();
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::once())->method('find')->with(5)->willReturn($user);
        $request = new Request(server: ['HTTP_X_MONITOR_SECRET' => str_repeat('a', 64), 'HTTP_X_MONITOR_USER' => 'balint']);
        $passport = (new AutheliaAuthenticator($repository))->authenticate($request);
        self::assertSame($user, $passport->getUser());
    }

    /** Unknown identities never silently register or fall back to an email/password account. */
    public function testUnknownIdentityIsRejected(): void
    {
        putenv('MONITOR_SSO_SECRET=' . str_repeat('a', 64));
        putenv('MONITOR_SSO_USERS={"balint":5}');
        $repository = $this->createMock(UserRepository::class);
        $repository->expects(self::never())->method('find');
        $request = new Request(server: ['HTTP_X_MONITOR_SECRET' => str_repeat('a', 64), 'HTTP_X_MONITOR_USER' => 'unknown']);
        $this->expectException(AuthenticationException::class);
        (new AutheliaAuthenticator($repository))->authenticate($request);
    }
    /** Other native browser authenticators cannot replace the central identity on the same request. */
    public function testNativeCredentialExchangesAreDisabled(): void
    {
        $authenticator = new AutheliaAuthenticator($this->createMock(UserRepository::class));
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);
        foreach (['security_check', 'link_login_check', 'saml_acs'] as $route) {
            $request = new Request(attributes: ['_route' => $route]);
            self::assertSame(403, $authenticator->onAuthenticationSuccess($request, $token, 'secured_area')->getStatusCode());
        }
        self::assertNull($authenticator->onAuthenticationSuccess(new Request(), $token, 'secured_area'));
    }

}
