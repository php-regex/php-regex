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

namespace PHPRegex\Explain;

use PHPRegex\Parser\NewlineConvention;
use PHPRegex\Parser\Node\RegexNode;

/**
 * The settings in force where a construct stands, which change what it
 * matches: whether the engine reads the pattern as UTF, whether "^" and "$"
 * are line anchors, whether "$" passes over a final newline, and what a
 * newline is.
 *
 * @internal
 */
final class MatchSettings
{
    public function __construct(
        /**
         * Whether the pattern is read as UTF-8, a character at a time: the u
         * flag, or a leading "(*UTF)".
         */
        public readonly bool $utf = false,
        /**
         * Whether "^" and "$" match at the start and end of every line: the
         * m flag, from the pattern or an inline setting.
         */
        public readonly bool $multiline = false,
        /**
         * Whether "$" matches at the very end of the subject only, not
         * before a final newline: the D flag, which m overrides.
         */
        public readonly bool $dollarEndOnly = false,
        /**
         * The newline convention a leading option sets, "(*CR)" or another;
         * LF, the one PHP builds PCRE2 with, when none does.
         */
        public readonly NewlineConvention $newline = NewlineConvention::Lf,
    ) {}

    /**
     * The settings a pattern starts with, from its flags; its leading
     * options come as the explanation meets them, first in the pattern.
     */
    public static function of(RegexNode $regex): self
    {
        return new self($regex->isUnicode(), str_contains($regex->flags, 'm'), str_contains($regex->flags, 'D'));
    }

    /**
     * The settings once an inline setting, "(?m)" or the "m-s" of
     * "(?m-s:...)", has turned its flags on and off: a letter after "-"
     * turns its flag off, "^" turns m off unless it lists it.
     */
    public function with(string $flags): self
    {
        [$on, $off] = explode('-', $flags, 2) + [1 => ''];
        $multiline = match (true) {
            str_contains($off, 'm') => false,
            str_contains($on, 'm') => true,
            default => $this->multiline && !str_starts_with($on, '^'),
        };

        return new self($this->utf, $multiline, $this->dollarEndOnly, $this->newline);
    }

    /**
     * The settings once a leading option, such as "(*CR)", has set what a
     * newline is.
     */
    public function withOption(string $option): self
    {
        $newline = NewlineConvention::tryFrom($option);
        if (null === $newline) {
            return $this;
        }

        return new self($this->utf, $this->multiline, $this->dollarEndOnly, $newline);
    }
}
