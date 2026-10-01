<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Optimizer;

/**
 * Captures optimization output with a simple change log.
 */
final readonly class OptimizationResult
{
    /**
     * @param array<string> $changes
     */
    public function __construct(
        public string $original,
        public string $optimized,
        public array $changes = [],
    ) {}

    public function isChanged(): bool
    {
        return $this->original !== $this->optimized;
    }
}
