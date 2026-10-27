<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Voter;

use App\Entity\User;
use App\Voter\ShiftUserVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Protects retained private accounting before native user deletion or reassignment can mutate records. */
class ShiftUserVoterTest extends TestCase
{
    /** Populated accounts cannot be deleted even by an administrator; unrelated attributes abstain. */
    public function testRetainedData(): void
    {
        $user = new User();
        $token = new UsernamePasswordToken($user, 'secured_area', ['ROLE_SUPER_ADMIN']);
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(1);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $voter = new ShiftUserVoter($em);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $user, ['delete']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $user, ['edit']));
    }

    /** Empty accounts remain deletable subject to native role and self-deletion checks. */
    public function testEmptyAccount(): void
    {
        $user = new User();
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('count')->willReturn(0);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $voter = new ShiftUserVoter($em);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new UsernamePasswordToken($user, 'secured_area', ['ROLE_SUPER_ADMIN']), $user, ['delete']));
    }
}
