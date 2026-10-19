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

use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DebugCommandEdgeCasesTest extends TestCase
{
    public function test_debug_command_renders_unknown_severity_details(): void
    {
        $command = new DebugCommand();
        $input = new Input(
            'debug',
            ['/(a/'],
            new GlobalOptions(false, false, false, true, null, null),
            [],
        );
        $output = OutputFactory::create();

        $exitCode = 0;
        $buffer = $this->captureOutput(static function () use ($command, $input, $output, &$exitCode): void {
            $exitCode = $command->run($input, $output);
        });

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Error:', $buffer);
        $this->assertStringContainsString('UNKNOWN', $buffer);
    }

    /**
     * A critical finding is red when the verdict stands confirmed, yellow
     * otherwise; a medium one is yellow either way. "(a+)+$" fails on
     * a…a! from 19 pumps (PCRE2 10.49, JIT off).
     */
    #[DataProvider('provideFindingColours')]
    public function test_debug_command_colours_each_finding_by_the_verdict(string $mode, string $critical): void
    {
        $command = new DebugCommand();
        $input = new Input('debug', ['/(a+)+$/', '--redos-mode='.$mode], new GlobalOptions(false, false, false, true, null, null), []);
        $output = OutputFactory::create(true);

        $buffer = $this->captureOutput(static function () use ($command, $input, $output): void {
            $command->run($input, $output);
        });

        $this->assertStringContainsString('- ['.Output::YELLOW.'MEDIUM'.Output::RESET.'] Unbounded quantifier', $buffer);
        $this->assertStringContainsString('- ['.$critical.'CRITICAL'.Output::RESET.'] Nested unbounded quantifiers', $buffer);
    }

    /**
     * @return iterable<string, array{mode: string, critical: string}>
     */
    public static function provideFindingColours(): iterable
    {
        yield 'reproduced' => ['mode' => 'confirmed', 'critical' => Output::RED];
        yield 'theoretical' => ['mode' => 'theoretical', 'critical' => Output::YELLOW];
    }

    /**
     * The banner names the command as it is typed, as every other command's
     * banner does.
     */
    public function test_debug_banner_names_the_command_in_lower_case(): void
    {
        $output = OutputFactory::create();
        $input = new Input('debug', ['/a/'], new GlobalOptions(false, false, false, true, null, null), []);

        $buffer = $this->captureOutput(static function () use ($input, $output): void {
            (new DebugCommand())->run($input, $output);
        });

        $this->assertMatchesRegularExpression('/^Command\s*: debug$/m', $buffer);
    }

    /**
     * A pattern that does not parse is reported by its message, as the
     * other commands report it, without the class of the exception.
     */
    public function test_debug_reports_a_pattern_that_does_not_parse_by_its_message(): void
    {
        $output = OutputFactory::create();
        $input = new Input('debug', ['/(/'], new GlobalOptions(false, false, false, true, null, null), []);

        $exitCode = 0;
        $buffer = $this->captureOutput(static function () use ($input, $output, &$exitCode): void {
            $exitCode = (new DebugCommand())->run($input, $output);
        });

        $this->assertSame(1, $exitCode);
        $this->assertMatchesRegularExpression('/^  Error:\s+Expected \) at end of input \(found eof\)$/m', $buffer);
        $this->assertStringNotContainsString('Exception', $buffer);
    }

    /**
     * @return iterable<string, array{args: list<string>, error: string}>
     */
    public static function provideOptionErrors(): iterable
    {
        yield 'unknown mode after a space' => ['args' => ['/a/', '--redos-mode', 'sometimes'], 'error' => 'Invalid value for --redos-mode.'];
        yield 'mode missing' => ['args' => ['/a/', '--redos-mode'], 'error' => 'Missing value for --redos-mode.'];
        yield 'threshold missing' => ['args' => ['/a/', '--redos-threshold', '--input'], 'error' => 'Missing value for --redos-threshold.'];
        yield 'unknown threshold after a space' => ['args' => ['/a/', '--redos-threshold', 'severe'], 'error' => 'Invalid value for --redos-threshold: '];
    }

    /**
     * An option without its value, or with one it does not take, stops
     * the command before the pattern is read, with the message and the
     * usage.
     *
     * @param list<string> $args
     */
    #[DataProvider('provideOptionErrors')]
    public function test_debug_refuses_an_option_without_a_value_it_takes(array $args, string $error): void
    {
        $output = OutputFactory::create();
        $input = new Input('debug', $args, new GlobalOptions(false, false, false, true, null, null), []);

        $exitCode = 0;
        $buffer = $this->captureOutput(static function () use ($input, $output, &$exitCode): void {
            $exitCode = (new DebugCommand())->run($input, $output);
        });

        $written = $buffer.OutputFactory::errors($output);
        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString($error, $written);
        $this->assertStringContainsString('Usage: regex debug <pattern>', $written);
    }

    /**
     * @param callable(): void $callback
     */
    private function captureOutput(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
