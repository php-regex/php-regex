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

namespace PHPRegex\Tests\Functional\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The analyze command in confirmed mode where ini_set() is disabled: the
 * witness of a proven verdict cannot be replayed under the limits it
 * needs, and the replay is skipped. The proven verdict fails the command
 * as the lint fails on it: with the exit code of a confirmed critical
 * risk, not with success.
 *
 * A disabled function is set per process: each case runs bin/regex in a
 * PHP process of its own.
 *
 * Oracle (PHP 8.4, PCRE2 10.49, JIT off): "/^(a|a)+$/" on "aa" x 8 . "!"
 * fails with PREG_BACKTRACK_LIMIT_ERROR under the default backtrack limit.
 */
final class AnalyzeCommandDisabledIniTest extends TestCase
{
    /**
     * The exit code of a confirmed critical risk: the replay ran and
     * reproduced the attack.
     *
     * @param list<string> $format
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_analyze_confirmed_fails_on_a_replayed_proven_verdict(array $format): void
    {
        [$exitCode, $output] = self::analyze(['/^(a|a)+$/', '--redos-mode=confirmed', ...$format]);

        $this->assertSame(1, $exitCode, $output);
    }

    /**
     * @param list<string> $format
     */
    #[Test]
    #[DataProvider('provideFormats')]
    public function test_analyze_confirmed_fails_on_a_proven_verdict_whose_replay_was_skipped(array $format): void
    {
        [$exitCode, $output] = self::analyze(['/^(a|a)+$/', '--redos-mode=confirmed', ...$format], 'ini_set');

        $this->assertSame(1, $exitCode, $output);
    }

    /**
     * A pattern with no risk still succeeds where the replay cannot run.
     */
    #[Test]
    public function test_analyze_confirmed_succeeds_on_a_safe_pattern_without_ini_set(): void
    {
        [$exitCode, $output] = self::analyze(['/^a+$/', '--redos-mode=confirmed'], 'ini_set');

        $this->assertSame(0, $exitCode, $output);
    }

    /**
     * @return iterable<string, array{format: list<string>}>
     */
    public static function provideFormats(): iterable
    {
        yield 'console' => ['format' => []];
        yield 'json' => ['format' => ['--format=json']];
    }

    /**
     * Runs "regex analyze", with the function disabled when one is named.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private static function analyze(array $args, ?string $disabled = null): array
    {
        $command = [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file='];
        if (null !== $disabled) {
            $command = [...$command, '-d', 'disable_functions='.$disabled];
        }

        $process = proc_open(
            [...$command, \dirname(__DIR__, 3).'/bin/regex', '--no-ansi', 'analyze', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout.$stderr];
    }
}
