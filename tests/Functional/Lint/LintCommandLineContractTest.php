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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the lint command makes of its command line before it reads a file:
 * a --format value in any case, and the paths and files it is given,
 * checked before the run starts.
 */
final class LintCommandLineContractTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    private const FILES = ['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"];

    /**
     * @param list<string> $format
     */
    #[Test]
    #[DataProvider('provideFormatsInAnyCase')]
    public function test_format_value_is_case_insensitive(array $format): void
    {
        $this->enterProject(self::FILES);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--jobs=1', ...$format]);

        $this->assertSame(0, $exitCode, $stdout);
        JsonContract::assertShape('lint', JsonContract::decodeDocument($stdout));
    }

    /**
     * @return iterable<string, array{format: list<string>}>
     */
    public static function provideFormatsInAnyCase(): iterable
    {
        yield '--format=JSON' => ['format' => ['--format=JSON']];
        yield '--format Json' => ['format' => ['--format', 'Json']];
    }

    #[Test]
    public function test_format_on_the_command_line_overrides_the_configuration_in_any_case(): void
    {
        $this->enterProject(self::FILES + ['regex.json' => '{"format": "json", "paths": ["src"]}']);

        [$exitCode, $stdout] = $this->runRegex(['lint', '--jobs=1', '--format=GitHub']);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertNull(json_decode($stdout, true), $stdout);
    }

    /**
     * A command line that names a file the run cannot use stops before the
     * run, in text on stderr, exit code 2: stdout holds no report.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideUnusableFiles')]
    public function test_unusable_file_is_a_usage_error_in_console_mode(array $arguments, string $named): void
    {
        $this->enterProject(self::FILES);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', ...$arguments, '--jobs=1']);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Error: ', $stderr);
        $this->assertStringContainsString($named, $stderr);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, named: string}>
     */
    public static function provideUnusableFiles(): iterable
    {
        yield 'a path that does not exist' => ['arguments' => ['src', 'missing'], 'named' => 'missing'];
        yield 'a baseline that cannot be read' => ['arguments' => ['src', '--baseline=src'], 'named' => 'src'];
        yield 'a baseline to generate in a missing directory' => ['arguments' => ['src', '--generate-baseline=missing/baseline.json'], 'named' => 'missing/baseline.json'];
        yield 'a baseline to generate over a directory' => ['arguments' => ['src', '--generate-baseline=src'], 'named' => 'src'];
    }

    /**
     * A baseline that does not exist is a usage error, as one that cannot
     * be read is: a mistyped name must not let every baselined issue through.
     * The first run of a project writes one with --generate-baseline.
     */
    #[Test]
    public function test_missing_baseline_is_a_usage_error(): void
    {
        $this->enterProject(self::FILES);

        [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--jobs=1', '--json', '--baseline=none.json']);

        $this->assertSame(2, $exitCode, $stdout);
        JsonContract::assertShape('error', JsonContract::decodeDocument($stdout));
        $this->assertSame(['error' => 'Baseline file not found: none.json', 'stage' => 'usage'], JsonContract::decodeDocument($stdout));
    }
}
