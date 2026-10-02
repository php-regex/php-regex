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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for optimizer safety to prevent semantic changes.
 */
final class OptimizerSafetyTest extends TestCase
{
    /**
     * @param array{digits?: bool, word?: bool, ranges?: bool, possessive?: bool, factorize?: bool} $options
     */
    #[DataProvider('provideOptimizationCases')]
    public function test_optimization_does_not_change_semantics(string $input, string $expected, array $options): void
    {
        $optimized = Regex::create()->optimize($input, $options)->optimized;

        $this->assertSame($expected, $optimized);
    }

    /**
     * An atomicity-introducing rewrite the solver cannot verify must not
     * ship: the safety net answers null when the LanguageSolver refuses the
     * possessive form — the atom of "(?:ab|a)++" is multi-character, out of
     * the disjoint-follower rule — and an unverified possessive can change
     * the language PCRE matches. Verified possessives still ship: "a++b"
     * holds, its follower is disjoint.
     */
    #[Test]
    public function test_an_unverifiable_atomic_rewrite_is_kept_back(): void
    {
        $options = ['possessive' => true];

        $this->assertSame(
            '/(?:ab|a)+c/',
            Regex::create()->optimize('/(?:ab|a)+c/', $options)->optimized,
            'the solver refuses this possessive; the rewrite must not ship unverified',
        );
        $this->assertSame(
            '/(?:foo|bar)+!/',
            Regex::create()->optimize('/(?:foo|bar)+!/', $options)->optimized,
        );

        $this->assertSame(
            '/a++b/',
            Regex::create()->optimize('/a+b/', $options)->optimized,
            'the solver verifies this one; it ships',
        );
    }

    /**
     * The marker walk behind the gate: an atomic group counts like a
     * possessive quantifier — no rule emits one today, the gate is ready
     * for the one that will — and a pattern the parser refuses reads as
     * maximally atomic: an unparseable rewrite never ships.
     */
    #[Test]
    public function test_the_atomicity_marker_walk_counts_every_form(): void
    {
        $optimizer = new \ReflectionMethod(Optimizer::class, 'atomicityMarkers');
        $on = Optimizer::class;
        $instance = (new \ReflectionClass(Optimizer::class))->newInstance(Regex::create()->parser());

        $this->assertSame(0, $optimizer->invoke($instance, '/(a+)b/'));
        $this->assertSame(1, $optimizer->invoke($instance, '/(?>a+)b/'));
        $this->assertSame(2, $optimizer->invoke($instance, '/(?>a+)b++/'));
        $this->assertSame(2, $optimizer->invoke($instance, '/a++b++/'));
        $this->assertSame(\PHP_INT_MAX, $optimizer->invoke($instance, '/['));
    }

    /**
     * @return \Generator<string, array{string, string, array<mixed>}>
     */
    public static function provideOptimizationCases(): \Generator
    {
        // --- 1. Sanity Checks (No Change Expected) ---
        yield 'Different literals' => ['/a|b/', '/[ab]/', ['possessive' => true]];
        yield 'Distinct ranges' => ['/[a-z]|[0-9]/', '/[a-z0-9]/', ['possessive' => true]];
        yield 'Distinct words' => ['/fo{2}|bar/', '/fo{2}|bar/', ['possessive' => true]];

        // --- 2. The Regression Case (CRITICAL) ---
        // Ensure distinct patterns are NOT deduplicated
        yield 'Distinct patterns with different quantifiers' => ['/[A-Z]{2,}|[a-z]/', '/[A-Z]{2,}|[a-z]/', ['possessive' => true]];
        yield 'Distinct literals with same length' => ['/abc|def/', '/abc|def/', ['possessive' => true]];

        // --- 3. Sequence Compaction (Safe) ---
        yield 'Repeat literal 4 times' => ['/aaaa/', '/a{4}/', ['possessive' => true]];
        yield 'Repeat literal 3 times stays unchanged' => ['/aaa/', '/aaa/', ['possessive' => true]];
        yield 'Repeat literal 2 times stays unchanged' => ['/aa/', '/aa/', ['possessive' => true]];

        // --- 4. Character Class Optimization (Safe) ---
        yield 'Digits to char type' => ['/[0-9]/', '/\d/', ['possessive' => true]];
        yield 'Word to char type' => ['/[a-zA-Z0-9_]/', '/\w/', ['possessive' => true]];

        // --- 5. Group Unwrapping (Safe) ---
        yield 'Unwrap non-capturing group' => ['/(?:abc)/', '/abc/', ['possessive' => true]];

        // --- 6. Prefix Factorization (Safe) ---
        yield 'Prefix factorization disabled by default' => ['/ab|ac/', '/ab|ac/', ['possessive' => true]];

        // --- 7. Safety First ---
        // Scenario A: Capturing groups prevent compaction
        yield 'Capturing groups block compaction' => ['/(?:(a)b)(?:(a)b)/', '/(?:(a)b)(?:(a)b)/', ['possessive' => true]];
        // Scenario B: Non-capturing groups allow compaction
        yield 'Non-capturing groups allow compaction but count < 4' => ['/(?:ab)(?:ab)/', '/abab/', ['possessive' => true]];
        // Scenario C: Alternation factorization disabled by default
        yield 'Alternation factorization disabled' => ['/(a)b|(c)b/', '/(a)b|(c)b/', ['possessive' => true]];

        // Regression tests for specific cases from audit
        yield 'WIN|WINDOWS alternation not factorized' => ['/(WIN|WINDOWS)(\d+)/', '/(WIN|WINDOWS)(\d+)/', []];
        yield 'a|ab alternation not factorized' => ['/(a|ab)/', '/(a|ab)/', []];
        yield 'possessive disabled by default' => ['/\d+/', '/\d+/', []];
    }
}
