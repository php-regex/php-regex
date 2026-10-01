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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Atomic groups and possessive quantifiers.
 *
 * A body that is a single run over one character set is modelled exactly:
 * it reads the longest run, one deterministic step. Any other body is kept
 * as written, listed in the abstractions, and a verdict whose pump crosses it
 * is not a proof: PCRE commits to the body's first way and never tries the
 * others.
 *
 * Engine figures: PHP 8.4.26 / PCRE2 10.49, pcre.backtrack_limit 1000000.
 */
final class RedosAtomicAbstractionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCrossedAtomicBodies')]
    public function test_pump_through_an_over_approximated_atomic_body_is_heuristic(string $pattern, int $offset): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertNull($analysis->error, $pattern);
        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
        $this->assertSame(self::heuristicSeverity($pattern), $analysis->severity, $pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern);
        $this->assertAtomicAbstractionAt($offset, $analysis, $pattern);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideCrossedAtomicBodies(): iterable
    {
        // a…a! never fails up to 64 bytes: the atomic group commits to the first "a" each time.
        yield 'ambiguous loop inside an atomic group' => ['/(?>(a|a)+)$/', 0];
        // x a…a! never fails up to 64 bytes.
        yield 'ambiguous loop inside an atomic group after a literal' => ['/x(?>(a|a)+)$/', 1];
        // a…a! never fails up to 64 bytes: each atomic step reads one "a", one way.
        yield 'atomic alternation repeated' => ['/(?>a|a)+$/', 0];
    }

    /**
     * X++ is (?>X+) in PCRE: the possessive body gets the atomic entry
     * ("atomic group at offset N over-approximated"). a…a! and x a…a!
     * never fail up to 64 bytes on the engine.
     */
    #[Test]
    #[DataProvider('provideCrossedPossessiveBodies')]
    public function test_pump_through_an_over_approximated_possessive_body_is_heuristic(string $pattern, int $offset): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
        $this->assertSame(self::heuristicSeverity($pattern), $analysis->severity, $pattern);
        $this->assertNotSame([], $analysis->abstractions, $pattern);
        $this->assertAtomicAbstractionAt($offset, $analysis, $pattern);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideCrossedPossessiveBodies(): iterable
    {
        yield 'possessive alternation' => ['/(a|a)++$/', 0];
        yield 'possessive alternation after a literal' => ['/x(a|a)++$/', 1];
    }

    /**
     * The pump "c" never enters the atomic group: the proof holds and the
     * abstraction is still listed. ab c…c! fails at n=19 on the engine.
     */
    #[Test]
    public function test_over_approximated_atomic_body_outside_the_pump_keeps_the_proof(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(?>ab|a)(c+)+$/');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertAtomicAbstractionAt(0, $analysis, '/(?>ab|a)(c+)+$/');
    }

    #[Test]
    #[DataProvider('provideSingleRunBodies')]
    public function test_single_run_atomic_body_keeps_an_exact_proven_verdict(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertTrue($analysis->isProvenSafe(), $pattern);
        $this->assertSame([], $analysis->abstractions, $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSingleRunBodies(): iterable
    {
        // a…a! never fails up to 64 bytes for any of them.
        yield 'atomic run' => ['/(?>a+)+$/'];
        yield 'possessive run' => ['/(a++)+$/'];
        yield 'atomic run over a class' => ['/(?>[ab]+)+$/'];
    }

    /**
     * Engine facts behind the rows above, on the CI matrix.
     */
    #[Test]
    #[DataProvider('provideEngineFacts')]
    public function test_engine_fact(string $pattern, string $prefix, string $pump, string $suffix, bool $fails): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $failing = false;
            for ($n = 1; !$failing && \strlen($prefix.str_repeat($pump, $n).$suffix) <= 64; $n++) {
                $failing = false === @preg_match($pattern, $prefix.str_repeat($pump, $n).$suffix);
            }
        } finally {
            if (false !== $limit) {
                ini_set('pcre.backtrack_limit', $limit);
            }
        }

        $this->assertSame($fails, $failing, $pattern);
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function provideEngineFacts(): iterable
    {
        yield 'ambiguous loop inside an atomic group' => ['/(?>(a|a)+)$/', '', 'a', '!', false];
        yield 'ambiguous loop inside an atomic group after a literal' => ['/x(?>(a|a)+)$/', 'x', 'a', '!', false];
        yield 'atomic alternation repeated' => ['/(?>a|a)+$/', '', 'a', '!', false];
        yield 'possessive alternation' => ['/(a|a)++$/', '', 'a', '!', false];
        yield 'possessive alternation after a literal' => ['/x(a|a)++$/', 'x', 'a', '!', false];
        yield 'atomic group before the loop, ab c…c! fails at n=19' => ['/(?>ab|a)(c+)+$/', 'ab', 'c', '!', true];
        yield 'atomic run' => ['/(?>a+)+$/', '', 'a', '!', false];
        yield 'possessive run' => ['/(a++)+$/', '', 'a', '!', false];
        yield 'atomic run over a class' => ['/(?>[ab]+)+$/', '', 'a', '!', false];
    }

    /**
     * Exactly one entry names the atomic group with its offset:
     * "atomic group at offset N over-approximated".
     */
    private function assertAtomicAbstractionAt(int $offset, RedosAnalysis $analysis, string $pattern): void
    {
        $atomic = array_values(array_filter(
            $analysis->abstractions,
            static fn (string $entry): bool => str_contains($entry, 'atomic'),
        ));

        $this->assertCount(1, $atomic, $pattern.': '.json_encode($analysis->abstractions));
        $this->assertSame('atomic group at offset '.$offset.' over-approximated', $atomic[0], $pattern);
    }

    /**
     * What the structural heuristics alone say about a pattern.
     */
    private static function heuristicSeverity(string $pattern): RedosSeverity
    {
        $ast = RegexParser::create()->parse($pattern);
        $profiler = new RedosProfiler(new CharSetAnalyzer($ast->flags));
        $ast->accept($profiler);

        return $profiler->getResult()['severity'];
    }
}
