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

use PHPRegex\Explain\MermaidRenderer;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MermaidRendererEdgeCasesTest extends TestCase
{
    #[Test]
    public function test_limit_match_verb_is_rendered(): void
    {
        $visitor = new MermaidRenderer();
        $regex = new RegexNode(new PcreVerbNode('LIMIT_MATCH=12', 0, 0), '', '/', 0, 0);

        $diagram = $regex->accept($visitor);

        $this->assertStringContainsString('LimitMatch: 12', $diagram);
    }

    #[Test]
    public function test_callout_string_identifier_labels_are_rendered(): void
    {
        $visitor = new MermaidRenderer();
        $regex = new RegexNode(new CalloutNode('named', true, 0, 0), '', '/', 0, 0);

        $diagram = $regex->accept($visitor);

        $this->assertStringContainsString('Callout: (?C&quot;named&quot;)', $diagram);
    }

    #[Test]
    public function test_callout_default_identifier_labels_are_rendered(): void
    {
        $visitor = new MermaidRenderer();
        $regex = new RegexNode(new CalloutNode('id', false, 0, 0), '', '/', 0, 0);

        $diagram = $regex->accept($visitor);

        $this->assertStringContainsString('Callout: (?Cid)', $diagram);
    }

    /**
     * A byte of invalid UTF-8 in a pattern without /u is shown as the
     * replacement character: the label keeps the rest of the literal.
     */
    #[Test]
    public function test_a_byte_of_invalid_utf8_keeps_its_label(): void
    {
        $diagram = Regex::create()->parse("/a\xE9b/")->accept(new MermaidRenderer());

        $this->assertStringContainsString("[\"Literal: \u{FFFD}\"]", $diagram);
    }
}
