<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Durable event identity, three-way merge baseline, review state and retryable outbound operation.
 * @phpstan-import-type Value from \App\ShiftAccounting\CalendarSync\EventMapper
 * @phpstan-import-type GoogleEvent from \App\ShiftAccounting\CalendarSync\EventMapper
 */
#[ORM\Entity]
#[ORM\Table(name: 'shift_calendar_link')]
#[ORM\UniqueConstraint(columns: ['connection_id', 'calendar_id', 'event_id'])]
#[ORM\UniqueConstraint(columns: ['connection_id', 'local_key'])]
class ShiftCalendarLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $localKey = null;
    #[ORM\Column(length: 30)]
    public string $state = 'review';
    /** @var Value|array{} */
    #[ORM\Column(type: 'json')]
    public array $baseline = [];
    /** @var GoogleEvent */
    #[ORM\Column(type: 'json')]
    public array $remote = [];
    /** @var array{delete?:true,value?:Value,reviewed?:true}|null */
    #[ORM\Column(type: 'json', nullable: true)]
    public ?array $pending = null;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $problem = null;
    #[ORM\Column(type: 'integer')]
    public int $attempts = 0;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $retryAt = null;

    /** Identifies $eventId in $calendarId under $connection; local records are linked only after validation. */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ShiftCalendarConnection::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public ShiftCalendarConnection $connection,
        #[ORM\Column(length: 255)]
        public string $calendarId,
        #[ORM\Column(length: 255)]
        public string $eventId,
    ) {
    }
}
