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

namespace PHPRegex\Cli\Graph;

use PHPRegex\Parser\Hir\CharSet;

/**
 * The label a graph edge carries for a character set: the empty set symbol,
 * the full alphabet symbol, or a bracketed list of ranges.
 *
 * @internal
 */
final class CharSetLabel
{
    private const FIRST_PRINTABLE = 32;

    private const LAST_PRINTABLE = 126;

    /**
     * @param bool $unicode the alphabet the set lives in: the bytes without
     *                      /u, the code points but the surrogates with it
     */
    public static function render(CharSet $set, bool $unicode): string
    {
        if ($set->isEmpty()) {
            return '∅';
        }

        if ($set->ranges === CharSet::universe($unicode)->ranges) {
            return 'Σ';
        }

        $parts = [];
        foreach ($set->ranges as [$start, $end]) {
            if ($start === $end) {
                $parts[] = self::formatChar($start);

                continue;
            }
            $parts[] = self::formatChar($start).'-'.self::formatChar($end);
        }

        return '['.implode('', $parts).']';
    }

    private static function formatChar(int $codePoint): string
    {
        if ($codePoint >= self::FIRST_PRINTABLE && $codePoint <= self::LAST_PRINTABLE) {
            return \chr($codePoint);
        }

        return sprintf('\\x%02X', $codePoint);
    }
}
