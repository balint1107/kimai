<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use App\Controller\ShiftCalendarController;
use App\ShiftAccounting\CalendarSync\CalendarSync;
use App\ShiftAccounting\CalendarSync\EventMapper;
use App\ShiftAccounting\CalendarSync\GoogleClient;
use App\ShiftAccounting\CalendarSync\LocalCalendar;
use App\ShiftAccounting\ShiftService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Ensures connection setup and every mutation reject missing CSRF before accessing owner data or Google. */
class ShiftCalendarControllerTest extends TestCase
{
    /** Rejects $action without a valid shift CSRF token; persistence must not be reached. */
    #[DataProvider('mutations')]
    public function testCsrfRequired(string $action): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('getRepository');
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn(false);
        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $controller = new ShiftCalendarController($em, (new \ReflectionClass(GoogleClient::class))->newInstanceWithoutConstructor(), (new \ReflectionClass(CalendarSync::class))->newInstanceWithoutConstructor(), (new \ReflectionClass(ShiftService::class))->newInstanceWithoutConstructor(), (new \ReflectionClass(LocalCalendar::class))->newInstanceWithoutConstructor(), new EventMapper(), (new \ReflectionClass(\App\ShiftAccounting\CalendarSync\CalendarProvisioner::class))->newInstanceWithoutConstructor(), new \App\ShiftAccounting\CalendarSync\CalendarOwnership($em));
        $controller->setContainer($container);
        $request = Request::create('/shifts/google/' . $action, 'POST');
        $this->expectException(AccessDeniedException::class);
        if ($action === 'resolve') {
            $controller->resolve($request, 1);
        } else {
            $controller->$action($request);
        }
    }

    /** Lists state-mutating controller actions; OAuth callback has its own expiring state validation.
     * @return iterable<string, array{string}>
     */
    public static function mutations(): iterable
    {
        foreach (['connect', 'settings', 'synchronize', 'resolve'] as $action) {
            yield $action => [$action];
        }
    }
}
