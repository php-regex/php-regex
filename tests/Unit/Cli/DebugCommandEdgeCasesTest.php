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
     * @param callable(): void $callback
     */
    private function captureOutput(callable $callback): string
    {
        ob_start();
        $callback();

        return (string) ob_get_clean();
    }
}
