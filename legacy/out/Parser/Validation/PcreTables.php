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

namespace PHPRegex\Parser\Validation;

/**
 * The tables of PCRE2 that the measures of a compiled pattern read: the
 * bytes each type and POSIX name of a class matches in the tables PCRE2
 * ships, and the characters of more than one other case.
 *
 * ClassLength measures a class with them and CompiledLength the items
 * around it; each kept its own copy, which a fix to one could leave behind.
 * They are written here once.
 *
 * @internal
 */
final class PcreTables
{
    /**
     * The characters below 256 each type and POSIX name matches in the
     * tables PCRE2 ships, when UCP is off, as ranges; a locale may extend
     * them past ASCII. PCRE matches the vertical tab with "\s".
     */
    public const MAP_RANGES = [
        'd' => [[0x30, 0x39]],
        'digit' => [[0x30, 0x39]],
        's' => [[0x09, 0x0D], [0x20, 0x20]],
        'space' => [[0x09, 0x0D], [0x20, 0x20]],
        'w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]],
        'word' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A]],
        'h' => [[0x09, 0x09], [0x20, 0x20], [0xA0, 0xA0]],
        'v' => [[0x0A, 0x0D], [0x85, 0x85]],
        'alpha' => [[0x41, 0x5A], [0x61, 0x7A]],
        'alnum' => [[0x30, 0x39], [0x41, 0x5A], [0x61, 0x7A]],
        'upper' => [[0x41, 0x5A]],
        'lower' => [[0x61, 0x7A]],
        'xdigit' => [[0x30, 0x39], [0x41, 0x46], [0x61, 0x66]],
        'blank' => [[0x09, 0x09], [0x20, 0x20]],
        'cntrl' => [[0x00, 0x1F], [0x7F, 0x7F]],
        'punct' => [[0x21, 0x2F], [0x3A, 0x40], [0x5B, 0x60], [0x7B, 0x7E]],
        'graph' => [[0x21, 0x7E]],
        'print' => [[0x20, 0x7E]],
        'ascii' => [[0x00, 0x7F]],
    ];

    /**
     * The characters of more than one other case, each set with its other
     * cases, the same in the Unicode tables of every release (pcre2_ucd.c,
     * ucd_caseless_sets, but for the Turkish casing ones): under a caseless
     * Unicode option, one of them compiles to a property of its set.
     */
    public const CASELESS_SETS = [
        [0x0053, 0x0073, 0x017F],
        [0x01C4, 0x01C5, 0x01C6],
        [0x01C7, 0x01C8, 0x01C9],
        [0x01CA, 0x01CB, 0x01CC],
        [0x01F1, 0x01F2, 0x01F3],
        [0x0345, 0x0399, 0x03B9, 0x1FBE],
        [0x00B5, 0x039C, 0x03BC],
        [0x03A3, 0x03C2, 0x03C3],
        [0x0392, 0x03B2, 0x03D0],
        [0x0398, 0x03B8, 0x03D1, 0x03F4],
        [0x03A6, 0x03C6, 0x03D5],
        [0x03A0, 0x03C0, 0x03D6],
        [0x039A, 0x03BA, 0x03F0],
        [0x03A1, 0x03C1, 0x03F1],
        [0x0395, 0x03B5, 0x03F5],
        [0x0412, 0x0432, 0x1C80],
        [0x0414, 0x0434, 0x1C81],
        [0x041E, 0x043E, 0x1C82],
        [0x0421, 0x0441, 0x1C83],
        [0x0422, 0x0442, 0x1C84, 0x1C85],
        [0x042A, 0x044A, 0x1C86],
        [0x0462, 0x0463, 0x1C87],
        [0x1E60, 0x1E61, 0x1E9B],
        [0x03A9, 0x03C9, 0x2126],
        [0x004B, 0x006B, 0x212A],
        [0x00C5, 0x00E5, 0x212B],
        [0x1C88, 0xA64A, 0xA64B],
    ];

    /**
     * The set of each character of CASELESS_SETS, once read.
     *
     * @var array<int, list<int>>|null
     */
    private static $caselessSets;

    /**
     * The caseless set of each character that has one, by that character.
     *
     * @return array<int, list<int>>
     */
    public static function caselessSets(): array
    {
        if (null === self::$caselessSets) {
            self::$caselessSets = [];
            foreach (self::CASELESS_SETS as $set) {
                foreach ($set as $codePoint) {
                    self::$caselessSets[$codePoint] = $set;
                }
            }
        }

        return self::$caselessSets;
    }
}
