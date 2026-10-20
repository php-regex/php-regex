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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\AnsiMarkup;
use PHPRegex\Linter\Formatter\TagMarkup;
use PHPRegex\Linter\Formatter\TextPreservingHighlighter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The highlighter `regex lint` falls back on when a pattern body does not
 * parse: it colors escapes, classes, groups, anchors and quantifiers, and
 * never changes a character of the body.
 */
final class TextPreservingHighlighterTest extends TestCase
{
    /**
     * @return iterable<string, array{body: string, expected: string}>
     */
    public static function provideBodies(): iterable
    {
        yield 'nothing' => ['body' => '', 'expected' => ''];
        yield 'plain text' => ['body' => 'plain', 'expected' => 'plain'];
        yield 'an escape' => ['body' => 'a\\d', 'expected' => 'a<fg=green>\\d</>'];
        yield 'a trailing backslash' => ['body' => 'a\\', 'expected' => 'a\\'];
        yield 'a class, its members left as they are' => ['body' => '[a(+]b', 'expected' => '<fg=cyan>[</>a(+<fg=cyan>]</>b'];
        yield 'an escape in a class' => ['body' => '[\\]]', 'expected' => '<fg=cyan>[</><fg=green>\\]</><fg=cyan>]</>'];
        yield 'groups, alternation and the dot' => ['body' => '(a|.)', 'expected' => '<fg=cyan>(</>a<fg=cyan>|</><fg=cyan>.</><fg=cyan>)</>'];
        yield 'anchors' => ['body' => '^a$', 'expected' => '<fg=cyan>^</>a<fg=cyan>$</>'];
        yield 'quantifiers' => ['body' => 'a+b*c?', 'expected' => 'a<fg=yellow>+</>b<fg=yellow>*</>c<fg=yellow>?</>'];
        yield 'a count' => ['body' => 'a{2}', 'expected' => 'a<fg=yellow>{2}</>'];
        yield 'a range of counts' => ['body' => 'a{1,3}b', 'expected' => 'a<fg=yellow>{1,3}</>b'];
        yield 'an open range' => ['body' => 'a{1,}', 'expected' => 'a<fg=yellow>{1,}</>'];
        yield 'a brace that counts nothing' => ['body' => 'a{b', 'expected' => 'a{b'];
        yield 'a brace left open' => ['body' => 'a{1', 'expected' => 'a{1'];
        yield 'a brace closed after a comma and a letter' => ['body' => 'a{1,x}', 'expected' => 'a{1,x}'];
        yield 'a brace at the end' => ['body' => 'a{', 'expected' => 'a{'];
        yield 'a brace around a letter' => ['body' => 'a{x}', 'expected' => 'a{x}'];
    }

    #[Test]
    #[DataProvider('provideBodies')]
    public function test_a_body_is_colored_without_a_character_changed(string $body, string $expected): void
    {
        $this->assertSame($expected, (new TextPreservingHighlighter(new TagMarkup()))->highlight($body));
        $this->assertSame($body, (new TextPreservingHighlighter(new AnsiMarkup(false)))->highlight($body));
        $this->assertSame($body, preg_replace('/\e\[[0-9;]*m/', '', (new TextPreservingHighlighter(new AnsiMarkup(true)))->highlight($body)));
    }

    #[Test]
    public function test_ansi_colors_are_the_report_colors(): void
    {
        $this->assertSame("a\033[32m\\d\033[0m\033[33m{2}\033[0m\033[36m$\033[0m", (new TextPreservingHighlighter(new AnsiMarkup(true)))->highlight('a\\d{2}$'));
    }
}
