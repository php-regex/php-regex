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
 * A run of characters matched as written: code points under /u, bytes without it.
 *
 * @internal
 */
final readonly class LiteralHir extends Hir
{
    /**
     * @param list<int> $codePoints
     */
    public function __construct(
        public array $codePoints,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::literal($codePoints), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [];
    }
}
