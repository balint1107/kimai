<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Private calendar-only time off; never a billable timesheet. Stored instants use UTC. */
#[ORM\Entity]
#[ORM\Table(name: 'shift_time_off')]
class ShiftTimeOff
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /** Creates time off owned by $owner with UTC $begin/$end (exclusive), $allDay rendering and optional $note. */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $owner,
        #[ORM\Column(name: 'start_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $begin,
        #[ORM\Column(name: 'end_at', type: 'datetime_immutable')]
        public \DateTimeImmutable $end,
        #[ORM\Column(type: 'boolean')]
        public bool $allDay,
        #[ORM\Column(type: 'string', length: 200)]
        public string $note = '',
    ) {
    }
}
