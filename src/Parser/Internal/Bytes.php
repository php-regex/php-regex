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

namespace PHPRegex\Parser\Internal;

/**
 * A string cut into bytes the same way on every PHP: str_split() gives [""]
 * for the empty string on PHP 8.1, and [] from PHP 8.2.
 *
 * @internal
 */
final class Bytes
{
    /**
     * @param positive-int $length
     *
     * @return list<string>
     */
    public static function split(string $text, int $length = 1): array
    {
        return '' === $text ? [] : str_split($text, $length);
    }
}
