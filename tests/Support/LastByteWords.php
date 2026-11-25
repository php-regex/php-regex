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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\Engine\CharacterTables;

/**
 * The word characters of two locales, given with C's cases under names no
 * machine sets, so that a row runs on every machine: those of
 * fr_FR.ISO8859-1, where "\w" takes the byte 0xFF, and those of
 * el_GR.ISO8859-7, where it takes 0xFE and leaves 0xFF out.
 */
final class LastByteWords
{
    /**
     * The word characters of fr_FR.ISO8859-1, 0xFF among them.
     */
    public const LATIN1 = 'xx_XX.LASTBYTEINWORDS';

    /**
     * The word characters of el_GR.ISO8859-7, 0xFE among them but not 0xFF.
     */
    public const GREEK = 'xx_XX.LASTBYTEOUTOFWORDS';

    private function __construct() {}

    public static function give(): void
    {
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr(($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A) ? $byte ^ 0x20 : $byte);
        }

        $ascii = [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA]];
        CharacterTables::useTables(self::LATIN1, $cases, [], null, ['w' => [...$ascii, [0xBA, 0xBA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]]]);
        CharacterTables::useTables(self::GREEK, $cases, [], null, ['w' => [...$ascii, [0xB6, 0xB6], [0xB8, 0xBA], [0xBC, 0xBC], [0xBE, 0xD1], [0xD3, 0xFE]]]);
    }
}
