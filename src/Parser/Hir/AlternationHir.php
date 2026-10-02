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
 * Its branches, tried in order.
 *
 * @internal
 */
final readonly class AlternationHir extends Hir
{
    /**
     * @param non-empty-list<Hir> $branches
     */
    public function __construct(
        public array $branches,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::alternation(array_map(static fn (Hir $branch): Properties => $branch->properties, $branches)), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return $this->branches;
    }
}
