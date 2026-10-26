<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Records one private workbook upload; checksum and owner make repeat uploads idempotent. */
#[ORM\Entity]
#[ORM\Table(name: 'shift_import_batch')]
#[ORM\UniqueConstraint(columns: ['owner_id', 'checksum'])]
class ShiftImportBatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /** Creates an upload owned by $owner, identified by its SHA-256 $checksum and safe $filename. */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public User $owner,
        #[ORM\Column(length: 64)]
        public string $checksum,
        #[ORM\Column(length: 255)]
        public string $filename,
        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $createdAt,
        #[ORM\Column(type: 'boolean')]
        public bool $imported = false,
    ) {
    }
}
