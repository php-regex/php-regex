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
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the lint command writes where: the report alone on stdout for the
 * machine formats, the target and the notes inside the console banner,
 * and the machine formats' target, notes and status lines on stderr.
 */
final class LintCommandStreamsTest extends TestCase
{
    use TemporaryProject;

    private const PHP_FILE = "<?php\n\npreg_match('/^[a-z]+$/', 'abc');\n";

    private const COMPOSER = '{"name": "acme/app", "require": {"php": "^8.3"}}';

    private const COMPOSER_BELOW_THE_FLOOR = '{"name": "acme/app", "require": {"php": "^7.4"}}';

    /**
     * The width the banner pads every label to: the widest of Runtime,
     * Target, Processes, PCRE JIT, Backtrack and Recursion. None of
     * the projects below ships a regex.dist.json or regex.json, which
     * would add a Configuration row and widen the padding to 13.
     */
    private const BANNER_LABEL_WIDTH = 9;

    #[Test]
    public function test_lint_prints_the_target_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        // Rank 2: the padded Target row right between Runtime and Processes,
        // same grammar as the bridges, padding per the native table. Runtime
        // and Target are parallel compound rows; the running PCRE2 release
        // rides on the Runtime row, not on a row of its own.
        $runningPcre = PcreTarget::runtime()->pcreVersion;
        $this->assertStringContainsString(
            self::bannerRow('Runtime', 'PHP '.\PHP_VERSION.', PCRE2 '.$runningPcre)
            .self::bannerRow('Target', 'PHP 8.3, PCRE2 10.42 (composer.json require.php)')
            .self::bannerRow('Processes', '1'),
            $stdout,
        );
        $this->assertStringNotContainsString("\n".str_pad('PCRE', self::BANNER_LABEL_WIDTH).' : ', $stdout);
        $this->assertStringNotContainsString('Target:', $stderr);
        $this->assertStringContainsString('Scanned 1 files, found 1 patterns', $stdout);
    }

    #[Test]
    public function test_lint_prints_the_notices_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Note: No composer.json', $stdout);
        $this->assertStringNotContainsString('Note:', $stderr);
    }

    #[Test]
    public function test_lint_prints_the_flag_target_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runLint(
            ['src', '--format=console', '--jobs=1', '--no-redos'],
            phpVersion: '8.1',
            pcreVersion: '10.42',
        );

        $this->assertSame(0, $exitCode);
        // Each version named by its own flag: the compound source.
        $this->assertStringContainsString(
            "\n".self::bannerRow('Target', 'PHP 8.1, PCRE2 10.42 (--php-version; --pcre-version)'),
            $stdout,
        );
    }

    #[Test]
    public function test_lint_prints_the_clamped_floor_and_its_notice_in_the_banner_for_the_console_format(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER_BELOW_THE_FLOOR, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            "\n".self::bannerRow('Target', 'PHP 8.2, PCRE2 10.40 (composer.json require.php)'),
            $stdout,
        );
        $this->assertStringContainsString(
            'Note: composer.json require.php allows PHP 7.4, older than the PHP 8.2 this library supports: judging for PHP 8.2.',
            $stdout,
        );
        $this->assertStringNotContainsString('Note:', $stderr);
    }

    #[Test]
    public function test_lint_prints_no_console_banner_when_quiet(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos', '--quiet']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stderr);
        // The banner header: the word PHPRegex alone cannot be asserted
        // absent, the report footer says "If PHPRegex helps" too.
        $this->assertStringNotContainsString('by Younes ENNAJI', $stdout);
    }

    #[Test]
    public function test_lint_prints_no_console_banner_for_the_global_quiet_layer(): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format=console', '--jobs=1', '--no-redos'], globalQuiet: true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertSame('', $stderr);
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
    #[DataProvider('provideMachineFormats')]
    public function test_lint_prints_the_target_and_notes_on_stderr_for_machine_formats(string $format): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runLint(['src', '--format='.$format, '--jobs=1', '--no-redos']);

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/^Target: PHP \d+\.\d+, PCRE2 [\d.]+ \(running PHP\)$/m', $stderr);
        $this->assertStringContainsString('Note: No composer.json', $stderr);
        $this->assertLessThan(
            strpos($stderr, 'Target:'),
            strpos($stderr, 'Note:'),
            'the notes print before the target line',
        );
        $this->assertStringNotContainsString('Target:', $stdout);
        $this->assertStringNotContainsString('Note:', $stdout);
    }

    /**
     * @return iterable<string, array{format: string}>
     */
    public static function provideMachineFormats(): iterable
    {
        yield 'github' => ['format' => 'github'];
        yield 'checkstyle' => ['format' => 'checkstyle'];
        yield 'junit' => ['format' => 'junit'];
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
     * One banner table row, padded the way the renderer pads it: the label
     * padded to the table's widest label, then " : ", then the value, then
     * the row's newline.
     */
    private static function bannerRow(string $label, string $value): string
    {
        return str_pad($label, self::BANNER_LABEL_WIDTH).' : '.$value."\n";
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function runLint(array $args, ?string $phpVersion = null, ?string $pcreVersion = null, bool $globalQuiet = false): array
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
                new Input('lint', $args, new GlobalOptions(false, false, false, true, $phpVersion, null, $pcreVersion), []),
                new Output(false, $globalQuiet, errorStream: $stream),
            );
        } finally {
            $stdout = (string) ob_get_clean();
        }

        rewind($stream);

        return [$exitCode, $stdout, (string) stream_get_contents($stream)];
    }
}
