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

namespace PHPRegex\Parser\Syntax;

use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Bytes;

/**
 * The modifiers a "(?...)" group turns on and off.
 *
 * PCRE spells this three ways in one place: "(?im)" turns two on, "(?im-sx)"
 * turns two on and two off, and "(?^im)" turns two on and the other options
 * of imnrsx off. Reading it is the same work for the lexer, which needs to know when
 * /x starts, for the parser, which builds the group, and for the compiler,
 * which writes it back out — so it is done here once.
 *
 * @internal
 */
final class InlineFlags
{
    /**
     */
    public string $set;
    /**
     */
    public string $unset;
    /**
     * The modifiers PCRE lets a group carry, "r" excepted: it arrived in
     * PCRE2 10.43 and the caller says whether it may be used. "u" and "d"
     * are PHP modifiers only: PCRE refuses them after "(?".
     */
    public const LETTERS = 'imsxUJn';

    /**
     * The options "^" turns off unless it lists them, as PCRE2 resets them
     * (pcre2pattern, "Internal option setting"): "(?^)" leaves ungreedy "U"
     * and duplicate names "J" in force.
     */
    private const RESET_BY_CARET = 'imnrsx';

    private function __construct(string $set, string $unset)
    {
        /**
         * Modifiers the group turns on.
         */
        $this->set = $set;
        /**
         * Modifiers the group turns off, including the ones "^" reset.
         */
        $this->unset = $unset;
    }

    /**
     * Read a modifier string, or null when the text is not one.
     *
     * @param string $letters the modifiers that may appear, which depends on
     *                        the PHP version the pattern is read for
     */
    public static function read(string $text, string $letters = self::LETTERS): ?self
    {
        $resetsOthers = 0 === strncmp($text, '^', strlen('^'));
        if ($resetsOthers) {
            $text = (string) substr($text, 1);
        }

        [$set, $unset] = false !== strpos($text, '-') ? explode('-', $text, 2) : [$text, ''];

        foreach ([$set, $unset] as $part) {
            if ('' !== trim($part, $letters)) {
                return null;
            }
        }

        if ('' === $set && '' === $unset && !$resetsOthers) {
            return null;
        }

        // PCRE reads the letters in order: "(?i-i)" turns "i" on, then off.
        $set = implode('', array_diff(Bytes::split($set), Bytes::split($unset)));

        if ($resetsOthers) {
            // "(?^im)" turns on what it lists and turns off the rest of the
            // options "^" resets; "U" and "J" stay as they are.
            $reset = array_intersect(Bytes::split($letters), Bytes::split(self::RESET_BY_CARET));
            $unset = implode('', array_diff($reset, Bytes::split($set))).$unset;
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
        return LibraryPcre::replace('/a[DSWPT]?/', '', $text) ?? $text;
    }

    /**
     * Whether the group of flags that opens at $start in $source closes
     * right after its letters, as "(?i)" does, setting options for the rest
     * of the group around it, rather than holding a body of its own, as
     * "(?i:...)" does.
     */
    public static function setOptionsOnly(string $source, int $start): bool
    {
        $end = $start + 2 + strspn($source, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ^-', $start + 2);

        return ')' === ($source[$end] ?? '');
    }

    public function turnsOn(string $flag): bool
    {
        return false !== strpos($this->set, $flag);
    }

    public function turnsOff(string $flag): bool
    {
        return false !== strpos($this->unset, $flag);
    }

    /**
     * Whether "(?xx)" is in force inside this group, given whether it was
     * outside it: "xx" turns it on, a single "x" set or any "x" unset turns
     * it off, as PCRE2 does.
     */
    public function extendedMoreInForce(bool $wasInForce): bool
    {
        return substr_count($this->set, 'x') >= 2
            || (!$this->turnsOn('x') && !$this->turnsOff('x') && $wasInForce);
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
        foreach (Bytes::split($this->unset) as $flag) {
            $flags = str_replace($flag, '', $flags);
        }

        foreach (Bytes::split($this->set) as $flag) {
            if ('' !== $flag && false === strpos($flags, $flag)) {
                $flags .= $flag;
            }
        }

        return $flags;
    }
}
