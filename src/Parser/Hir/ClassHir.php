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
 * One character of a set: a class, an escape such as "\w", a dot, a Unicode
 * property, or a letter under /i.
 *
 * @internal
 */
final readonly class ClassHir extends Hir
{
    public function __construct(
        public CharSet $set,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::characterClass($set), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [];
    }
}
