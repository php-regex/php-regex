<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Lint\Command;

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
        /** @var array<array{file: string, line: int, message: string, type: string, pattern?: string|null}> $baseline */
        $baseline = json_decode($content, true);
        $this->assertIsArray($baseline);
        $this->assertCount(1, $baseline);

        $issue = $baseline[0];
        $this->assertArrayHasKey('file', $issue);
        $this->assertArrayHasKey('line', $issue);
        $this->assertArrayHasKey('message', $issue);
        $this->assertArrayHasKey('type', $issue);

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
