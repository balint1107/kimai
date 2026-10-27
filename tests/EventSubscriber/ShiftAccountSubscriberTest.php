<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\ShiftAccountSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Verifies request boundaries independently of role grants on native controllers. */
class ShiftAccountSubscriberTest extends TestCase
{
    /** Covers $route for a regular or administrative $admin account and optional $username ownership. */
    #[DataProvider('routes')]
    public function testRoutes(string $route, ?string $username, bool $admin, bool $allowed): void
    {
        $user = (new User())->setEnabled(true);
        $user->setUsername('alice');
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'secured_area', ['ROLE_USER']));
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->with('ROLE_ADMIN')->willReturn($admin);
        $subscriber = new ShiftAccountSubscriber($storage, $authorization, $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class));
        self::assertNotEmpty($subscriber::getSubscribedEvents());
        $request = new Request(attributes: ['_route' => $route, 'username' => $username], server: ['REQUEST_METHOD' => 'POST']);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        if (!$allowed) {
            $this->expectException(AccessDeniedException::class);
        }
        $subscriber->onRequest($event);
        if ($allowed) {
            self::assertNotNull($storage->getToken());
        }
    }

    /** Supplies native/API denial, own-profile, authentication and administrative route cases.
     * @return iterable<string, array{string, ?string, bool, bool}>
     */
    public static function routes(): iterable
    {
        yield 'post-login root' => ['home', null, false, true];
        yield 'private shifts' => ['shift_data', null, false, true];
        yield 'own profile' => ['shift_account_password', 'alice', false, true];
        yield 'foreign profile' => ['user_profile_password', 'bob', false, false];
        yield 'roles self escalation' => ['user_profile_roles', 'alice', false, false];
        yield 'native customers' => ['admin_customer', null, false, false];
        yield 'native API' => ['get_customers', null, false, false];
        yield 'admin users' => ['shift_users', null, true, true];
        yield 'admin native API' => ['get_customers', null, true, false];
        yield 'own onboarding' => ['shift_account', null, false, true];
        yield '2fa' => ['2fa_login', null, false, true];
    }

    /** Native profile/logo dashboard links redirect to the private calendar without running a native dashboard query. */
    public function testDashboardRedirect(): void
    {
        $user = (new User())->setEnabled(true);
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken($user, 'secured_area', ['ROLE_USER']));
        $urls = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $urls->method('generate')->with('shift_calendar')->willReturn('/kimai/hu/shifts');
        $subscriber = new ShiftAccountSubscriber($tokens, $this->createMock(AuthorizationCheckerInterface::class), $urls);
        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), new Request(attributes: ['_route' => 'dashboard']), HttpKernelInterface::MAIN_REQUEST);
        $subscriber->onRequest($event);
        self::assertSame('/kimai/hu/shifts', $event->getResponse()?->headers->get('Location'));
    }

    /** Disabled users lose their token even with an administrative role. */
    public function testDisabledAccount(): void
    {
        $user = (new User())->setEnabled(false);
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'secured_area', ['ROLE_ADMIN']));
        $subscriber = new ShiftAccountSubscriber($storage, $this->createMock(AuthorizationCheckerInterface::class), $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class));
        try {
            $subscriber->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), new Request(attributes: ['_route' => 'shift_data']), HttpKernelInterface::MAIN_REQUEST));
            self::fail('Disabled account was allowed');
        } catch (AccessDeniedException) {
            self::assertNull($storage->getToken());
        }
    }
}
