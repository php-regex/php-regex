<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Optimizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

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
