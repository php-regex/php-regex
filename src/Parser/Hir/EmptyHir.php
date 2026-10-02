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
 * Matches the empty string: an empty group or branch, an option setting, a comment.
 *
 * @internal
 */
final readonly class EmptyHir extends Hir
{
    public function __construct(
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::empty(), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [];
    }
}
