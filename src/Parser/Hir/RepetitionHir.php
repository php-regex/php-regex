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
 * Its body, repeated between the bounds; a null maximum is unbounded.
 *
 * @internal
 */
final readonly class RepetitionHir extends Hir
{
    public function __construct(
        public Hir $body,
        public int $min,
        public ?int $max,
        public Greed $greed,
        int $startPosition = 0,
        int $endPosition = 0,
    ) {
        parent::__construct(Properties::repetition($body->properties, $min, $max, $greed), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->body];
    }
}
