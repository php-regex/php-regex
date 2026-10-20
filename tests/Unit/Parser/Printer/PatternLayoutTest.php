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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\Printer\PatternLayout;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pieces of the line layout that read no tree: the last pass over the
 * laid-out lines, widths, and how deep a construct may nest and still stay
 * on one line. The layout of whole patterns is covered by the formatter's
 * tests.
 */
final class PatternLayoutTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLaidOutLines')]
    public function test_finish_lines_aligns_trailing_comments_and_spells_a_final_escaped_space(string $text, bool $countsCodePoints, string $expected): void
    {
        $this->assertSame($expected, PatternLayout::finishLines($text, PatternLayout::TRAIL, $countsCodePoints));
    }

    /**
     * @return iterable<string, array{text: string, countsCodePoints: bool, expected: string}>
     */
    public static function provideLaidOutLines(): iterable
    {
        $trail = PatternLayout::TRAIL;

        yield 'comments of consecutive lines on one column' => ['text' => "a{$trail}# x\nbbb{$trail}# y\nc", 'countsCodePoints' => false, 'expected' => "a    # x\nbbb  # y\nc"];
        yield 'a line between two comments parts their columns' => ['text' => "a{$trail}# x\nbbb\ncc{$trail}# y", 'countsCodePoints' => false, 'expected' => "a  # x\nbbb\ncc  # y"];
        yield 'code points under UTF' => ['text' => "é{$trail}# x\nab{$trail}# y", 'countsCodePoints' => true, 'expected' => "é   # x\nab  # y"];
        yield 'bytes otherwise' => ['text' => "é{$trail}# x\nab{$trail}# y", 'countsCodePoints' => false, 'expected' => "é  # x\nab  # y"];
        // An editor that strips trailing spaces would drop the escaped
        // space, and the pattern with it.
        yield 'a line ending in an escaped space' => ['text' => "a\\ \nb", 'countsCodePoints' => false, 'expected' => "a\\x20\nb"];
        yield 'an escaped backslash, then a space' => ['text' => 'a\\\\ ', 'countsCodePoints' => false, 'expected' => 'a\\\\ '];
        yield 'a control backslash, then an escaped space' => ['text' => 'a\\c\\\\ ', 'countsCodePoints' => false, 'expected' => 'a\\c\\\\x20'];
    }

    #[Test]
    public function test_the_last_pass_keeps_the_pattern_it_is_given(): void
    {
        // Oracle: "\x20" and "\ " are the same space under x.
        $this->assertSame(1, preg_match("/a\\ \n/x", 'a '));
        $this->assertSame(1, preg_match("/a\\x20\n/x", 'a '));
        $this->assertSame(1, preg_match('/a\\c\\\\x20/x', "a\x1C "));
    }

    #[Test]
    public function test_columns_of_counts_code_points_or_bytes(): void
    {
        $this->assertSame(1, PatternLayout::columnsOf('é', true));
        $this->assertSame(2, PatternLayout::columnsOf('é', false));
    }

    #[Test]
    #[DataProvider('provideNests')]
    public function test_is_shallow_counts_the_groups_a_construct_nests(string $pattern, bool $shallow): void
    {
        $this->assertSame($shallow, PatternLayout::isShallow(RegexParser::create(['cache' => null])->parse($pattern)->pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, shallow: bool}>
     */
    public static function provideNests(): iterable
    {
        yield 'two groups' => ['pattern' => '/((a))/', 'shallow' => true];
        yield 'three groups' => ['pattern' => '/(((a)))/', 'shallow' => false];
        yield 'a setting does not count' => ['pattern' => '/((?i)(a))/', 'shallow' => true];
        yield 'an empty scope beside a group' => ['pattern' => '/((?i:)(a))/', 'shallow' => true];
        yield 'an empty scope in two groups' => ['pattern' => '/(((?i:)))/', 'shallow' => false];
        yield 'a scope around two groups' => ['pattern' => '/(?i:((a)))/', 'shallow' => false];
        yield 'a class does not count' => ['pattern' => '/(([(]))/', 'shallow' => true];
        yield 'a conditional counts' => ['pattern' => '/(a)(?(1)((b)))/', 'shallow' => false];
        yield 'DEFINE counts' => ['pattern' => '/(?(DEFINE)((a)))/', 'shallow' => false];
    }
}
