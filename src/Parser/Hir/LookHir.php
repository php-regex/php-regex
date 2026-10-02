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
 * A lookaround: its body must match, or must not, ahead of or behind the
 * position, which does not move. A non-atomic one, "(*napla:...)", is not
 * atomic.
 *
 * @internal
 */
final readonly class LookHir extends Hir
{
    public function __construct(
        public Hir $body,
        public LookKind $kind,
        public bool $atomic = true,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::zeroWidth(false, $body->properties->captureCount), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->body];
    }
}
