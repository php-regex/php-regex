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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Recompiling a parsed pattern must give the exact same text back: neither the
 * whitespace /x makes ignorable nor the optional escapes and alternative
 * spellings an author picked are the compiler's to rewrite.
 */
final class RoundTripFidelityTest extends TestCase
{
    #[Test]
    #[DataProvider('provideExtendedPatterns')]
    public function test_extended_patterns_recompile_unchanged(string $pattern): void
    {
        $recompiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame($pattern, $recompiled);
    }

    /**
     * Escaping punctuation is optional in most places, and a backreference or
     * a code point can be spelled several ways. Recompiling must keep the
     * spelling the author chose.
     */
    #[Test]
    #[DataProvider('provideSpellings')]
    public function test_spelling_is_preserved(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'PCRE must accept the pattern');

        $recompiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame($pattern, $recompiled);
    }

    /**
     * Quoted text means something else once \Q...\E is gone, so those literals
     * must still be escaped rather than copied from the source.
     */
    #[Test]
    #[DataProvider('provideQuotedPatterns')]
    public function test_quoted_literals_are_still_escaped(string $pattern, string $expected): void
    {
        $recompiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame($expected, $recompiled);
        $this->assertNotFalse(@preg_match($recompiled, ''));
    }

    #[Test]
    public function test_pretty_printing_still_reflows_the_pattern(): void
    {
        $ast = Regex::create()->parse('/a  b/x');

        $this->assertNotSame('/a  b/x', $ast->accept(new PatternPrinter(pretty: true)));
    }

    #[Test]
    public function test_an_ast_built_without_a_source_still_compiles(): void
    {
        $ast = Regex::create()->parse('/a b/x');
        $rebuilt = new RegexNode($ast->pattern, $ast->flags, $ast->delimiter, 0, 3);

        $this->assertSame('/ab/x', $rebuilt->accept(new PatternPrinter()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExtendedPatterns(): iterable
    {
        yield 'single space' => ['/a b/x'];
        yield 'leading and trailing spaces' => ['/  a  b  /x'];
        yield 'inline x' => ['/(?x)  a b  /'];
        yield 'scoped x' => ['/(?x:  a b  )c d/'];
        yield 'around alternation' => ['/a  |  b/x'];
        yield 'one space before the bar, two after' => ['/a |  b/x'];
        yield 'two spaces before the bar, one after' => ['/a  | b/x'];
        yield 'around alternation under an inline x' => ['/((?x)x y z | a b c)/'];
        yield 'inside a group' => ['/(  a b  )c/x'];
        yield 'inside a non capturing group' => ['/(?: a | b )/x'];
        yield 'inside a branch reset' => ['/(?| a | b )/x'];
        yield 'named group' => ['/(?<n> a )/x'];
        yield 'lookahead' => ['/(?= a )/x'];
        yield 'lookbehind' => ['/(?<= a )/x'];
        yield 'atomic group' => ['/(?> a )/x'];
        yield 'comment line' => ["/a # comment\nb/x"];
        yield 'comment after inline x' => ["/(?x)a # comment\nb/"];
        yield 'documented multi line pattern' => ["/\n  (\\w+)   # word\n  \\s*      # spacing\n  (\\d+)    # number\n/x"];
        yield 'space inside a character class' => ['/[ a b ]/x'];
        yield 'escaped space' => ['/a\\ b/x'];
        yield 'no extended mode' => ['/a b/'];
        yield 'empty pattern' => ['//x'];
        yield 'only whitespace' => ['/ /x'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSpellings(): iterable
    {
        yield 'optional escape kept' => ['/[a-z0-9_\\-]+/'];
        yield 'optional escape absent' => ['/[a-z0-9_-]+/'];
        yield 'escaped brace' => ['/\\{foo\\}/'];
        yield 'bare brace' => ['/{foo}/'];
        yield 'escaped bracket in class' => ['/[@\\[\\]]/'];
        yield 'bare bracket in class' => ['/[@[\\]]/'];
        yield 'closing bracket outside a class' => ['/foo\\]/'];
        yield 'bare closing bracket outside a class' => ['/foo]/'];
        yield 'leading bracket in class' => ['/[]\\^-]/'];
        yield 'escaped quote' => ["/lang=['\\\"]/"];
        yield 'escaped star in class' => ['/[\\s\\*]/'];
        yield 'bare star in class' => ['/[\\s*]/'];
        yield 'python backreference' => ['/(?<x>a)(?P=x)/'];
        // A name of non-ASCII letters keeps its syntax as well
        // (preg_match on "aa" is 1 for both).
        yield 'python backreference by a non-ASCII name' => ['/(?<אABC>a)(?P=אABC)/u'];
        yield 'braced backreference by a non-ASCII name' => ['/(?<éa>a)\\k{éa}/u'];
        yield 'k backreference' => ['/(?<x>a)\\k<x>/'];
        yield 'numeric backreference' => ['/(a)\\1/'];
        yield 'bell escape' => ['/[\\a-z]/'];
        yield 'hex escape' => ['/[\\x07-z]/'];
        yield 'raw multi byte character' => ['/[«»“”]/'];
        yield 'escaped multi byte character' => ['/[\\¡\\¿]/'];
        yield 'assertion condition' => ['/(?(?<!^--) +\\n|  +\\n)/m'];
        yield 'group condition' => ['/(a)(?(1)yes|no)/'];

        // The four ways of naming a group, and the two of writing a script
        // run, all mean the same thing and none of them is the compiler's to
        // pick.
        yield 'named group with angle brackets' => ['/(?<name>x)/'];
        yield 'named group with quotes' => ["/(?'name'x)/"];
        yield 'python named group' => ['/(?P<name>x)/'];
        yield 'script run written short' => ['/(*sr:\\d+)/'];
        yield 'script run spelled out' => ['/(*script_run:\\d+)/'];
        yield 'mark written short' => ['/(*:label)/'];
        yield 'mark spelled out' => ['/(*MARK:label)/'];
        yield 'mark in the middle of a pattern' => ['/a(*:label)b/'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideQuotedPatterns(): iterable
    {
        yield 'quoted space under x' => ['/\\Q \\E/x', '/\\ /x'];
        yield 'quoted hash under x' => ['/\\Q#\\E/x', '/\\#/x'];
        yield 'quoted character class' => ['/\\Q[a-z]\\E/', '/\\[a-z\\]/'];
        yield 'quoted metacharacters' => ['/\\Qa+b\\E/', '/a\\+b/'];
        // Under x in UTF mode PCRE also skips U+0085, U+200E, U+200F,
        // U+2028 and U+2029 written raw.
        yield 'quoted next line under x and (*UTF)' => ["/(*UTF)a\\Q\u{85}\\Eb/x", '/(*UTF)a\\x{85}b/x'];
        yield 'quoted next line under x and u' => ["/a\\Q\u{85}\\Eb/xu", '/a\\x{85}b/xu'];
        yield 'quoted left-to-right mark under x and u' => ["/a\\Q\u{200E}\\Eb/xu", '/a\\x{200E}b/xu'];
        yield 'quoted right-to-left mark under x and u' => ["/a\\Q\u{200F}\\Eb/xu", '/a\\x{200F}b/xu'];
        yield 'quoted line separator under x and u' => ["/a\\Q\u{2028}\\Eb/xu", '/a\\x{2028}b/xu'];
        yield 'quoted paragraph separator under x and u' => ["/a\\Q\u{2029}\\Eb/xu", '/a\\x{2029}b/xu'];
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49: the quoted character is matched,
     * where written raw under x it would be skipped.
     */
    #[Test]
    #[DataProvider('provideQuotedPatternWhiteSpace')]
    public function test_quoted_pattern_white_space_keeps_its_meaning(string $pattern, string $subject): void
    {
        $recompiled = Regex::create()->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame(1, preg_match($pattern, $subject));
        $this->assertSame(1, preg_match($recompiled, $subject), $recompiled);
        $this->assertSame(0, preg_match($recompiled, 'ab'), $recompiled);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideQuotedPatternWhiteSpace(): iterable
    {
        yield 'next line under (*UTF)' => ['pattern' => "/(*UTF)^a\\Q\u{85}\\Eb$/x", 'subject' => "a\u{85}b"];
        yield 'line separator under u' => ['pattern' => "/^a\\Q\u{2028}\\Eb$/xu", 'subject' => "a\u{2028}b"];
    }
}
