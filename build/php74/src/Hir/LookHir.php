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
final class LookHir extends Hir
{
    /**
     */
    public Hir $body;
    /**
     */
    public LookKind $kind;
    /**
     */
    public bool $atomic = true;

    public function __construct(
        Hir $body,
        LookKind $kind,
        bool $atomic = true,
        int $startPosition = 0,
        int $endPosition = 0
    ) {
        $this->body = $body;
        $this->kind = $kind;
        $this->atomic = $atomic;
        parent::__construct(Properties::zeroWidth(false, $body->properties->captureCount), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->body];
    }
}
