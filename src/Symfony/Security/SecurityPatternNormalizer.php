<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Symfony\Security;

/**
 * Normalizes Symfony security regex patterns into full regex strings.
 *
 * @internal
 */
final readonly class SecurityPatternNormalizer
{
    private const PATTERN_DELIMITERS = ['/', '#', '~', '%'];

    public function normalize(string $pattern): string
    {
        $trimmed = trim($pattern);
        if ('' === $trimmed) {
            return '#.*#';
        }

        $firstChar = $trimmed[0] ?? '';
        if (\in_array($firstChar, self::PATTERN_DELIMITERS, true)) {
            return $trimmed;
        }

        $delimiter = '#';
        $body = str_replace($delimiter, '\\'.$delimiter, $trimmed);

        return $delimiter.$body.$delimiter;
    }
}
