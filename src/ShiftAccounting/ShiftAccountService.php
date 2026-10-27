<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\ShiftAccounting;

use App\Entity\User;
use App\User\UserService;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Keeps account lifecycle and privilege changes behind native Kimai user validation and password hashing. */
final class ShiftAccountService
{
    /** Uses native $users for persistence/hashing and $authorization for the current session's permissions. */
    public function __construct(private UserService $users, private AuthorizationCheckerInterface $authorization)
    {
    }

    /** Returns a new ordinary account with Hungarian/Budapest defaults and no legacy wizard dependency. */
    public function create(): User
    {
        if (!$this->authorization->isGranted('create_user')) {
            throw new AccessDeniedException();
        }
        $user = $this->users->createNewUser();
        $user->setLanguage('hu');
        $user->setLocale('hu_HU');
        $user->setTimezone('Europe/Budapest');
        $user->setWizardAsSeen('intro');
        $user->setWizardAsSeen('profile');

        return $user;
    }

    /** Saves validated $target for signed-in $actor; optional $administrator/$reset apply only to authorized account administration.
     * Self-disable/self-demotion and changes to protected super-admin roles are rejected before persistence.
     * Returns the saved user; native password changes invalidate other sessions through existing user equality checks.
     */
    public function save(User $actor, User $target, ?bool $administrator = null, ?bool $reset = null): User
    {
        $new = $target->getId() === null;
        if (!$this->authorization->isGranted($new ? 'create_user' : 'edit', $new ? null : $target)) {
            throw new AccessDeniedException();
        }
        if (!$new && $target->getId() === $actor->getId() && !$target->isEnabled()) {
            throw new AccessDeniedException('Cannot disable your own account');
        }
        if ($administrator !== null && $administrator !== $target->isAdmin(false)) {
            if ($target->getId() === $actor->getId() || $target->isSuperAdmin() || !$actor->canAssignRole(User::ROLE_ADMIN) || !$this->authorization->isGranted('roles', $target)) {
                throw new AccessDeniedException('Role change is not allowed');
            }
            $target->setRoles($administrator ? [User::ROLE_USER, User::ROLE_ADMIN] : [User::ROLE_USER]);
        }
        if ($reset !== null) {
            if (!$this->authorization->isGranted('password', $target)) {
                throw new AccessDeniedException();
            }
            $target->setRequiresPasswordReset($reset);
        }

        return $this->users->saveUser($target);
    }
}
