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

namespace PhpRegex\Tests\Support;

final class SymfonyExtractorFunctionOverrides
{
    /**
     * @var array<array<int, string>|false>
     */
    public static array $fileSequence = [];

    public static function reset(): void
    {
        self::$fileSequence = [];
    }

    /**
     * @param array<int, string>|false $lines
     */
    public static function queueFileResult(array|false $lines): void
    {
        self::$fileSequence[] = $lines;
    }

    /**
     * @param 0|1|2|3|4|5|6|7|16|17|18|19|20|21|22|23 $flags
     *
     * @return array<int, string>|false
     */
    public static function file(string $filename, int $flags = 0): array|false
    {
        if ([] !== self::$fileSequence) {
            return array_shift(self::$fileSequence);
        }

        return file($filename, $flags);
    }
}

namespace PhpRegex\Symfony\Extractor;

use PhpRegex\Tests\Support\SymfonyExtractorFunctionOverrides;

/**
 * @param 0|1|2|3|4|5|6|7|16|17|18|19|20|21|22|23 $flags
 *
 * @return array<int, string>|false
 */
function file(string $filename, int $flags = 0): array|false
{
    return SymfonyExtractorFunctionOverrides::file($filename, $flags);
}
