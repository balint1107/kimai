<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Retains normalized source data and resolution state, even when a shift cannot be created.
 * @phpstan-type SourceRow array{coordinate:string,date:string|null,workplace:string,begin:string,end:string,rate:int|float|null,category:string,flags:list<string>,usable:bool,source_seconds:int,source_amount:float|int,raw:array<string,mixed>}
 */
#[ORM\Entity]
#[ORM\Table(name: 'shift_import_item')]
#[ORM\UniqueConstraint(columns: ['batch_id', 'coordinate'])]
class ShiftImportItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Timesheet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Timesheet $timesheet = null;

    #[ORM\Column(type: 'boolean')]
    public bool $resolved = false;

    /** Creates a source $coordinate within $batch; $payload retains normalized values and warnings.
     * @param SourceRow $payload
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ShiftImportBatch::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public ShiftImportBatch $batch,
        #[ORM\Column(length: 100)]
        public string $coordinate,
        #[ORM\Column(type: 'json')]
        public array $payload,
    ) {
    }
}
