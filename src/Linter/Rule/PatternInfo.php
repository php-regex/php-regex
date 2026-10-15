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

namespace PHPRegex\Linter\Rule;

/**
 * Immutable facts about the pattern being linted.
 *
 * @internal
 */
final class PatternInfo
{
    public function __construct(
        public readonly string $flags,
        public readonly string $delimiter,
        public readonly string $patternValue,
        public readonly bool $unicodeMode,
        /**
         * The body as written, which node positions point into.
         */
        public readonly string $source = '',
    ) {}

    public function hasFlag(string $flag): bool
    {
        return str_contains($this->flags, $flag);
    }
}
