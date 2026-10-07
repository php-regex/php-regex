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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * quantifier.lazyEnd, widened: a lazy quantifier followed, up to the end of
 * the pattern, only by elements that can match the empty string and hold no
 * anchor or lookaround still takes its minimum, the rest matching empty
 * ("a+?b*" on "aab" matches "a"). Same id, wider trigger.
 */
final class LazyEndNullableSuffixTest extends TestCase
{
    private const ID = 'regex.lint.quantifier.lazyEnd';

    #[Test]
    #[DataProvider('provideLazyQuantifiersBeforeANullableSuffix')]
    public function test_a_lazy_quantifier_before_a_nullable_suffix_is_reported(string $pattern, string $subject, string $match, string $quantifier): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the lazy part takes its minimum
        // and the suffix matches empty.
        $this->assertSame(1, preg_match($pattern, $subject, $matches), $pattern);
        $this->assertSame($match, $matches[0]);

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Warning, $violation->severity);
        $this->assertStringContainsString('"'.$quantifier.'"', $violation->message);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, match: string, quantifier: string}>
     */
    public static function provideLazyQuantifiersBeforeANullableSuffix(): iterable
    {
        yield 'star suffix' => ['pattern' => '/a+?b*/', 'subject' => 'aab', 'match' => 'a', 'quantifier' => '+?'];
        yield 'group with an empty branch' => ['pattern' => '/a+?(?:b|)/', 'subject' => 'aab', 'match' => 'a', 'quantifier' => '+?'];
        yield 'two optional items' => ['pattern' => '/a+?b?c*/', 'subject' => 'aabc', 'match' => 'a', 'quantifier' => '+?'];
        yield 'lazy dot star' => ['pattern' => '/a.*?b*/', 'subject' => 'axxbb', 'match' => 'a', 'quantifier' => '*?'];
    }

    #[Test]
    #[DataProvider('provideLazyQuantifiersSomethingMustFollow')]
    public function test_a_lazy_quantifier_before_a_suffix_that_can_fail_is_not_reported(string $pattern, string $subject, string $match): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the lazy part takes more than its minimum.
        $this->assertSame(1, preg_match($pattern, $subject, $matches), $pattern);
        $this->assertSame($match, $matches[0]);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, match: string}>
     */
    public static function provideLazyQuantifiersSomethingMustFollow(): iterable
    {
        yield 'nullable suffix then an end anchor' => ['pattern' => '/a+?b*$/', 'subject' => 'aab', 'match' => 'aab'];
        yield 'end anchor' => ['pattern' => '/a+?$/', 'subject' => 'aa', 'match' => 'aa'];
        yield 'suffix that must read' => ['pattern' => '/a+?b+/', 'subject' => 'aab', 'match' => 'aab'];
        yield 'nullable suffix then a lookahead' => ['pattern' => '/a+?b*(?=c)/', 'subject' => 'aabc', 'match' => 'aab'];
        yield 'nullable suffix then a word boundary' => ['pattern' => '/a+?b*\b/', 'subject' => 'aab', 'match' => 'aab'];
        // The suffix may match empty, but only where its anchor or
        // lookahead holds: the lazy part grows until it does.
        yield 'nullable group holding an end anchor' => ['pattern' => '/a+?(?:b|$)/', 'subject' => 'aa', 'match' => 'aa'];
        yield 'nullable group holding a lookahead' => ['pattern' => '/a+?(?:b|(?=c))/', 'subject' => 'aac', 'match' => 'aa'];
    }

    private function violation(string $pattern): ?RuleViolation
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
