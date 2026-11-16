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
 * "{1}" repeats once, as writing nothing does; "{1}+" is possessive, an
 * atomic group that never gives back, which the optimizer keeps too.
 */
final class UselessQuantifierRuleTest extends TestCase
{
    private const ID = 'regex.lint.quantifier.useless';

    #[Test]
    public function test_a_count_of_one_is_reported(): void
    {
        $this->assertInstanceOf(RuleViolation::class, $this->violation('/ab{1}c/'));
        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(a|ab){1}?ab/'));
    }

    /**
     * Oracle, PHP 8.4.27 / PCRE2 10.49: /(a|ab){1}+ab/ answers 0 on "ababx",
     * /(a|ab)ab/ answers 1.
     */
    #[Test]
    public function test_a_possessive_count_of_one_is_not_reported(): void
    {
        $this->assertSame(0, preg_match('/(a|ab){1}+ab/', 'ababx'));
        $this->assertSame(1, preg_match('/(a|ab)ab/', 'ababx'));

        $this->assertNull($this->violation('/(a|ab){1}+ab/'));
        $this->assertNull($this->violation('/(a|ab){1,1}+ab/'));
    }

    /**
     * The tip names the count as written, the lazy marker included: without
     * its "?", "a{1}?b" would read "a?b". Oracle, PHP 8.4.27 / PCRE2 10.49:
     * /a{1}?b/ and /ab/ answer 0 on "b", /a?b/ answers 1.
     */
    #[Test]
    public function test_the_tip_names_the_count_as_written(): void
    {
        $this->assertSame(0, preg_match('/a{1}?b/', 'b'));
        $this->assertSame(1, preg_match('/a?b/', 'b'));

        $this->assertSame('Remove the {1}? quantifier; it does not change the match.', $this->violation('/a{1}?b/')?->hint);
        $this->assertSame('Remove the {1,1} quantifier; it does not change the match.', $this->violation('/a{1,1}b/')?->hint);
        $this->assertSame('Remove the {1} quantifier; it does not change the match.', $this->violation('/(a)\1{1}x/')?->hint);
    }

    /**
     * A count that keeps an escape apart from the digit after it changes
     * the match once removed. Oracle, PHP 8.4.27 / PCRE2 10.49: /(a)\1{1}0/
     * answers 1 on "aa0", /(a)\10/ 0; /\x4{1}1/ answers 1 on "\x041",
     * /\x41/ 0; /\01{1}2/ answers 1 on "\x012", /\012/ 0.
     *
     * @return iterable<string, array{pattern: string, removed: string, subject: string}>
     */
    public static function provideCountsKeepingAnEscapeApart(): iterable
    {
        yield 'reference before a digit' => ['pattern' => '/(a)\1{1}0/', 'removed' => '/(a)\10/', 'subject' => 'aa0'];
        yield 'hex escape before a hex digit' => ['pattern' => '/\x4{1}1/', 'removed' => '/\x41/', 'subject' => "\x041"];
        yield 'octal escape before a digit' => ['pattern' => '/\01{1}2/', 'removed' => '/\012/', 'subject' => "\x012"];
    }

    #[Test]
    #[DataProvider('provideCountsKeepingAnEscapeApart')]
    public function test_a_count_keeping_an_escape_apart_is_not_reported(string $pattern, string $removed, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(0, preg_match($removed, $subject));

        $this->assertNull($this->violation($pattern));
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
