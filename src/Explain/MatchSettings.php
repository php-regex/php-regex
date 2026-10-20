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
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\Bytes;

/**
 * The settings in force where a construct stands, which change what it
 * matches: whether the engine reads the pattern as UTF, whether "^" and "$"
 * are line anchors, whether "$" passes over a final newline, what a newline
 * is, and whether "\d", "\s" and "\w" match by the Unicode tables.
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
        /**
         * Whether "\d", "\s", "\w" and the word boundaries match by the
         * Unicode tables: PHP turns this on with the u flag, and a leading
         * "(*UCP)" does too.
         */
        public readonly bool $ucp = false,
        /**
         * The types an ASCII option of PCRE2 10.43 keeps to ASCII under
         * Unicode properties: "D" for "\d", "S" for "\s", "W" for "\w";
         * "(?a)" sets the three.
         */
        public readonly string $asciiTypes = '',
    ) {}

    /**
     * The settings a pattern starts with, from its flags; its leading
     * options come as the explanation meets them, first in the pattern.
     */
    public static function of(RegexNode $regex): self
    {
        return new self(
            utf: $regex->isUnicode(),
            multiline: str_contains($regex->flags, 'm'),
            dollarEndOnly: str_contains($regex->flags, 'D'),
            ucp: str_contains($regex->flags, 'u'),
        );
    }

    /**
     * The settings once an inline setting, "(?m)" or the "m-s" of
     * "(?m-s:...)", has turned its flags on and off: a letter after "-"
     * turns its flag off, "^" turns m off unless it lists it, "a" keeps the
     * types it names to ASCII, or all three.
     */
    public function with(string $flags): self
    {
        [$on, $off] = explode('-', $flags, 2) + [1 => ''];
        $multiline = match (true) {
            str_contains($off, 'm') => false,
            str_contains($on, 'm') => true,
            default => $this->multiline && !str_starts_with($on, '^'),
        };

        $asciiTypes = str_replace(Bytes::split(self::asciiTypes($off)), '', $this->asciiTypes).self::asciiTypes($on);

        return new self($this->utf, $multiline, $this->dollarEndOnly, $this->newline, $this->ucp, $asciiTypes);
    }

    /**
     * Whether a character type, "d", "s" or "w" or its negation, matches by
     * the Unicode tables where these settings hold.
     */
    public function unicodeType(string $type): bool
    {
        return $this->ucp && !str_contains($this->asciiTypes, Ascii::upper($type));
    }

    /**
     * The settings once a leading option, such as "(*CR)", has set what a
     * newline is.
     */
    public function withOption(string $option): self
    {
        if ('UCP' === $option) {
            return new self($this->utf, $this->multiline, $this->dollarEndOnly, $this->newline, true, $this->asciiTypes);
        }

        $newline = NewlineConvention::tryFrom($option);
        if (null === $newline) {
            return $this;
        }

        return new self($this->utf, $this->multiline, $this->dollarEndOnly, $newline, $this->ucp, $this->asciiTypes);
    }

    /**
     * The types the ASCII options among some modifiers name: "aD" names
     * "\d", a bare "a" all three; "aP" and "aT", for POSIX classes and
     * properties, none of them.
     */
    private static function asciiTypes(string $modifiers): string
    {
        $types = '';
        $length = \strlen($modifiers);
        for ($i = 0; $i < $length; $i++) {
            if ('a' === $modifiers[$i]) {
                $next = $modifiers[$i + 1] ?? '';
                $types .= match ($next) {
                    'D', 'S', 'W' => $next,
                    'P', 'T' => '',
                    default => 'DSW',
                };
            }
        }

        return $types;
    }
}
