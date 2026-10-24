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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for ReDoS rule false positives and false negatives,
 * each verified against the real PCRE engine before being recorded.
 */
final class ReDoSRegressionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // False positives: patterns the rules flag but the engine handles in O(n)
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{pattern: string, ruleId: string}>
     */
    public static function provideAtomicOrPossessiveFalsePositives(): iterable
    {
        yield 'nested quantifier inside atomic group' => [
            'pattern' => '/(?>(?:a+)+)$/',
            'ruleId' => 'regex.lint.quantifier.nested',
        ];

        yield 'overlapping charset inside atomic group' => [
            'pattern' => '/(?>(?:a|a)+)$/',
            'ruleId' => 'regex.lint.overlap.charset',
        ];

        yield 'dotstar nested inside atomic group' => [
            'pattern' => '/(?>(?:.*)+)/s',
            'ruleId' => 'regex.lint.dotstar.nested',
        ];

        yield 'nested quantifier body under possessive outer' => [
            'pattern' => '/(?:(?:a+)+)++$/',
            'ruleId' => 'regex.lint.quantifier.nested',
        ];

        yield 'overlapping charset body under possessive outer' => [
            'pattern' => '/((?:a|a)+)++$/',
            'ruleId' => 'regex.lint.overlap.charset',
        ];

        yield 'dotstar nested body under possessive outer' => [
            'pattern' => '/(?:(?:.*)+)++$/s',
            'ruleId' => 'regex.lint.dotstar.nested',
        ];
    }

    #[Test]
    #[DataProvider('provideAtomicOrPossessiveFalsePositives')]
    public function test_redos_rules_do_not_fire_inside_atomic_or_possessive_context(string $pattern, string $ruleId): void
    {
        $this->assertNotContains($ruleId, $this->issueIds($pattern), $pattern);
    }

    /**
     * A possessive inner dot-star prevents the outer loop from backtracking
     * into it, so the pattern is linear despite the nested structure.
     */
    #[Test]
    public function test_nested_dotstar_does_not_fire_when_inner_dotstar_is_possessive(): void
    {
        $this->assertNotContains(
            'regex.lint.dotstar.nested',
            $this->issueIds('/(?:.*+)+$/s'),
            '/(?:.*+)+$/s',
        );
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSingleCharClassShortRun(): iterable
    {
        yield 'single-char class [a] at most twice before end' => [
            'pattern' => '/(?:[a]+){1,2}$/',
        ];

        yield 'single-char escape class [\x61] at most twice before end' => [
            'pattern' => '/(?:[\x61]+){1,2}$/',
        ];

        yield 'single-char class [z] at most twice before end' => [
            'pattern' => '/(?:[z]+){1,2}$/',
        ];
    }

    /**
     * A single-character class `[a]` is equivalent to the literal `a` for
     * the short-run-before-the-end exemption.
     */
    #[Test]
    #[DataProvider('provideSingleCharClassShortRun')]
    public function test_nested_quantifier_short_run_exemption_works_for_single_char_classes(string $pattern): void
    {
        $this->assertNotContains(
            'regex.lint.quantifier.nested',
            $this->issueIds($pattern),
            $pattern,
        );
    }

    /**
     * `(?=$)` is semantically equivalent to `$` when determining whether
     * PCRE auto-possessifies a trailing loop.
     */
    #[Test]
    public function test_nested_quantifier_short_run_exemption_works_for_end_anchor_lookahead(): void
    {
        $this->assertNotContains(
            'regex.lint.quantifier.nested',
            $this->issueIds('/(?:a+){1,2}(?=$)/'),
            '/(?:a+){1,2}(?=$)/',
        );
    }

    /**
     * A non-consuming assertion between the inner loop and its separator
     * must not block the separator from being recognised.
     */
    #[Test]
    public function test_nested_quantifier_separator_skips_non_consuming_node_between_inner_loop_and_separator(): void
    {
        $this->assertNotContains(
            'regex.lint.quantifier.nested',
            $this->issueIds('/(?:a+(?=b)b+)+$/'),
            '/(?:a+(?=b)b+)+$/',
        );
    }

    /**
     * Under `/i`, `[^z]` excludes both `z` and `Z`.  The runs `[^z]+` and
     * `Z+` are provably disjoint so the iteration partitions uniquely.
     */
    #[Test]
    public function test_nested_quantifier_negated_class_fold_under_i_is_treated_as_disjoint(): void
    {
        $this->assertNotContains(
            'regex.lint.quantifier.nested',
            $this->issueIds('/(?:[^z]+Z+)+$/i'),
            '/(?:[^z]+Z+)+$/i',
        );
    }

    // -------------------------------------------------------------------------
    // False negatives: patterns the rules miss but the engine handles badly
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCharClassOverlapUnderI(): iterable
    {
        yield 'lowercase range vs uppercase range under /i' => [
            'pattern' => '/(?:[a-z]|[A-Z])+$/i',
        ];

        yield 'literal vs uppercase range under /i' => [
            'pattern' => '/(?:a|[A-Z])+$/i',
        ];

        yield 'overlapping ranges under /i' => [
            'pattern' => '/(?:[a-c]|[B-D])+$/i',
        ];
    }

    /**
     * Overlapping character-class branches under `/i` should be detected
     * the same way literal-branch overlaps are.
     */
    #[Test]
    #[DataProvider('provideCharClassOverlapUnderI')]
    public function test_overlapping_charset_rule_detects_char_class_overlap_under_i(string $pattern): void
    {
        $this->assertContains(
            'regex.lint.overlap.charset',
            $this->issueIds($pattern),
            $pattern,
        );
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideConcatenationSubsetUnderI(): iterable
    {
        yield 'literal subset of uppercase range under /i' => [
            'pattern' => '/(?:a+[A-Z]+)+$/i',
        ];

        yield 'inline i flag' => [
            'pattern' => '/(?i)(?:a+[A-Z]+)+$/',
        ];

        yield 'scoped i group' => [
            'pattern' => '/(?:(?i:a)+(?i:[A-Z])+)+$/',
        ];
    }

    /**
     * When `/i` is active, a literal `a` is a subset of `[A-Z]` (both fold
     * to `{a, A}`), so `QuantifierConcatenationRule` should emit
     * `quantifier.concatenation`.
     */
    #[Test]
    #[DataProvider('provideConcatenationSubsetUnderI')]
    public function test_quantifier_concatenation_detects_subset_relationship_under_i(string $pattern): void
    {
        $this->assertContains(
            'regex.lint.quantifier.concatenation',
            $this->issueIds($pattern),
            $pattern,
        );
    }

    /**
     * A negated class `[^z]` excludes `{z, Z}` under `/i`; a literal `a`
     * (folded: `{a, A}`) is disjoint from that excluded set, so `a` IS a
     * valid subset of `[^z]` under `/i` and the rule should still emit
     * `quantifier.concatenation`.
     */
    #[Test]
    public function test_quantifier_concatenation_detects_subset_for_negated_class_under_i(): void
    {
        $pattern = '/(?:[^z]+a+)+$/i';

        $this->assertContains(
            'regex.lint.quantifier.concatenation',
            $this->issueIds($pattern),
            $pattern,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern): array
    {
        $visitor = new PatternLinter();
        Regex::create()->parse($pattern)->accept($visitor);

        return array_values(array_map(static fn (object $issue): string => $issue->id, $visitor->getIssues()));
    }
}
