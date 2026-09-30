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

namespace RegexParser\Tests\Functional\Lint;

use PHPUnit\Framework\TestCase;
use RegexParser\Cli\Command\HelpCommand;
use RegexParser\Cli\Command\LintCommand;
use RegexParser\Cli\Command\LintOutputRenderer;
use RegexParser\Cli\GlobalOptions;
use RegexParser\Cli\Input;
use RegexParser\Cli\Output;
use RegexParser\Lint\Command\LintArgumentParser;
use RegexParser\Lint\Command\LintConfigLoader;
use RegexParser\Lint\Command\LintDefaultsBuilder;
use RegexParser\Lint\Command\LintExtractorFactory;

final class LintCommandTest extends TestCase
{
    /**
     * @var resource|null
     */
    private $errorStream;

    public function test_lint_command_reports_invalid_config(): void
    {
        $cwd = getcwd();
        $tempDir = sys_get_temp_dir().'/regex-parser-lint-'.uniqid('', true);
        if (false === @mkdir($tempDir) && !is_dir($tempDir)) {
            $this->markTestSkipped('Unable to create temp directory.');
        }

        copy(__DIR__.'/../../Fixtures/Config/invalid_json.json', $tempDir.'/regex.json');

        try {
            if (false === @chdir($tempDir)) {
                $this->markTestSkipped('Unable to change directory.');
            }

            $command = $this->makeLintCommand();
            $output = $this->makeOutput();

            $exitCode = 0;
            $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput([]), $output), $exitCode);

            $this->assertSame(2, $exitCode);
            $this->assertStringContainsString('Invalid JSON', $buffer);
        } finally {
            if (is_dir($tempDir)) {
                @unlink($tempDir.'/regex.json');
                @rmdir($tempDir);
            }
            if (false !== $cwd) {
                @chdir($cwd);
            }
        }
    }

    public function test_lint_command_invokes_help(): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput(['--help']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage', $buffer);
    }

    public function test_lint_command_rejects_unknown_format(): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput(['--format=bogus', self::fixturePath('simple_text.php')]), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unknown format', $buffer);
    }

    public function test_lint_command_handles_empty_patterns(): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput([
            self::fixturePath('simple_text.php'),
            '--format=console',
            '--no-redos',
            '--no-validate',
            '--no-optimize',
        ]), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No regex patterns found', $buffer);
    }

    public function test_lint_command_outputs_json_report(): void
    {
        $command = $this->makeLintCommand();
        $output = $this->makeOutput();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput([
            self::fixturePath('valid_preg_match.php'),
            '--format=json',
            '--no-redos',
            '--no-validate',
            '--no-optimize',
        ]), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"stats"', $buffer);
        $this->assertStringContainsString('"results"', $buffer);
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
     */
    private static function makeInput(array $args): Input
    {
        return new Input(
            'lint',
            $args,
            new GlobalOptions(false, false, false, true, null, null),
            [],
        );
    }

    private static function fixturePath(string $file): string
    {
        return __DIR__.'/../../Fixtures/Functional/'.$file;
    }

    /**
     * @param callable(): int $callback
     */
    private function captureOutput(callable $callback, int &$exitCode): string
    {
        ob_start();
        $exitCode = $callback();
        $output = (string) ob_get_clean();

        if (\is_resource($this->errorStream)) {
            rewind($this->errorStream);
            $output .= (string) stream_get_contents($this->errorStream);
        }

        return $output;
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
}
