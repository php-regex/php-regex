<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Parser\Hir;

/**
 * Its parts, one after the other.
 *
 * @internal
 */
final readonly class ConcatHir extends Hir
{
    /**
     * @param list<Hir> $parts
     */
    public function __construct(
        public array $parts,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::concat(array_map(static fn (Hir $part): Properties => $part->properties, $parts)), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return $this->parts;
    }
}
