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

use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The highlighters write the pattern back as it is written, their markup
 * aside: an inline setting "(?i)" and an empty scope "(?i:)" are two
 * patterns, the first making the rest caseless, the second nothing; a
 * verb keeps the name it is given, a callout its argument.
 */
final class HighlightWritesThePatternBackTest extends TestCase
{
    #[Test]
    #[DataProvider('provideInlineFlags')]
    public function test_the_highlight_writes_the_inline_flags_back_as_written(string $pattern, int $matchesCapitalA): void
    {
        $this->assertSame($matchesCapitalA, preg_match($pattern, 'A'), 'Oracle: what the pattern makes of "A".');

        $ast = Regex::create(['cache' => null])->parse($pattern);
        $body = substr($pattern, 1, (int) strrpos($pattern, '/') - 1);

        $this->assertSame($body, html_entity_decode(strip_tags($ast->accept(new HtmlHighlighter()))));
        $this->assertSame($body, preg_replace('/\e\[[\d;]*+m/', '', $ast->accept(new ConsoleHighlighter())));
    }

    /**
     * @return iterable<string, array{pattern: string, matchesCapitalA: int}>
     */
    public static function provideInlineFlags(): iterable
    {
        yield 'a setting' => ['pattern' => '/(?i)a/', 'matchesCapitalA' => 1];
        yield 'an empty scope' => ['pattern' => '/(?i:)a/', 'matchesCapitalA' => 0];
        yield 'an empty scope turning a flag off' => ['pattern' => '/(?-i:)a/i', 'matchesCapitalA' => 1];
        yield 'an empty scope resetting the flags' => ['pattern' => '/(?^:)a/i', 'matchesCapitalA' => 1];
        yield 'a setting resetting the flags' => ['pattern' => '/(?^)a/i', 'matchesCapitalA' => 0];
        yield 'a scope around a letter' => ['pattern' => '/(?i:x)a/', 'matchesCapitalA' => 0];
    }

    /**
     * Oracle: PCRE compiles each pattern and matches "ab" as the row says.
     */
    #[Test]
    #[DataProvider('provideNamedVerbs')]
    public function test_the_highlight_writes_a_named_verb_or_a_callout_back_as_written(string $pattern, int $matchesAb): void
    {
        $this->assertSame($matchesAb, preg_match($pattern, 'ab'), 'Oracle: what the pattern makes of "ab".');

        $ast = Regex::create(['cache' => null])->parse($pattern);
        $body = substr($pattern, 1, (int) strrpos($pattern, '/') - 1);

        $this->assertSame($body, html_entity_decode(strip_tags($ast->accept(new HtmlHighlighter()))));
        $this->assertSame($body, preg_replace('/\e\[[\d;]*+m/', '', $ast->accept(new ConsoleHighlighter())));
    }

    /**
     * @return iterable<string, array{pattern: string, matchesAb: int}>
     */
    public static function provideNamedVerbs(): iterable
    {
        yield 'a mark' => ['pattern' => '/(*MARK:tag)a/', 'matchesAb' => 1];
        yield 'a prune with a name' => ['pattern' => '/a(*PRUNE:p)b/', 'matchesAb' => 1];
        yield 'a then with a name' => ['pattern' => '/a(*THEN:t)b|c/', 'matchesAb' => 1];
        yield 'a skip to a mark' => ['pattern' => '/(*MARK:A)x(*SKIP:A)y/', 'matchesAb' => 0];
        yield 'a verb without a name' => ['pattern' => '/a(*SKIP)b/', 'matchesAb' => 1];
        // PHP sets no callout function: the engine passes over the callout.
        yield 'a callout with a string' => ['pattern' => '/a(?C"name")b/', 'matchesAb' => 1];
        yield 'a callout with a number' => ['pattern' => '/a(?C7)b/', 'matchesAb' => 1];
    }

    /**
     * Each kind of group is written back with its opener.
     */
    #[Test]
    #[DataProvider('provideGroups')]
    public function test_the_highlight_writes_each_group_back_as_written(string $pattern, int $matchesAb): void
    {
        $this->assertSame($matchesAb, preg_match($pattern, 'ab'), 'Oracle: what the pattern makes of "ab".');

        $ast = Regex::create(['cache' => null])->parse($pattern);
        $body = substr($pattern, 1, (int) strrpos($pattern, '/') - 1);

        $this->assertSame($body, html_entity_decode(strip_tags($ast->accept(new HtmlHighlighter()))));
        $this->assertSame($body, preg_replace('/\e\[[\d;]*+m/', '', $ast->accept(new ConsoleHighlighter())));
    }

    /**
     * @return iterable<string, array{pattern: string, matchesAb: int}>
     */
    public static function provideGroups(): iterable
    {
        yield 'a negative lookahead' => ['pattern' => '/a(?!c)/', 'matchesAb' => 1];
        yield 'a negative lookbehind' => ['pattern' => '/(?<!c)b/', 'matchesAb' => 1];
        yield 'a branch reset' => ['pattern' => '/(?|(a)|(c))b/', 'matchesAb' => 1];
        yield 'an atomic group' => ['pattern' => '/(?>a)b/', 'matchesAb' => 1];
    }
}
