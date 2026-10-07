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
 * '".*?"' backtracks one character at a time where '"[^"\n]*"' reads the
 * run at once and matches the same text. A perf rule, off by default; only
 * when nothing that can fail follows the closing character (a later failure
 * lets the lazy dot cross it, the class cannot), never under a newline verb
 * (the dot then excludes another character than "\n").
 */
final class LazyToClassRuleTest extends TestCase
{
    private const ID = 'regex.lint.quantifier.lazyToClass';

    private const ENABLED = ['quantifier.lazyToClass' => true];

    private const SUBJECTS = ['', '"', '""', '"a"', '"a"b"', "\"a\"\n\"b\"", "\"a\nb\"", "\"a\rb\"", "\"a\r\nb\"", 'x"a"b"x', '"a"b"x', "'a'b'", "x'a'"];

    #[Test]
    public function test_the_rule_is_off_by_default(): void
    {
        $this->assertNull($this->violation('/".*?"/', []));
        // Off, not missing: the same pattern trips it once enabled.
        $this->assertInstanceOf(RuleViolation::class, $this->violation('/".*?"/', self::ENABLED));
    }

    #[Test]
    #[DataProvider('provideLazyDotsBeforeADelimiter')]
    public function test_a_lazy_dot_before_one_closing_character_is_reported_with_the_class(string $pattern, string $rewrite, string $tip): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the rewrite matches the same text.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $original), preg_match($rewrite, $subject, $rewritten), var_export($subject, true));
            $this->assertSame($original, $rewritten, var_export($subject, true));
        }

        $violation = $this->violation($pattern, self::ENABLED);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Perf, $violation->severity);
        $this->assertNotNull($violation->hint);
        $this->assertStringContainsString($tip, (string) $violation->hint);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, tip: string}>
     */
    public static function provideLazyDotsBeforeADelimiter(): iterable
    {
        yield 'double quotes' => ['pattern' => '/".*?"/', 'rewrite' => '/"[^"\n]*"/', 'tip' => '"[^"\n]*"'];
        yield 'dot all' => ['pattern' => '/".*?"/s', 'rewrite' => '/"[^"]*"/s', 'tip' => '"[^"]*"'];
        yield 'single quotes' => ['pattern' => "/'.*?'/", 'rewrite' => "/'[^'\\n]*'/", 'tip' => "'[^'\\n]*'"];
        yield 'after a prefix' => ['pattern' => '/x".*?"/', 'rewrite' => '/x"[^"\n]*"/', 'tip' => '"[^"\n]*"'];
    }

    /**
     * The tip quotes the rewrite, as the other rules' tips do.
     */
    #[Test]
    public function test_the_tip_quotes_the_rewrite(): void
    {
        $violation = $this->violation('/x".*?"/', self::ENABLED);

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Write ""[^"\n]*"" instead.', $violation->hint);
    }

    #[Test]
    #[DataProvider('provideLazyDotsTheClassWouldChange')]
    public function test_a_lazy_dot_the_class_would_read_differently_is_not_reported(string $pattern, string $rewrite, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the rewrite answers otherwise on this subject.
        preg_match($pattern, $subject, $original);
        preg_match($rewrite, $subject, $rewritten);
        $this->assertNotSame($original, $rewritten, $pattern);

        $this->assertNull($this->violation($pattern, self::ENABLED));
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, subject: string}>
     */
    public static function provideLazyDotsTheClassWouldChange(): iterable
    {
        // '"a"b"x': the lazy dot crosses the middle quote, the class cannot.
        yield 'a literal follows' => ['pattern' => '/".*?"x/', 'rewrite' => '/"[^"]*"x/', 'subject' => '"a"b"x'];
        yield 'an anchor follows' => ['pattern' => '/".*?"$/', 'rewrite' => '/"[^"\n]*"$/', 'subject' => '"a"b"'];
        // Under CRLF the dot reads a lone "\n"; under CR and ANYCRLF it
        // refuses "\r".
        yield 'CRLF newline' => ['pattern' => '/(*CRLF)".*?"/', 'rewrite' => '/(*CRLF)"[^"\n]*"/', 'subject' => "\"a\nb\""];
        yield 'ANYCRLF newline' => ['pattern' => '/(*ANYCRLF)".*?"/', 'rewrite' => '/(*ANYCRLF)"[^"\n]*"/', 'subject' => "\"a\rb\""];
        yield 'CR newline' => ['pattern' => '/(*CR)".*?"/', 'rewrite' => '/(*CR)"[^"\n]*"/', 'subject' => "\"a\rb\""];
        // At least one character: the lazy dot takes the opening quote's
        // neighbour even when it is a quote, the class cannot.
        yield 'one or more' => ['pattern' => '/".+?"/', 'rewrite' => '/"[^"\n]+"/', 'subject' => '""x"'];
    }

    /**
     * The rule reads "X.*?Y" in one sequence: a lazy dot with no closing
     * character after it in its sequence has nothing to rewrite.
     */
    #[Test]
    #[DataProvider('provideLazyDotsWithNoClosingCharacter')]
    public function test_a_lazy_dot_with_no_closing_character_is_not_reported(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $this->assertNull($this->violation($pattern, self::ENABLED));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLazyDotsWithNoClosingCharacter(): iterable
    {
        yield 'the whole pattern' => ['pattern' => '/.*?/'];
        yield 'a whole alternative' => ['pattern' => '/a|.*?/'];
        yield 'at the end of a sequence' => ['pattern' => '/".*?/'];
    }

    #[Test]
    public function test_a_greedy_dot_is_not_reported(): void
    {
        $this->assertNull($this->violation('/".*"/', self::ENABLED));
    }

    /**
     * @param array<string, bool> $rules
     */
    private function violation(string $pattern, array $rules): ?RuleViolation
    {
        $linter = new PatternLinter($rules);
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
