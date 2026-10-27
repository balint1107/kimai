<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\ShiftAccounting;

use App\Entity\User;
use App\ShiftAccounting\ShiftAccountService;
use App\User\UserService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Verifies account policy before native persistence and password hashing. */
class ShiftAccountServiceTest extends TestCase
{
    /** Native defaults are narrowed to the Hungarian application without legacy onboarding. */
    public function testCreate(): void
    {
        $native = $this->createMock(UserService::class);
        $native->method('createNewUser')->willReturn(new User());
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $user = (new ShiftAccountService($native, $authorization))->create();
        self::assertSame('hu', $user->getLanguage());
        self::assertSame('Europe/Budapest', $user->getTimezone());
        self::assertTrue($user->hasSeenWizard('intro'));
        self::assertTrue($user->hasSeenWizard('profile'));
    }

    /** Missing create permission cannot reach native account creation. */
    public function testUnauthorizedCreate(): void
    {
        $native = $this->createMock(UserService::class);
        $native->expects(self::never())->method('createNewUser');
        $this->expectException(AccessDeniedException::class);
        (new ShiftAccountService($native, $this->createMock(AuthorizationCheckerInterface::class)))->create();
    }

    /** An unauthorized update never hashes passwords or persists mutated data. */
    public function testUnauthorizedSave(): void
    {
        $native = $this->createMock(UserService::class);
        $native->expects(self::never())->method('saveUser');
        $this->expectException(AccessDeniedException::class);
        (new ShiftAccountService($native, $this->createMock(AuthorizationCheckerInterface::class)))->save(new User(), new User());
    }

    /** A protected super-admin is displayed/treated as an administrator without replacing its inherited role. */
    public function testProtectedSuperAdmin(): void
    {
        $user = new User();
        $user->setRoles([User::ROLE_SUPER_ADMIN]);
        $native = $this->createMock(UserService::class);
        $native->expects(self::once())->method('saveUser')->with($user)->willReturn($user);
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $saved = (new ShiftAccountService($native, $authorization))->save(new User(), $user, true);
        self::assertTrue($saved->isAdmin(false));
        self::assertTrue($saved->isSuperAdmin());
    }

    /** Granting administrators requires an actor that can assign that role, even if edit permissions are broad. */
    public function testCannotEscalateRole(): void
    {
        $native = $this->createMock(UserService::class);
        $native->expects(self::never())->method('saveUser');
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $actor = new User();
        $actor->setRoles([User::ROLE_ADMIN]);
        $this->expectException(AccessDeniedException::class);
        (new ShiftAccountService($native, $authorization))->save($actor, new User(), true);
    }
}
