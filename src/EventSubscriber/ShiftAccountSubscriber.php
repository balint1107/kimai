<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Keeps every account in the standalone frontend; native business pages/APIs are unavailable even to administrators. */
final class ShiftAccountSubscriber implements EventSubscriberInterface
{
    /** Reads the authenticated $tokens and inherited administrative roles through $authorization; $urls generates prefix-aware calendar navigation. */
    public function __construct(private TokenStorageInterface $tokens, private AuthorizationCheckerInterface $authorization, private UrlGeneratorInterface $urls)
    {
    }

    /** Runs after routing/firewall authentication and before any controller data query. */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 0]];
    }

    /** Allows private routes for the current owner and redirects legacy GET navigation into the standalone UI; all other native requests are denied. */
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }
        $request = $event->getRequest();
        $route = $request->attributes->getString('_route');
        if ($route === 'logout') {
            return;
        }
        if (!$user->isEnabled()) {
            $this->tokens->setToken(null);
            if ($request->hasSession()) {
                $request->getSession()->invalidate();
            }
            throw new AccessDeniedException('Account is disabled');
        }
        if ($request->isMethod('GET')) {
            $destination = match ($route) {
                'dashboard' => 'shift_calendar',
                'admin_user', 'admin_user_paginated', 'user_export' => 'shift_users',
                'admin_user_create' => 'shift_user_create',
                'my_profile', 'wizard_intro', 'wizard_profile', 'wizard_next', 'wizard_previous', 'wizard_finish' => 'shift_account',
                'wizard_password' => 'shift_account_password',
                default => null,
            };
            if (\in_array($route, ['user_profile', 'user_profile_edit', 'user_profile_preferences', 'user_profile_password'], true)) {
                $destination = $request->attributes->get('username') === $user->getUserIdentifier()
                    ? ($route === 'user_profile_password' ? 'shift_account_password' : 'shift_account')
                    : ($this->authorization->isGranted('ROLE_ADMIN') ? 'shift_users' : null);
            }
            if ($destination !== null) {
                $event->setResponse(new RedirectResponse($this->urls->generate($destination)));

                return;
            }
        }
        $value = $request->attributes->get('_controller', '');
        $controller = \is_string($value) ? $value : '';
        if (str_starts_with($route, 'shift_') || \in_array($route, ['home', 'homepage', '2fa_login', '2fa_login_check', 'loginFallback', 'logoutFallback'], true)
            || str_starts_with($controller, 'App\\Controller\\Security\\') || str_starts_with($controller, 'App\\Controller\\Auth\\')) {
            return;
        }
        if (\in_array($route, ['user_profile_2fa', 'user_profile_2fa_deactivate'], true)
            && $request->attributes->get('username') === $user->getUserIdentifier()) {
            return;
        }
        throw new AccessDeniedException('This account can access private accounting and its own profile only');
    }
}
