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
use PHPRegex\Automata\Options\SolverStrategy;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The lazy search walks the product of the NFAs as it is reached; the eager
 * one the product of the minimized DFAs. Both answer the same, and give the
 * same witness: the shortest string, and of those the least.
 */
final class SolverStrategyParityTest extends TestCase
{
    use PhpUnitForwardCompat;

    private const CORPUS = [
        '/a/', '/b/', '/ab/', '/ba/', '/a*/', '/a+/', '/a?/', '/a{2}/', '/a{0,2}/', '/(a|b)/',
        '/(ab|ba)/', '/[ab]+/', '/a*ab/', '/(a|b)*a(a|b){2}/', '/^a|b$/', '/^\d{2,3}$/', '/x\d+y/',
        '/(?=a)\w+/', '/a(?!b)/', '/\bab\b/', '/^(?:ab)++c$/', '/[^a]+/', '/./s', '/^$/', '/k/i',
        '/^[\p{L}\p{N}]{1,3}$/u', '/^\w+$/u', '/é+/u', '/[\x{1F600}-\x{1F64F}]/u',
    ];

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideModes')]
    public function test_both_strategies_answer_alike_on_the_corpus(MatchMode $mode, array $patterns): void
    {
        $solver = new LanguageSolver();
        $eager = new SolverOptions(matchMode: $mode, strategy: SolverStrategy::Eager);
        $lazy = new SolverOptions(matchMode: $mode, strategy: SolverStrategy::Lazy);
        $compared = 0;

        foreach ($patterns as $left) {
            foreach ($patterns as $right) {
                // A pattern read as UTF-8 beside one read as bytes is read
                // eagerly by both strategies.
                if (str_ends_with($left, 'u') !== str_ends_with($right, 'u')) {
                    continue;
                }

                foreach (['intersection', 'subsetOf', 'equivalent'] as $method) {
                    $this->assertSame(
                        self::answer($solver, $method, $left, $right, $eager),
                        self::answer($solver, $method, $left, $right, $lazy),
                        \sprintf('%s(%s, %s) under %s', $method, $left, $right, $mode->name),
                    );
                    $compared++;
                }
            }
        }

        $this->assertGreaterThan(1_000, $compared);
    }

    /**
     * @return iterable<string, array{mode: MatchMode, patterns: list<string>}>
     */
    public static function provideModes(): iterable
    {
        yield 'full' => ['mode' => MatchMode::Full, 'patterns' => self::CORPUS];
        yield 'partial' => ['mode' => MatchMode::Partial, 'patterns' => self::CORPUS];
    }

    /**
     * Sixteen positions back from the end: the DFA of the left side holds
     * 2^16 states, past the default limit, where the product with "b+"
     * holds a handful of pairs.
     */
    #[Test]
    public function test_an_intersection_needs_no_determinization(): void
    {
        $left = '/(a|b)*a(a|b){15}/';
        $right = '/b+/';

        $result = (new LanguageSolver())->intersection($left, $right, new SolverOptions(maxDfaStates: 100));
        $this->assertTrue($result->isEmpty);

        $result = (new LanguageSolver())->intersection($left, '/[ab]+/', new SolverOptions(maxDfaStates: 1_000));
        $this->assertSame('a'.str_repeat('a', 15), $result->example);
        $this->assertSame(1, preg_match('/^(?:(a|b)*a(a|b){15})$/', (string) $result->example));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains('DFA state limit exceeded (10000).');
        (new LanguageSolver())->intersection($left, $right, new SolverOptions(strategy: SolverStrategy::Eager));
    }

    /**
     * An inclusion determinizes the right side only where the left one
     * leads it: "b+" never reaches the states that remember sixteen letters.
     */
    #[Test]
    public function test_an_inclusion_determinizes_only_what_the_left_side_reaches(): void
    {
        $result = (new LanguageSolver())->subsetOf('/b+/', '/(a|b)*a(a|b){15}|b+/', new SolverOptions(maxDfaStates: 100));

        $this->assertTrue($result->isSubset);
    }

    /**
     * The pairs a lazy search keeps are bounded as the eager ones are.
     */
    #[Test]
    public function test_the_lazy_comparison_keeps_its_pairs_bounded(): void
    {
        try {
            (new LanguageSolver())->intersection('/(?:a{11})+/', '/(?:a{13})+/', new SolverOptions(maxDfaStates: 40));
            $this->fail('Expected the comparison to keep more pairs than the limit.');
        } catch (ComplexityException $exception) {
            $this->assertSame(['phase' => 'comparison', 'limit' => 40], $exception->getDiagnostic());
        }

        try {
            (new LanguageSolver())->intersection('/(?:a{11})+/', '/(?:a{13})+/', new SolverOptions(maxTransitionsProcessed: 50));
            $this->fail('Expected the comparison to exceed its budget.');
        } catch (ComplexityException $exception) {
            $this->assertSame('comparison', $exception->getDiagnostic()['phase'] ?? null);
            $this->assertSame(50, $exception->getDiagnostic()['limit'] ?? null);
        }
    }

    private static function answer(LanguageSolver $solver, string $method, string $left, string $right, SolverOptions $options): string
    {
        try {
            return (string) json_encode(match ($method) {
                'intersection' => $solver->intersection($left, $right, $options),
                'subsetOf' => $solver->subsetOf($left, $right, $options),
                default => $solver->equivalent($left, $right, $options),
            });
        } catch (ComplexityException $exception) {
            return 'refused: '.$exception->getMessage();
        }
    }
}
