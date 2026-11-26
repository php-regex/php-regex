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
 * named once, in the order first made, RewriteKind::Digits and its message
 * "Wrote [0-9] as \d." for "/[0-9]+/" into "/\d+/", and whether the
 * automata checked the rewrite. A pattern left as written has none.
 */
final class OptimizationResult implements \JsonSerializable
{
    /**
     * The message of each kind of rewrite made, in the order of $kinds.
     *
     * @var array<string>
     */
    public readonly array $changes;

    /**
     * @internal built by Optimizer::optimize(), Regex::optimize() and Regex::analyze()
     *
     * @param array<string>     $changes the messages of the rewrites made, when no kind is given
     * @param list<RewriteKind> $kinds   the kinds of the rewrites made, each once, in the order first made; $changes holds their messages
     * @param bool              $proven  whether the automata checked that the rewrite matches what the pattern matches
     */
    public function __construct(
        public readonly string $original,
        public readonly string $optimized,
        array $changes = [],
        public readonly array $kinds = [],
        public readonly bool $proven = false,
    ) {
        $this->changes = [] === $kinds ? $changes : array_map(static fn (RewriteKind $kind): string => $kind->message(), $kinds);
    }

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
