<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Voter;

use App\Entity\ShiftCalendarConnection;
use App\Entity\ShiftImportBatch;
use App\Entity\ShiftTimeOff;
use App\Entity\ShiftWorkplace;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** Prevents native account deletion/transfer from orphaning private accounting or calendar history.
 * @extends Voter<string, User>
 */
final class ShiftUserVoter extends Voter
{
    /** Uses $em to detect retained account-owned data before native deletion starts. */
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** Handles only native user deletion $attribute for User $subject; other operations abstain. */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'delete' && $subject instanceof User;
    }

    /** Returns false when $subject owns private data; native voters still decide whether $token may delete an empty account. */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        foreach ([ShiftWorkplace::class, ShiftTimeOff::class, ShiftImportBatch::class, ShiftCalendarConnection::class] as $entity) {
            if ($this->em->getRepository($entity)->count(['owner' => $subject]) > 0) {
                return false;
            }
        }

        return true;
    }
}
