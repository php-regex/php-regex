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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lazy quantifier nothing follows matches its minimum, and a quantifier on
 * a lookaround either lets the match skip it or changes nothing. Every
 * verdict below is the engine's.
 */
final class LazyEndAndQuantifiedAssertionRulesTest extends TestCase
{
    private const LAZY_END = 'regex.lint.quantifier.lazyEnd';

    private const ASSERTION = 'regex.lint.quantifier.assertion';

    #[Test]
    public function test_the_engine_stops_a_trailing_lazy_quantifier_at_its_minimum(): void
    {
        preg_match('/a\d+?/', 'a123', $matches);
        $this->assertSame(['a1'], $matches);

        preg_match('/a.*?/', 'abc', $matches);
        $this->assertSame(['a'], $matches);

        preg_match('/a\d+?$/', 'a123', $matches);
        $this->assertSame(['a123'], $matches);
    }

    #[Test]
    public function test_the_engine_skips_an_optional_lookaround(): void
    {
        $this->assertSame(1, preg_match('/(?=a)?b/', 'b'));
        $this->assertSame(0, preg_match('/(?=a)b/', 'b'));
        $this->assertSame(0, preg_match('/(?=a){2}b/', 'b'));
    }

    #[Test]
    #[DataProvider('provideLazyEnds')]
    public function test_a_trailing_lazy_quantifier_is_reported(string $pattern, string $quantifier): void
    {
        $violation = $this->violation($pattern, self::LAZY_END);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('"'.$quantifier.'"', $violation->message);
    }

    #[Test]
    #[DataProvider('provideLazyQuantifiersSomethingFollows')]
    public function test_a_lazy_quantifier_something_follows_is_not_reported(string $pattern): void
    {
        $this->assertNull($this->violation($pattern, self::LAZY_END));
    }

    #[Test]
    #[DataProvider('provideSkippableLookarounds')]
    public function test_a_lookaround_the_match_may_skip_is_reported(string $pattern): void
    {
        $violation = $this->violation($pattern, self::ASSERTION);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('skip', $violation->message);
    }

    #[Test]
    #[DataProvider('provideRepeatedLookarounds')]
    public function test_a_repeated_lookaround_is_reported(string $pattern): void
    {
        $violation = $this->violation($pattern, self::ASSERTION);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertStringContainsString('once', $violation->message);
    }

    #[Test]
    #[DataProvider('provideMeaningfulLookarounds')]
    public function test_a_lookaround_whose_quantifier_matters_is_not_reported(string $pattern): void
    {
        $this->assertNull($this->violation($pattern, self::ASSERTION));
    }

    /**
     * @return iterable<string, array{pattern: string, quantifier: string}>
     */
    public static function provideLazyEnds(): iterable
    {
        yield 'star' => ['pattern' => '/a.*?/', 'quantifier' => '*?'];
        yield 'plus' => ['pattern' => '/a\d+?/', 'quantifier' => '+?'];
        yield 'range' => ['pattern' => '/a.{2,5}?/', 'quantifier' => '{2,5}?'];
        yield 'in a trailing group' => ['pattern' => '/(a.*?)/', 'quantifier' => '*?'];
        yield 'in the last branch' => ['pattern' => '/x|a.*?/', 'quantifier' => '*?'];
        yield 'in a branch of a trailing group' => ['pattern' => '/(?:a|b\w*?)/', 'quantifier' => '*?'];
        yield 'before a comment' => ['pattern' => '/a.*?(?#tail)/', 'quantifier' => '*?'];
        yield 'ungreedy flag' => ['pattern' => '/a.*/U', 'quantifier' => '*'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLazyQuantifiersSomethingFollows(): iterable
    {
        yield 'end anchor' => ['pattern' => '/a.*?$/'];
        yield 'literal' => ['pattern' => '/a.*?b/'];
        yield 'lookahead' => ['pattern' => '/a.*?(?=b)/'];
        yield 'word boundary' => ['pattern' => '/a.*?\b/'];
        yield 'repeated group' => ['pattern' => '/(a.*?)+/'];
        yield 'greedy' => ['pattern' => '/a.*/'];
        yield 'possessive' => ['pattern' => '/a.*+/'];
        yield 'inside a lookahead' => ['pattern' => '/(?=a.*?)b/'];
        yield 'fixed count' => ['pattern' => '/a{2}?/'];
        yield 'ungreedy flag makes it greedy' => ['pattern' => '/a.*?/U'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSkippableLookarounds(): iterable
    {
        yield 'optional lookahead' => ['pattern' => '/(?=a)?b/'];
        yield 'starred negative lookahead' => ['pattern' => '/(?!a)*b/'];
        yield 'optional lookbehind' => ['pattern' => '/(?<=a)?b/'];
        yield 'lazy optional' => ['pattern' => '/(?=a)??b/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRepeatedLookarounds(): iterable
    {
        yield 'plus' => ['pattern' => '/(?<=a)+b/'];
        yield 'exact count' => ['pattern' => '/(?=a){2}b/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMeaningfulLookarounds(): iterable
    {
        yield 'plain lookahead' => ['pattern' => '/(?=a)b/'];
        yield 'optional lookahead that captures' => ['pattern' => '/(?=(a))?a/'];
        yield 'optional lookahead with a nested capture' => ['pattern' => '/(?=x(a))?x/'];
        yield 'zero count is another rule' => ['pattern' => '/(?=a){0}b/'];
        yield 'quantified group' => ['pattern' => '/(?:a)?b/'];
    }

    /**
     * A subroutine call runs the group again where something follows it:
     * that instance takes more than its minimum. The rule skips a node
     * inside a group a subroutine calls.
     *
     * @return iterable<string, array{pattern: string, subject: string, match: string}>
     */
    public static function provideLazyQuantifiersReenteredBySubroutine(): iterable
    {
        // The called instance takes "aaa" before the "x".
        yield 'numbered call before the group' => ['pattern' => '/(?1)x(a+?)/', 'subject' => 'aaaxa', 'match' => 'aaaxa'];
        yield 'named call before the group' => ['pattern' => '/(?&w)!(?<w>\w+?)/', 'subject' => 'abc!de', 'match' => 'abc!d'];
        // The call inside the first branch must reach the "y".
        yield 'call from another branch' => ['pattern' => '/(?:x(?1)y|(a+?))/', 'subject' => 'xaay', 'match' => 'xaay'];
    }

    #[Test]
    #[DataProvider('provideLazyQuantifiersReenteredBySubroutine')]
    public function test_a_lazy_quantifier_a_subroutine_reenters_is_not_reported(string $pattern, string $subject, string $match): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches));
        $this->assertSame($match, $matches[0]);

        $this->assertNull($this->violation($pattern, self::LAZY_END));
    }

    /**
     * Inside a branch reset two groups share a number, and (?1) calls the
     * first of them.
     */
    #[Test]
    public function test_a_lazy_quantifier_in_the_branch_reset_group_a_call_reaches_is_not_reported(): void
    {
        // The called instance takes "aaa" before the "x".
        $this->assertSame(1, preg_match('/(?1)x(?|(a+?)|(b))/', 'aaaxa', $matches));
        $this->assertSame('aaaxa', $matches[0]);

        $this->assertNull($this->violation('/(?1)x(?|(a+?)|(b))/', self::LAZY_END));
    }

    #[Test]
    public function test_a_lazy_quantifier_in_the_branch_reset_group_no_call_reaches_is_still_reported(): void
    {
        // (?1) calls (b): "a+?" only runs at the end, at its minimum.
        $this->assertSame(1, preg_match('/(?1)x(?|(b)|(a+?))/', 'bxaaa', $matches));
        $this->assertSame('bxa', $matches[0]);
        $this->assertSame(0, preg_match('/(?1)x(?|(b)|(a+?))/', 'aaaxa'));

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?1)x(?|(b)|(a+?))/', self::LAZY_END));
    }

    /**
     * A pattern that recurses into itself is skipped whole.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRecursivePatterns(): iterable
    {
        yield 'recursion with (?R)' => ['pattern' => '/x(?R)y|a+?/'];
        yield 'recursion with (?0)' => ['pattern' => '/x(?0)y|a+?/'];
    }

    #[Test]
    #[DataProvider('provideRecursivePatterns')]
    public function test_a_lazy_quantifier_in_a_recursive_pattern_is_not_reported(string $pattern): void
    {
        // Oracle: the recursed instance of "a+?" takes "aa" to reach the "y".
        $this->assertSame(1, preg_match($pattern, 'xaay', $matches));
        $this->assertSame('xaay', $matches[0]);

        $this->assertNull($this->violation($pattern, self::LAZY_END));
    }

    /**
     * Deliberate loss of recall: here every instance of "b+?" does stop at
     * its minimum ("aabbb" matches "aabb"), so the report would be true, but
     * a recursive pattern is skipped whole rather than reasoned about.
     */
    #[Test]
    public function test_a_recursive_pattern_is_skipped_even_when_the_report_would_hold(): void
    {
        $this->assertSame(1, preg_match('/a(?R)?b+?/', 'aabbb', $matches));
        $this->assertSame('aabb', $matches[0]);

        $this->assertNull($this->violation('/a(?R)?b+?/', self::LAZY_END));
    }

    /**
     * A subroutine call elsewhere does not silence a trailing lazy
     * quantifier outside every called group.
     */
    #[Test]
    public function test_a_lazy_quantifier_outside_every_called_group_is_still_reported(): void
    {
        preg_match('/(?1)x(a+?)z|(b)c+?/', 'bccc', $matches);
        $this->assertSame('bc', $matches[0]);

        $violation = $this->violation('/(?1)x(a+?)z|(b)c+?/', self::LAZY_END);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Lazy quantifier "+?" ends the pattern, so it always matches its minimum.', $violation->message);
    }

    /**
     * Under U a quantifier written greedy is lazy; the message says why.
     *
     * @return iterable<string, array{pattern: string, message: string}>
     */
    public static function provideLazyEndsUnderTheUFlag(): iterable
    {
        yield 'R escape under U and u' => [
            'pattern' => '/\R+/Uu',
            'message' => 'Quantifier "+" is lazy under the U flag and ends the pattern, so it always matches its minimum.',
        ];
        yield 'dot star under U' => [
            'pattern' => '/a.*/U',
            'message' => 'Quantifier "*" is lazy under the U flag and ends the pattern, so it always matches its minimum.',
        ];
        yield 'inline U' => [
            'pattern' => '/(?U)a\d+/',
            'message' => 'Quantifier "+" is lazy under the U flag and ends the pattern, so it always matches its minimum.',
        ];
        yield 'explicit lazy keeps its message' => [
            'pattern' => '/a\d+?/',
            'message' => 'Lazy quantifier "+?" ends the pattern, so it always matches its minimum.',
        ];
    }

    #[Test]
    #[DataProvider('provideLazyEndsUnderTheUFlag')]
    public function test_a_lazy_end_under_the_u_flag_says_the_flag_made_it_lazy(string $pattern, string $message): void
    {
        $violation = $this->violation($pattern, self::LAZY_END);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame($message, $violation->message);
    }

    #[Test]
    public function test_the_engine_stops_a_quantifier_made_lazy_by_u_at_its_minimum(): void
    {
        preg_match('/\R+/Uu', "\n\n\n", $matches);
        $this->assertSame(["\n"], $matches);

        preg_match('/(?U)a\d+/', 'a123', $matches);
        $this->assertSame(['a1'], $matches);
    }

    private function violation(string $pattern, string $id): ?RuleViolation
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if ($id === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
