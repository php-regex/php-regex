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

use PHPRegex\Cli\Application;
use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function test_register_adds_aliases(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $command = new DummyCommand('lint', ['check']);
        $app->register($command);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', 'check'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $command->runs);
        $this->assertInstanceOf(Input::class, $command->lastInput);
        $this->assertSame('check', $command->lastInput->command);
    }

    public function test_run_with_help_option_invokes_help_command(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--help'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $help->runs);
    }

    public function test_run_with_missing_command_shows_help(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex'], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $help->runs);
    }

    public function test_run_with_unknown_command_outputs_error_and_help(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', 'unknown'], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $help->runs);
        $this->assertStringContainsString('Unknown command', $buffer);
    }

    public function test_run_with_pattern_uses_highlight_command(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $highlight = new DummyCommand('highlight');
        $app = new Application(new GlobalOptionsParser(), $output, $help);
        $app->register($highlight);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '/a+/'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $highlight->runs);
        $this->assertInstanceOf(Input::class, $highlight->lastInput);
        $this->assertSame('/a+/', $highlight->lastInput->args[0]);
    }

    public function test_run_reports_global_option_errors(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--php-version', '--help'], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Missing value for --php-version', $buffer);
        $this->assertSame(0, $help->runs);
    }

    public function test_resolve_ansi_honors_forced_value_and_fallback(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $method = new \ReflectionMethod(Application::class, 'shouldUseAnsi');

        $this->assertTrue($method->invoke($app, true));
        $this->assertFalse($method->invoke($app, false));

        $fallback = $method->invoke($app, null);
        $this->assertIsBool($fallback);
    }

    public function test_run_with_empty_command_name_shows_help(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $app = new Application(new GlobalOptionsParser(), $output, $help);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', ''], $buffer);

        $this->assertSame(2, $exitCode);
        $this->assertSame(1, $help->runs);
    }

    public function test_run_with_php_version_option_sets_regex_options(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $command = new DummyCommand('test');
        $app = new Application(new GlobalOptionsParser(), $output, $help);
        $app->register($command);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--php-version=8.1', 'test'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $command->runs);
        $this->assertInstanceOf(Input::class, $command->lastInput);
        $this->assertSame(['php_version' => '8.1'], $command->lastInput->regexOptions);
    }

    public function test_run_with_pcre_version_option_sets_regex_options(): void
    {
        $output = new Output(false, false, errorStream: fopen('php://memory', 'w+'));
        $help = new DummyCommand('help');
        $command = new DummyCommand('test');
        $app = new Application(new GlobalOptionsParser(), $output, $help);
        $app->register($command);

        $buffer = '';
        $exitCode = $this->runApp($app, ['regex', '--php-version=8.4', '--pcre-version', '10.42', 'test'], $buffer);

        $this->assertSame(0, $exitCode);
        $this->assertInstanceOf(Input::class, $command->lastInput);
        $this->assertSame(['php_version' => '8.4', 'pcre_version' => '10.42'], $command->lastInput->regexOptions);
    }

    /**
     * @param array<int, string> $argv
     */
    private function runApp(Application $app, array $argv, string &$buffer): int
    {
        ob_start();
        $exitCode = $app->run($argv);
        $buffer = (string) ob_get_clean();

        return $exitCode;
    }
}

final class DummyCommand implements CommandInterface
{
    public int $runs = 0;

    public ?Input $lastInput = null;

    /**
     * @param array<int, string> $aliases
     */
    public function __construct(
        private readonly string $name,
        private readonly array $aliases = [],
        private readonly int $exitCode = 0,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getDescription(): string
    {
        return 'dummy';
    }

    public function run(Input $input, Output $output): int
    {
        $this->runs++;
        $this->lastInput = $input;

        return $this->exitCode;
    }
}
