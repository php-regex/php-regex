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
 * Without D and m, a "$" that ends the pattern also matches before a
 * newline that ends the subject: "/^\d+$/" accepts "123\n". PHP 8.4.26 /
 * PCRE2 10.49: each reported pattern matches its subject, and no longer
 * does once D is set. The rule is off by default: the tests turn it on.
 */
final class DollarNewlineTest extends TestCase
{
    private const RULE = 'regex.lint.anchor.dollarNewline';

    private const MESSAGE = '"$" also matches before a newline that ends the subject, so a value with a trailing newline passes.';

    #[Test]
    #[DataProvider('provideTrailingNewlines')]
    public function test_a_final_dollar_that_lets_a_trailing_newline_through_is_reported(string $pattern, string $subject, int $count): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern.' on '.json_encode($subject));
        $this->assertSame(0, preg_match($pattern.'D', $subject), $pattern.'D on '.json_encode($subject));

        $issues = $this->issues($pattern);
        $this->assertCount($count, $issues);
        foreach ($issues as $issue) {
            $this->assertSame(self::MESSAGE, $issue->message);
            $this->assertSame('Write \z to match at the very end only, or add the D modifier.', $issue->hint);
            $this->assertSame(LintSeverity::Info, $issue->severity);
            $this->assertSame('$', substr(Regex::create(['cache' => null])->parse($pattern)->source ?? '', (int) $issue->offset, 1));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, count: int}>
     */
    public static function provideTrailingNewlines(): iterable
    {
        yield 'digits' => ['pattern' => '/^\d+$/', 'subject' => "123\n", 'count' => 1];
        yield 'letters under i' => ['pattern' => '/^[a-z]+$/i', 'subject' => "Abc\n", 'count' => 1];
        yield 'a start written \A' => ['pattern' => '/\A\w+$/', 'subject' => "abc\n", 'count' => 1];
        yield 'the A modifier anchors the start' => ['pattern' => '/\w+$/A', 'subject' => "abc\n", 'count' => 1];
        yield 'a group of alternatives' => ['pattern' => '/^(?:a|b)$/', 'subject' => "b\n", 'count' => 1];
        yield 'each anchored alternative' => ['pattern' => '/^a$|^b$/', 'subject' => "b\n", 'count' => 2];
        yield 'a dollar ending a group that ends the pattern' => ['pattern' => '/^(a|b$)/', 'subject' => "b\n", 'count' => 1];
        yield 'a dot without s' => ['pattern' => '/^.*$/', 'subject' => "abc\n", 'count' => 1];
        yield 'a comment after the dollar' => ['pattern' => '/^a$(?#end)/', 'subject' => "a\n", 'count' => 1];
        yield 'under x' => ['pattern' => '/^ a + $/x', 'subject' => "aa\n", 'count' => 1];
        yield 'an inline i before the start' => ['pattern' => '/(?i)^a$/', 'subject' => "A\n", 'count' => 1];
        yield 'a lookbehind before the dollar' => ['pattern' => '/^\w+(?<!_)$/', 'subject' => "ab\n", 'count' => 1];
        yield 'an option turned off before the dollar' => ['pattern' => '/^(?s)a(?-s)$/', 'subject' => "a\n", 'count' => 1];
        yield 'a CRLF newline' => ['pattern' => '/(*CRLF)^\d+$/', 'subject' => "1\r\n", 'count' => 1];
        yield 'a CR newline' => ['pattern' => '/(*CR)^\d+$/', 'subject' => "1\r", 'count' => 1];
        yield 'every alternative of a leading group anchored' => ['pattern' => '/(?:^a|^b)$/', 'subject' => "b\n", 'count' => 1];
    }

    #[Test]
    #[DataProvider('provideSilentDollars')]
    public function test_a_dollar_that_says_what_it_means_is_not_reported(string $pattern): void
    {
        $this->assertSame([], $this->issues($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSilentDollars(): iterable
    {
        yield 'the very end' => ['pattern' => '/^\d+\z/'];
        // \Z is PCRE's explicit spelling of "the end, or before a final
        // newline": who writes it asks for that.
        yield 'the end or before a final newline, spelled out' => ['pattern' => '/^\d+\Z/'];
        yield 'D set' => ['pattern' => '/^\d+$/D'];
        yield 'm set' => ['pattern' => '/^\d+$/m'];
        yield 'an inline m' => ['pattern' => '/^\d+(?m)$/'];
        yield 'a scoped m' => ['pattern' => '/(?m:^\d+$)/'];
        yield 'a start read under m' => ['pattern' => '/(?m)^(?-m)\d+$/'];
        yield 'no start anchor' => ['pattern' => '/\.php$/'];
        yield 'an alternative left unanchored' => ['pattern' => '/^a|b$/'];
        yield 'a start written \G' => ['pattern' => '/\Ga$/'];
        yield 'something follows the dollar' => ['pattern' => '/^a$\n?/'];
        yield 'a repeat around the dollar' => ['pattern' => '/^(?:a$)+/'];
        yield 'a dollar in a lookahead' => ['pattern' => '/^a(?=$)/'];
        yield 'a lookahead elsewhere' => ['pattern' => '/^(?=.*\n)a+$/'];
        yield 'white space before the dollar takes the newline' => ['pattern' => '/^a\s*$/'];
        yield 'a negated class takes the newline' => ['pattern' => '/^[^,]+$/'];
        yield 'a dot under s takes the newline' => ['pattern' => '/^.+$/s'];
        yield 'a newline before the dollar' => ['pattern' => '/^a\n$/'];
        yield 'any newline convention' => ['pattern' => '/(*ANY)^a$/'];
        yield 'a dollar in a class' => ['pattern' => '/^a[$]/'];
        yield 'a group a subroutine calls' => ['pattern' => '/^(a$)|b(?1)/'];
        yield 'an alternative of a leading group left unanchored' => ['pattern' => '/(?:^a|b)$/'];
        yield 'an alternative of a leading group holding no item' => ['pattern' => '/(?:(?#c)(?i)|^a)b$/'];
    }

    /**
     * What the character sets cannot read keeps the rule silent, though the
     * trailing newline still passes.
     */
    #[Test]
    public function test_a_dollar_after_what_the_sets_cannot_read_is_not_reported(): void
    {
        $this->assertSame(1, preg_match('/^(a)\1$/', "aa\n"));
        $this->assertSame([], $this->issues('/^(a)\1$/'));
    }

    #[Test]
    public function test_the_rule_is_off_by_default(): void
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse('/^\d+$/')->accept($linter);

        $this->assertSame([], array_values(array_filter($linter->getIssues(), static fn (object $issue): bool => self::RULE === $issue->id)));
    }

    #[Test]
    public function test_the_rule_can_be_turned_off(): void
    {
        $linter = new PatternLinter(['anchor.dollarNewline' => false]);
        Regex::create(['cache' => null])->parse('/^\d+$/')->accept($linter);

        $this->assertSame([], array_values(array_filter($linter->getIssues(), static fn (object $issue): bool => self::RULE === $issue->id)));
    }

    /**
     * @return list<RuleViolation>
     */
    private function issues(string $pattern): array
    {
        $linter = new PatternLinter(['anchor.dollarNewline' => true]);
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_values(array_filter($linter->getIssues(), static fn (object $issue): bool => self::RULE === $issue->id));
    }
}
