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

use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternPrinterEdgeCasesTest extends TestCase
{
    #[Test]
    public function test_pretty_group_prefixes_are_rendered(): void
    {
        $visitor = new PatternPrinter(true);
        $child = new LiteralNode('a', 0, 0);

        $named = new GroupNode($child, GroupType::Named, 'name', null, 0, 0);
        $this->assertStringContainsString('<name>', $named->accept($visitor));

        $lookahead = new GroupNode($child, GroupType::LookaheadPositive, null, null, 0, 0);
        $this->assertStringContainsString('(?=', $lookahead->accept($visitor));

        $lookbehind = new GroupNode($child, GroupType::LookbehindNegative, null, null, 0, 0);
        $this->assertStringContainsString('(?<!', $lookbehind->accept($visitor));

        $atomic = new GroupNode($child, GroupType::Atomic, null, null, 0, 0);
        $this->assertStringContainsString('(?>', $atomic->accept($visitor));

        $branchReset = new GroupNode($child, GroupType::BranchReset, null, null, 0, 0);
        $this->assertStringContainsString('(?|', $branchReset->accept($visitor));

        $inlineFlags = new GroupNode($child, GroupType::InlineFlags, null, 'im', 0, 0);
        $this->assertStringContainsString('(?im:', $inlineFlags->accept($visitor));
    }

    #[Test]
    public function test_char_literal_control_escapes_use_f_and_e(): void
    {
        $visitor = new PatternPrinter();

        $formFeed = new CharLiteralNode("\x0C", 12, CharLiteralType::Unicode, 0, 0);
        $this->assertSame('\\f', $formFeed->accept($visitor));

        $escape = new CharLiteralNode("\x1B", 27, CharLiteralType::Unicode, 0, 0);
        $this->assertSame('\\e', $escape->accept($visitor));
    }

    #[Test]
    public function test_literal_control_characters_escape_string(): void
    {
        $visitor = new PatternPrinter();

        $formFeed = new LiteralNode("\x0C", 0, 0);
        $this->assertSame('\\f', $formFeed->accept($visitor));

        $escape = new LiteralNode("\x1B", 0, 0);
        $this->assertSame('\\e', $escape->accept($visitor));
    }

    #[Test]
    public function test_collapse_extended_comments_in_extended_mode(): void
    {
        $visitor = new PatternPrinter(false, true);
        $regex = new RegexNode(new CommentNode('comment', 0, 0), 'x', '/', 0, 0);

        $this->assertSame('/(?#...)/x', $regex->accept($visitor));
    }

    #[Test]
    public function test_pretty_inline_comment_multiline_stays_a_comment_group(): void
    {
        $visitor = new PatternPrinter(true);
        $comment = new CommentNode("line1\nline2\n", 0, 0);
        $regex = new RegexNode($comment, 'x', '/', 0, 0);

        $compiled = $regex->accept($visitor);

        $this->assertStringContainsString('(?#line1', $compiled);
        $this->assertStringContainsString("line2\n)", $compiled);
    }

    #[Test]
    public function test_pretty_inline_comment_starting_with_a_hash_stays_a_comment_group(): void
    {
        $visitor = new PatternPrinter(true);
        $comment = new CommentNode('# note', 0, 0);
        $regex = new RegexNode($comment, 'x', '/', 0, 0);

        $this->assertSame('/(?## note)/x', $regex->accept($visitor));
    }

    #[Test]
    public function test_pretty_define_block_includes_newlines(): void
    {
        // A block only breaks under x, past the width.
        $visitor = new PatternPrinter(true);
        $define = new RegexNode(new DefineNode(new LiteralNode(str_repeat('a', 80), 0, 0), 0, 0), 'x', '/', 0, 0);

        $compiled = $define->accept($visitor);

        $this->assertStringContainsString('(?(DEFINE)', $compiled);
        $this->assertStringContainsString("\n", $compiled);
    }

    #[Test]
    public function test_pretty_conditional_without_else_branch(): void
    {
        $visitor = new PatternPrinter(true);
        $conditional = new ConditionalNode(
            new BackrefNode('1', 0, 0),
            new LiteralNode('a', 0, 0),
            new LiteralNode('', 0, 0),
            0,
            0,
        );

        $compiled = $conditional->accept($visitor);

        $this->assertStringContainsString('(?(', $compiled);
        $this->assertStringNotContainsString('|', $compiled);
    }

    #[Test]
    public function test_pretty_conditional_with_else_branch(): void
    {
        $visitor = new PatternPrinter(true);
        $conditional = new ConditionalNode(
            new BackrefNode('1', 0, 0),
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0),
            0,
            0,
        );

        $compiled = $conditional->accept($visitor);

        $this->assertStringContainsString('|', $compiled);
    }
}
