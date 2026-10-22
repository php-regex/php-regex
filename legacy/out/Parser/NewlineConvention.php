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

namespace PHPRegex\Parser;

class NewlineConvention
{
    public const Cr = 'CR';
    public const Lf = 'LF';
    public const CrLf = 'CRLF';
    public const Any = 'ANY';
    public const AnyCrLf = 'ANYCRLF';
    public const Nul = 'NUL';

    public static function from($value)
    {
        if (!\in_array($value, self::cases(), true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown value "%s" for %s.', $value, self::class));
        }

        return $value;
    }

    public static function tryFrom($value)
    {
        return \in_array($value, self::cases(), true) ? $value : null;
    }

    public static function cases()
    {
        return \array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
