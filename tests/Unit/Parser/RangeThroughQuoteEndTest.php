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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An \E, or an empty \Q\E, between a range start and its hyphen is
 * transparent: PCRE still builds the range. "[z\E-a]" is a reversed range,
 * and "[a\E-c]" matches "b". Every row was checked with preg_match() on
 * PCRE2 10.48 and with pcre2test 10.40.
 */
final class RangeThroughQuoteEndTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReversedRanges')]
    public function test_validate_rejects_a_reversed_range_through_a_quote_end(string $pattern): void
    {
        $this->assertFalse(Regex::create()->validate($pattern)->isValid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    #[Test]
    #[DataProvider('provideRanges')]
    public function test_validate_accepts_a_range_through_a_quote_end(string $pattern): void
    {
        $result = Regex::create()->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles in PHP but was reported invalid: %s', $pattern, (string) $result->error));
    }

    /**
     * A multibyte range start is one character under u whatever encoding the
     * host gives mbstring: "/[ÿ\E-é]/u" stays refused (PHP 8.4.26, PCRE2
     * 10.49: "range out of order").
     */
    #[Test]
    #[DataProvider('provideMultibyteReversedRanges')]
    public function test_a_multibyte_range_start_is_one_character_whatever_the_mbstring_encoding(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $encoding = mb_internal_encoding();
        mb_internal_encoding('ISO-8859-1');

        try {
            $valid = Regex::create(['cache' => null])->validate($pattern)->isValid;
        } finally {
            mb_internal_encoding($encoding);
        }

        $this->assertFalse($valid, \sprintf('%s is refused by PHP but was reported valid.', $pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideMultibyteReversedRanges(): iterable
    {
        yield 'two-byte start through \\E' => ['pattern' => '/[ÿ\E-é]/u'];
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideMatchingPatterns')]
    public function test_the_tree_matches_what_php_matches(string $pattern, array $subjects): void
    {
        $compiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($compiled, $subject), \sprintf('%s compiled to %s on "%s"', $pattern, $compiled, $subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideReversedRanges(): iterable
    {
        yield 'range down to an ampersand through \\E: /[a\\E-&]/' => ['pattern' => '/[a\\E-&]/'];
        yield 'reversed range through \\E: /[z\\E-a]/' => ['pattern' => '/[z\\E-a]/'];
        yield 'reversed range through an empty quote: /[z\\Q\\E-a]/' => ['pattern' => '/[z\\Q\\E-a]/'];
        yield 'reversed range from a quoted start: /[\\Qz\\E-a]/' => ['pattern' => '/[\\Qz\\E-a]/'];
        yield 'reversed range through two \\E: /[\\Qz\\E\\E-a]/' => ['pattern' => '/[\\Qz\\E\\E-a]/'];
        yield 'reversed range through \\E in a negated class: /[^z\\E-a]/' => ['pattern' => '/[^z\\E-a]/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRanges(): iterable
    {
        yield 'range through \\E: /[a\\E-z]/' => ['pattern' => '/[a\\E-z]/'];
        yield 'range through an empty quote: /[a\\Q\\E-z]/' => ['pattern' => '/[a\\Q\\E-z]/'];
        yield 'range from a quoted start: /[\\Qa\\E-z]/' => ['pattern' => '/[\\Qa\\E-z]/'];
        yield 'range through two \\E: /[a\\E\\E-z]/' => ['pattern' => '/[a\\E\\E-z]/'];
        yield 'range with \\E on both sides of the hyphen: /[a\\E-\\Ez]/' => ['pattern' => '/[a\\E-\\Ez]/'];
        yield 'trailing hyphen after \\E: /[a\\E-]/' => ['pattern' => '/[a\\E-]/'];
        yield 'quoted run of several characters before the hyphen: /[\\Qabc\\E-z]+/' => ['pattern' => '/[\\Qabc\\E-z]+/'];
        yield 'quoted start and quoted end: /^[\\E\\Qa\\E-\\Qz\\E]+/' => ['pattern' => '/^[\\E\\Qa\\E-\\Qz\\E]+/'];
        yield 'quoted multibyte start and end: /^[\\QĀ\\E-\\QŐ\\E]/u' => ['pattern' => '/^[\\QĀ\\E-\\QŐ\\E]/u'];
        yield 'hyphen then \\E at the end: /[a\\E-\\E]/' => ['pattern' => '/[a\\E-\\E]/'];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function provideMatchingPatterns(): iterable
    {
        yield '/[a\\E-c]/' => ['pattern' => '/[a\\E-c]/', 'subjects' => ['a', 'b', 'c', '-', 'd']];
        yield '/[\\Qa\\E-c]/' => ['pattern' => '/[\\Qa\\E-c]/', 'subjects' => ['b', '-']];
        yield '/^[\\Qa\\E-\\Qz\\E]+$/' => ['pattern' => '/^[\\Qa\\E-\\Qz\\E]+$/', 'subjects' => ['m', 'az', '-', 'A']];
        yield '/[a\\E-]/' => ['pattern' => '/[a\\E-]/', 'subjects' => ['a', '-', 'b']];
    }
}
