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
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The verdict lines of "regex analyze" and "regex debug": the headline
 * names the class and whether it was proven, never a bare "safe", and the
 * witness follows as "Attack: …". Exit code 1 only for a replayed verdict at
 * or above high.
 */
final class RedosVerdictConsoleTest extends TestCase
{
    private const OVER_BUDGET_CRITICAL = '/^(?:(?:a{16}){16}){16}(a+)+$/';

    /**
     * Over the budget with a heuristic finding: the heuristic headline, the
     * budget named in the detail, never "not analyzed".
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_console_verdict_budget_exceeded_with_a_heuristic_finding(string $command): void
    {
        [, $buffer] = $this->runCommand($command, [self::OVER_BUDGET_CRITICAL]);

        $this->assertStringContainsString('Potential backtracking (heuristic)', $buffer);
        $this->assertStringContainsString('budget exceeded', $buffer);
        $this->assertStringNotContainsString('not analyzed (budget exceeded)', $buffer);
    }

    #[Test]
    #[DataProvider('provideHeadlines')]
    public function test_console_verdict_headline(string $command, string $pattern, string $headline): void
    {
        [, $buffer] = $this->runCommand($command, [$pattern]);

        $this->assertStringContainsString($headline, $buffer);
    }

    /**
     * @return iterable<string, array{command: string, pattern: string, headline: string}>
     */
    public static function provideHeadlines(): iterable
    {
        foreach (['analyze', 'debug'] as $command) {
            yield $command.', proven exponential' => ['command' => $command, 'pattern' => '/(a+)+$/', 'headline' => 'Exponential backtracking (proven)'];
            yield $command.', proven polynomial of degree 3' => ['command' => $command, 'pattern' => '/a*a*a*$/', 'headline' => 'Polynomial backtracking, degree 3 (proven)'];
            // Out of the model, judged medium by the heuristics.
            yield $command.', heuristic risk' => ['command' => $command, 'pattern' => '/(a)\1+/', 'headline' => 'Potential backtracking (heuristic)'];
            // Out of the model, judged safe by the heuristics.
            yield $command.', heuristic without risk' => ['command' => $command, 'pattern' => '/(a)?(?(1)a|b)/', 'headline' => 'no risk found (heuristic)'];
            yield $command.', proven safe' => ['command' => $command, 'pattern' => '/(?>a+)+$/', 'headline' => 'safe (proven)'];
            // 200 alternatives of 20 states, over the 2,000-state budget; the heuristics find nothing.
            yield $command.', over the budget, no heuristic finding' => ['command' => $command, 'pattern' => self::overBudgetSafePattern(), 'headline' => 'not analyzed (budget exceeded)'];
            // 4,096 unrolled states then a nested loop the heuristics judge critical: their headline wins.
            yield $command.', over the budget, heuristic finding' => ['command' => $command, 'pattern' => self::OVER_BUDGET_CRITICAL, 'headline' => 'Potential backtracking (heuristic)'];
        }
    }

    #[Test]
    #[DataProvider('provideCommands')]
    public function test_console_verdict_attack_line(string $command): void
    {
        $witness = (new RedosAnalyzer())->analyze('/(a+)+$/')->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);

        [, $buffer] = $this->runCommand($command, ['/(a+)+$/']);

        $this->assertStringContainsString('Attack: '.$witness->render(), $buffer);
    }

    #[Test]
    #[DataProvider('provideCommands')]
    public function test_console_verdict_replayed_line(string $command): void
    {
        [, $buffer] = $this->runCommand($command, ['/(a+)+$/', '--redos-mode=confirmed']);

        $this->assertMatchesRegularExpression(
            '/Replayed on PCRE2 '.preg_quote(self::pcreRelease(), '/').': preg_match fails from length (\d+) \(backtrack_limit (\d+), JIT off\)/',
            $buffer,
        );
        if (1 !== preg_match('/fails from length (\d+) \(backtrack_limit (\d+), JIT off\)/', $buffer, $line)) {
            $this->fail('No replay line: '.$buffer);
        }

        // "length N" counts the bytes of the replayed input: the shortest build of the
        // published witness that fails with the JIT off at the replay's backtrack limit
        // (17 bytes, a…a!, at the default 100,000 on 10.49; 20 bytes at PHP's 1,000,000).
        $witness = (new RedosAnalyzer())->analyze('/(a+)+$/', null, RedosMode::Confirmed)->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);
        $this->assertSame((int) $line[1], self::firstFailingLength('/(*NO_JIT)(a+)+$/', $witness, $line[2]));
    }

    /**
     * (?=b) and a+ never hold together, so PCRE never backtracks (a…a! and
     * a…a!b never fail up to 64 bytes on 10.49); the model ignores the
     * lookahead's constraint and keeps an exponential verdict.
     */
    #[Test]
    #[DataProvider('provideCommands')]
    public function test_console_verdict_not_reproduced_line(string $command): void
    {
        [$exitCode, $buffer] = $this->runCommand($command, ['/(?=b)(a+)+$/', '--redos-mode=confirmed']);

        $this->assertStringContainsString('Not reproduced on PCRE2 '.self::pcreRelease()." (PCRE's optimisations defuse it)", $buffer);
        $this->assertSame(0, $exitCode, $buffer);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideExitCodes')]
    public function test_console_verdict_exit_code(string $command, array $arguments, int $exitCode): void
    {
        [$code, $buffer] = $this->runCommand($command, $arguments);

        $this->assertSame($exitCode, $code, $buffer);
    }

    /**
     * @return iterable<string, array{command: string, arguments: list<string>, exitCode: int}>
     */
    public static function provideExitCodes(): iterable
    {
        foreach (['analyze', 'debug'] as $command) {
            yield $command.', theoretical exponential' => ['command' => $command, 'arguments' => ['/(a+)+$/'], 'exitCode' => 0];
            yield $command.', replayed exponential' => ['command' => $command, 'arguments' => ['/(a+)+$/', '--redos-mode=confirmed'], 'exitCode' => 1];
            // High, but a polynomial verdict is never replayed.
            yield $command.', confirmed cubic' => ['command' => $command, 'arguments' => ['/a*a*a*$/', '--redos-mode=confirmed'], 'exitCode' => 0];
            yield $command.', proven safe, confirmed' => ['command' => $command, 'arguments' => ['/(?>a+)+$/', '--redos-mode=confirmed'], 'exitCode' => 0];
        }
    }

    /**
     * @return iterable<string, array{command: string}>
     */
    public static function provideCommands(): iterable
    {
        yield 'analyze' => ['command' => 'analyze'];
        yield 'debug' => ['command' => 'debug'];
    }

    private static function firstFailingLength(string $pattern, RedosWitness $witness, string $backtrackLimit): ?int
    {
        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', $backtrackLimit);

        try {
            for ($n = 1; \strlen($witness->build($n)) <= 4096; $n++) {
                if (false === @preg_match($pattern, $witness->build($n))) {
                    return \strlen($witness->build($n));
                }
            }

            return null;
        } finally {
            ini_set('pcre.backtrack_limit', false === $previous ? '1000000' : $previous);
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runCommand(string $command, array $arguments): array
    {
        $handler = self::command($command);
        $input = new Input($command, $arguments, new GlobalOptions(false, false, false, true, null, null), []);

        $level = ob_get_level();
        ob_start();

        try {
            $exitCode = $handler->run($input, OutputFactory::create());
        } finally {
            $buffer = (string) ob_get_clean();
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exitCode, $buffer];
    }

    private static function command(string $name): CommandInterface
    {
        return 'debug' === $name ? new DebugCommand() : new AnalyzeCommand();
    }

    private static function overBudgetSafePattern(): string
    {
        return '/^(?:'.implode('|', array_map(static fn (int $i): string => \sprintf('k%03d\d{16}', $i), range(0, 199))).')$/';
    }

    private static function pcreRelease(): string
    {
        return explode(' ', \PCRE_VERSION)[0];
    }
}
