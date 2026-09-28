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
 * PHP compiles patterns without PCRE2's extended class syntax, so "&&" and
 * "--" inside a class are plain members and ranges: "[a&&b]" matches "&",
 * and "[a--b]" is a range from "a" down to "-". Every row was checked with
 * preg_match() on PCRE2 10.48 and with pcre2test 10.40.
 */
final class ClassOperatorsAreLiteralTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRejectedPatterns')]
    public function test_validate_rejects_a_reversed_range_through_the_hyphen(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    #[Test]
    #[DataProvider('provideAcceptedPatterns')]
    public function test_validate_accepts_ampersands_and_hyphen_ranges(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PHP but was reported invalid: %s', $pattern, (string) $result->error));
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchingPatterns')]
    public function test_the_tree_matches_what_php_matches(string $pattern, array $subjects): void
    {
        $ast = Regex::create()->parse($pattern);

        $this->assertStringNotContainsString('ClassOperation', $ast->accept(new DumperNodeVisitor()));

        $compiled = $ast->accept(new CompilerNodeVisitor());
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s on "%s"', $pattern, $compiled, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRejectedPatterns(): iterable
    {
        yield 'range from a down to the hyphen: /[a--]/' => ['pattern' => '/[a--]/'];
        yield 'range from the caret down to the hyphen: /[^^--]/' => ['pattern' => '/[^^--]/'];
        yield 'range from a down to the hyphen, then b: /[a--b]/' => ['pattern' => '/[a--b]/'];
        yield 'class escape as a range start: /[\\w--\\d]/' => ['pattern' => '/[\\w--\\d]/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedPatterns(): iterable
    {
        yield 'ampersands between members: /[a&&b]/' => ['pattern' => '/[a&&b]/'];
        yield 'ampersands between class escapes: /[\\w&&\\d]/' => ['pattern' => '/[\\w&&\\d]/'];
        yield 'two ampersands: /[&&]/' => ['pattern' => '/[&&]/'];
        yield 'hyphen range up to a: /[--a]/' => ['pattern' => '/[--a]/'];
        yield 'hyphen range up to a bracket: /[--[]/' => ['pattern' => '/[--[]/'];
        yield 'bracket range after an empty quote: /[]-\\E]/' => ['pattern' => '/[]-\\E]/'];
        yield 'range up to the hyphen: /[!--]/' => ['pattern' => '/[!--]/'];
        yield 'range from NUL up to the hyphen: /[\\x00--]/' => ['pattern' => '/[\\x00--]/'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideMatchingPatterns(): iterable
    {
        yield '/[a&&b]/' => ['pattern' => '/[a&&b]/', 'subjects' => ['&', 'a', 'b', 'c']];
        yield '/[\\w&&\\d]/' => ['pattern' => '/[\\w&&\\d]/', 'subjects' => ['x', '5', '&', '-']];
        yield '/[--a]/' => ['pattern' => '/[--a]/', 'subjects' => ['-', 'a', 'Z', 'b']];
        yield '/^[a-z&&[^aeiou]]$/' => ['pattern' => '/^[a-z&&[^aeiou]]$/', 'subjects' => ['a]', '&]', 'a', 'b']];
    }
}
