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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\LayoutSplicer;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\LinearTimeAssertions;
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
    use LinearTimeAssertions;

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

        // The rewrite joins "\[" and "\]" into one literal, which prints
        // "\[\]" where the two print "\[]": the text read back is the
        // rewrite all the same.
        yield 'brackets the rewrite joined into one literal' => [
            'pattern' => "/\n  [0-9]   # a digit\n  \\[\\]    # a pair\n/x",
            'expected' => "/\n  \\d   # a digit\n  \\[\\]    # a pair\n/x",
            'subject' => '7[]',
        ];

        yield 'tabs, nested groups and lookarounds' => [
            'pattern' => "/\n\t\t\t&\t\t\t# 1. entity prefix\n\t\t\t(?=\t\t\t# 2. followed by:\n\t\t\t(?:\n"
                ."\t\t\t\tfrac(?:1(?:2|4)|34)|\n"
                ."\t\t\t\tnot(?!in(?:E|v(?:a|b|c)|);)|\n"
                ."\t\t\t\ts(?:ect|up(?:1|2|3))\n"
                ."\t\t\t)\n\t\t\t(?:[^;]|$))\t#  b. and not followed by a semicolon\n\t\t\t# S = study, for efficiency\n\t\t\t/Sx",
            'expected' => "/\n\t\t\t&\t\t\t# 1. entity prefix\n\t\t\t(?=\t\t\t# 2. followed by:\n\t\t\t(?:\n"
                ."\t\t\t\tfrac(?:1[24]|34)|\n"
                ."\t\t\t\tnot(?!in(?:E|v[a-c]|);)|\n"
                ."\t\t\t\ts(?:ect|up[1-3])\n"
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

    /**
     * Every class is rewritten, so a search through the original list for
     * each replacement finds none: four times the classes took some
     * fourteen times as long. 64 KB stays within the default parser limit.
     */
    #[Test]
    public function test_splice_matches_a_large_list_of_replaced_nodes_in_linear_work(): void
    {
        // The engine reads both spellings alike where both compile; at 8,000
        // pairs the classes of the original pass the compiled size PCRE2
        // allows, and the splice, which works on the text, still writes the
        // rewrite PCRE2 compiles.
        foreach ([100 => true, 8000 => false] as $pairs => $originalCompiles) {
            $before = '/'.str_repeat('[aa][bb]', $pairs).'/x';
            $after = '/'.str_repeat('[a][b]', $pairs).'/x';
            if ($originalCompiles) {
                $this->assertSame(1, preg_match($before, str_repeat('ab', $pairs)));
                $this->assertSame(0, preg_match($before, str_repeat('ab', $pairs - 1).'ba'));
            } else {
                $this->assertStringContainsString('too large', (string) EngineProbe::warning($before));
            }
            $this->assertSame(1, preg_match($after, str_repeat('ab', $pairs)));
            $this->assertSame(0, preg_match($after, str_repeat('ab', $pairs - 1).'ba'));

            $original = $this->parser->parse($before);
            $rewritten = $original->accept(new Rewriter(factorize: false));
            $this->assertInstanceOf(RegexNode::class, $rewritten);
            $this->assertSame($after, (new LayoutSplicer($this->parser))->splice($original, $rewritten));
        }

        // Parsed and rewritten once for each size: only the splice is timed.
        $trees = [];
        foreach ([2000, 8000] as $pairs) {
            $tree = $this->parser->parse('/'.str_repeat('[aa][bb]', $pairs).'/x');
            $rewritten = $tree->accept(new Rewriter(factorize: false));
            $this->assertInstanceOf(RegexNode::class, $rewritten);
            $trees[$pairs] = [$tree, $rewritten];
        }

        $this->assertLinearTime(function (int $pairs) use ($trees): void {
            (new LayoutSplicer($this->parser))->splice(...$trees[$pairs]);
        }, 2000, 'Splicing rewritten classes');
    }

    #[Test]
    public function test_matching_keeps_the_first_remaining_occurrence_of_a_shared_node(): void
    {
        $a = new LiteralNode('a', 0, 1);
        $b = new LiteralNode('b', 1, 2);
        $positions = [spl_object_id($a) => [2, 0], spl_object_id($b) => [1]];
        $after = [$a, $b, $a, $b];
        $match = new \ReflectionMethod(LayoutSplicer::class, 'nextKept');

        $this->assertSame([0, 0], $match->invokeArgs(null, [&$positions, 3, $after, 0, 0]));
        $this->assertSame([2, 2], $match->invokeArgs(null, [&$positions, 3, $after, 2, 1]));
        $this->assertSame([3, 4], $match->invokeArgs(null, [&$positions, 3, $after, 3, 3]));
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
