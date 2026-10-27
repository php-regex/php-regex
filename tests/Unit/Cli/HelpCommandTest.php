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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\ParseCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPRegex\Transpiler\Target\TargetRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HelpCommandTest extends TestCase
{
    #[Test]
    public function test_help_uses_invocation_in_usage_and_examples(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/regex'];

        try {
            $command = new HelpCommand();
            $options = new GlobalOptions(false, null, false, false, null, null);
            $input = new Input('help', [], $options, []);
            $output = OutputFactory::create();

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

    /**
     * The overview lists the commands and the options every command takes,
     * then points at `help <command>` for the rest: each command's options
     * are on its own page, not repeated there.
     */
    #[Test]
    public function test_the_overview_lists_the_commands_and_points_at_their_pages(): void
    {
        $overview = self::commandHelp(null);

        $this->assertArrayHasKey('lint', self::section($overview, 'Commands'));
        $this->assertArrayHasKey('--php-version <ver>', self::section($overview, 'Global Options'));
        $this->assertArrayHasKey('--locale <name>', self::section($overview, 'Global Options'));
        $this->assertArrayHasKey('--cache-dir <dir>', self::section($overview, 'Global Options'));
        $this->assertMatchesRegularExpression('/^  Run \S+ help <command> for the options of a command\.$/m', $overview);
        foreach (['Lint', 'Diagram', 'Transpile', 'Format', 'Analyze', 'Debug', 'ReDoS Benchmark'] as $section) {
            $this->assertStringNotContainsString($section.' Options:', $overview);
        }
        $this->assertStringNotContainsString('--min-savings', $overview);
    }

    /**
     * `help <command>` lists every option the command's parser accepts. The
     * expected lists below are written by hand, one per command, from its
     * parser: LintArgumentParser for lint, the parseArguments() of compare,
     * analyze, debug, redos and transpile, the readArguments() call of
     * diagram. An option a parser learns is added to its list here too.
     * Options every command takes (--php-version, --pcre-version, --locale) are
     * expected where the page lists them.
     */
    #[Test]
    #[DataProvider('provideAcceptedOptions')]
    public function test_command_help_lists_the_option_the_command_accepts(string $command, string $option): void
    {
        $this->assertContains($option, self::optionNames(self::section(self::commandHelp($command), 'Options')), 'help '.$command.' does not list '.$option.'.');
    }

    /**
     * @return iterable<string, array{command: string, option: string}>
     */
    public static function provideAcceptedOptions(): iterable
    {
        $accepted = [
            'lint' => [
                '--exclude', '--min-savings', '--jobs', '--format', '--json', '--output',
                '--redos', '--redos-mode', '--redos-threshold', '--no-validate', '--no-optimize', '--no-lint',
                '--interop', '--no-interop', '--pattern-function', '--generate-baseline', '--baseline',
                '--verbose', '--debug',
                '--lint', '--enable-rule', '--disable-rule', '-j', '--no-redos',
            ],
            'compare' => ['--method', '--determinizer', '--minimizer', '--php-version', '--pcre-version', '--locale'],
            'diagram' => ['--format', '--output', '--php-version', '--pcre-version', '--locale'],
            'analyze' => ['--format', '--redos-mode', '--redos-threshold', '--php-version', '--pcre-version', '--locale', '--json'],
            'debug' => ['--input', '--format', '--redos-mode', '--redos-threshold', '--php-version', '--pcre-version', '--locale', '--json'],
            'redos' => [
                '--safe', '--input', '--input-file', '--repeat', '--prefix', '--suffix', '--iterations', '--warmup',
                '--jit', '--backtrack-limit', '--recursion-limit', '--time-limit', '--format', '--show-input',
                '--php-version', '--pcre-version', '--locale', '--json',
            ],
            'transpile' => ['--target', '--format', '--json'],
            'format' => ['--compact', '--width', '--format', '--json'],
        ];

        foreach ($accepted as $command => $options) {
            foreach ($options as $option) {
                yield $command.' '.$option => ['command' => $command, 'option' => $option];
            }
        }
    }

    /**
     * The locale goes with the PHP and the PCRE2 release: every page that
     * offers --pcre-version offers --locale.
     */
    #[Test]
    public function test_every_page_that_names_the_pcre2_release_names_the_locale(): void
    {
        $pages = 0;
        foreach (['parse', 'analyze', 'compare', 'explain', 'debug', 'redos', 'diagram', 'highlight', 'validate', 'transpile', 'format', 'lint', 'graph'] as $command) {
            $options = self::optionNames(self::section(self::commandHelp($command), 'Options'));
            if (\in_array('--pcre-version', $options, true)) {
                $this->assertContains('--locale', $options, 'help '.$command.' does not list --locale.');
                $pages++;
            }
        }

        $this->assertSame(9, $pages);
    }

    /**
     * DiagramCommand renders text (the default) and svg; ascii is only an
     * old spelling of text it still accepts, and the page does not offer it.
     */
    #[Test]
    public function test_diagram_help_offers_the_console_and_svg_formats(): void
    {
        $rows = self::section(self::commandHelp('diagram'), 'Options');

        $this->assertArrayHasKey('--format <format>', $rows);
        $this->assertSame('Output format (console, svg)', $rows['--format <format>']);
        $this->assertStringNotContainsString('ascii', implode("\n", $rows));
    }

    /**
     * The lint options that take a value, or have a short alias, are shown
     * in the spelling LintArgumentParser accepts: --enable-rule and
     * --disable-rule only after "=", -j in the --jobs row.
     */
    #[Test]
    #[DataProvider('provideLintOptionSpellings')]
    public function test_lint_help_shows_the_option_in_its_accepted_spelling(string $spelling): void
    {
        $this->assertArrayHasKey($spelling, self::section(self::commandHelp('lint'), 'Options'));
    }

    /**
     * @return iterable<string, array{spelling: string}>
     */
    public static function provideLintOptionSpellings(): iterable
    {
        yield '-j' => ['spelling' => '-j, --jobs <n>'];
        yield '--enable-rule' => ['spelling' => '--enable-rule=<id>'];
        yield '--disable-rule' => ['spelling' => '--disable-rule=<id>'];
        yield '--no-redos' => ['spelling' => '--no-redos'];
        yield '--baseline' => ['spelling' => '--baseline <file>'];
        yield '--generate-baseline' => ['spelling' => '--generate-baseline <file>'];
    }

    /**
     * Every command that takes --json describes it the same way on its
     * page.
     */
    #[Test]
    #[DataProvider('provideJsonCommands')]
    public function test_command_help_describes_json_as_the_json_format(string $command): void
    {
        $this->assertSame('Same as --format=json', self::section(self::commandHelp($command), 'Options')['--json'] ?? null);
    }

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideJsonCommands(): iterable
    {
        yield 'lint' => ['command' => 'lint'];
        yield 'analyze' => ['command' => 'analyze'];
        yield 'debug' => ['command' => 'debug'];
        yield 'redos' => ['command' => 'redos'];
        yield 'transpile' => ['command' => 'transpile'];
        yield 'format' => ['command' => 'format'];
    }

    #[Test]
    public function test_render_command_help_returns_zero_for_valid_command(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

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

    #[Test]
    public function test_render_command_help_returns_two_for_invalid_command(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'invalid');
        $text = (string) ob_get_clean();

        $this->assertSame(2, $result);
        $this->assertStringContainsString('Unknown command: invalid', OutputFactory::errors($output));
        $this->assertStringNotContainsString('Unknown command', $text);
        $this->assertStringContainsString('Available Commands', $text);
    }

    #[Test]
    public function test_help_for_a_mistyped_command_suggests_the_nearest(): void
    {
        $output = OutputFactory::create();
        $input = new Input('help', ['anlyze'], new GlobalOptions(false, null, false, false, null, null), []);

        ob_start();
        $result = (new HelpCommand())->run($input, $output);
        ob_end_clean();

        $this->assertSame(2, $result);
        $this->assertStringContainsString('Unknown command: anlyze. Did you mean "analyze"?', OutputFactory::errors($output));
    }

    #[Test]
    public function test_render_command_help_includes_options(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

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

    #[Test]
    public function test_render_command_help_includes_notes(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $method->invoke($command, $output, 'regex', 'debug');
        $text = (string) ob_get_clean();

        $this->assertStringContainsString('Provides detailed ReDoS analysis including attack vectors and complexity heatmaps.', $text);
    }

    #[Test]
    public function test_render_command_help_includes_examples(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $method->invoke($command, $output, 'regex', 'explain');
        $text = (string) ob_get_clean();

        $this->assertStringContainsString('Examples:', $text);
        $this->assertStringContainsString('Explain a simple pattern', $text);
    }

    #[Test]
    public function test_render_command_help_for_diagram(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'diagram');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Render a diagram of the AST (text or SVG)', $text);
        $this->assertStringContainsString('--format <format>', $text);
        $this->assertStringContainsString('Output format (console, svg)', $text);
        $this->assertStringContainsString('Basic diagram', $text);
    }

    #[Test]
    public function test_render_command_help_for_highlight(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderCommandHelp');

        ob_start();
        $result = $method->invoke($command, $output, 'regex', 'highlight');
        $text = (string) ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertStringContainsString('Highlight a regex for display', $text);
        $this->assertStringContainsString('--format <format>', $text);
        $this->assertStringContainsString('Output format (auto, console, html)', $text);
        $this->assertStringContainsString('Console highlighting', $text);
        $this->assertStringContainsString('HTML highlighting', $text);
    }

    #[Test]
    public function test_render_command_help_for_validate(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

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

    #[Test]
    public function test_render_command_help_for_lint(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

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

    #[Test]
    public function test_render_command_help_for_self_update(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

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

    #[Test]
    public function test_render_command_help_for_help(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

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

    #[Test]
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

    #[Test]
    #[DataProvider('provideCommandsWithAHelpPage')]
    public function test_command_has_its_help_page(string $command, string $description): void
    {
        $data = (new \ReflectionMethod(new HelpCommand(), 'getCommandData'))->invoke(new HelpCommand(), $command);

        $this->assertIsArray($data);
        $this->assertSame($description, $data['description']);
        $this->assertNotSame([], $data['examples']);
    }

    /**
     * @return iterable<string, array{command: string, description: string}>
     */
    public static function provideCommandsWithAHelpPage(): iterable
    {
        yield 'graph' => ['command' => 'graph', 'description' => 'Generate a graph diagram (DOT/Mermaid) of the NFA'];
        yield 'version' => ['command' => 'version', 'description' => 'Display version information'];
    }

    /**
     * The format page says what the command prints, and its examples, like
     * the one the overview shows, each run the binary the help was invoked
     * as.
     */
    #[Test]
    public function test_format_help_shows_its_notes_and_its_examples_under_the_invocation(): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/regex'];

        try {
            $page = self::commandHelp('format');
            $overview = self::commandHelp(null);
        } finally {
            if (null === $originalArgv) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }

        $this->assertStringContainsString(
            "  Prints the pattern alone, so that it can be piped or pasted back. A pattern that fits the width comes back unchanged.\n"
            ."  The output matches exactly what the input matches. A pattern it cannot lay out safely comes back as written, with one note on stderr.\n",
            $page,
        );
        $this->assertSame([
            "bin/regex format '/^(?<year>\\d{4})-(?<month>\\d{2})$/' --width=24" => '# Lay a pattern out over lines',
            'bin/regex format "$(cat pattern.txt)" --compact' => '# Compact a laid-out pattern to one line',
            "bin/regex format '/^(?<year>\\d{4})-(?<month>\\d{2})$/' --json" => '# JSON output',
        ], self::section($page, 'Examples'));
        $this->assertSame('# Lay a pattern out over lines', self::section($overview, 'Examples')["bin/regex format '/^(?<year>\\d{4})-(?<month>\\d{2})$/' --width=24"] ?? null);
    }

    /**
     * In an example, the command name is colored as a command, as it is in
     * the usage line.
     */
    #[Test]
    public function test_format_help_colors_the_command_name_in_its_examples(): void
    {
        ob_start();
        (new HelpCommand())->run(new Input('help', ['format'], new GlobalOptions(false, null, false, false, null, null), []), OutputFactory::create(ansi: true));
        $text = (string) ob_get_clean();

        $examples = array_values(array_filter(explode("\n", $text), static fn (string $line): bool => str_contains($line, '--width=24')));
        $this->assertCount(1, $examples);
        $this->assertStringContainsString(' '.Output::YELLOW.Output::BOLD.'format'.Output::RESET.' ', $examples[0]);
    }

    /**
     * `transpile --target=html` writes an HTML pattern attribute: the page
     * and the overview name the three targets, each one the command takes.
     */
    #[Test]
    public function test_transpile_help_names_every_target(): void
    {
        $page = self::section(self::commandHelp('transpile'), 'Options')['--target <dialect>'] ?? null;

        $this->assertSame('Target dialect (js, html, python)', $page);

        $registry = new TargetRegistry();
        foreach (['js', 'html', 'python'] as $target) {
            $this->assertContains($target, $registry->listTargets());
        }
    }

    /**
     * The binary keeps nothing between runs but in the directory
     * "--cache-dir" names, which removing empties, so there is no
     * clear-cache command: the overview does not list one and its page is
     * an unknown command's.
     */
    #[Test]
    public function test_help_documents_no_clear_cache_command(): void
    {
        $this->assertStringNotContainsString('clear-cache', self::commandHelp(null));

        $output = OutputFactory::create();
        ob_start();
        $exitCode = (new HelpCommand())->run(new Input('help', ['clear-cache'], new GlobalOptions(false, null, false, false, null, null), []), $output);
        ob_end_clean();

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Unknown command: clear-cache', OutputFactory::errors($output));
    }

    /**
     * `regex compare '/(?=a)\w/' '/a/' --method=equivalence` passes (the two
     * are equivalent) and `/^a$/m` is compared, while a backreference,
     * recursion or an atomic group is refused: the note says what the solver
     * reads, not what 1.x refused.
     */
    #[Test]
    public function test_compare_help_names_what_the_solver_reads_and_refuses(): void
    {
        $data = (new \ReflectionMethod(new HelpCommand(), 'getCommandData'))->invoke(new HelpCommand(), 'compare');

        $this->assertIsArray($data);
        $this->assertSame(
            ['Compares the regular subset: lookarounds, \b and anchors are read; backreferences, recursion and atomic groups are refused.'],
            $data['notes'],
        );
    }

    /**
     * The help pages teach what 2.0 reads, never what 1.x did: the word
     * "1.x" appears on no page, the lint page's baseline options included —
     * a 1.x baseline is refused, not read, and the copy does not claim
     * otherwise.
     *
     * Pins an invariant that already holds: the copy never carried the
     * 1.x-baseline claim (the runtime note in LintCommand did, and the
     * baseline refusal tests cover its removal).
     */
    #[Test]
    public function test_no_help_page_claims_a_1_x_behaviour(): void
    {
        $pages = [self::commandHelp(null)];
        foreach (['lint', 'compare', 'analyze', 'debug', 'redos', 'diagram', 'explain', 'parse', 'transpile', 'validate', 'highlight', 'graph', 'format', 'version', 'help'] as $command) {
            $pages[] = self::commandHelp($command);
        }

        foreach ($pages as $page) {
            $this->assertStringNotContainsString('1.x', $page);
        }
    }

    #[Test]
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
        $this->assertCount(23, $data['options']);
        $this->assertContains(['--json', 'Same as --format=json'], $data['options']);
        $this->assertCount(4, $data['notes']);
    }

    #[Test]
    public function test_get_command_data_returns_null_for_invalid_command(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('getCommandData');

        $data = $method->invoke($command, 'nonexistent');

        $this->assertNull($data);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function test_format_command_usage_for_lint(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatCommandUsage');

        $usage = $method->invoke($command, $output, 'regex', 'lint', LintCommand::options());

        $this->assertIsString($usage);
        $this->assertStringContainsString('regex', (string) $usage);
        $this->assertStringContainsString('lint', (string) $usage);
        $this->assertStringContainsString('[options]', (string) $usage);
        $this->assertStringContainsString('<path>', (string) $usage);
    }

    #[Test]
    public function test_format_command_usage_for_parse_analyze_and_commands_with_pattern(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatCommandUsage');

        $usage = $method->invoke($command, $output, 'regex', 'parse', ParseCommand::options());

        $this->assertIsString($usage);
        $this->assertStringContainsString('regex', (string) $usage);
        $this->assertStringContainsString('parse', (string) $usage);
        $this->assertStringContainsString('[options]', (string) $usage);
        $this->assertStringContainsString('<pattern>', (string) $usage);
    }

    #[Test]
    public function test_format_command_usage_for_help(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatCommandUsage');

        $usage = $method->invoke($command, $output, 'regex', 'help', HelpCommand::options());

        $this->assertIsString($usage);
        $this->assertStringContainsString('regex', (string) $usage);
        $this->assertStringContainsString('help', (string) $usage);
        $this->assertStringContainsString('[command]', (string) $usage);
        $this->assertStringNotContainsString('<pattern>', (string) $usage);
    }

    #[Test]
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

    #[Test]
    public function test_format_option_with_ansi_disabled(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatOption');

        $option = '--format <format>';
        $formatted = $method->invoke($command, $output, $option);

        $this->assertSame('--format <format>', $formatted);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function test_format_example_command_formats_command_and_tokens(): void
    {
        $command = new HelpCommand();
        $output = OutputFactory::create();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('formatExampleCommand');

        $tokens = ['regex', 'parse', "'/a+/'", '--validate'];
        $formatted = $method->invoke($command, $output, $tokens);

        $this->assertIsString($formatted);
        $this->assertStringContainsString('regex', (string) $formatted);
        $this->assertStringContainsString('parse', (string) $formatted);
        $this->assertStringContainsString("'/a+/'", (string) $formatted);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function test_is_placeholder_detects_placeholders(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPlaceholder');

        $this->assertTrue($method->invoke($command, '<format>'));
        $this->assertTrue($method->invoke($command, '<path>'));
        $this->assertTrue($method->invoke($command, '<ver>'));
    }

    #[Test]
    public function test_is_placeholder_returns_false_for_non_placeholders(): void
    {
        $command = new HelpCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('isPlaceholder');

        $this->assertFalse($method->invoke($command, '--format'));
        $this->assertFalse($method->invoke($command, '-v'));
        $this->assertFalse($method->invoke($command, 'format'));
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }
    }

    #[Test]
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

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideDocumentedCommands(): iterable
    {
        foreach (['parse', 'analyze', 'compare', 'explain', 'debug', 'redos', 'diagram', 'graph', 'highlight', 'validate', 'transpile', 'format', 'lint', 'lsp', 'version', 'self-update'] as $command) {
            yield $command => ['command' => $command];
        }
    }

    /**
     * In the examples of a command's page, the name of the command takes
     * the colour of a command, whichever command it is.
     */
    #[Test]
    #[DataProvider('provideDocumentedCommands')]
    public function test_the_examples_colour_the_name_of_every_documented_command(string $command): void
    {
        $originalArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['bin/regex'];

        try {
            $input = new Input('help', [$command], new GlobalOptions(false, null, false, false, null, null), []);
            ob_start();
            (new HelpCommand())->run($input, OutputFactory::create(ansi: true));
            $text = (string) ob_get_clean();
        } finally {
            if (null === $originalArgv) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $originalArgv;
            }
        }

        $this->assertStringContainsString(
            Output::BLUE.Output::BOLD.'bin/regex'.Output::RESET.' '.Output::YELLOW.Output::BOLD.$command.Output::RESET,
            $text,
        );
    }

    /**
     * The text of `help` (no command: the overview) or `help <command>`.
     */
    private static function commandHelp(?string $command): string
    {
        $input = new Input('help', null === $command ? [] : [$command], new GlobalOptions(false, null, false, false, null, null), []);

        ob_start();
        (new HelpCommand())->run($input, OutputFactory::create());

        return (string) ob_get_clean();
    }

    /**
     * The rows of a two-column section of a help page: left cell => right cell.
     *
     * @return array<string, string>
     */
    private static function section(string $text, string $title): array
    {
        $lines = preg_split('/\R/', $text) ?: [];
        $start = array_search($title.':', $lines, true);
        self::assertIsInt($start, 'No "'.$title.':" section.');

        $rows = [];
        for ($i = $start + 1; isset($lines[$i]) && '' !== trim($lines[$i]); $i++) {
            if (1 === preg_match('/^  (\S.*?)  +(\S.*)$/', $lines[$i], $row)) {
                $rows[$row[1]] = $row[2];
            }
        }

        return $rows;
    }

    /**
     * The option names of a section's left cells: "-v, --verbose" gives -v
     * and --verbose, "--jobs <n>" gives --jobs.
     *
     * @param array<string, string> $rows
     *
     * @return list<string>
     */
    private static function optionNames(array $rows): array
    {
        $names = [];
        foreach (array_keys($rows) as $left) {
            preg_match_all('/(?<![\w<-])--?[a-z][a-z0-9-]*/', $left, $matches);
            array_push($names, ...$matches[0]);
        }

        return $names;
    }
}
