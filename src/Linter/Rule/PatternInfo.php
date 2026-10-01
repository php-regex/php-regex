<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule;

use PhpRegex\Parser\Internal\PatternParser;

/**
 * Immutable facts about the pattern being linted.
 */
final readonly class PatternInfo
{
    public function __construct(
        public string $flags,
        public string $delimiter,
        public string $patternValue,
        public bool $unicodeMode,
    ) {}

    /**
     * The full regex pattern including delimiters and flags.
     */
    public function fullPattern(): string
    {
        return $this->delimiter.$this->patternValue.PatternParser::closingDelimiter($this->delimiter).$this->flags;
    }

    public function hasFlag(string $flag): bool
    {
        return str_contains($this->flags, $flag);
    }
}
