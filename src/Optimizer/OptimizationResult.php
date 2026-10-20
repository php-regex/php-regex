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

namespace PHPRegex\Optimizer;

/**
 * A pattern and its rewrite, with the kinds of rewrite that made it: each
 * named once, in the order first made, "Wrote [0-9] as \d." for "/[0-9]+/"
 * into "/\d+/". A pattern left as written has none.
 */
final class OptimizationResult implements \JsonSerializable
{
    /**
     * @internal built by Optimizer::optimize(), Regex::optimize() and Regex::analyze()
     *
     * @param array<string> $changes
     */
    public function __construct(
        public readonly string $original,
        public readonly string $optimized,
        public readonly array $changes = [],
    ) {}

    public function isChanged(): bool
    {
        return $this->original !== $this->optimized;
    }

    /**
     * @return array{original: string, optimized: string, changes: array<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'original' => $this->original,
            'optimized' => $this->optimized,
            'changes' => $this->changes,
        ];
    }
}
