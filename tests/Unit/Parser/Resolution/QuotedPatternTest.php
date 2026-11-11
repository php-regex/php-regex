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

namespace PHPRegex\Tests\Unit\Parser\Resolution;

use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Resolution\QuotedPattern;
use PHPRegex\Tests\Support\EngineProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern written around preg_quote() texts nobody knows, as
 * '/^(\d+)-'.preg_quote($id, '/').'$/': its parts are the literal strings,
 * and null where a quoted text stands.
 *
 * One thing can be said of it without the text: where every quoted text is
 * read as literal characters, its groups are those of the pattern with the
 * text read as any string. Each row below is what the class gave before it
 * moved to the parser package.
 */
final class QuotedPatternTest extends TestCase
{
    /**
     * Which arm a release takes changes no group number nor name: the
     * groups a quoted text must leave as they are read the same for every
     * target.
     */
    #[Test]
    #[DataProvider('provideReleases')]
    public function test_quoted_pattern_moved_reads_as_before(string $release): void
    {
        $parser = RegexParser::create(['php_version' => '8.4', 'pcre_version' => $release]);

        $this->assertSame('/(?(VERSION>=10.45)(a)|(?<n>b))(?:[\s\S]*)/', QuotedPattern::of(['/(?(VERSION>=10.45)(a)|(?<n>b))', null, '/'], $parser));

        // The engine takes one arm, the yes arm from PCRE2 10.45 (10.49 matches
        // 'ax.y') and the no arm before (10.42 matches 'bx.y'), and writes the
        // same groups for either: 0, 1, n and 2.
        $written = '/(?(VERSION>=10.45)(a)|(?<n>b))'.preg_quote('x.y', '/').'/';
        $groups = [];
        foreach (['ax.y', 'bx.y'] as $subject) {
            if (1 === preg_match($written, $subject, $matches, \PREG_UNMATCHED_AS_NULL)) {
                $groups[] = array_keys($matches);
            }
        }
        $this->assertSame([[0, 1, 'n', 2]], $groups);
    }

    /**
     * @return iterable<string, array{release: string}>
     */
    public static function provideReleases(): iterable
    {
        yield '10.44 takes the no arm' => ['release' => '10.44'];
        yield '10.49 takes the yes arm' => ['release' => '10.49'];
    }

    /**
     * The engine speaks first: with a text quoted in each place, whatever
     * the text, the pattern has the groups the row says. The class then
     * reads the pattern, and its stand-in has the same groups wherever
     * the engine compiles it.
     *
     * @param non-empty-list<string|null> $parts
     * @param list<int|string>            $groups   the keys the engine gives the groups of the written pattern
     * @param bool                        $compiles whether the engine compiles the stand-in
     */
    #[Test]
    #[DataProvider('providePositions')]
    public function test_quoted_pattern_moved_reads_each_position_as_before(array $parts, string $expected, array $groups, bool $compiles): void
    {
        foreach (['', 'a', 'a.b', '1', '(x)|[y]', "\u{e9}"] as $text) {
            $written = self::written($parts, $text);
            $this->assertTrue(EngineProbe::compiles($written), 'The engine refuses '.$written.'.');
            $this->assertSame($groups, self::groupKeys($written), $written);
        }

        $this->assertSame($expected, QuotedPattern::of($parts, RegexParser::create()));
        $this->assertSame($compiles ? $groups : null, self::groupKeys($expected), $expected);
    }

    /**
     * @return iterable<string, array{parts: non-empty-list<string|null>, expected: string, groups: list<int|string>, compiles: bool}>
     */
    public static function providePositions(): iterable
    {
        // preg_match('/^(\d+)-' . preg_quote('x.y', '/') . '$/', '12-x.y', $m) -> 1, $m = ['12-x.y', '12'].
        yield 'after a group' => ['parts' => ['/^(\d+)-', null, '$/'], 'expected' => '/^(\d+)-(?:[\s\S]*)$/', 'groups' => [0, 1], 'compiles' => true];
        // The quoted text may be empty: preg_match('/(' . preg_quote('', '/') . ')/', 'a', $m) -> 1, $m = ['', ''].
        yield 'inside a group' => ['parts' => ['/(', null, ')/'], 'expected' => '/((?:[\s\S]*))/', 'groups' => [0, 1], 'compiles' => true];
        yield 'closing the pattern body' => ['parts' => ['/(a)', null, '/'], 'expected' => '/(a)(?:[\s\S]*)/', 'groups' => [0, 1], 'compiles' => true];
        yield 'after a named group' => ['parts' => ['/(?<id>\d+)-', null, '$/'], 'expected' => '/(?<id>\d+)-(?:[\s\S]*)$/', 'groups' => [0, 'id', 1], 'compiles' => true];
        yield 'two quoted texts' => ['parts' => ['/^', null, '-(\d+)-', null, '$/'], 'expected' => '/^(?:[\s\S]*)-(\d+)-(?:[\s\S]*)$/', 'groups' => [0, 1], 'compiles' => true];
        yield 'between bracket delimiters' => ['parts' => ['{^(a)', null, '$}'], 'expected' => '{^(a)(?:[\s\S]*)$}', 'groups' => [0, 1], 'compiles' => true];
        yield 'beside multi-byte characters' => ['parts' => ["/\u{e9}(a)", null, "\u{fc}/u"], 'expected' => "/\u{e9}(a)(?:[\\s\\S]*)\u{fc}/u", 'groups' => [0, 1], 'compiles' => true];
        // The stand-in is returned as it is written: judging it is the
        // caller's. The engine refuses a lookbehind of any length.
        yield 'in a lookbehind' => ['parts' => ['/(?<=', null, ')(a)/'], 'expected' => '/(?<=(?:[\s\S]*))(a)/', 'groups' => [0, 1], 'compiles' => false];
    }

    /**
     * Where the quoted text is not read as literal characters, one text
     * shows it: the engine reads the subject with the pattern written
     * around that text as the row says, and does not with the text taken
     * for any string. The class reads no such pattern.
     *
     * @param non-empty-list<string|null> $parts
     * @param list<string>                $matches what preg_match() gives for the subject
     */
    #[Test]
    #[DataProvider('provideRefusedPositions')]
    public function test_a_quoted_text_that_is_not_read_as_literal_characters_is_refused(array $parts, string $text, string $subject, array $matches): void
    {
        $written = self::written($parts, $text);
        $given = [];
        $this->assertSame(1, preg_match($written, $subject, $given), $written);
        $this->assertSame($matches, $given, $written);

        $standIn = implode('', array_map(static fn (?string $part): string => $part ?? '(?:[\s\S]*)', $parts));
        $read = [];
        $this->assertNotSame($matches, 1 === @preg_match($standIn, $subject, $read) ? $read : null, $standIn);

        $this->assertNull(QuotedPattern::of($parts, RegexParser::create()));
    }

    /**
     * @return iterable<string, array{parts: non-empty-list<string|null>, text: string, subject: string, matches: list<string>}>
     */
    public static function provideRefusedPositions(): iterable
    {
        // Inside a class the quoted text is a set of characters, not a string: "y" alone is one of "xy".
        yield 'in a class' => ['parts' => ['/[a', null, '](b)/'], 'text' => 'xy', 'subject' => 'yb', 'matches' => ['yb', 'b']];
        // Digits make a quantifier, and the group repeated no time is not set.
        yield 'inside braces' => ['parts' => ['/(a){', null, '}b/'], 'text' => '0', 'subject' => 'b', 'matches' => ['b']];
        // An empty quoted text leaves the quantifier to the group.
        yield 'before a quantifier' => ['parts' => ['/(a)', null, '{0}b/'], 'text' => '', 'subject' => 'b', 'matches' => ['b']];
        // An empty quoted text makes a group that does not capture.
        yield 'opening a group' => ['parts' => ['/(', null, '?:a)/'], 'text' => '', 'subject' => 'a', 'matches' => ['a']];
        // \Q quotes what follows, the parentheses of the group among it.
        yield 'after a backslash' => ['parts' => ['/\\', null, '(a)/'], 'text' => 'Q', 'subject' => '(a)', 'matches' => ['(a)']];
        // Its digits continue the escape: \x41 is "A".
        yield 'after a hex escape' => ['parts' => ['/(a)\x', null, '/'], 'text' => '41', 'subject' => 'aA', 'matches' => ['aA', 'a']];
        // preg_quote('\E') is '\\E', which ends \Q: the group after it captures.
        yield 'inside a quoted run' => ['parts' => ['/\Q', null, '(a)\E/'], 'text' => '\E', 'subject' => '\a', 'matches' => ['\a', 'a']];
    }

    /**
     * A pattern the engine compiles with no text is not read.
     */
    #[Test]
    public function test_a_pattern_that_does_not_compile_is_refused(): void
    {
        $parts = ['/(', null, '/'];
        foreach (['', 'a', 'a.b', '1'] as $text) {
            $this->assertFalse(EngineProbe::compiles(self::written($parts, $text)));
        }

        $this->assertNull(QuotedPattern::of($parts, RegexParser::create()));
    }

    /**
     * The delimiter must open a literal part, a quoted text must stand
     * between two parts, and the last part closes the pattern: parts of
     * another shape are not read, whatever the engine makes of them.
     *
     * @param non-empty-list<string|null> $parts
     */
    #[Test]
    #[DataProvider('provideShapesThatAreNotRead')]
    public function test_parts_of_another_shape_are_refused(array $parts): void
    {
        $this->assertNull(QuotedPattern::of($parts, RegexParser::create()));
    }

    /**
     * @return iterable<string, array{parts: non-empty-list<string|null>}>
     */
    public static function provideShapesThatAreNotRead(): iterable
    {
        yield 'opening the pattern' => ['parts' => [null, '(a)/']];
        yield 'closing the pattern' => ['parts' => ['/(a)', null]];
        yield 'no quoted text' => ['parts' => ['/(a)/']];
        yield 'after an empty first part' => ['parts' => ['', null, '/(a)/']];
        yield 'after a first part starting with a space' => ['parts' => [' /(a)', null, '/']];
        yield 'after a first part starting with a tab' => ['parts' => ["\t/(a)", null, '/']];
    }

    /**
     * The pattern as the code writes it, with one text quoted in each
     * place for the delimiter the first part opens with.
     *
     * @param non-empty-list<string|null> $parts
     */
    private static function written(array $parts, string $text): string
    {
        return implode('', array_map(static fn (?string $part): string => $part ?? preg_quote($text, $parts[0][0] ?? null), $parts));
    }

    /**
     * The keys the engine gives the groups of a pattern, numbers and
     * names in their order, whatever the subject: preg_match_all() fills
     * one list for each group even where nothing matches. Null when the
     * engine does not compile the pattern.
     *
     * @return list<int|string>|null
     */
    private static function groupKeys(string $pattern): ?array
    {
        $matches = [];

        return false === @preg_match_all($pattern, '', $matches) ? null : array_keys($matches);
    }
}
