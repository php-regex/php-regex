<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\MermaidRenderer;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\RegexNode;
use PHPUnit\Framework\TestCase;

final class MermaidNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_limit_match_verb_is_rendered(): void
    {
        $visitor = new MermaidRenderer();
        $regex = new RegexNode(new PcreVerbNode('LIMIT_MATCH=12', 0, 0), '', '/', 0, 0);

        $diagram = $regex->accept($visitor);

        $this->assertStringContainsString('LimitMatch: 12', $diagram);
    }

    public function test_callout_string_identifier_labels_are_rendered(): void
    {
        $visitor = new MermaidRenderer();
        $regex = new RegexNode(new CalloutNode('named', true, 0, 0), '', '/', 0, 0);

        $diagram = $regex->accept($visitor);

        $this->assertStringContainsString('Callout: (?C&quot;named&quot;)', $diagram);
    }

    public function test_callout_default_identifier_labels_are_rendered(): void
    {
        $visitor = new MermaidRenderer();
        $regex = new RegexNode(new CalloutNode('id', false, 0, 0), '', '/', 0, 0);

        $diagram = $regex->accept($visitor);

        $this->assertStringContainsString('Callout: (?Cid)', $diagram);
    }
}
