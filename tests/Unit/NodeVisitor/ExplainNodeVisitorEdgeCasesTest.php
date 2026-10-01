<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Explain\TextExplainer;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\ControlCharNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\LiteralNode;
use PHPUnit\Framework\TestCase;

final class ExplainNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_inline_flags_group_is_explained(): void
    {
        $visitor = new TextExplainer();
        $group = new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_INLINE_FLAGS, null, 'im', 0, 0);

        $this->assertStringContainsString("Inline flags 'im'", $group->accept($visitor));
    }

    public function test_control_char_is_explained(): void
    {
        $visitor = new TextExplainer();
        $control = new ControlCharNode('A', 1, 0, 0);

        $this->assertStringContainsString('\\cA', $control->accept($visitor));
    }

    public function test_unicode_named_character_extracts_name(): void
    {
        $visitor = new TextExplainer();
        $node = new CharLiteralNode('\\N{LATIN SMALL LETTER A}', 0, CharLiteralType::UNICODE_NAMED, 0, 0);

        $this->assertStringContainsString('LATIN SMALL LETTER A', $node->accept($visitor));
    }

    public function test_unicode_named_character_falls_back_to_representation(): void
    {
        $visitor = new TextExplainer();
        $node = new CharLiteralNode('\\N{', 0, CharLiteralType::UNICODE_NAMED, 0, 0);

        $this->assertStringContainsString('\\N{', $node->accept($visitor));
    }
}
