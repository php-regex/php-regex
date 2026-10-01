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
use PhpRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the lint command writes where: the report alone on stdout, the
 * target, the notes and the status lines on stderr.
 */
final class LintCommandStreamsTest extends TestCase
{
    use TemporaryProject;

    private const PHP_FILE = "<?php\n\npreg_match('/^[a-z]+$/', 'abc');\n";

    private const COMPOSER = '{"name": "acme/app", "require": {"php": "^8.3"}}';

    #[Test]
    public function test_lint_prints_the_target_on_stderr_for_the_console_format(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Target: PHP 8.3, PCRE2 10.42 (composer.json require.php)', $stderr);
        $this->assertStringNotContainsString('Target:', $stdout);
        $this->assertStringContainsString('Scanned 1 files, found 1 patterns', $stdout);
    }

    #[Test]
    public function test_lint_prints_nothing_on_stderr_when_quiet(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, , $stderr] = $this->runLint(['src', '--format=github', '--jobs=1', '--no-redos', '--quiet']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_lint_prints_the_composer_notice_on_stderr(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Note: No composer.json', $stderr);
        $this->assertStringNotContainsString('Target:', $stderr);
        $this->assertIsArray(json_decode($stdout, true));
    }

    #[Test]
    public function test_lint_keeps_status_lines_out_of_a_json_report(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint([
            'src', '--format=json', '--jobs=1', '--no-redos',
            '--output='.$directory.'/build/report.json',
            '--generate-baseline='.$directory.'/baseline.json',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray(json_decode($stdout, true), 'stdout is not JSON: '.$stdout);
        $this->assertStringContainsString('Output also written to: '.$directory.'/build/report.json', $stderr);
        $this->assertStringContainsString('Baseline generated at', $stderr);
        $this->assertIsArray(json_decode((string) file_get_contents($directory.'/build/report.json'), true));
    }

    #[Test]
    public function test_lint_reports_an_output_file_it_cannot_write_on_stderr(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE, 'taken' => '']);

        [, , $unwritableDirectory] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos', '--output='.$directory.'/taken/report.json']);
        [, , $unwritableFile] = $this->runLint(['src', '--format=json', '--jobs=1', '--no-redos', '--output='.$directory.'/src']);

        $this->assertStringContainsString('Could not create directory: '.$directory.'/taken', $unwritableDirectory);
        $this->assertStringContainsString('Could not write to file: '.$directory.'/src', $unwritableFile);
    }

    #[Test]
    public function test_lint_reports_a_collection_failure_as_json(): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'locked/a.php' => self::PHP_FILE]);
        chmod($directory.'/locked', 0o000);

        try {
            [$exitCode, $stdout] = $this->runLint(['locked', '--format=json', '--jobs=1', '--no-redos']);
        } finally {
            chmod($directory.'/locked', 0o700);
        }

        $payload = json_decode($stdout, true);
        $this->assertSame(1, $exitCode);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$stdout);
        $this->assertIsString($payload['error'] ?? null);
        $this->assertStringContainsString('Failed to collect patterns', (string) $payload['error']);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runLint(array $args): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        ob_start();

        try {
            $exitCode = $command->run(
                new Input('lint', $args, new GlobalOptions(false, false, false, true, null, null), []),
                new Output(false, false, errorStream: $stream),
            );
        } finally {
            $stdout = (string) ob_get_clean();
        }

        rewind($stream);

        return [$exitCode, $stdout, (string) stream_get_contents($stream)];
    }
}
