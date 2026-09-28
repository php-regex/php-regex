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

namespace RegexParser\Internal;

/**
 * The modifiers a "(?...)" group turns on and off.
 *
 * PCRE spells this three ways in one place: "(?im)" turns two on, "(?im-sx)"
 * turns two on and two off, and "(?^im)" turns two on and everything else
 * off. Reading it is the same work for the lexer, which needs to know when
 * /x starts, for the parser, which builds the group, and for the compiler,
 * which writes it back out — so it is done here once.
 *
 * @internal
 */
final readonly class InlineFlags
{
    /**
     * The modifiers PCRE lets a group carry, "r" excepted: it arrived in
     * PCRE2 10.43 and the caller says whether it may be used.
     */
    public const LETTERS = 'imsxUJnud';

    private function __construct(
        /**
         * Modifiers the group turns on.
         */
        public string $set,
        /**
         * Modifiers the group turns off, including the ones "^" reset.
         */
        public string $unset,
    ) {}

    /**
     * Read a modifier string, or null when the text is not one.
     *
     * @param string $letters the modifiers that may appear, which depends on
     *                        the PHP version the pattern is read for
     */
    public static function read(string $text, string $letters = self::LETTERS): ?self
    {
        $resetsOthers = str_starts_with($text, '^');
        if ($resetsOthers) {
            $text = substr($text, 1);
        }

        [$set, $unset] = str_contains($text, '-') ? explode('-', $text, 2) : [$text, ''];

        foreach ([$set, $unset] as $part) {
            if ('' !== trim($part, $letters)) {
                return null;
            }
        }

        if ('' === $set && '' === $unset && !$resetsOthers) {
            return null;
        }

        // PCRE reads the letters in order: "(?i-i)" turns "i" on, then off.
        $set = implode('', array_diff(str_split($set), str_split($unset)));

        if ($resetsOthers) {
            // "(?^im)" turns on what it lists and turns off everything else.
            $unset = implode('', array_diff(str_split($letters), str_split($set))).$unset;
        }

        return new self($set, $unset);
    }

    /**
     * A modifier string without the ASCII options PCRE2 10.43 added: "a"
     * alone, or followed by one of "D", "S", "W", "P", "T". They narrow what
     * "\d", "\s", "\w", POSIX classes and "\p" match, and nothing read here
     * depends on them.
     */
    public static function withoutAsciiOptions(string $text): string
    {
        return preg_replace('/a[DSWPT]?/', '', $text) ?? $text;
    }

    public function turnsOn(string $flag): bool
    {
        return str_contains($this->set, $flag);
    }

    public function turnsOff(string $flag): bool
    {
        return str_contains($this->unset, $flag);
    }

    /**
     * Whether $flag is in force inside this group, given what was in force
     * outside it.
     */
    public function inForce(string $flag, bool $wasInForce): bool
    {
        if ($this->turnsOn($flag)) {
            return true;
        }

        return $this->turnsOff($flag) ? false : $wasInForce;
    }

    /**
     * The modifiers in force inside this group, given the ones outside it.
     */
    public function applyTo(string $flags): string
    {
        foreach (str_split($this->unset) as $flag) {
            $flags = str_replace($flag, '', $flags);
        }

        foreach (str_split($this->set) as $flag) {
            if ('' !== $flag && !str_contains($flags, $flag)) {
                $flags .= $flag;
            }
        }

        return $flags;
    }
}
