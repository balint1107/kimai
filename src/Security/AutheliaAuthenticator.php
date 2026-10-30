<?php

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/** Links a secret-verified Authelia identity to an existing user; never creates accounts or changes roles. */
final class AutheliaAuthenticator extends AbstractAuthenticator
{
    /** Borrows the repository for explicit ID lookup; no user data is modified. */
    public function __construct(private readonly UserRepository $users)
    {
    }

    /** Returns true for browser requests when SSO is explicitly enabled; missing identity then fails closed. */
    public function supports(Request $request): bool
    {
        return getenv('MONITOR_SSO_ENABLED') === '1';
    }

    /** Verifies the proxy secret and mapped username from request headers, returning the existing user's passport. */
    public function authenticate(Request $request): Passport
    {
        $secret = (string) getenv('MONITOR_SSO_SECRET');
        $provided = (string) $request->headers->get('X-Monitor-Secret', '');
        $mapping = json_decode((string) getenv('MONITOR_SSO_USERS'), true);
        $username = (string) $request->headers->get('X-Monitor-User', '');
        if (strlen($secret) < 32 || !hash_equals($secret, $provided) || !\is_array($mapping) || !isset($mapping[$username])) {
            throw new CustomUserMessageAuthenticationException('Central sign-in required.');
        }
        $id = $mapping[$username];
        if (!\is_int($id) || $id < 1) {
            throw new CustomUserMessageAuthenticationException('No linked account.');
        }

        return new SelfValidatingPassport(new UserBadge((string) $id, function (string $identifier): User {
            $user = $this->users->find((int) $identifier);
            if (!$user instanceof User) {
                throw new CustomUserMessageAuthenticationException('Linked account unavailable.');
            }

            return $user;
        }));
    }

    /** Continues the original request after authentication without changing its destination or data. */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        if (\in_array($request->attributes->get('_route'), ['security_check', 'link_login_check', 'saml_acs'], true)) {
            return new JsonResponse(['error' => 'Use central sign-in.'], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /** Rejects missing/forged identity with 403, without disclosing account or secret details. */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'Central sign-in required.'], Response::HTTP_FORBIDDEN);
    }
}
