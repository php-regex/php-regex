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
     * @param non-empty-list<string|null> $parts
     */
    #[Test]
    #[DataProvider('providePositions')]
    public function test_quoted_pattern_moved_reads_each_position_as_before(array $parts, ?string $expected): void
    {
        $this->assertSame($expected, QuotedPattern::of($parts, RegexParser::create()));
    }

    /**
     * @return iterable<string, array{parts: non-empty-list<string|null>, expected: ?string}>
     */
    public static function providePositions(): iterable
    {
        // preg_match('/^(\d+)-' . preg_quote('x.y', '/') . '$/', '12-x.y', $m) -> 1, $m = ['12-x.y', '12'].
        yield 'after a group' => ['parts' => ['/^(\d+)-', null, '$/'], 'expected' => '/^(\d+)-(?:[\s\S]*)$/'];
        // The quoted text may be empty: preg_match('/(' . preg_quote('', '/') . ')/', 'a', $m) -> 1, $m = ['', ''].
        yield 'inside a group' => ['parts' => ['/(', null, ')/'], 'expected' => '/((?:[\s\S]*))/'];
        yield 'closing the pattern body' => ['parts' => ['/(a)', null, '/'], 'expected' => '/(a)(?:[\s\S]*)/'];
        yield 'after a named group' => ['parts' => ['/(?<id>\d+)-', null, '$/'], 'expected' => '/(?<id>\d+)-(?:[\s\S]*)$/'];
        yield 'two quoted texts' => ['parts' => ['/^', null, '-(\d+)-', null, '$/'], 'expected' => '/^(?:[\s\S]*)-(\d+)-(?:[\s\S]*)$/'];
        yield 'between bracket delimiters' => ['parts' => ['{^(a)', null, '$}'], 'expected' => '{^(a)(?:[\s\S]*)$}'];
        yield 'beside multi-byte characters' => ['parts' => ["/\u{e9}(a)", null, "\u{fc}/u"], 'expected' => "/\u{e9}(a)(?:[\\s\\S]*)\u{fc}/u"];
        // The stand-in is returned as it is written: judging it is the caller's.
        yield 'in a lookbehind' => ['parts' => ['/(?<=', null, ')(a)/'], 'expected' => '/(?<=(?:[\s\S]*))(a)/'];

        // Inside a class the quoted text is a set of characters, not a string.
        yield 'in a class' => ['parts' => ['/[a', null, '](b)/'], 'expected' => null];
        // Digits make a quantifier: preg_match('/(a){' . preg_quote('0') . '}b/', 'b', $m) -> 1, $m = ['b'].
        yield 'inside braces' => ['parts' => ['/(a){', null, '}b/'], 'expected' => null];
        // An empty quoted text leaves the quantifier to the group:
        // preg_match('/(a)' . preg_quote('') . '{0}b/', 'b', $m) -> 1, $m = ['b'].
        yield 'before a quantifier' => ['parts' => ['/(a)', null, '{0}b/'], 'expected' => null];
        // An empty quoted text makes a group that does not capture:
        // preg_match('/(' . preg_quote('') . '?:a)/', 'a', $m) -> 1, $m = ['a'].
        yield 'opening a group' => ['parts' => ['/(', null, '?:a)/'], 'expected' => null];
        // \Q quotes what follows: preg_match('/\\' . preg_quote('Q') . '(a)/', '(a)', $m) -> 1, $m = ['(a)'].
        yield 'after a backslash' => ['parts' => ['/\\', null, '(a)/'], 'expected' => null];
        // Its digits continue the escape: preg_match('/(a)\x' . preg_quote('41', '/') . '/', 'aA', $m) -> 1, $m = ['aA', 'a'].
        yield 'after a hex escape' => ['parts' => ['/(a)\x', null, '/'], 'expected' => null];
        // preg_quote('\E') -> '\\E', which ends \Q: preg_match('/\Q' . preg_quote('\E') . '(a)\E/', '\a', $m) -> 1, $m = ['\a', 'a'].
        yield 'inside a quoted run' => ['parts' => ['/\Q', null, '(a)\E/'], 'expected' => null];
        yield 'in a pattern that does not compile' => ['parts' => ['/(', null, '/'], 'expected' => null];

        // The delimiter must open a literal part, and the last part closes the pattern.
        yield 'opening the pattern' => ['parts' => [null, '(a)/'], 'expected' => null];
        yield 'closing the pattern' => ['parts' => ['/(a)', null], 'expected' => null];
        yield 'no quoted text' => ['parts' => ['/(a)/'], 'expected' => null];
        yield 'after an empty first part' => ['parts' => ['', null, '/(a)/'], 'expected' => null];
        yield 'after a first part starting with a space' => ['parts' => [' /(a)', null, '/'], 'expected' => null];
        yield 'after a first part starting with a tab' => ['parts' => ["\t/(a)", null, '/'], 'expected' => null];
    }
}
