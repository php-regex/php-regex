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

namespace RegexParser\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\NodeVisitor\CompilerNodeVisitor;
use RegexParser\NodeVisitor\DumperNodeVisitor;
use RegexParser\Regex;

/**
 * Inside a class, a \Q...\E run stands for its characters one by one:
 * "[\Qabc\E-z]" is "a", "b" and the range c-z, and "[\Qaz\E-a]" is the
 * reversed range z-a. Every row was checked with preg_match() on PCRE2
 * 10.48 and with pcre2test 10.40.
 */
final class QuotedRunInClassRangeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReversedRanges')]
    public function test_validate_rejects_a_reversed_range_through_a_quoted_run(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    #[Test]
    #[DataProvider('provideAcceptedPatterns')]
    public function test_validate_accepts_quoted_runs_in_a_class(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PHP but was reported invalid: %s', $pattern, (string) $result->error));
    }

    #[Test]
    public function test_the_range_starts_at_the_last_quoted_character(): void
    {
        $dump = Regex::create()->parse('/[\\Qabc\\E-z]/')->accept(new DumperNodeVisitor());

        $this->assertStringContainsString('Range', $dump);
        $this->assertStringNotContainsString("'abc'", $dump);
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchingPatterns')]
    public function test_the_tree_matches_what_php_matches(string $pattern, array $subjects): void
    {
        $compiled = Regex::create()->parse($pattern)->accept(new CompilerNodeVisitor());

        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s on "%s"', $pattern, $compiled, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideReversedRanges(): iterable
    {
        yield 'quoted run ending past the range end: /[\\Qaz\\E-a]/' => ['pattern' => '/[\\Qaz\\E-a]/'];
        yield 'range ending inside a quoted run: /[z-\\Qaz\\E]/' => ['pattern' => '/[z-\\Qaz\\E]/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPatterns(): iterable
    {
        yield 'quoted run starting a range at its last character: /[\\Qabc\\E-z]/' => ['pattern' => '/[\\Qabc\\E-z]/'];
        yield 'quoted run ending a range at its first character: /[a-\\Qcz\\E]/' => ['pattern' => '/[a-\\Qcz\\E]/'];
        yield 'quoted run whose last character starts a range: /[\\Qza\\E-a]/' => ['pattern' => '/[\\Qza\\E-a]/'];
        yield 'hyphen inside a quoted run: /[\\Qa-z\\E]/' => ['pattern' => '/[\\Qa-z\\E]/'];
        yield 'quoted run of members: /[\\Qabc\\E]/' => ['pattern' => '/[\\Qabc\\E]/'];
        yield 'two quoted runs: /[\\Qx\\E\\Qyz\\E]/' => ['pattern' => '/[\\Qx\\E\\Qyz\\E]/'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideMatchingPatterns(): iterable
    {
        yield '/[\\Qabc\\E-z]/' => ['pattern' => '/[\\Qabc\\E-z]/', 'subjects' => ['a', 'b', 'm', '-']];
        yield '/[a-\\Qcz\\E]/' => ['pattern' => '/[a-\\Qcz\\E]/', 'subjects' => ['b', 'z', 'y', '-']];
        yield '/[\\Qza\\E-a]/' => ['pattern' => '/[\\Qza\\E-a]/', 'subjects' => ['a', 'z', 'b', '-']];
        yield '/[\\Qa-z\\E]/' => ['pattern' => '/[\\Qa-z\\E]/', 'subjects' => ['m', '-', 'a']];
    }
}
