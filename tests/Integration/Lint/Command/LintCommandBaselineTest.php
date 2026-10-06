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

namespace PHPRegex\Tests\Integration\Lint\Command;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPUnit\Framework\TestCase;

final class LintCommandBaselineTest extends TestCase
{
    /**
     * @var array<int, string>
     */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
    }

    public function test_generate_baseline_creates_file_with_relative_paths(): void
    {
        $dir = $this->makeTempDir();
        $file = realpath($dir).'/test.php';
        copy(__DIR__.'/../../../Fixtures/Lint/unclosed_character_class.php', $file);

        $baselineFile = $dir.'/baseline.json';

        $command = $this->makeLintCommand();
        $output = new Output(true, true);
        $input = $this->makeInput([$file, '--generate-baseline='.$baselineFile]);

        $exitCode = $command->run($input, $output);

        $this->assertSame(1, $exitCode); // Should have errors
        $this->assertFileExists($baselineFile);

        $content = file_get_contents($baselineFile);
        $this->assertIsString($content);
        /** @var array{version: int, issues: array<array{file: string, line: int, message: string, type: string, issueId: string, pattern: string}>} $baseline */
        $baseline = json_decode($content, true);
        $this->assertIsArray($baseline);
        $this->assertSame(1, $baseline['version']);
        $this->assertCount(1, $baseline['issues']);

        $issue = $baseline['issues'][0];
        $this->assertArrayHasKey('file', $issue);
        $this->assertArrayHasKey('line', $issue);
        $this->assertArrayHasKey('message', $issue);
        $this->assertArrayHasKey('type', $issue);
        $this->assertArrayHasKey('issueId', $issue);
        $this->assertIsString($issue['pattern']);

        // File should be relative
        $this->assertStringStartsNotWith('/', $issue['file']);
        $this->assertStringStartsNotWith('\\', $issue['file']);
        $this->assertStringContainsString('test.php', (string) $issue['file']);
    }

    public function test_baseline_filters_out_known_issues(): void
    {
        $dir = $this->makeTempDir();
        $file = realpath($dir).'/test.php';
        copy(__DIR__.'/../../../Fixtures/Lint/unclosed_character_class.php', $file);

        $baselineFile = $dir.'/baseline.json';

        $command = $this->makeLintCommand();

        // Generate baseline
        $output1 = new Output(true, true);
        $input1 = $this->makeInput([$file, '--generate-baseline='.$baselineFile]);
        $command->run($input1, $output1);

        // Run with baseline on the same file
        $output2 = new Output(true, true);
        $input2 = $this->makeInput([$file, '--baseline='.$baselineFile]);

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input2, $output2), $exitCode);

        $this->assertSame(0, $exitCode); // No errors after filtering
        $this->assertStringNotContainsString('Unknown regex flag', $buffer);
    }

    /**
     * A 1.x baseline, a plain list matched on file, line and message, is
     * still read: its issues stay filtered, and the report suggests
     * generating the baseline again.
     */
    public function test_a_1x_list_baseline_still_filters_its_issues(): void
    {
        $dir = $this->makeTempDir();
        $file = realpath($dir).'/test.php';
        copy(__DIR__.'/../../../Fixtures/Lint/unclosed_character_class.php', $file);

        $command = $this->makeLintCommand();
        $generated = $dir.'/generated.json';
        $command->run($this->makeInput([$file, '--generate-baseline='.$generated]), new Output(true, true));

        $current = json_decode((string) file_get_contents($generated), true);
        $this->assertIsArray($current);
        $this->assertIsArray($current['issues']);
        $legacy = [];
        foreach ($current['issues'] as $issue) {
            $this->assertIsArray($issue);
            $legacy[] = ['file' => $issue['file'], 'line' => $issue['line'], 'message' => $issue['message'], 'type' => $issue['type']];
        }
        $this->assertNotSame([], $legacy);
        $legacyFile = $dir.'/legacy.json';
        file_put_contents($legacyFile, json_encode($legacy, \JSON_PRETTY_PRINT));

        // Not quiet: the note goes where the report's status lines go.
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);
        $exitCode = 0;
        $buffer = $this->captureOutput(fn (): int => $command->run($this->makeInput([$file, '--baseline='.$legacyFile]), new Output(false, false, '#', '-', $errors)), $exitCode);
        rewind($errors);
        $buffer .= (string) stream_get_contents($errors);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('Unknown regex flag', $buffer);
        $this->assertMatchesRegularExpression('/regenerat/i', $buffer);
    }

    /**
     * An empty 1.x baseline is a valid baseline that filters nothing.
     */
    public function test_an_empty_1x_list_baseline_is_accepted(): void
    {
        $dir = $this->makeTempDir();
        $file = realpath($dir).'/test.php';
        copy(__DIR__.'/../../../Fixtures/Lint/unclosed_character_class.php', $file);
        $baselineFile = $dir.'/empty.json';
        file_put_contents($baselineFile, '[]');

        $exitCode = 0;
        $this->captureOutput(fn (): int => $this->makeLintCommand()->run($this->makeInput([$file, '--baseline='.$baselineFile]), new Output(true, true)), $exitCode);

        $this->assertSame(1, $exitCode);
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
     * @param array<int, string>   $args
     * @param array<string, mixed> $regexOptions
     */
    private function makeInput(array $args, array $regexOptions = []): Input
    {
        return new Input('lint', $args, new GlobalOptions(false, false, false, true, null, null), $regexOptions);
    }

    private function captureOutput(callable $callable, int &$exitCode): string
    {
        ob_start();
        // @phpstan-ignore-next-line
        $exitCode = $callable();
        $output = ob_get_clean();

        return $output ?: '';
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($dir);
    }

    private function makeTempDir(): string
    {
        $dir = 'temp-baseline-test';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }
}
