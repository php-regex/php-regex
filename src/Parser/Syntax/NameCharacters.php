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
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Text\Ascii;

/**
 * The characters a group name holds under UTF, from PCRE2 10.33: "_", the
 * letters and the decimal digits of the Unicode tables of the release
 * judged, a digit never first. Those tables are Unicode 11 for 10.33, 12.1
 * for 10.34, 13 for 10.35 to 10.38, 14 for 10.39 to 10.42, 15 for 10.43
 * and 10.44, 16 for 10.45 to 10.47, and 17 from 10.48.
 *
 * A character all of them agree on is asked of the running PCRE2; one a
 * later Unicode made a letter or a digit is in LETTERS_SINCE or
 * DIGITS_SINCE, and is one from the release it is listed under on,
 * whatever the running PCRE2 knows.
 *
 * @internal
 */
final class NameCharacters
{
    private const ASCII_WORD = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_';

    /**
     * The code points that became a letter after Unicode 11, by the first
     * release whose tables have them, and the same for the decimal digits:
     * every code point "\p{L}" or "\p{Nd}" matches on one of the pcre2test
     * of 10.33, 10.34, 10.35, 10.39, 10.43, 10.45 and 10.48 but not on
     * another. None of them stopped being one.
     */
    private const LETTERS_SINCE = [
        '10.34' => [
            [0x0E86, 0x0E86], [0x0E89, 0x0E89], [0x0E8C, 0x0E8C],
            [0x0E8E, 0x0E93], [0x0E98, 0x0E98], [0x0EA0, 0x0EA0],
            [0x0EA8, 0x0EA9], [0x0EAC, 0x0EAC], [0x1CF2, 0x1CF3],
            [0x1CFA, 0x1CFA], [0xA7BA, 0xA7BF], [0xA7C2, 0xA7C6],
            [0xAB66, 0xAB67], [0x10FE0, 0x10FF6], [0x1145F, 0x1145F],
            [0x116B8, 0x116B8], [0x119A0, 0x119A7], [0x119AA, 0x119D0],
            [0x119E1, 0x119E1], [0x119E3, 0x119E3], [0x11A84, 0x11A85],
            [0x16F45, 0x16F4A], [0x16FE3, 0x16FE3], [0x187F2, 0x187F7],
            [0x1B150, 0x1B152], [0x1B164, 0x1B167], [0x1E100, 0x1E12C],
            [0x1E137, 0x1E13D], [0x1E14E, 0x1E14E], [0x1E2C0, 0x1E2EB],
            [0x1E94B, 0x1E94B],
        ],
        '10.35' => [
            [0x08BE, 0x08C7], [0x0D04, 0x0D04], [0x31BB, 0x31BF],
            [0x4DB6, 0x4DBF], [0x9FF0, 0x9FFC], [0xA7C7, 0xA7CA],
            [0xA7F5, 0xA7F6], [0xAB68, 0xAB69], [0x10E80, 0x10EA9],
            [0x10EB0, 0x10EB1], [0x10FB0, 0x10FC4], [0x11147, 0x11147],
            [0x11460, 0x11461], [0x11900, 0x11906], [0x11909, 0x11909],
            [0x1190C, 0x11913], [0x11915, 0x11916], [0x11918, 0x1192F],
            [0x1193F, 0x1193F], [0x11941, 0x11941], [0x11FB0, 0x11FB0],
            [0x18AF3, 0x18CD5], [0x18D00, 0x18D08], [0x2A6D7, 0x2A6DD],
            [0x30000, 0x3134A],
        ],
        '10.39' => [
            [0x0870, 0x0887], [0x0889, 0x088E], [0x08B5, 0x08B5],
            [0x08C8, 0x08C9], [0x0C5D, 0x0C5D], [0x0CDD, 0x0CDD],
            [0x170D, 0x170D], [0x171F, 0x171F], [0x1B4C, 0x1B4C],
            [0x2C2F, 0x2C2F], [0x2C5F, 0x2C5F], [0x9FFD, 0x9FFF],
            [0xA7C0, 0xA7C1], [0xA7D0, 0xA7D1], [0xA7D3, 0xA7D3],
            [0xA7D5, 0xA7D9], [0xA7F2, 0xA7F4], [0x10570, 0x1057A],
            [0x1057C, 0x1058A], [0x1058C, 0x10592], [0x10594, 0x10595],
            [0x10597, 0x105A1], [0x105A3, 0x105B1], [0x105B3, 0x105B9],
            [0x105BB, 0x105BC], [0x10780, 0x10785], [0x10787, 0x107B0],
            [0x107B2, 0x107BA], [0x10F70, 0x10F81], [0x11071, 0x11072],
            [0x11075, 0x11075], [0x11740, 0x11746], [0x11AB0, 0x11ABF],
            [0x12F90, 0x12FF0], [0x16A70, 0x16ABE], [0x1AFF0, 0x1AFF3],
            [0x1AFF5, 0x1AFFB], [0x1AFFD, 0x1AFFE], [0x1B11F, 0x1B122],
            [0x1DF00, 0x1DF1E], [0x1E290, 0x1E2AD], [0x1E7E0, 0x1E7E6],
            [0x1E7E8, 0x1E7EB], [0x1E7ED, 0x1E7EE], [0x1E7F0, 0x1E7FE],
            [0x2A6DE, 0x2A6DF], [0x2B735, 0x2B738],
        ],
        '10.43' => [
            [0x1123F, 0x11240], [0x11F02, 0x11F02], [0x11F04, 0x11F10],
            [0x11F12, 0x11F33], [0x1342F, 0x1342F], [0x13441, 0x13446],
            [0x1B132, 0x1B132], [0x1B155, 0x1B155], [0x1DF25, 0x1DF2A],
            [0x1E030, 0x1E06D], [0x1E4D0, 0x1E4EB], [0x2B739, 0x2B739],
            [0x31350, 0x323AF],
        ],
        '10.45' => [
            [0x1C89, 0x1C8A], [0xA7CB, 0xA7CD], [0xA7DA, 0xA7DC],
            [0x105C0, 0x105F3], [0x10D4A, 0x10D65], [0x10D6F, 0x10D85],
            [0x10EC2, 0x10EC4], [0x11380, 0x11389], [0x1138B, 0x1138B],
            [0x1138E, 0x1138E], [0x11390, 0x113B5], [0x113B7, 0x113B7],
            [0x113D1, 0x113D1], [0x113D3, 0x113D3], [0x11BC0, 0x11BE0],
            [0x13460, 0x143FA], [0x16100, 0x1611D], [0x16D40, 0x16D6C],
            [0x18CFF, 0x18CFF], [0x1E5D0, 0x1E5ED], [0x1E5F0, 0x1E5F0],
            [0x2EBF0, 0x2EE5D],
        ],
        '10.48' => [
            [0x088F, 0x088F], [0x0C5C, 0x0C5C], [0x0CDC, 0x0CDC],
            [0xA7CE, 0xA7CF], [0xA7D2, 0xA7D2], [0xA7D4, 0xA7D4],
            [0xA7F1, 0xA7F1], [0x10940, 0x10959], [0x10EC5, 0x10EC7],
            [0x11DB0, 0x11DDB], [0x16EA0, 0x16EB8], [0x16EBB, 0x16ED3],
            [0x16FF2, 0x16FF3], [0x187F8, 0x187FF], [0x18D09, 0x18D1E],
            [0x18D80, 0x18DF2], [0x1E6C0, 0x1E6DE], [0x1E6E0, 0x1E6E2],
            [0x1E6E4, 0x1E6E5], [0x1E6E7, 0x1E6ED], [0x1E6F0, 0x1E6F4],
            [0x1E6FE, 0x1E6FF], [0x2B73A, 0x2B73F], [0x2CEA2, 0x2CEAD],
            [0x323B0, 0x33479],
        ],
    ];

    private const DIGITS_SINCE = [
        '10.34' => [
            [0x1E140, 0x1E149], [0x1E2F0, 0x1E2F9],
        ],
        '10.35' => [
            [0x11950, 0x11959], [0x1FBF0, 0x1FBF9],
        ],
        '10.39' => [
            [0x16AC0, 0x16AC9],
        ],
        '10.43' => [
            [0x11F50, 0x11F59], [0x1E4F0, 0x1E4F9],
        ],
        '10.45' => [
            [0x10D40, 0x10D49], [0x116D0, 0x116E3], [0x11BF0, 0x11BF9],
            [0x16130, 0x16139], [0x16D70, 0x16D79], [0x1CCF0, 0x1CCF9],
            [0x1E5F1, 0x1E5FA],
        ],
        '10.48' => [
            [0x11DE0, 0x11DE9],
        ],
    ];

    /**
     * The regular expression fragments for a character a name holds and for
     * a decimal digit, by release.
     *
     * @var array<string, array{name: string, digit: string}>
     */
    private static array $fragments = [];

    /**
     * The length in bytes of the decimal digit at $at, 0 when none stands
     * there.
     */
    public static function digitAt(string $pattern, int $at, PcreTarget $target): int
    {
        // The tables differ on nothing below U+0870, whose first byte is
        // below 0xE0 in UTF-8.
        $byte = $pattern[$at] ?? '';
        if ('' === $byte || \ord($byte) < 0x80) {
            return Ascii::isDigit($byte) ? 1 : 0;
        }

        $digit = \ord($byte) < 0xE0 ? '\p{Nd}' : self::fragments($target)['digit'];

        return 1 === LibraryPcre::match('/\G'.$digit.'/u', $pattern, $matches, 0, $at) ? \strlen($matches[0]) : 0;
    }

    /**
     * Where the characters a name holds, read from $at, end.
     */
    public static function end(string $pattern, int $at, PcreTarget $target): int
    {
        // The ASCII word characters first, which every table holds.
        $end = $at + strspn($pattern, self::ASCII_WORD, $at);
        if (\ord($pattern[$end] ?? "\0") < 0x80) {
            return $end;
        }

        // The running PCRE2 answers for a run, and the character that stops
        // it, that hold nothing the tables list: nothing from U+0800 on.
        LibraryPcre::match('/\G[_\p{L}\p{Nd}]*+/u', $pattern, $matches, 0, $end);
        $stop = $end + \strlen($matches[0] ?? '');
        if (1 !== LibraryPcre::match('/[\xE0-\xFF]/', substr($pattern, $end, $stop - $end + 1))) {
            return $stop;
        }

        LibraryPcre::match('/\G'.self::fragments($target)['name'].'*+/u', $pattern, $matches, 0, $end);

        return $end + \strlen($matches[0] ?? '');
    }

    /**
     * Whether $name is a whole group name: characters a name holds, a digit
     * not first.
     */
    public static function isName(string $name, PcreTarget $target): bool
    {
        if (\strlen($name) === strspn($name, self::ASCII_WORD)) {
            return '' !== $name && !Ascii::isDigit($name[0]);
        }

        if (1 !== LibraryPcre::match('/[\xE0-\xFF]/', $name)) {
            return 1 === LibraryPcre::match('/^[_\p{L}][_\p{L}\p{Nd}]*+\z/u', $name);
        }

        $fragments = self::fragments($target);

        return 1 === LibraryPcre::match('/^(?!'.$fragments['digit'].')'.$fragments['name'].'++\z/u', $name);
    }

    /**
     * @return array{name: string, digit: string}
     */
    private static function fragments(PcreTarget $target): array
    {
        if (isset(self::$fragments[$target->pcreVersion])) {
            return self::$fragments[$target->pcreVersion];
        }

        $changed = $named = $digits = '';
        foreach ([self::LETTERS_SINCE, self::DIGITS_SINCE] as $index => $table) {
            foreach ($table as $release => $ranges) {
                $known = $target->pcreAtLeast($release);
                foreach ($ranges as [$first, $last]) {
                    $range = \sprintf('\x{%X}-\x{%X}', $first, $last);
                    $changed .= $range;
                    $named .= $known ? $range : '';
                    $digits .= $known && 1 === $index ? $range : '';
                }
            }
        }

        // Outside the tables, the running PCRE2 answers.
        return self::$fragments[$target->pcreVersion] = [
            'name' => '(?:(?!['.$changed.'])[_\p{L}\p{Nd}]'.('' !== $named ? '|['.$named.']' : '').')',
            'digit' => '(?:(?!['.$changed.'])\p{Nd}'.('' !== $digits ? '|['.$digits.']' : '').')',
        ];
    }
}
