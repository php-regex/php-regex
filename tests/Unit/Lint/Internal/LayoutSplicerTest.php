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

namespace PHPRegex\Tests\Unit\Lint\Internal;

use PHPRegex\Linter\Internal\LayoutSplicer;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A rewrite written into the pattern as its author laid it out: only the
 * parts the rewrite changed are written again, in place, and the newlines,
 * indents and comments around them are kept byte for byte.
 */
final class LayoutSplicerTest extends TestCase
{
    private RegexParser $parser;

    protected function setUp(): void
    {
        $this->parser = RegexParser::create(['cache' => null]);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subject: string}>
     */
    public static function provideRewrites(): iterable
    {
        yield 'quantifier before a comment' => [
            'pattern' => "/a{1}  # comment\n/x",
            'expected' => "/a  # comment\n/x",
            'subject' => 'a',
        ];

        yield 'two groups on two lines' => [
            'pattern' => "/ (?:a|b)\n  (?:c|d) /x",
            'expected' => "/ [ab]\n  [cd] /x",
            'subject' => 'bd',
        ];

        yield 'escaped space turned into a class' => [
            'pattern' => "/(?:\\ |a)  # sp\n/x",
            'expected' => "/[ a]  # sp\n/x",
            'subject' => ' ',
        ];

        yield 'slash under another delimiter' => [
            'pattern' => "#(?:/|a)  \\# sp\n#x",
            'expected' => "#[/a]  \\# sp\n#x",
            'subject' => '/#sp',
        ];

        yield 'flags kept' => [
            'pattern' => "/[0-9]  # digit\n/xi",
            'expected' => "/\\d  # digit\n/xi",
            'subject' => '7',
        ];

        yield 'literals joined around a rewrite' => [
            'pattern' => "/f o o{1} # c\n/x",
            'expected' => "/f o o # c\n/x",
            'subject' => 'foo',
        ];

        yield 'literals joined over a newline, useless flag dropped' => [
            'pattern' => "/a{1}\n b  # c\n/xs",
            'expected' => "/a\n b  # c\n/x",
            'subject' => 'ab',
        ];

        yield 'a run of escapes counted' => [
            'pattern' => "/a\\d\\d\\d\\d\\d\\d\\d\\d # eight\n/x",
            'expected' => "/a\\d{8} # eight\n/x",
            'subject' => 'a12345678',
        ];

        yield 'bytes that are no UTF-8 joined around a rewrite' => [
            'pattern' => "/a{1} \xFF\xFE # c\n/x",
            'expected' => "/a \xFF\xFE # c\n/x",
            'subject' => "a\xFF\xFE",
        ];

        yield 'tabs, nested groups and lookarounds' => [
            'pattern' => "/\n\t\t\t&\t\t\t# 1. entity prefix\n\t\t\t(?=\t\t\t# 2. followed by:\n\t\t\t(?:\n"
                ."\t\t\t\tfrac(?:1(?:2|4)|34)|\n"
                ."\t\t\t\tnot(?!in(?:E|v(?:a|b|c)|);)|\n"
                ."\t\t\t\ts(?:ect|up(?:1|2|3))\n"
                ."\t\t\t)\n\t\t\t(?:[^;]|$))\t#  b. and not followed by a semicolon\n\t\t\t# S = study, for efficiency\n\t\t\t/Sx",
            'expected' => "/\n\t\t\t&\t\t\t# 1. entity prefix\n\t\t\t(?=\t\t\t# 2. followed by:\n\t\t\t(?:\n"
                ."\t\t\t\tfrac(?:1[24]|34)|\n"
                ."\t\t\t\tnot(?!in(?:E|v[abc]|);)|\n"
                ."\t\t\t\ts(?:ect|up[123])\n"
                ."\t\t\t)\n\t\t\t(?:[^;]|$))\t#  b. and not followed by a semicolon\n\t\t\t# S = study, for efficiency\n\t\t\t/Sx",
            'subject' => '&sup2x',
        ];
    }

    #[Test]
    #[DataProvider('provideRewrites')]
    public function test_splice_writes_only_the_rewritten_parts(string $pattern, string $expected, string $subject): void
    {
        // Oracle: both compile and agree on the subject.
        $this->assertSame(1, preg_match($pattern, $subject, $before));
        $this->assertSame(1, preg_match($expected, $subject, $after));
        $this->assertSame($before, $after);

        $original = $this->parser->parse($pattern);
        $rewritten = $original->accept(new Rewriter(factorize: false));
        $this->assertInstanceOf(RegexNode::class, $rewritten);

        $this->assertSame($expected, (new LayoutSplicer($this->parser))->splice($original, $rewritten));
    }

    #[Test]
    public function test_splice_gives_back_an_unchanged_pattern_as_written(): void
    {
        $pattern = "/a  # one\n\tb/x";
        $original = $this->parser->parse($pattern);

        $this->assertSame($pattern, (new LayoutSplicer($this->parser))->splice($original, $original));
    }

    #[Test]
    public function test_splice_refuses_a_tree_without_its_text(): void
    {
        $tree = new RegexNode(new LiteralNode('a', 0, 1), 'x', '/', 0, 1);

        $this->assertNull((new LayoutSplicer($this->parser))->splice($tree, new RegexNode(new LiteralNode('b', 0, 1), 'x', '/', 0, 1)));
    }

    #[Test]
    public function test_splice_writes_again_the_list_a_node_was_added_to(): void
    {
        $original = $this->parser->parse("/a # one\n/x");
        $this->assertInstanceOf(SequenceNode::class, $original->pattern);
        [$literal, $comment] = $original->pattern->children;

        // A node before the first one has no place in the text to go: the
        // sequence holding it is written again as a whole.
        $rewritten = new RegexNode(new SequenceNode([new LiteralNode('b', 0, 0), $literal, $comment], 0, 0), 'x', '/', 0, 0);

        $this->assertSame("/ba# one\n/x", (new LayoutSplicer($this->parser))->splice($original, $rewritten));
    }

    #[Test]
    public function test_splice_drops_a_node_the_rewrite_removed(): void
    {
        $original = $this->parser->parse("/a # one\n b/x");
        $this->assertInstanceOf(SequenceNode::class, $original->pattern);
        [$literal, $comment] = $original->pattern->children;

        $rewritten = new RegexNode(new SequenceNode([$literal, $comment], 0, 0), 'x', '/', 0, 0);

        $this->assertSame("/a # one\n /x", (new LayoutSplicer($this->parser))->splice($original, $rewritten));
    }

    #[Test]
    public function test_splice_refuses_a_text_that_does_not_parse(): void
    {
        $original = $this->parser->parse('/a/x');

        $rewritten = new RegexNode(new GroupNode(new LiteralNode('a', 0, 0), GroupType::Named, '1bad', null, 0, 0), 'x', '/', 0, 0);

        $this->assertNull((new LayoutSplicer($this->parser))->splice($original, $rewritten));
    }

    #[Test]
    public function test_splice_refuses_a_text_that_does_not_read_back_as_the_rewrite(): void
    {
        $original = $this->parser->parse('/a b/x');

        // Without x, the space the text keeps would be matched.
        $rewritten = new RegexNode($original->pattern, '', '/', 0, 0);

        $this->assertNull((new LayoutSplicer($this->parser))->splice($original, $rewritten));
    }
}
