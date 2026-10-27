<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** Private ownership of a native customer and its managed project/default rates. */
#[ORM\Entity]
#[ORM\Table(name: 'shift_workplace')]
#[ORM\UniqueConstraint(columns: ['owner_id', 'name_key'])]
class ShiftWorkplace
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    public ?int $id = null;

    /** Binds persisted $customer to $owner using the normalized-name hash $nameKey; native IDs remain stable. */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
        public User $owner,
        #[ORM\OneToOne(targetEntity: Customer::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Customer $customer,
        #[ORM\Column(name: 'name_key', type: 'string', length: 64)]
        public string $nameKey,
    ) {
    }

    /** Returns a case-insensitive Unicode-normalized hash for trimmed workplace $name; accents remain significant. */
    public static function key(string $name): string
    {
        return hash('sha256', mb_strtolower(\Normalizer::normalize(trim($name)) ?: trim($name)));
    }
}
