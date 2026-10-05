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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lazy quantifier that ends the pattern stops at its minimum, unless a
 * subroutine call runs its group again elsewhere. The calls are read in
 * every part of a conditional: the yes branch, the no branch, and an
 * assertion used as the condition. A recursion test such as (?(R1)...)
 * reads where the match stands and calls nothing.
 */
final class ConditionalSubroutineLazyEndTest extends TestCase
{
    private const LAZY_END = 'regex.lint.quantifier.lazyEnd';

    /**
     * Each row also gives the pattern with "b+?" made "b": the call then
     * fails or matches less, so the called instance takes more than one "b".
     *
     * @return iterable<string, array{pattern: string, single: string, subject: string, match: string}>
     */
    public static function provideCallsInAConditional(): iterable
    {
        yield 'call in the yes branch' => ['pattern' => '/(a)?(?(1)(?2)x|y)(b+?)/', 'single' => '/(a)?(?(1)(?2)x|y)(b)/', 'subject' => 'abbbxb', 'match' => 'abbbxb'];
        yield 'call in the no branch' => ['pattern' => '/(a)?(?(1)y|(?2)x)(b+?)/', 'single' => '/(a)?(?(1)y|(?2)x)(b)/', 'subject' => 'bbbxb', 'match' => 'bbbxb'];
        yield 'call in an assertion condition' => ['pattern' => '/(?(?=(?1)x)bbbx|c)(b+?)/', 'single' => '/(?(?=(?1)x)bbbx|c)(b)/', 'subject' => 'bbbxb', 'match' => 'bbbxb'];
    }

    #[Test]
    #[DataProvider('provideCallsInAConditional')]
    public function test_a_call_inside_a_conditional_reenters_the_lazy_group(string $pattern, string $single, string $subject, string $match): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches));
        $this->assertSame($match, $matches[0]);
        preg_match($single, $subject, $singleMatches);
        $this->assertNotSame($match, $singleMatches[0] ?? null);

        $this->assertNotContains(self::LAZY_END, self::lint($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRecursionTests(): iterable
    {
        yield 'recursion test' => ['pattern' => '/(?(R)x|y)(a+?)/'];
        yield 'recursion test of a numbered group' => ['pattern' => '/(?(R1)x|y)(a+?)/'];
        yield 'recursion test of a named group' => ['pattern' => '/(?(R&n)x|y)(?<n>a+?)/'];
    }

    #[Test]
    #[DataProvider('provideRecursionTests')]
    public function test_a_recursion_test_calls_nothing(string $pattern): void
    {
        // Oracle: nothing recurses, so "a+?" stops at its minimum.
        $this->assertSame(1, preg_match($pattern, 'yaaa', $matches));
        $this->assertSame('ya', $matches[0]);

        $this->assertContains(self::LAZY_END, self::lint($pattern), $pattern);
    }

    /**
     * @return list<string>
     */
    private static function lint(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        return array_values(array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));
    }
}
