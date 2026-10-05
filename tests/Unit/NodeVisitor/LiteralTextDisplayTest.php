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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Explain\AsciiTreeRenderer;
use PHPRegex\Explain\RailroadSvgRenderer;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A literal is shown by the text it matches, not as pattern source: a
 * backslash in it is a plain byte, written doubled, so that it never reads
 * as the start of an escape next to a spelled byte.
 */
final class LiteralTextDisplayTest extends TestCase
{
    /**
     * @return iterable<string, array{pattern: string, subject: string, shown: string}>
     */
    public static function provideTreeLiterals(): iterable
    {
        yield 'quoted backslash and letters' => ['pattern' => '/\Qa\x07\E/', 'subject' => 'a\x07', 'shown' => "Literal ('a\\\\x07')"];
        yield 'quoted backslash before a control byte' => ['pattern' => "/\\Q\\\x07\\E/", 'subject' => "\\\x07", 'shown' => "Literal ('\\\\\\x07')"];
        yield 'escaped backslash' => ['pattern' => '/\\\\/', 'subject' => '\\', 'shown' => "Literal ('\\\\')"];
        yield 'control byte' => ['pattern' => "/\x07/", 'subject' => "\x07", 'shown' => "Literal ('\\x07')"];
    }

    #[Test]
    #[DataProvider('provideTreeLiterals')]
    public function test_the_tree_shows_a_literal_as_text(string $pattern, string $subject, string $shown): void
    {
        // Oracle: the pattern matches the text the literal is shown as.
        $this->assertSame(1, preg_match($pattern, $subject));

        $tree = Regex::create(['cache' => null])->parse($pattern)->accept(new AsciiTreeRenderer());

        $this->assertStringContainsString($shown, $tree);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, shown: string}>
     */
    public static function provideRailroadRuns(): iterable
    {
        yield 'backslash before a control byte' => ['pattern' => "/C:\\\\\x07dir/", 'subject' => "C:\\\x07dir", 'shown' => '>C:\\\\\x07dir<'];
        yield 'backslash and Q before a control byte' => ['pattern' => "/a\\\\Q\x01b/", 'subject' => "a\\Q\x01b", 'shown' => '>a\\\\Q\x01b<'];
        // A character written as an escape keeps its spelling in the run.
        yield 'escape written in the run' => ['pattern' => "/a\\x41\\\\Q\x01b(c)/", 'subject' => "aA\\Q\x01bc", 'shown' => '>a\x41\\\\Q\x01b<'];
        // A run that ends the sequence with an escape is shown whole, and a
        // run before a group is shown before it, apart from the run after.
        yield 'escape ending the sequence' => ['pattern' => '/a\x41/', 'subject' => 'aA', 'shown' => '>a\x41<'];
        yield 'run before a group' => ['pattern' => '/ab(c)d/', 'subject' => 'abcd', 'shown' => '>ab<'];
        // A run longer than a line keeps every space when it is cut in
        // lines, at the end of the sequence and before a group.
        yield 'long run with double spaces' => ['pattern' => '/one  two  three  four  five  six  seven/', 'subject' => 'one  two  three  four  five  six  seven', 'shown' => '> six  seven<'];
        yield 'long run with double spaces before a group' => ['pattern' => '/one  two  three  four  five  six  seven(c)/', 'subject' => 'one  two  three  four  five  six  sevenc', 'shown' => '> six  seven<'];
    }

    #[Test]
    #[DataProvider('provideRailroadRuns')]
    public function test_the_railroad_shows_a_run_of_literals_as_text(string $pattern, string $subject, string $shown): void
    {
        $this->assertSame(1, preg_match($pattern, $subject));

        $svg = Regex::create(['cache' => null])->parse($pattern)->accept(new RailroadSvgRenderer());

        $this->assertIsString($svg);
        $this->assertStringContainsString($shown, (string) $svg);
    }
}
