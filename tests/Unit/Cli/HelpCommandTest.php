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

namespace PhpRegex\Tests\Unit\Cli;

use PhpRegex\Cli\Command\HelpCommand;
use PhpRegex\Cli\GlobalOptions;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PHPUnit\Framework\TestCase;

final class HelpCommandTest extends TestCase
{
    public function test_help_uses_invocation_in_usage_and_examples(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/regex'];

        try {
            $command = new HelpCommand();
            $options = new GlobalOptions(false, null, false, false, null, null);
            $input = new Input('help', [], $options, []);
            $output = new Output(false, false);

            ob_start();
            $command->run($input, $output);
            $text = (string) ob_get_clean();

            $this->assertStringContainsString("Usage:\n  bin/regex <command> [options] <pattern>", $text);
            $this->assertStringContainsString("bin/regex '/a+/'", $text);
            $this->assertStringContainsString("bin/regex analyze '/a+/'", $text);
        } finally {
            if (null === $originalArgv) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    public function test_render_command_help_returns_zero_for_valid_command(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'parse');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Parse and recompile a regex pattern', $text);
        $this->assertStringContainsString('Description:', $text);
        $this->assertStringContainsString('Usage:', $text);
    }

    public function test_render_command_help_returns_two_for_invalid_command(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'invalid');
        $text = (string) ob_get_clean();

        $this->assertSame(2, $result);
        $this->assertStringContainsString('Unknown command: invalid', $text);
        $this->assertStringContainsString('Available Commands', $text);
    }

    public function test_render_command_help_includes_options(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $method->invoke($command, $output, 'regex', 'analyze');
        $text = (string) ob_get_clean();

        $this->assertStringContainsString('Options:', $text);
        $this->assertStringContainsString('--php-version', $text);
        $this->assertStringContainsString('--pcre-version', $text);
        $this->assertStringContainsString('--redos-mode', $text);
    }

    public function test_render_command_help_includes_notes(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $method->invoke($command, $output, 'regex', 'debug');
        $text = (string) ob_get_clean();

        $this->assertStringContainsString('Provides detailed ReDoS analysis including attack vectors and complexity heatmaps.', $text);
    }

    public function test_render_command_help_includes_examples(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $method->invoke($command, $output, 'regex', 'explain');
        $text = (string) ob_get_clean();

        $this->assertStringContainsString('Examples:', $text);
        $this->assertStringContainsString('Explain a simple pattern', $text);
    }

    public function test_render_command_help_for_diagram(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'diagram');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Render an ASCII diagram of the AST', $text);
        $this->assertStringContainsString('--format <format>', $text);
        $this->assertStringContainsString('Output format (ascii)', $text);
        $this->assertStringContainsString('Basic diagram', $text);
    }

    public function test_render_command_help_for_highlight(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'highlight');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Highlight a regex for display', $text);
        $this->assertStringContainsString('--format <format>', $text);
        $this->assertStringContainsString('Output format (console, html)', $text);
        $this->assertStringContainsString('Console highlighting', $text);
        $this->assertStringContainsString('HTML highlighting', $text);
    }

    public function test_render_command_help_for_validate(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'validate');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Validate a regex pattern', $text);
        $this->assertStringContainsString('--php-version <ver>', $text);
        $this->assertStringContainsString('--pcre-version <ver>', $text);
        $this->assertStringContainsString('Validate a pattern', $text);
        $this->assertStringContainsString('Validate for PHP 8.0', $text);
    }

    public function test_render_command_help_for_lint(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'lint');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Description:', $text);
        $this->assertStringContainsString('Usage:', $text);
        $this->assertStringContainsString('Options:', $text);
        $this->assertStringContainsString('--exclude <path>', $text);
        $this->assertStringContainsString('--format <format>', $text);
    }

    public function test_render_command_help_for_self_update(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'self-update');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Description:', $text);
        $this->assertStringContainsString('Usage:', $text);
        $this->assertStringContainsString('Update the CLI phar to the latest release', $text);
        $this->assertStringContainsString('Examples:', $text);
    }

    public function test_render_command_help_for_help(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'help');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Display this help message', $text);
        $this->assertStringContainsString('<command>', $text);
        $this->assertStringContainsString('Show help for specific command', $text);
        $this->assertStringContainsString('Show lint command help', $text);
    }

    public function test_get_command_data_returns_data_for_parse(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getCommandData');

        $data = $method->invoke($command, 'parse');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('options', $data);
        $this->assertArrayHasKey('notes', $data);
        $this->assertArrayHasKey('examples', $data);
        $this->assertSame('Parse and recompile a regex pattern', $data['description']);
    }

    public function test_get_command_data_returns_data_for_lint(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getCommandData');

        $data = $method->invoke($command, 'lint');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('options', $data);
        $this->assertArrayHasKey('notes', $data);
        $this->assertArrayHasKey('examples', $data);
        $this->assertIsArray($data['options']);
        $this->assertIsArray($data['notes']);
        $this->assertCount(11, $data['options']);
        $this->assertCount(4, $data['notes']);
    }

    public function test_get_command_data_returns_null_for_invalid_command(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getCommandData');

        $data = $method->invoke($command, 'nonexistent');

        $this->assertNull($data);
    }

    public function test_get_command_data_returns_one_option_for_help(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getCommandData');

        $data = $method->invoke($command, 'help');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('options', $data);
        $this->assertIsArray($data['options']);
        $this->assertCount(1, $data['options']);
    }

    public function test_get_command_data_returns_empty_options_for_self_update(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getCommandData');

        $data = $method->invoke($command, 'self-update');

        $this->assertIsArray($data);
        $this->assertArrayHasKey('options', $data);
        $this->assertEmpty($data['options']);
    }

    public function test_format_command_usage_for_lint(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatCommandUsage');

        $lintData = [
            'description' => 'Test',
            'options' => [],
            'notes' => [],
            'examples' => [],
        ];

        $usage = $method->invoke($command, $output, 'regex', 'lint', $lintData);

        $this->assertIsString($usage);
        $this->assertStringContainsString('regex', (string) $usage);
        $this->assertStringContainsString('lint', (string) $usage);
        $this->assertStringContainsString('[options]', (string) $usage);
        $this->assertStringContainsString('<path>', (string) $usage);
    }

    public function test_format_command_usage_for_parse_analyze_and_commands_with_pattern(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatCommandUsage');

        $parseData = [
            'description' => 'Test',
            'options' => [],
            'notes' => [],
            'examples' => [],
        ];

        $usage = $method->invoke($command, $output, 'regex', 'parse', $parseData);

        $this->assertIsString($usage);
        $this->assertStringContainsString('regex', (string) $usage);
        $this->assertStringContainsString('parse', (string) $usage);
        $this->assertStringContainsString('[options]', (string) $usage);
        $this->assertStringContainsString('<pattern>', (string) $usage);
    }

    public function test_format_command_usage_for_help(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatCommandUsage');

        $helpData = [
            'description' => 'Test',
            'options' => [],
            'notes' => [],
            'examples' => [],
        ];

        $usage = $method->invoke($command, $output, 'regex', 'help', $helpData);

        $this->assertIsString($usage);
        $this->assertStringContainsString('regex', (string) $usage);
        $this->assertStringContainsString('help', (string) $usage);
        $this->assertStringContainsString('[command]', (string) $usage);
        $this->assertStringNotContainsString('<pattern>', (string) $usage);
    }

    public function test_format_option_with_ansi_enabled(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatOption');

        $option = '--format <format>';
        $formatted = $method->invoke($command, $output, $option);

        $this->assertIsString($formatted);
    }

    public function test_format_option_with_ansi_disabled(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatOption');

        $option = '--format <format>';
        $formatted = $method->invoke($command, $output, $option);

        $this->assertSame('--format <format>', $formatted);
    }

    public function test_format_option_with_placeholder(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatOption');

        $option = '--php-version <ver>';
        $formatted = $method->invoke($command, $output, $option);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('--php-version', (string) $formatted);
        $this->assertStringContainsString('<ver>', (string) $formatted);
    }

    public function test_format_option_without_placeholder(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatOption');

        $option = '--no-ansi';
        $formatted = $method->invoke($command, $output, $option);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('--no-ansi', (string) $formatted);
    }

    public function test_format_option_with_multiple_parts(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatOption');

        $option = '-v, --verbose';
        $formatted = $method->invoke($command, $output, $option);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('-v', (string) $formatted);
        $this->assertStringContainsString('--verbose', (string) $formatted);
    }

    public function test_format_example_command_formats_command_and_tokens(): void
    {
        $command = new HelpCommand();
        $output = new Output(false, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatExampleCommand');

        $tokens = ['regex', 'parse', "'/a+/'", '--validate'];
        $formatted = $method->invoke($command, $output, $tokens);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('regex', (string) $formatted);
        $this->assertStringContainsString('parse', (string) $formatted);
        $this->assertStringContainsString("'/a+/'", (string) $formatted);
    }

    public function test_format_example_token_colors_first_token_as_command(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatExampleToken');

        $formatted = $method->invoke($command, $output, 'regex', 0);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('regex', (string) $formatted);
    }

    public function test_format_example_token_colors_option_tokens(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatExampleToken');

        $formatted = $method->invoke($command, $output, '--format=json', 1);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('--format=json', (string) $formatted);
    }

    public function test_format_example_token_colors_pattern_tokens(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatExampleToken');

        $formatted = $method->invoke($command, $output, "'/a+/'", 1);

        $this->assertIsString($formatted);
        $this->assertStringContainsString("'/a+/'", (string) $formatted);
    }

    public function test_format_example_token_colors_subcommands(): void
    {
        $command = new HelpCommand();
        $output = new Output(true, false);

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatExampleToken');

        $formatted = $method->invoke($command, $output, 'analyze', 1);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('analyze', (string) $formatted);
    }

    public function test_is_placeholder_detects_placeholders(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPlaceholder');

        $this->assertTrue($method->invoke($command, '<format>'));
        $this->assertTrue($method->invoke($command, '<path>'));
        $this->assertTrue($method->invoke($command, '<ver>'));
    }

    public function test_is_placeholder_returns_false_for_non_placeholders(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPlaceholder');

        $this->assertFalse($method->invoke($command, '--format'));
        $this->assertFalse($method->invoke($command, '-v'));
        $this->assertFalse($method->invoke($command, 'format'));
    }

    public function test_is_pattern_token_detects_quoted_patterns(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPatternToken');

        $this->assertTrue($method->invoke($command, "'/a+/'"));
        $this->assertTrue($method->invoke($command, "'/test/'"));
        $this->assertFalse($method->invoke($command, "'not-a-pattern"));
        $this->assertTrue($method->invoke($command, '/a+/'));
    }

    public function test_is_pattern_token_detects_unquoted_patterns(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPatternToken');

        $this->assertTrue($method->invoke($command, '/a+/'));
        $this->assertTrue($method->invoke($command, '/test/'));
        $this->assertFalse($method->invoke($command, "'/quoted"));
        $this->assertFalse($method->invoke($command, 'not-a-pattern'));
        $this->assertFalse($method->invoke($command, '/not-closed'));
    }

    public function test_resolve_invocation_returns_argv_when_available(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['custom-binary'];

        try {
            $command = new HelpCommand();

            $reflection = new \ReflectionClass($command);
            $method = $reflection->getMethod('resolveInvocation');

            $result = $method->invoke($command);

            $this->assertSame('custom-binary', $result);
        } finally {
            if (null === $originalArgv) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    public function test_resolve_invocation_returns_default_when_argv_missing(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        unset($_SERVER['argv']);

        try {
            $command = new HelpCommand();

            $reflection = new \ReflectionClass($command);
            $method = $reflection->getMethod('resolveInvocation');

            $result = $method->invoke($command);

            $this->assertSame('regex', $result);
        } finally {
            /** @var array<int, string>|null $originalArgv */
            if (null === $originalArgv) {
                /* @phpstan-ignore-next-line */
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    public function test_resolve_invocation_returns_default_when_argv_empty(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = [''];

        try {
            $command = new HelpCommand();

            $reflection = new \ReflectionClass($command);
            $method = $reflection->getMethod('resolveInvocation');

            $result = $method->invoke($command);

            $this->assertSame('regex', $result);
        } finally {
            /** @var array<int, string>|null $originalArgv */
            if (null === $originalArgv) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }
}
