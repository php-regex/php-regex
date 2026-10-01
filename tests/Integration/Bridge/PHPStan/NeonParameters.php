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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

/**
 * Reads one setting out of the "phpRegex" parameter a PHPStan container
 * resolved, without assuming the keys exist.
 */
final class NeonParameters
{
    public const MISSING = '(missing)';

    public static function read(mixed $parameters, string ...$path): mixed
    {
        $value = $parameters;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return self::MISSING;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
