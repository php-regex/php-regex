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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regex::info() is the one-line entry point to the pattern facts: the
 * analyzer's answer on the tree the facade parses, for a pattern the
 * instance's target accepts. A pattern validate() refuses at that target
 * throws.
 */
final class RegexInfoTest extends TestCase
{
    private const TARGET = ['php_version' => '8.4', 'pcre_version' => '10.44'];

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_info_is_the_analyzer_answer_on_the_parsed_tree(string $pattern): void
    {
        $info = Regex::create(['cache' => new NullCache()] + self::TARGET)->info($pattern);

        $this->assertEquals((new PatternInfoAnalyzer())->analyze(RegexParser::create(self::TARGET)->parse($pattern)), $info);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'plain' => ['pattern' => '/abc/'];
        yield 'every fact set' => ['pattern' => '/(*LIMIT_MATCH=5)(*LIMIT_DEPTH=6)(*LIMIT_HEAP=7)(*CRLF)(*BSR_ANYCRLF)\A(?<n>a)\1\Cb\z/'];
        yield 'unicode' => ['pattern' => '/(?<=é)\X+/u'];
    }

    #[Test]
    public function test_info_reads_every_fact_of_one_pattern(): void
    {
        // pcre2test 10.49 "/I": capture count 1, max back reference 1,
        // n -> 1, contains \C, match limit 5, depth limit 6, heap limit 7,
        // forced newline CRLF, \R matches CR, LF, or CRLF, anchored.
        $info = Regex::create(self::TARGET)->info('/(*LIMIT_MATCH=5)(*LIMIT_DEPTH=6)(*LIMIT_HEAP=7)(*CRLF)(*BSR_ANYCRLF)\A(?<n>a)\1\Cb\z/');

        $this->assertSame(1, $info->captureCount);
        $this->assertSame(['n' => [1]], $info->names);
        $this->assertSame(1, $info->maxBackreference);
        $this->assertTrue($info->usesBackslashC);
        $this->assertSame(5, $info->matchLimit);
        $this->assertSame(6, $info->depthLimit);
        $this->assertSame(7, $info->heapLimit);
        $this->assertSame('CRLF', $info->newline?->value);
        $this->assertSame('ANYCRLF', $info->bsr?->value);
        $this->assertTrue($info->anchoredStart);
        $this->assertTrue($info->anchoredEnd);
        // Every match is 4 bytes: "a", the back reference to it, one code
        // unit, "b". A sound range holds 4; how tight it is may change.
        $this->assertLessThanOrEqual(4, $info->minMatchLength);
        $this->assertTrue(null === $info->maxMatchLength || $info->maxMatchLength >= 4);
        $this->assertSame(0, $info->maxLookbehind);
    }

    /**
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_info_throws_what_validate_refuses_at_the_instance_target(string $pattern, array $options): void
    {
        $regex = Regex::create($options);
        $this->assertFalse($regex->validate($pattern)->isValid, 'The row must be a pattern the target refuses.');

        $this->expectException(ExceptionInterface::class);

        $regex->info($pattern);
    }

    /**
     * Each refusal grounded in preg_match() on PHP 8.4.26 / PCRE2 10.49,
     * but for PHP 8.5, which compiles without
     * PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK (pcre2test 10.49 without that option:
     * error 199).
     *
     * @return iterable<string, array{pattern: string, options: array<string, mixed>}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'unclosed group' => ['pattern' => '/(a/', 'options' => self::TARGET];
        yield 'no closing delimiter' => ['pattern' => '/abc', 'options' => self::TARGET];
        yield 'alphanumeric delimiter' => ['pattern' => 'abc', 'options' => self::TARGET];
        yield '\C inside a class' => ['pattern' => '/[\C]a/', 'options' => self::TARGET];
        yield 'limit one past the largest PCRE2 reads' => ['pattern' => '/(*LIMIT_MATCH=4294967290)a/', 'options' => self::TARGET];
        // Refused only by the validator, once the tree is read.
        yield '\K in a lookbehind on PHP 8.5' => ['pattern' => '/(?<=a\Kb)c/', 'options' => ['php_version' => '8.5', 'pcre_version' => '10.44']];
    }

    #[Test]
    public function test_info_answers_for_a_pattern_the_target_accepts_that_another_refuses(): void
    {
        // The same \K-in-lookbehind pattern compiles on PHP 8.4 (preg_match()
        // on 8.4.26 returns 1).
        $info = Regex::create(self::TARGET)->info('/(?<=a\Kb)c/');

        $this->assertSame(0, $info->captureCount);
        $this->assertSame(2, $info->maxLookbehind);
    }
}
