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
final readonly class PatternInfo
{
    public function __construct(
        public string $flags,
        public string $delimiter,
        public string $patternValue,
        public bool $unicodeMode,
        /**
         * The body as written, which node positions point into.
         */
        public string $source = '',
    ) {}

    public function hasFlag(string $flag): bool
    {
        return str_contains($this->flags, $flag);
    }
}
