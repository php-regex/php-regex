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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A refusal that holds for the pattern as a whole, not for one of its
 * parts, points at its start.
 */
final class WholePatternRefusalTest extends TestCase
{
    use PhpUnitForwardCompat;

    /**
     * @param \Closure(LanguageSolver): mixed $ask
     */
    #[Test]
    #[DataProvider('provideWholePatternRefusals')]
    public function test_a_refusal_of_the_whole_pattern_points_at_its_start(\Closure $ask, string $message): void
    {
        try {
            $ask(new LanguageSolver());

            $this->fail('The solver answers.');
        } catch (ComplexityException $refusal) {
            $this->assertStringContainsString($message, $refusal->getMessage());
            $this->assertSame(0, $refusal->position);
        }
    }

    /**
     * @return iterable<string, array{ask: \Closure(LanguageSolver): mixed, message: string}>
     */
    public static function provideWholePatternRefusals(): iterable
    {
        $between = 'may skip a match that starts between "\r" and "\n"';
        yield 'a possessive inside a group a call runs' => [
            'ask' => static fn (LanguageSolver $solver): mixed => $solver->matchEquivalent('/(a+)c(?1)d/', '/(a++)c(?1)d/'),
            'message' => 'PCRE2\'s JIT',
        ];
        yield 'a match that may start between cr and lf, compared' => [
            'ask' => static fn (LanguageSolver $solver): mixed => $solver->matchEquivalent('/(*ANYCRLF)\na/', '/(*ANYCRLF)[\n]a/'),
            'message' => $between,
        ];
        yield 'a search that may start between cr and lf, compiled' => [
            'ask' => static fn (LanguageSolver $solver): mixed => $solver->compile('/(*ANYCRLF)^\n/m', new SolverOptions(matchMode: MatchMode::Partial)),
            'message' => $between,
        ];
    }

    /**
     * Under (*ANYCRLF) and (*ANY) a match that starts with neither a "\n"
     * nor empty never starts between "\r" and "\n": the match solver reads
     * it.
     */
    #[Test]
    #[DataProvider('provideMatchesThatNeverStartBetweenCrAndLf')]
    public function test_a_match_that_cannot_start_between_cr_and_lf_is_compared(string $left, string $right): void
    {
        foreach (['', "\r", "\n", "\r\n", "a\r\n", "\r\na", "\ra\n", 'ab'] as $subject) {
            $this->assertSame(preg_match($left, $subject, $leftMatch), preg_match($right, $subject, $rightMatch), 'Oracle: '.json_encode($subject));
            $this->assertSame($leftMatch, $rightMatch, 'Oracle: '.json_encode($subject));
        }

        $this->assertTrue((new LanguageSolver())->matchEquivalent($left, $right)->isEquivalent);
    }

    /**
     * @return iterable<string, array{left: string, right: string}>
     */
    public static function provideMatchesThatNeverStartBetweenCrAndLf(): iterable
    {
        yield 'a letter under (*ANYCRLF)' => ['left' => '/(*ANYCRLF)ab/', 'right' => '/(*ANYCRLF)a(?:b)/'];
        yield 'a letter under (*ANY)' => ['left' => '/(*ANY)a\n?/', 'right' => '/(*ANY)a(?:\n)?/'];
    }
}
