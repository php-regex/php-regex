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

use PHPRegex\Parser\Node\QuantifierNode;

/**
 * Quantifier classification helpers shared by lint rules.
 *
 * @internal
 */
final class QuantifierMath
{
    private function __construct() {}

    /**
     * @return array{0: int, 1: int|null}
     */
    public static function parseRange(QuantifierNode $node): array
    {
        $bounds = $node->bounds;

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max];
    }

    public static function isVariable(QuantifierNode $node): bool
    {
        [$min, $max] = self::parseRange($node);

        return null === $max || $min !== $max;
    }

    public static function isRepeatable(QuantifierNode $node): bool
    {
        [, $max] = self::parseRange($node);

        return null === $max || $max > 1;
    }

    public static function isUnbounded(QuantifierNode $node): bool
    {
        [, $max] = self::parseRange($node);

        return null === $max;
    }
}
