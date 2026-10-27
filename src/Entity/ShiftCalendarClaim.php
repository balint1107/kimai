<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable exclusive calendar reservation; retained on pause/disconnect to protect historical links. */
#[ORM\Entity]
#[ORM\Table(name: 'shift_calendar_claim')]
class ShiftCalendarClaim
{
    /** Reserves canonical Google $calendarId for $connection; the hash key prevents cross-account collisions. */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        public string $id,
        #[ORM\ManyToOne(targetEntity: ShiftCalendarConnection::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public ShiftCalendarConnection $connection,
        #[ORM\Column(name: 'calendar_id', type: 'text')]
        public string $calendarId,
    ) {
    }
}
