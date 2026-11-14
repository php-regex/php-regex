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
final class AtomicHir extends Hir
{
    /**
     */
    public Hir $body;

    public function __construct(
        Hir $body,
        int $startPosition = 0,
        int $endPosition = 0
    ) {
        $this->body = $body;
        parent::__construct($body->properties->irregular(), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->body];
    }
}
