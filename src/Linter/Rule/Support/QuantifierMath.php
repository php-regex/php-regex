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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Parser\Node\QuantifierBounds;

/**
 * Quantifier string classification helpers shared by lint rules.
 *
 * @internal
 */
final class QuantifierMath
{
    private function __construct() {}

    /**
     * @return array{0: int, 1: int|null}
     */
    public static function parseRange(string $quantifier): array
    {
        $bounds = QuantifierBounds::parse($quantifier);

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max];
    }

    public static function isVariable(string $quantifier): bool
    {
        [$min, $max] = self::parseRange($quantifier);

        return null === $max || $min !== $max;
    }

    public static function isRepeatable(string $quantifier): bool
    {
        [, $max] = self::parseRange($quantifier);

        return null === $max || $max > 1;
    }

    public static function isUnbounded(string $quantifier): bool
    {
        [, $max] = self::parseRange($quantifier);

        return null === $max;
    }
}
