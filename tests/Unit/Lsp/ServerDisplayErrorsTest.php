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

namespace PHPRegex\Tests\Unit\Lsp;

use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\LanguageServer\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Stdout carries the JSON-RPC frames: a PHP warning printed there would
 * corrupt the stream, so the server sends PHP's own diagnostics to stderr
 * when PHP prints them at all, and leaves them off when they are off.
 */
final class ServerDisplayErrorsTest extends TestCase
{
    /**
     * @return iterable<string, array{setting: string, expected: string}>
     */
    public static function provideSettings(): iterable
    {
        yield 'printed on stdout' => ['setting' => '1', 'expected' => 'stderr'];
        yield 'printed on stdout, by name' => ['setting' => 'stdout', 'expected' => 'stderr'];
        yield 'off' => ['setting' => '0', 'expected' => '0'];
    }

    #[Test]
    #[RunInSeparateProcess]
    #[DataProvider('provideSettings')]
    public function test_php_diagnostics_stay_off_stdout(string $setting, string $expected): void
    {
        $input = fopen('php://memory', 'r+');
        $output = fopen('php://memory', 'r+');
        $this->assertIsResource($input);
        $this->assertIsResource($output);
        Response::writeTo($output);
        ini_set('display_errors', $setting);

        try {
            (new Server(null, $input))->run();
            $actual = ini_get('display_errors');
        } finally {
            Response::writeTo(null);
        }

        $this->assertSame($expected, $actual);
    }
}
