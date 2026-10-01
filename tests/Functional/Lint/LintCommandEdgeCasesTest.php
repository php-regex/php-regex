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

namespace PhpRegex\Tests\Functional\Lint;

use PhpRegex\Cli\Command\HelpCommand;
use PhpRegex\Cli\Command\LintCommand;
use PhpRegex\Cli\Command\LintOutputRenderer;
use PhpRegex\Cli\GlobalOptions;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Linter\Config\LintArgumentParser;
use PhpRegex\Linter\Config\LintConfigLoader;
use PhpRegex\Linter\Config\LintDefaultsBuilder;
use PhpRegex\Linter\Config\LintExtractorFactory;
use PhpRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LintCommandEdgeCasesTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempDirs = [];

    /**
     * @var resource|null
     */
    private $errorStream;

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();

        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
    }

    public function test_lint_command_reports_argument_parse_error(): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput(['--format']), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing value for --format', $buffer);
        $this->assertStringContainsString('Usage: regex lint', $buffer);
    }

    public function test_lint_command_defaults_paths_when_empty(): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput(['--format=bogus']), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unknown format', $buffer);
    }

    public function test_lint_command_returns_error_for_invalid_regex_options(): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput(['--format=console'], 'eight'), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Invalid option', $buffer);
    }

    #[DataProvider('verbosityFlagProvider')]
    public function test_lint_command_builds_output_config_for_verbosity(string $flag): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $this->captureOutput(fn (): int => $command->run($this->makeInput([$flag, '--format=bogus']), $output), $exitCode);

        $this->assertSame(2, $exitCode);
    }

    /**
     * @return \Iterator<int, array{string}>
     */
    public static function verbosityFlagProvider(): \Iterator
    {
        yield ['--quiet'];
        yield ['--verbose'];
        yield ['--debug'];
    }

    public function test_lint_command_outputs_empty_json_report_when_no_patterns(): void
    {
        $dir = $this->makeTempDir();

        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput([
            $dir,
            '--format=json',
            '--no-redos',
            '--no-validate',
            '--no-optimize',
        ]), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"stats"', $buffer);
    }

    public function test_lint_command_progress_handles_empty_collection(): void
    {
        $dir = $this->makeTempDir();

        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput([
            $dir,
            '--format=console',
            '--no-redos',
            '--no-validate',
            '--no-optimize',
        ]), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No regex patterns found', $buffer);
    }

    public function test_lint_command_reports_collection_and_analysis_times(): void
    {
        $dir = $this->makeTempDir();
        copy(__DIR__.'/../../Fixtures/Lint/coverage_sample.php', $dir.'/sample.php');

        LintFunctionOverrides::queueMicrotime(0.0);
        LintFunctionOverrides::queueMicrotime(2.5);
        LintFunctionOverrides::queueMicrotime(3.0);
        LintFunctionOverrides::queueMicrotime(3.3);
        LintFunctionOverrides::queueMicrotime(4.5);

        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput([
            $dir,
            '--format=console',
            '--no-redos',
            '--no-validate',
            '--no-optimize',
            '--jobs=2',
        ]), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Scanned 1 files, found 1 patterns', $buffer);
    }

    public function test_lint_command_reports_collection_failure(): void
    {
        $dir = $this->makeTempDir();
        @chmod($dir, 0o000);

        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = '';

        try {
            $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput([
                $dir,
                '--format=console',
                '--no-redos',
                '--no-validate',
                '--no-optimize',
            ]), $output), $exitCode);
        } finally {
            @chmod($dir, 0o777);
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to collect patterns', $buffer);
    }

    private function makeLintCommand(): LintCommand
    {
        $helpCommand = new HelpCommand();

        return new LintCommand(
            $helpCommand,
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
    }

    /**
     * @param array<int, string> $args
     * @param string|null        $phpVersion the --php-version option
     */
    private function makeInput(array $args, ?string $phpVersion = null): Input
    {
        return new Input(
            'lint',
            $args,
            new GlobalOptions(false, false, false, true, $phpVersion, null),
            null === $phpVersion ? [] : ['php_version' => $phpVersion],
        );
    }

    /**
     * An output whose error stream the test reads back with stdout.
     */
    private function makeOutput(): Output
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $this->errorStream = $stream;

        return new Output(false, false, errorStream: $stream);
    }

    /**
     * @param callable(): int $callback
     */
    private function captureOutput(callable $callback, int &$exitCode): string
    {
        $level = ob_get_level();
        ob_start();
        $exitCode = $callback();

        $output = (string) ob_get_clean();

        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        if (\is_resource($this->errorStream)) {
            rewind($this->errorStream);
            $output .= (string) stream_get_contents($this->errorStream);
        }

        return $output;
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir().'/regex-parser-lint-'.bin2hex(random_bytes(4));
        @mkdir($dir, 0o777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }
}
