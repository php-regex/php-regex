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
 * An atomic group: once its body matched, PCRE never backtracks into it.
 *
 * @internal
 */
final readonly class AtomicHir extends Hir
{
    public function __construct(
        public Hir $body,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct($body->properties->irregular(), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->body];
    }
}
