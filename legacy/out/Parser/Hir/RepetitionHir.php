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
final class RepetitionHir extends Hir
{
    /**
     * @readonly
     * @var \PHPRegex\Parser\Hir\Hir
     */
    public $body;
    /**
     * @readonly
     * @var int
     */
    public $min;
    /**
     * @readonly
     * @var int|null
     */
    public $max;
    /**
     * @readonly
     * @var \PHPRegex\Parser\Hir\Greed
     */
    public $greed;
    /**
     * @param mixed $greed
     */
    public function __construct(
        Hir $body,
        int $min,
        ?int $max,
        $greed,
        int $startPosition = 0,
        int $endPosition = 0
    ) {
        $this->body = $body;
        $this->min = $min;
        $this->max = $max;
        $this->greed = $greed;
        parent::__construct(Properties::repetition($body->properties, $min, $max, $greed), $startPosition, $endPosition);
    }

    public function children(): array
    {
        return [$this->body];
    }
}
