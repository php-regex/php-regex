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

    /**
     * The possessifier's charset overlap is computed from the outer flags
     * only, so an inline (?s:...) scope would look disjoint from a newline
     * follower while the engine's dot still matches it. Refuse instead of
     * rewriting: `(?:(?s:.))++` stops before the newline the group could
     * have consumed.
     */
    #[Test]
    public function test_possessify_refuses_inline_flag_scopes(): void
    {
        $optimized = Regex::create(['cache' => null])->optimize(
            '/(?:(?s:.))+\n/',
            ['possessive' => true, 'verify_with_automata' => false],
        )->optimized;

        $this->assertSame('/(?:(?s:.))+\n/', $optimized);
    }

    /**
     * The refusal holds wherever the inline scope hides — directly under
     * the quantifier, inside a sequence, an alternation or a conditional —
     * while a clean shape still possessifies.
     *
     * @param array<string, bool|string> $options
     */
    #[DataProvider('provideInlineFlagScopeShapes')]
    #[Test]
    public function test_possessify_refuses_inline_flag_scopes_at_any_depth(string $input, array $options): void
    {
        $expected = isset($options['expected']) ? (string) $options['expected'] : $input;
        unset($options['expected']);

        $optimized = Regex::create(['cache' => null])->optimize(
            $input,
            $options + ['verify_with_automata' => false],
        )->optimized;

        $this->assertSame($expected, $optimized);
    }

    /**
     * @return iterable<string, array{input: string, options: array<string, bool|string>}>
     */
    public static function provideInlineFlagScopeShapes(): iterable
    {
        yield 'scoped group under the quantifier' => [
            'input' => '/(?:(?s:a))+\n/',
            'options' => ['possessive' => true],
        ];

        yield 'scoped group inside a sequence' => [
            'input' => '/(?:(?s:a)b)+\n/',
            'options' => ['possessive' => true],
        ];

        yield 'scoped group inside an alternation' => [
            'input' => '/(?:(?s:a)|b)+\n/',
            'options' => ['possessive' => true],
        ];

        yield 'scoped group inside a conditional' => [
            'input' => '/()(?(1)(?s:a)|b)+\n/',
            'options' => ['possessive' => true],
        ];

        yield 'conditional wrapped in the quantified group' => [
            'input' => '/(?:(?(1)(?s:a)|b))+\n/',
            'options' => ['possessive' => true],
        ];

        yield 'quantifier under the quantified group' => [
            'input' => '/(?:(?s:a)+)+\n/',
            'options' => ['possessive' => true],
        ];

        yield 'lookahead-conditioned scoped group in a conditional' => [
            'input' => '/(?:(?(?=\w)(?s:a)|b))+\n/',
            'options' => ['possessive' => true],
        ];

        yield 'scoped group inside a conditional in the suffix' => [
            'input' => '/\n+(?(1)(?s:.)|b)/',
            'options' => ['possessive' => true],
        ];

        yield 'plain nested groups are still possessified' => [
            'input' => '/(?:a(?:bc)d)+e/',
            'options' => ['possessive' => true, 'expected' => '/(?:a(?:bc)d)++e/'],
        ];
    }
}
