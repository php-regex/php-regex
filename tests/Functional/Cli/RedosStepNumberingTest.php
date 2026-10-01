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
use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\Command\DebugCommand;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "[i/N]" markers of "regex analyze" and "regex debug" count the
 * sections actually printed: 1, 2, … N, each once, N the number of
 * numbered sections. Whether a section is numbered is the command's choice;
 * a number repeated or a total that is not the count is not.
 */
final class RedosStepNumberingTest extends TestCase
{
    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_step_markers_count_the_printed_sections(string $command, array $arguments): void
    {
        $buffer = $this->runCommand($command, $arguments);

        $this->assertNotFalse(preg_match_all('/^\s*\[(\d+)\/(\d+)\] (.+)$/m', $buffer, $markers, \PREG_SET_ORDER));
        $this->assertNotSame([], $markers, $buffer);

        $count = \count($markers);
        $steps = array_map(static fn (array $marker): int => (int) $marker[1], $markers);
        $totals = array_map(static fn (array $marker): int => (int) $marker[2], $markers);

        $this->assertSame(range(1, $count), $steps, $buffer);
        $this->assertSame(array_fill(0, $count, $count), $totals, $buffer);
    }

    /**
     * @return iterable<string, array{command: string, arguments: list<string>}>
     */
    public static function provideRuns(): iterable
    {
        yield 'analyze, theoretical' => ['command' => 'analyze', 'arguments' => ['/(a+)+$/']];
        // Replayed: a…a! fails at 19 pumps (PCRE2 10.49, JIT off), a Confirmation section is printed.
        yield 'analyze, confirmed' => ['command' => 'analyze', 'arguments' => ['/(a+)+$/', '--redos-mode=confirmed']];
        // Nothing to confirm on a safe pattern: no Confirmation section.
        yield 'analyze, confirmed, safe pattern' => ['command' => 'analyze', 'arguments' => ['/abc/', '--redos-mode=confirmed']];
        yield 'debug, theoretical' => ['command' => 'debug', 'arguments' => ['/(a+)+$/', '--redos-mode=theoretical']];
        // Heatmap, Confirmation and Findings.
        yield 'debug, confirmed' => ['command' => 'debug', 'arguments' => ['/(a+)+$/', '--redos-mode=confirmed']];
        // Not reproduced (a…a! and a…a!b never fail up to 64 bytes): still a Confirmation section.
        yield 'debug, confirmed, not reproduced' => ['command' => 'debug', 'arguments' => ['/(?=b)(a+)+$/', '--redos-mode=confirmed']];
        yield 'debug, confirmed, safe pattern' => ['command' => 'debug', 'arguments' => ['/abc/', '--redos-mode=confirmed']];
    }

    /**
     * @param list<string> $arguments
     */
    private function runCommand(string $command, array $arguments): string
    {
        $handler = self::command($command);
        $input = new Input($command, $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        $level = ob_get_level();
        ob_start();

        try {
            $handler->run($input, new Output(false, false));
        } finally {
            $buffer = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $buffer;
    }

    private static function command(string $name): CommandInterface
    {
        return 'debug' === $name ? new DebugCommand() : new AnalyzeCommand();
    }
}
