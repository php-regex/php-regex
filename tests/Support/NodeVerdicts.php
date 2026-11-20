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

/**
 * What Node compiles under the v flag, new RegExp(value, "v"), as the HTML
 * pattern attribute compiles it: the record tests/Tools/write_v_flag_verdicts.php
 * writes from the values the tests list.
 */
final class NodeVerdicts
{
    public const RECORD = __DIR__.'/../Fixtures/Transpiler/v-flag-compiles.json';

    /**
     * @var array<array-key, bool>|null
     */
    private static ?array $compiles = null;

    /**
     * Whether Node compiles the value, or null when the record holds none:
     * run tests/Tools/write_v_flag_verdicts.php after adding a value.
     */
    public static function compiles(string $value): ?bool
    {
        if (null === self::$compiles) {
            $record = json_decode((string) file_get_contents(self::RECORD), true, 512, \JSON_THROW_ON_ERROR);
            $compiles = \is_array($record) && \is_array($record['compiles'] ?? null) ? $record['compiles'] : [];
            self::$compiles = array_map(static fn (mixed $verdict): bool => true === $verdict, $compiles);
        }

        return self::$compiles[$value] ?? null;
    }
}
