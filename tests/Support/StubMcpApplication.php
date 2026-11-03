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

final class StubMcpApplication
{
    public static int|string $exitCode = 0;

    /**
     * @var array<string, string>|null the target the command handed over
     */
    public static ?array $target = null;

    /**
     * @param array<string, string> $target
     */
    public function run(array $target = []): int|string
    {
        self::$target = $target;

        return self::$exitCode;
    }
}
