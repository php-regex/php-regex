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

namespace PHPRegex\Tests\Integration\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "regex redos" where ini_set() is disabled (disable_functions): an option
 * that sets a PCRE or PHP setting cannot be honoured, and the command says
 * so, where it stopped on a fatal error. A disabled function is set per
 * process: each case runs in a PHP process of its own.
 */
final class RedosCommandDisabledIniTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSettingOptions')]
    public function test_an_option_that_needs_ini_set_is_refused_without_it(string $option): void
    {
        [$exitCode, $stdout, $stderr] = self::regex(['redos', '/(a+)+$/', $option], 'ini_set');

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('ini_set() is disabled', $stdout.$stderr);
        $this->assertStringNotContainsString('Fatal error', $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{option: string}>
     */
    public static function provideSettingOptions(): iterable
    {
        yield 'jit' => ['option' => '--jit=0'];
        yield 'backtrack limit' => ['option' => '--backtrack-limit=1000'];
        yield 'recursion limit' => ['option' => '--recursion-limit=100'];
        yield 'time limit' => ['option' => '--time-limit=1'];
    }

    #[Test]
    public function test_the_refusal_is_the_json_envelope_under_json(): void
    {
        [$exitCode, $stdout] = self::regex(['redos', '/(a+)+$/', '--backtrack-limit=1000', '--format=json'], 'ini_set');

        $this->assertSame(2, $exitCode);
        $document = json_decode($stdout, true);
        $this->assertIsArray($document);
        $this->assertIsString($document['error'] ?? null);
        $this->assertStringContainsString('ini_set() is disabled', (string) $document['error']);
        $this->assertSame('usage', $document['stage'] ?? null);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function regex(array $args, string $disabled): array
    {
        $command = [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-d', 'disable_functions='.$disabled];
        $process = proc_open([...$command, \dirname(__DIR__, 3).'/bin/regex', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($process)) {
            return [-1, '', 'proc_open failed'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
