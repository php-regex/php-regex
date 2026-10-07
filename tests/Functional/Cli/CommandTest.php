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

use PHPRegex\Cli\Command\AnalyzeCommand;
use PHPRegex\Cli\Command\CompareCommand;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\Command\DiagramCommand;
use PHPRegex\Cli\Command\ExplainCommand;
use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\HighlightCommand;
use PHPRegex\Cli\Command\ParseCommand;
use PHPRegex\Cli\Command\RedosCommand;
use PHPRegex\Cli\Command\SelfUpdateCommand;
use PHPRegex\Cli\Command\ValidateCommand;
use PHPRegex\Cli\Command\VersionCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Cli\SelfUpdate\SelfUpdater;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CommandTest extends TestCase
{
    public function test_analyze_command_reports_missing_pattern(): void
    {
        $command = new AnalyzeCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('analyze', []), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing pattern', $buffer);
    }

    public function test_analyze_command_handles_pattern(): void
    {
        $command = new AnalyzeCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('analyze', ['/a+/']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Severity', $buffer);
    }

    public function test_analyze_command_outputs_json(): void
    {
        $command = new AnalyzeCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('analyze', ['/a+/', '--format=json']), $output), $exitCode);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeJsonPayload($buffer);
        $this->assertSame('/a+/', $payload['pattern']);
        $this->assertIsString($payload['pattern']);

        $parse = $this->requireArrayKey($payload, 'parse');
        $this->assertArrayHasKey('ok', $parse);
        $this->assertTrue((bool) $parse['ok']);

        $this->requireArrayKey($payload, 'runtime');
        $this->requireArrayKey($payload, 'validation');
        $redos = $this->requireArrayKey($payload, 'redos');
        $this->assertArrayHasKey('mode', $redos);

        $this->assertArrayHasKey('explain', $payload);
        $this->assertIsString($payload['explain']);
    }

    public function test_analyze_command_json_carries_the_error_code_as_a_string(): void
    {
        $command = new AnalyzeCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('analyze', ['/(?<=a+)b/', '--format=json']), $output), $exitCode);

        $validation = $this->requireArrayKey($this->decodeJsonPayload($buffer), 'validation');
        $this->assertFalse($validation['is_valid'] ?? null);
        $this->assertSame('regex.lookbehind.unbounded', $validation['error_code']);
    }

    public function test_analyze_command_handles_invalid_regex_options(): void
    {
        $command = new AnalyzeCommand();
        $output = OutputFactory::create();

        // Create input with invalid regex options
        $input = new Input(
            'analyze',
            ['/a+/'],
            new GlobalOptions(false, false, false, true, null, null),
            ['invalid_option' => 'value'],
        );

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Invalid option:', $buffer);
    }

    public function test_analyze_command_reports_invalid_pattern(): void
    {
        $command = new AnalyzeCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('analyze', ['[unclosed']), $output), $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Analyze failed:', $buffer);
    }

    public function test_analyze_command_reports_validation_error(): void
    {
        $command = new AnalyzeCommand();
        $output = OutputFactory::create();

        // Pattern that may cause validation error (e.g., invalid quantifier range)
        $exitCode = 0;
        $this->captureOutput(static fn (): int => $command->run(self::makeInput('analyze', ['/a{5,3}/']), $output), $exitCode);

        $this->assertSame(1, $exitCode);
        // Check if validation error is shown
        // This covers the if (!$validation->isValid && $validation->error) branch
    }

    public function test_redos_command_reads_the_pattern_for_the_target(): void
    {
        $command = new RedosCommand();
        $output = new Output(true, false);
        $input = new Input(
            'redos',
            ['/(a+)+$/', '--safe', '/a+$/', '--iterations', '1', '--warmup', '0', '--repeat', '2'],
            new GlobalOptions(false, true, false, false, '8.2', null, '10.40'),
            ['php_version' => '8.2', 'pcre_version' => '10.40'],
        );

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Vulnerable:', $buffer);
        $this->assertStringContainsString('Safe:', $buffer);
    }

    public function test_banners_name_the_target_asked_for(): void
    {
        $options = new GlobalOptions(false, false, false, true, '8.4', null, '10.42');
        $regexOptions = ['php_version' => '8.4', 'pcre_version' => '10.42'];

        foreach ([
            [new ExplainCommand(), new Input('explain', ['/a+/'], $options, $regexOptions)],
            [new CompareCommand(), new Input('compare', ['/a+/', '/a*/'], $options, $regexOptions)],
            [new HelpCommand(), new Input('help', [], $options, $regexOptions)],
        ] as [$command, $input]) {
            $exitCode = 0;
            $buffer = $this->captureOutput(static fn (): int => $command->run($input, OutputFactory::create()), $exitCode);

            $this->assertStringContainsString('PCRE2 10.42', $buffer, $command->getName());
        }
    }

    public function test_debug_command_reports_missing_pattern(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', []), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing pattern', $buffer);
    }

    public function test_debug_command_reports_missing_input_value(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['/(a+)+$/', '--input']), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing value for --input', $buffer);
    }

    public function test_debug_command_outputs_json(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['/(a+)+$/', '--format=json']), $output), $exitCode);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeJsonPayload($buffer);
        $this->assertSame('/(a+)+$/', $payload['pattern']);
        $this->assertIsString($payload['pattern']);

        $this->requireArrayKey($payload, 'runtime');
        $analysis = $this->requireArrayKey($payload, 'analysis');
        $this->assertArrayHasKey('severity', $analysis);
        $this->assertArrayHasKey('mode', $analysis);

        $input = $this->requireArrayKey($payload, 'input');
        $this->assertArrayHasKey('value', $input);
        $this->assertArrayHasKey('source', $input);
    }

    public function test_debug_command_runs_with_input(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['/(a+)+$/', '--input=aaaa']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
    }

    public function test_debug_command_reports_invalid_pattern(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['[unclosed']), $output), $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Debug failed:', $buffer);
    }

    public function test_debug_command_outputs_with_ansi(): void
    {
        $command = new DebugCommand();
        $output = new Output(true, false);

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['/(a+)+$/']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Debug', $buffer);
    }

    public function test_debug_command_generates_auto_input_when_none_provided(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['/(a+)+$/']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Input:', $buffer);
        $this->assertStringContainsString('(auto)', $buffer);
    }

    public function test_debug_command_handles_input_option_with_value(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['/(a+)+$/', '--input', 'testinput']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Input:      "testinput"', $buffer);
    }

    public function test_debug_command_handles_invalid_regex_options(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        // Create input with invalid regex options
        $input = new Input(
            'debug',
            ['/a+/'],
            new GlobalOptions(false, false, false, true, null, null),
            ['invalid_option' => 'value'],
        );

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Invalid option:', $buffer);
    }

    public function test_debug_command_handles_php_version_option(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        // Create input with php_version regex option
        $input = new Input(
            'debug',
            ['/a+/'],
            new GlobalOptions(false, false, false, true, null, null),
            ['php_version' => '8.1'],
        );

        $exitCode = 0;
        $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(0, $exitCode);
        // Should process without error
    }

    public function test_debug_command_handles_simple_pattern(): void
    {
        $command = new DebugCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('debug', ['/a/']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Debug', $buffer);
    }

    public function test_diagram_command_reports_missing_pattern(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('diagram', []), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing pattern', $buffer);
    }

    public function test_diagram_command_rejects_unsupported_format(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('diagram', ['/a+/', '--format=bogus']), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unsupported format', $buffer);
    }

    public function test_diagram_command_renders_diagram(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $this->captureOutput(static fn (): int => $command->run(self::makeInput('diagram', ['/a+/']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
    }

    public function test_diagram_command_renders_svg_to_stdout(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('diagram', ['/a+/', '--format=svg']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('<svg', $buffer);
        $this->assertStringContainsString('</svg>', $buffer);
    }

    public function test_diagram_command_renders_svg_diagram(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();
        $tempFile = tempnam(sys_get_temp_dir(), 'regex-svg-');
        $this->assertNotFalse($tempFile);

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('diagram', ['/a+/', '--format=svg', '--output='.$tempFile]), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', $buffer);
        $svg = file_get_contents($tempFile);
        $this->assertNotFalse($svg);
        $this->assertStringContainsString('<svg', (string) $svg);
        $this->assertStringContainsString('</svg>', (string) $svg);
        @unlink($tempFile);
    }

    public function test_diagram_command_reports_invalid_pattern(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('diagram', ['[unclosed']), $output), $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Diagram failed:', $buffer);
    }

    public function test_diagram_command_supports_format_option_with_separate_value(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('diagram', ['/a+/', '--format', 'ascii']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('a', $buffer); // Should contain diagram output
    }

    public function test_diagram_command_handles_invalid_regex_options(): void
    {
        $command = new DiagramCommand();
        $output = OutputFactory::create();

        // Create input with invalid regex options
        $input = new Input(
            'diagram',
            ['/a+/'],
            new GlobalOptions(false, false, false, true, null, null),
            ['invalid_option' => 'value'],
        );

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Invalid option:', $buffer);
    }

    public function test_highlight_command_reports_missing_pattern(): void
    {
        $command = new HighlightCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('highlight', []), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing pattern', $buffer);
    }

    public function test_highlight_command_outputs_highlight(): void
    {
        $command = new HighlightCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('highlight', ['/a+/']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('/a+/', $buffer);
        $this->assertStringContainsString('Highlighting pattern', $buffer);
    }

    public function test_highlight_command_supports_html_format(): void
    {
        $command = new HighlightCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $this->captureOutput(static fn (): int => $command->run(self::makeInput('highlight', ['/a+/', '--format=html']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
    }

    public function test_highlight_command_reports_invalid_pattern(): void
    {
        $command = new HighlightCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('highlight', ['[unclosed']), $output), $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAIL Error:', $buffer);
    }

    public function test_highlight_command_supports_format_option_with_separate_value(): void
    {
        $command = new HighlightCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('highlight', ['/a+/', '--format', 'cli']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('/a+/', $buffer);
    }

    public function test_highlight_command_reports_invalid_format(): void
    {
        $command = new HighlightCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('highlight', ['/a+/', '--format=invalid']), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('FAIL Error: Invalid format: invalid', $buffer);
    }

    public function test_highlight_command_handles_invalid_regex_options(): void
    {
        $command = new HighlightCommand();
        $output = OutputFactory::create();

        // Create input with invalid regex options
        $input = new Input(
            'highlight',
            ['/a+/'],
            new GlobalOptions(false, false, false, true, null, null),
            ['invalid_option' => 'value'],
        );

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Invalid option:', $buffer);
    }

    public function test_parse_command_reports_missing_pattern(): void
    {
        $command = new ParseCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('parse', []), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing pattern', $buffer);
    }

    public function test_parse_command_outputs_compiled_and_validation(): void
    {
        $command = new ParseCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $this->captureOutput(static fn (): int => $command->run(self::makeInput('parse', ['/a+/', '--validate']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
    }

    public function test_parse_command_reports_invalid_pattern(): void
    {
        $command = new ParseCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('parse', ['[unclosed']), $output), $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Parse failed:', $buffer);
    }

    public function test_parse_command_handles_invalid_regex_options(): void
    {
        $command = new ParseCommand();
        $output = OutputFactory::create();

        // Create input with invalid regex options
        $input = new Input(
            'parse',
            ['/a+/'],
            new GlobalOptions(false, false, false, true, null, null),
            ['invalid_option' => 'value'],
        );

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Invalid option:', $buffer);
    }

    public function test_validate_command_reports_missing_pattern(): void
    {
        $command = new ValidateCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('validate', []), $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing pattern', $buffer);
    }

    public function test_validate_command_reports_invalid_pattern(): void
    {
        $command = new ValidateCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('validate', ['/(abc/']), $output), $exitCode);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('INVALID', $buffer);
    }

    /**
     * The message and the caret snippet under it: the snippet no longer
     * rides inside the message, so each command prints it.
     *
     * @return iterable<string, array{command: string, arguments: list<string>}>
     */
    public static function provideCommandsReportingAValidationError(): iterable
    {
        yield 'validate' => ['command' => 'validate', 'arguments' => ['/(?<=a+)b/']];
        yield 'analyze' => ['command' => 'analyze', 'arguments' => ['/(?<=a+)b/']];
        yield 'parse --validate' => ['command' => 'parse', 'arguments' => ['/(?<=a+)b/', '--validate']];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('provideCommandsReportingAValidationError')]
    public function test_command_prints_the_message_and_the_caret_snippet(string $command, array $arguments): void
    {
        $instance = match ($command) {
            'validate' => new ValidateCommand(),
            'analyze' => new AnalyzeCommand(),
            default => new ParseCommand(),
        };
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $instance->run(self::makeInput($command, $arguments), $output), $exitCode);

        $this->assertStringContainsString("  Lookbehind is unbounded. PCRE requires a bounded maximum length.\nLine 1: (?<=a+)b\n", $buffer);
        // PCRE reports an unbounded lookbehind where it opens, at offset 0;
        // the caret line ends its own line.
        $this->assertMatchesRegularExpression('/^ {8}\^$/m', $buffer);
        $this->assertStringContainsString("\n        ^\n", $buffer);
    }

    public function test_validate_command_accepts_valid_pattern(): void
    {
        $command = new ValidateCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('validate', ['/abc/']), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('OK', $buffer);
    }

    public function test_validate_command_handles_invalid_regex_options(): void
    {
        $command = new ValidateCommand();
        $output = OutputFactory::create();

        // Create input with invalid regex options
        $input = new Input(
            'validate',
            ['/a+/'],
            new GlobalOptions(false, false, false, true, null, null),
            ['invalid_option' => 'value'],
        );

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run($input, $output), $exitCode);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Invalid option:', $buffer);
    }

    public function test_help_command_outputs_sections_with_and_without_ansi(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('help', []), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Usage', $buffer);
        $this->assertStringContainsString('Commands', $buffer);

        $ansiOutput = new Output(true, false);
        $ansiExitCode = 0;
        $ansiBuffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('help', []), $ansiOutput), $ansiExitCode);

        $this->assertSame(0, $ansiExitCode);
        $this->assertStringContainsString('Usage', $ansiBuffer);
    }

    public function test_version_command_outputs_version(): void
    {
        $command = new VersionCommand();
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('version', []), $output), $exitCode);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('PHPRegex', $buffer);
        $this->assertStringContainsString('github.com/php-regex/php-regex', $buffer);
    }

    public function test_self_update_command_help_and_error_paths(): void
    {
        $updater = new SelfUpdater();
        $command = new SelfUpdateCommand($updater);
        $output = OutputFactory::create();

        $helpExitCode = 0;
        $helpBuffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('self-update', ['--help']), $output), $helpExitCode);

        $this->assertSame(0, $helpExitCode);
        $this->assertStringContainsString('Usage: regex self-update', $helpBuffer);

        $errorExitCode = 0;
        $errorBuffer = $this->captureOutput(static fn (): int => $command->run(self::makeInput('self-update', []), $output), $errorExitCode);

        $this->assertSame(1, $errorExitCode);
        $this->assertStringContainsString('Self-update failed', $errorBuffer);
    }

    public function test_self_update_command_get_name_returns_correct_value(): void
    {
        $command = new SelfUpdateCommand(new SelfUpdater());

        $this->assertSame('self-update', $command->getName());
    }

    public function test_self_update_command_get_aliases_returns_correct_value(): void
    {
        $command = new SelfUpdateCommand(new SelfUpdater());

        $this->assertSame(['selfupdate'], $command->getAliases());
    }

    public function test_self_update_command_get_description_returns_correct_value(): void
    {
        $command = new SelfUpdateCommand(new SelfUpdater());

        $this->assertSame('Update the CLI phar to the latest release', $command->getDescription());
    }

    public function test_self_update_command_run_returns_zero_on_success(): void
    {
        $updater = new class extends SelfUpdater {
            public function run(Output $output): void
            {
                // Do nothing
            }
        };

        $command = new SelfUpdateCommand($updater);
        $output = OutputFactory::create();

        $exitCode = 0;
        $this->captureOutput(static fn (): int => $command->run(self::makeInput('self-update', []), $output), $exitCode);

        $this->assertSame(0, $exitCode);
    }

    public function test_version_command_get_name_returns_correct_value(): void
    {
        $command = new VersionCommand();

        $this->assertSame('version', $command->getName());
    }

    public function test_version_command_get_aliases_returns_correct_value(): void
    {
        $command = new VersionCommand();

        $this->assertSame(['--version', '-v'], $command->getAliases());
    }

    public function test_version_command_get_description_returns_correct_value(): void
    {
        $command = new VersionCommand();

        $this->assertSame('Display version information', $command->getDescription());
    }

    public function test_help_command_get_name_returns_correct_value(): void
    {
        $command = new HelpCommand();

        $this->assertSame('help', $command->getName());
    }

    public function test_help_command_get_aliases_returns_correct_value(): void
    {
        $command = new HelpCommand();

        $this->assertSame(['--help', '-h'], $command->getAliases());
    }

    public function test_help_command_get_description_returns_correct_value(): void
    {
        $command = new HelpCommand();

        $this->assertSame('Display this help message', $command->getDescription());
    }

    public function test_analyze_command_get_name_returns_correct_value(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame('analyze', $command->getName());
    }

    public function test_analyze_command_get_aliases_returns_correct_value(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame(['analyse'], $command->getAliases());
    }

    public function test_analyze_command_get_description_returns_correct_value(): void
    {
        $command = new AnalyzeCommand();

        $this->assertSame('Parse, validate, and analyze ReDoS risk', $command->getDescription());
    }

    public function test_debug_command_get_name_returns_correct_value(): void
    {
        $command = new DebugCommand();

        $this->assertSame('debug', $command->getName());
    }

    public function test_debug_command_get_aliases_returns_correct_value(): void
    {
        $command = new DebugCommand();

        $this->assertSame([], $command->getAliases());
    }

    public function test_debug_command_get_description_returns_correct_value(): void
    {
        $command = new DebugCommand();

        $this->assertSame('Deep ReDoS analysis with heatmap output', $command->getDescription());
    }

    public function test_diagram_command_get_name_returns_correct_value(): void
    {
        $command = new DiagramCommand();

        $this->assertSame('diagram', $command->getName());
    }

    public function test_diagram_command_get_aliases_returns_correct_value(): void
    {
        $command = new DiagramCommand();

        $this->assertSame([], $command->getAliases());
    }

    public function test_diagram_command_get_description_returns_correct_value(): void
    {
        $command = new DiagramCommand();

        $this->assertSame('Render a diagram of the AST (text or SVG)', $command->getDescription());
    }

    public function test_highlight_command_get_name_returns_correct_value(): void
    {
        $command = new HighlightCommand();

        $this->assertSame('highlight', $command->getName());
    }

    public function test_highlight_command_get_aliases_returns_correct_value(): void
    {
        $command = new HighlightCommand();

        $this->assertSame([], $command->getAliases());
    }

    public function test_highlight_command_get_description_returns_correct_value(): void
    {
        $command = new HighlightCommand();

        $this->assertSame('Highlight a regex for display', $command->getDescription());
    }

    public function test_parse_command_get_name_returns_correct_value(): void
    {
        $command = new ParseCommand();

        $this->assertSame('parse', $command->getName());
    }

    public function test_parse_command_get_aliases_returns_correct_value(): void
    {
        $command = new ParseCommand();

        $this->assertSame([], $command->getAliases());
    }

    public function test_parse_command_get_description_returns_correct_value(): void
    {
        $command = new ParseCommand();

        $this->assertSame('Parse and recompile a regex pattern', $command->getDescription());
    }

    public function test_validate_command_get_name_returns_correct_value(): void
    {
        $command = new ValidateCommand();

        $this->assertSame('validate', $command->getName());
    }

    public function test_validate_command_get_aliases_returns_correct_value(): void
    {
        $command = new ValidateCommand();

        $this->assertSame([], $command->getAliases());
    }

    public function test_validate_command_get_description_returns_correct_value(): void
    {
        $command = new ValidateCommand();

        $this->assertSame('Validate a regex pattern', $command->getDescription());
    }

    /**
     * @param array<int, string> $args
     */
    private static function makeInput(string $command, array $args): Input
    {
        return new Input(
            $command,
            $args,
            new GlobalOptions(false, false, false, true, null, null),
            [],
        );
    }

    /**
     * @return array<mixed, mixed>
     */
    private function decodeJsonPayload(string $buffer): array
    {
        $payload = json_decode($buffer, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @return array<mixed, mixed>
     */
    private function requireArrayKey(array $payload, string $key): array
    {
        $this->assertArrayHasKey($key, $payload);
        $value = $payload[$key];
        $this->assertIsArray($value);

        return $value;
    }

    /**
     * @param callable(): int $callback
     */
    private function captureOutput(callable $callback, int &$exitCode): string
    {
        $initialLevel = ob_get_level();
        ob_start();

        try {
            $exitCode = $callback();

            $content = (string) ob_get_clean();

            // Clean up any extra buffers started by the callback
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }

            return $content;
        } catch (\Throwable $e) {
            // Clean up any extra buffers on exception
            while (ob_get_level() > $initialLevel) {
                ob_end_clean();
            }

            throw $e;
        }
    }
}
