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
 * A server "regex lsp" can run in the test process: it reads nothing and
 * ends the session with the code a test chose.
 */
final class StubLanguageServer
{
    public static int|string $exitCode = 0;

    public function run(): int|string
    {
        return self::$exitCode;
    }
}
