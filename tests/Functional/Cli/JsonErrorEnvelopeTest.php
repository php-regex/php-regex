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

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Once a command line asks for JSON (--format=json, --format json or
 * --json, anywhere on it), a command line the command cannot use is
 * reported as the error envelope on stdout, stage "usage", with the exit
 * code it always had: 2. --quiet silences status lines, never the document.
 */
final class JsonErrorEnvelopeTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    private const PATTERN_COMMANDS = ['analyze', 'debug', 'redos', 'transpile'];

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideUsageErrors')]
    public function test_usage_error_in_json_mode_prints_the_envelope(array $arguments): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);

        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(2, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $keys = array_keys($document);
        sort($keys);
        $this->assertSame(['error', 'stage'], $keys, $stdout);
        $this->assertSame('usage', $document['stage']);
        $this->assertIsString($document['error']);
        $this->assertNotSame('', $document['error']);
    }

    /**
     * @return iterable<string, array{arguments: list<string>}>
     */
    public static function provideUsageErrors(): iterable
    {
        foreach (self::PATTERN_COMMANDS as $command) {
            yield $command.', unknown option, --format=json before it' => ['arguments' => [$command, '--format=json', '/a/', '--bogus']];
            yield $command.', unknown option, --json last' => ['arguments' => [$command, '/a/', '--bogus', '--json']];
            yield $command.', unknown option, --format json last' => ['arguments' => [$command, '/a/', '--bogus', '--format', 'json']];
            yield $command.', no pattern, --json' => ['arguments' => [$command, '--json']];
            yield $command.', invalid --php-version, --json last' => ['arguments' => ['--php-version=banana', $command, '/a/', '--json']];
            yield $command.', global option without its value, --json before it' => ['arguments' => [$command, '/a/', '--json', '--php-version']];
            yield $command.', quiet, unknown option, --json last' => ['arguments' => ['--quiet', $command, '/a/', '--bogus', '--json']];
        }

        yield 'analyze, unknown --redos-mode, --json last' => ['arguments' => ['analyze', '/a/', '--redos-mode=bogus', '--json']];
        yield 'analyze, removed --redos-no-jit, --json last' => ['arguments' => ['analyze', '/a/', '--redos-no-jit', '--json']];
        yield 'debug, --input without its value, --format=json before it' => ['arguments' => ['debug', '/a/', '--format=json', '--input']];
        yield 'redos, invalid --iterations, --json last' => ['arguments' => ['redos', '/a/', '--iterations=0', '--json']];
        yield 'redos, invalid --jit, --json last' => ['arguments' => ['redos', '/a/', '--jit=2', '--json']];
        yield 'redos, --input-file that does not exist, --json last' => ['arguments' => ['redos', '/a/', '--input-file=missing.txt', '--json']];
        yield 'redos, --input-file that is a directory, --format=json' => ['arguments' => ['redos', '/a/', '--input-file=src', '--format=json']];
        yield 'transpile, unknown --target, --json last' => ['arguments' => ['transpile', '/a/', '--target=perl', '--json']];

        yield 'lint, unknown option, --format=json before it' => ['arguments' => ['lint', '--format=json', '--bogus']];
        yield 'lint, unknown option, --json last' => ['arguments' => ['lint', '--bogus', '--json']];
        yield 'lint, unknown option, --format json last' => ['arguments' => ['lint', '--bogus', '--format', 'json']];
        yield 'lint, invalid --php-version, --format=json' => ['arguments' => ['--php-version=banana', 'lint', 'src', '--jobs=1', '--format=json']];
        yield 'lint, invalid --php-version, --json last' => ['arguments' => ['--php-version=banana', 'lint', 'src', '--jobs=1', '--json']];
        yield 'lint, global option without its value, --format=json before it' => ['arguments' => ['lint', 'src', '--jobs=1', '--format=json', '--php-version']];
        yield 'lint, quiet, unknown option, --json last' => ['arguments' => ['--quiet', 'lint', '--bogus', '--json']];
        yield 'lint, --format=JSON in capitals, unknown option' => ['arguments' => ['lint', '--format=JSON', '--bogus']];
        // A directory is no baseline file it can read, whoever runs it.
        yield 'lint, --baseline not readable, --format=json' => ['arguments' => ['lint', 'src', '--jobs=1', '--format=json', '--baseline=src']];
        yield 'lint, --generate-baseline in a missing directory, --format=json' => ['arguments' => ['lint', 'src', '--jobs=1', '--format=json', '--generate-baseline=missing/dir/baseline.json']];
        yield 'lint, path argument that does not exist, --json' => ['arguments' => ['lint', 'missing', '--jobs=1', '--json']];

        yield 'unknown command, --json last' => ['arguments' => ['lnt', 'src', '--json']];
        yield 'unknown command, --format json last' => ['arguments' => ['lnt', 'src', '--format', 'json']];
        yield '--json before the command' => ['arguments' => ['--json', 'analyze', '/a/']];
        yield '--format=json before the command' => ['arguments' => ['--format=json', 'lint', 'src']];
        yield '--json alone' => ['arguments' => ['--json']];
        yield 'global option without its value before any command, --json' => ['arguments' => ['--php-version', '--json']];
    }

    /**
     * The command line's own pre-scan holds for the commands that have a
     * JSON mode only: a command without one reports a global-option error
     * in text, whatever the command line holds.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideTextUsageErrors')]
    public function test_usage_error_stays_text_when_json_does_not_apply(array $arguments): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex($arguments);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertNull(json_decode($stdout, true), $stdout);
        $this->assertStringContainsString('Error: ', $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{arguments: list<string>}>
     */
    public static function provideTextUsageErrors(): iterable
    {
        yield 'validate has no JSON mode, global option without its value' => ['arguments' => ['validate', '/a/', '--json', '--php-version']];
        yield 'highlight has no JSON mode, global option without its value' => ['arguments' => ['/a/', '--json', '--php-version']];
        yield 'the last --format wins, console after json' => ['arguments' => ['analyze', '--format=json', '--format=console']];
        yield 'the last --format wins, --format console after --json' => ['arguments' => ['debug', '--json', '--format', 'console']];
        yield 'the last --format wins, global error, console after json' => ['arguments' => ['analyze', '/a/', '--json', '--format=console', '--php-version']];
        foreach (['validate', 'parse', 'explain', 'diagram', 'graph', 'highlight'] as $command) {
            yield $command.' has no JSON mode, no pattern' => ['arguments' => [$command]];
        }
    }

    /**
     * The envelope names what the command line got wrong, not only that it
     * is wrong.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideNamedUsageErrors')]
    public function test_usage_error_envelope_names_the_problem(array $arguments, string $error): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);

        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame(['error' => $error, 'stage' => 'usage'], JsonContract::decodeDocument($stdout));
    }

    /**
     * @return iterable<string, array{arguments: list<string>, error: string}>
     */
    public static function provideNamedUsageErrors(): iterable
    {
        yield 'unknown command' => ['arguments' => ['lnt', 'src', '--json'], 'error' => 'Unknown command: lnt.'];
        yield 'an option before the command' => ['arguments' => ['--json', 'analyze', '/a/'], 'error' => 'Unknown command: --json. Options go after the command name.'];
        yield 'lint, path argument that does not exist' => ['arguments' => ['lint', 'missing', '--jobs=1', '--json'], 'error' => 'Path not found: missing'];
        yield 'lint, --baseline not readable' => ['arguments' => ['lint', 'src', '--jobs=1', '--json', '--baseline=src'], 'error' => 'Baseline file not readable: src'];
        yield 'lint, --generate-baseline in a missing directory' => ['arguments' => ['lint', 'src', '--jobs=1', '--json', '--generate-baseline=missing/dir/baseline.json'], 'error' => 'Baseline file not writable: missing/dir/baseline.json'];
        yield 'lint, --generate-baseline naming a directory' => ['arguments' => ['lint', 'src', '--jobs=1', '--json', '--generate-baseline=src'], 'error' => 'Baseline file not writable: src'];
        yield 'redos, --input-file that does not exist' => ['arguments' => ['redos', '/a/', '--input-file=missing.txt', '--json'], 'error' => 'Input file not readable: missing.txt'];
        yield 'lint, invalid --pcre-version' => ['arguments' => ['--pcre-version=banana', 'lint', 'src', '--jobs=1', '--json'], 'error' => 'Invalid option: "pcre_version" must be a PCRE2 release like "10.44", not "banana".'];
        yield 'lint, invalid --php-version beside a valid --pcre-version' => ['arguments' => ['--php-version=99', '--pcre-version=10.44', 'lint', 'src', '--jobs=1', '--json'], 'error' => 'Invalid option: "php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".'];
    }

    /**
     * --generate-baseline in a directory the process cannot write to is
     * refused before the run, as a usage error.
     */
    #[Test]
    public function test_lint_generate_baseline_in_a_read_only_directory_is_a_usage_error(): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);
        mkdir('read-only', 0o500);

        try {
            [$exitCode, $stdout] = $this->runRegex(['lint', 'src', '--jobs=1', '--json', '--generate-baseline=read-only/baseline.json']);
        } finally {
            chmod('read-only', 0o700);
        }

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame(['error' => 'Baseline file not writable: read-only/baseline.json', 'stage' => 'usage'], JsonContract::decodeDocument($stdout));
        $this->assertFileDoesNotExist('read-only/baseline.json');
    }

    /**
     * A target the library refuses (--php-version=99) stops a pattern
     * command before it reads the pattern: the envelope in JSON mode, one
     * error line otherwise, both carrying the library's message.
     */
    #[Test]
    #[DataProvider('providePatternCommandsAndModes')]
    public function test_invalid_library_option_is_reported_with_its_message(string $command, bool $json): void
    {
        $this->enterProject();
        $message = 'Invalid option: "php_version" must be a version string like "8.2", a PHP_VERSION_ID integer, or "runtime".';

        [$exitCode, $stdout] = $this->runRegex(['--php-version=99', $command, '/a/', ...($json ? ['--json'] : [])]);

        $this->assertSame(2, $exitCode, $stdout);
        if ($json) {
            $this->assertSame(['error' => $message, 'stage' => 'usage'], JsonContract::decodeDocument($stdout));

            return;
        }

        $this->assertSame($message."\n", $stdout);
    }

    /**
     * @return iterable<string, array{command: string, json: bool}>
     */
    public static function providePatternCommandsAndModes(): iterable
    {
        foreach (self::PATTERN_COMMANDS as $command) {
            yield $command.', JSON' => ['command' => $command, 'json' => true];
            yield $command.', text' => ['command' => $command, 'json' => false];
        }
    }

    /**
     * A command without a JSON mode reports the same refused option as one
     * text line.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideCommandsWithoutJsonMode')]
    public function test_invalid_library_option_stays_text_without_a_json_mode(array $arguments): void
    {
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['--php-version=99', ...$arguments]);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertSame("Invalid option: \"php_version\" must be a version string like \"8.2\", a PHP_VERSION_ID integer, or \"runtime\".\n", $stdout);
    }

    /**
     * @return iterable<string, array{arguments: list<string>}>
     */
    public static function provideCommandsWithoutJsonMode(): iterable
    {
        foreach (['validate', 'parse', 'explain', 'diagram', 'graph', 'highlight'] as $command) {
            yield $command => ['arguments' => [$command, '/a/']];
        }
        yield 'compare' => ['arguments' => ['compare', '/a/', '/b/']];
    }

    /**
     * Without JSON the same failures are one error line on stdout.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideTextErrors')]
    public function test_text_error_is_one_line(array $arguments, string $files, string $line): void
    {
        $this->enterProject('' === $files ? [] : ['regex.json' => $files]);

        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertMatchesRegularExpression($line, $stdout);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, files: string, line: string}>
     */
    public static function provideTextErrors(): iterable
    {
        yield 'redos, --input-file that does not exist' => ['arguments' => ['redos', '/a/', '--input-file=missing.txt'], 'files' => '', 'line' => '/\AError: Input file not readable: missing\.txt\n\z/'];
        yield 'debug, a regex.json with an unknown key' => ['arguments' => ['debug', '/a/'], 'files' => '{"bogusKey": 1}', 'line' => '/\AError: Unknown key "bogusKey" in \S*regex\.json\.\n\z/'];
    }

    /**
     * transpile on an invalid pattern, in text: one line carrying the
     * validation's message, and no translation after it.
     */
    #[Test]
    public function test_transpile_invalid_pattern_in_text_is_one_line(): void
    {
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['transpile', '/(a/']);

        $this->assertSame(1, $exitCode, $stdout);
        $this->assertSame("  Transpile failed: Expected ) at end of input (found eof)\n", $stdout);
    }

    /**
     * --format given as a separate argument needs its value: an option or
     * the end of the command line in its place is a usage error, reported
     * as the envelope once --json asks for JSON, as text otherwise.
     *
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideTranspileFormatsWithoutValue')]
    public function test_transpile_format_without_its_value_is_a_usage_error(array $arguments, bool $json): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex($arguments);

        $this->assertSame(2, $exitCode, $stdout);
        if ($json) {
            $this->assertSame(['error' => 'Missing value for --format.', 'stage' => 'usage'], JsonContract::decodeDocument($stdout));

            return;
        }

        $this->assertNull(json_decode($stdout, true), $stdout);
        $this->assertStringContainsString('Error: Missing value for --format.', $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, json: bool}>
     */
    public static function provideTranspileFormatsWithoutValue(): iterable
    {
        yield 'last on the command line, --json before it' => ['arguments' => ['transpile', '/a/', '--json', '--format'], 'json' => true];
        yield 'followed by --json' => ['arguments' => ['transpile', '/a/', '--format', '--json'], 'json' => true];
        yield 'last on the command line, no JSON asked' => ['arguments' => ['transpile', '/a/', '--format'], 'json' => false];
    }

    /**
     * A regex.json debug cannot use stops the run before the pattern is
     * read: the envelope, stage "config", exit 2.
     */
    #[Test]
    #[DataProvider('provideBrokenConfigurations')]
    public function test_debug_configuration_error_prints_the_config_envelope(string $configuration, string $error): void
    {
        $this->enterProject(['regex.json' => $configuration]);

        [$exitCode, $stdout] = $this->runRegex(['debug', '/a/', '--format=json']);

        $this->assertSame(2, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['error', 'stage'], array_keys($document), $stdout);
        $this->assertSame('config', $document['stage']);
        $this->assertIsString($document['error']);
        $this->assertStringContainsString($error, (string) $document['error']);
        $this->assertStringContainsString('regex.json', (string) $document['error']);
    }

    /**
     * @return iterable<string, array{configuration: string, error: string}>
     */
    public static function provideBrokenConfigurations(): iterable
    {
        yield 'not JSON' => ['configuration' => '{"redosMode": 5', 'error' => 'Invalid JSON'];
        yield 'an unknown key' => ['configuration' => '{"bogusKey": 1}', 'error' => 'Unknown key "bogusKey"'];
    }

    /**
     * "format": "json" in regex.json asks for JSON as the option does: a
     * command line lint cannot use is reported as the envelope, unless the
     * command line asks for another format.
     */
    #[Test]
    public function test_lint_usage_error_follows_the_format_of_the_configuration(): void
    {
        $this->enterProject([
            'regex.json' => '{"format": "json", "paths": ["src"]}',
            'src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n",
        ]);

        [$exitCode, $stdout] = $this->runRegex(['lint', '--nope']);

        $this->assertSame(2, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame('usage', $document['stage'] ?? null, $stdout);
        $this->assertIsString($document['error'] ?? null);
        $this->assertStringContainsString('--nope', (string) $document['error']);

        [$exitCode, $stdout] = $this->runRegex(['lint', '--nope', '--format=console']);

        $this->assertSame(2, $exitCode, $stdout);
        $this->assertNull(json_decode($stdout, true), $stdout);
    }

    /**
     * A path regex.json names that does not exist is the configuration's
     * error, not the command line's.
     */
    #[Test]
    public function test_lint_configured_path_that_does_not_exist_is_a_config_error(): void
    {
        $this->enterProject(['regex.json' => '{"paths": ["missing"]}']);

        [$exitCode, $stdout] = $this->runRegex(['lint', '--jobs=1', '--json']);

        $this->assertSame(2, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['error', 'stage'], array_keys($document), $stdout);
        $this->assertSame('config', $document['stage']);
        $this->assertSame('Path not found: missing (from the "paths" of the configuration)', $document['error']);
    }

    /**
     * An invalid --safe pattern stops a JSON run as an invalid main pattern
     * does: the envelope, stage "pattern", with its validation, and a
     * message naming the --safe pattern.
     */
    #[Test]
    public function test_redos_invalid_safe_pattern_prints_the_pattern_envelope(): void
    {
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['redos', '/a+/', '--safe', '/(/', '--input', 'aaa', '--iterations', '1', '--warmup', '0', '--json']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame(['error', 'stage', 'validation'], array_keys($document), $stdout);
        $this->assertSame('pattern', $document['stage']);
        $this->assertIsString($document['error']);
        $this->assertStringContainsString('--safe', (string) $document['error']);
        $validation = JsonContract::asArray($document['validation']);
        $this->assertFalse($validation['is_valid'] ?? null);
        $this->assertSame('regex.group.unclosed', $validation['error_code'] ?? null);
        $this->assertIsString($validation['error'] ?? null);
        $this->assertSame('Invalid --safe pattern: '.$validation['error'], $document['error']);
    }

    /**
     * An unknown --format value does not ask for JSON: it stays a text usage
     * error, and stdout holds no JSON document.
     */
    #[Test]
    #[DataProvider('provideUnknownFormats')]
    public function test_unknown_format_stays_a_text_usage_error(string $command): void
    {
        $this->enterProject();

        $arguments = 'lint' === $command ? ['lint', '--format=xml'] : [$command, '/a/', '--format=xml'];
        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(2, $exitCode);
        $this->assertNull(json_decode($stdout, true), $stdout);
    }

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideUnknownFormats(): iterable
    {
        foreach ([...self::PATTERN_COMMANDS, 'lint'] as $command) {
            yield $command => ['command' => $command];
        }
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideQuietRuns')]
    public function test_quiet_still_prints_the_json_document(array $arguments, string $address): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);

        [$exitCode, $stdout] = $this->runRegex($arguments);

        $this->assertSame(0, $exitCode);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape($address, $document);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, address: string}>
     */
    public static function provideQuietRuns(): iterable
    {
        yield 'analyze, --quiet' => ['arguments' => ['--quiet', 'analyze', '/a/', '--format=json'], 'address' => 'analyze'];
        yield 'analyze, -q after the command' => ['arguments' => ['analyze', '/a/', '-q', '--json'], 'address' => 'analyze'];
        yield 'debug, --quiet' => ['arguments' => ['--quiet', 'debug', '/a/', '--format=json'], 'address' => 'debug'];
        yield 'redos, --quiet' => ['arguments' => ['--quiet', 'redos', '/a/', '--input', 'a', '--iterations', '1', '--warmup', '0', '--format=json'], 'address' => 'redos'];
        yield 'transpile, --quiet' => ['arguments' => ['--quiet', 'transpile', '/a/', '--format=json'], 'address' => 'transpile'];
        yield 'lint, --quiet' => ['arguments' => ['--quiet', 'lint', 'src', '--jobs=1', '--format=json'], 'address' => 'lint'];
        yield 'lint, -q' => ['arguments' => ['-q', 'lint', 'src', '--jobs=1', '--format=json'], 'address' => 'lint'];
    }

    /**
     * The status lines a JSON run still has go to stderr, never into the
     * document on stdout.
     */
    #[Test]
    public function test_lint_json_keeps_its_status_lines_on_stderr(): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json', '--generate-baseline=baseline.json']);

        $this->assertSame(0, $exitCode);
        JsonContract::decodeDocument($stdout);
        $this->assertStringContainsString('Baseline generated at baseline.json', $stderr);
    }
}
