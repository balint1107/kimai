<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Owner-scoped Google authorization and sync policy; secrets are authenticated ciphertext. */
#[ORM\Entity]
#[ORM\Table(name: 'shift_calendar_connection')]
#[ORM\UniqueConstraint(columns: ['owner_id'])]
class ShiftCalendarConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $credentials = null;
    /** @var array{calendars:list<string>,destination:string,aliases:array<string,string>,from:string,to:string,cursors:array<string,string>,masters?:array<string,array<string,bool>>,previewed?:bool} */
    #[ORM\Column(type: 'json')]
    public array $settings;
    #[ORM\Column(type: 'boolean')]
    public bool $enabled = false;
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    public ?\DateTimeImmutable $lastSuccess = null;
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $error = null;

    /** Creates a paused connection for $owner; explicit preview approval is needed before writes. */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $owner,
    ) {
        $this->settings = ['calendars' => [], 'destination' => '', 'aliases' => [], 'from' => (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Budapest')))->format('Y-m-d'), 'to' => (new \DateTimeImmutable('last day of December next year'))->format('Y-m-d'), 'cursors' => []];
    }
}
