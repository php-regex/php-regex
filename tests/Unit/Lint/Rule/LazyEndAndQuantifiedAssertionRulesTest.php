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
