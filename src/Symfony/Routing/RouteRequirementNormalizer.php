<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Symfony\Routing;

/**
 * Normalizes Symfony route requirements into full regex patterns.
 *
 * @internal
 */
final readonly class RouteRequirementNormalizer
{
    private const PATTERN_DELIMITERS = ['/', '#', '~', '%'];

    public function normalize(string $pattern): string
    {
        $firstChar = $pattern[0] ?? '';

        if (\in_array($firstChar, self::PATTERN_DELIMITERS, true)) {
            return $pattern;
        }

        if (str_starts_with($pattern, '^') && str_ends_with($pattern, '$')) {
            return '#'.$pattern.'#';
        }

        $delimiter = '#';
        $body = str_replace($delimiter, '\\'.$delimiter, $pattern);

        return $delimiter.'^'.$body.'$'.$delimiter;
    }
}
