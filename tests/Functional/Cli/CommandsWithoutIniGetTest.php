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
 * The commands that print the PCRE runtime, and the benchmark that saves
 * and restores the engine settings, where ini_get() is disabled
 * (disable_functions): the settings cannot be read, and the command still
 * runs to its end, with the exit code its findings give, in the default
 * console format.
 *
 * A disabled function is set per process: each case runs bin/regex in a
 * PHP process of its own, once with ini_get() and once without.
 */
final class CommandsWithoutIniGetTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regex-without-ini-get-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        file_put_contents($this->directory.'/patterns.php', "<?php\npreg_match('/(a|a)+\$/', \$subject);\n");
    }

    protected function tearDown(): void
    {
        $paths = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($paths as $path) {
            $this->assertInstanceOf(\SplFileInfo::class, $path);
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($this->directory);
    }

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_command_runs_to_its_end_without_ini_get(array $args, string $shown): void
    {
        [$expectedExitCode, $expected] = $this->regex($args);
        $this->assertStringContainsString($shown, $expected, 'The run with ini_get() shows the result.');

        [$exitCode, $output] = $this->regex($args, 'ini_get');

        $this->assertStringNotContainsString('ini_get', $output);
        $this->assertStringNotContainsString('Fatal error', $output);
        $this->assertSame($expectedExitCode, $exitCode, $output);
        $this->assertStringContainsString($shown, $output);
    }

    /**
     * @return iterable<string, array{args: list<string>, shown: string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'lint, console format' => ['args' => ['lint', 'patterns.php'], 'shown' => 'Duplicate alternation branch "a".'];
        yield 'lint, console format, one process' => ['args' => ['lint', 'patterns.php', '--jobs=1'], 'shown' => 'Duplicate alternation branch "a".'];
        yield 'analyze' => ['args' => ['analyze', '/a+/'], 'shown' => "'a' (one or more times)"];
        yield 'debug' => ['args' => ['debug', '/a+/'], 'shown' => 'Heatmap'];
        yield 'redos' => ['args' => ['redos', '/a+/'], 'shown' => 'Final length'];
    }

    /**
     * The lint exits on the error a pattern PCRE refuses is, with ini_get()
     * as without it.
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_lint_without_ini_get_fails_on_an_error(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''), 'Oracle: PCRE refuses the pattern.');
        file_put_contents($this->directory.'/refused.php', "<?php\npreg_match('".$pattern."', \$subject);\n");

        [$exitCode, $output] = $this->regex(['lint', 'refused.php'], 'ini_get');

        $this->assertSame(1, $exitCode, $output);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'unclosed group' => ['pattern' => '/(a/'];
    }

    /**
     * Runs bin/regex in the test directory, with the function disabled when
     * one is named.
     *
     * @param list<string> $args
     *
     * @return array{int, string}
     */
    private function regex(array $args, ?string $disabled = null): array
    {
        $command = [\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file='];
        if (null !== $disabled) {
            $command = [...$command, '-d', 'disable_functions='.$disabled];
        }

        $process = proc_open(
            [...$command, \dirname(__DIR__, 3).'/bin/regex', '--no-ansi', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->directory,
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout.$stderr];
    }
}
