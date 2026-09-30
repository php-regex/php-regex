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

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\TestCase;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\QuantifierType;
use RegexParser\Node\RangeNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\NodeVisitor\NodeVisitorInterface;
use RegexParser\NodeVisitor\ReDoSProfileNodeVisitor;
use RegexParser\ReDoS\ReDoSConfidence;
use RegexParser\ReDoS\ReDoSSeverity;

final class ReDoSProfileNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_get_result_includes_message_without_suggested_rewrite(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $this->invokePrivate($visitor, 'addVulnerability', [
            ReDoSSeverity::LOW,
            'Test risk',
            new LiteralNode('a', 0, 0),
            null,
            ReDoSConfidence::LOW,
            null,
        ]);

        $result = $visitor->getResult();

        $this->assertSame(['Test risk'], $result['recommendations']);
    }

    public function test_get_result_includes_hint_for_suggested_rewrite(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $this->invokePrivate($visitor, 'addVulnerability', [
            ReDoSSeverity::MEDIUM,
            'Test risk with suggestion',
            new LiteralNode('a', 0, 0),
            'Use possessive quantifiers',
            ReDoSConfidence::MEDIUM,
            null,
        ]);

        $result = $visitor->getResult();

        $this->assertSame(['Test risk with suggestion Suggested (verify behavior): Use possessive quantifiers'], $result['recommendations']);
    }

    public function test_large_bounded_quantifier_adds_low_risk(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $quantifier = new QuantifierNode(new LiteralNode('a', 0, 0), '{1,2001}', QuantifierType::T_GREEDY, 0, 0);

        $severity = $quantifier->accept($visitor);

        $this->assertSame(ReDoSSeverity::LOW, $severity);
    }

    public function test_star_height_critical_when_child_returns_high(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $highNode = new class implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function accept(NodeVisitorInterface $visitor): ReDoSSeverity|string
            {
                if ($visitor instanceof ReDoSProfileNodeVisitor) {
                    return ReDoSSeverity::HIGH;
                }

                return '';
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 0;
            }
        };

        $quantifier = new QuantifierNode($highNode, '*', QuantifierType::T_GREEDY, 0, 0);
        $severity = $quantifier->accept($visitor);

        $this->assertSame(ReDoSSeverity::CRITICAL, $severity);
    }

    public function test_safe_nodes_return_safe_severity(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $nodes = [
            new AssertionNode('A', 0, 0),
            new KeepNode(0, 0),
            new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('z', 0, 0), 0, 0),
            new UnicodePropNode('L', true, 0, 0),
            new PosixClassNode('alpha', 0, 0),
            new CommentNode('note', 0, 0),
            new LimitMatchNode(100, 0, 0),
            new CalloutNode('callout', true, 0, 0),
        ];

        foreach ($nodes as $node) {
            $this->assertSame(ReDoSSeverity::SAFE, $node->accept($visitor));
        }
    }

    public function test_conditional_and_define_delegate_to_children(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $conditional = new ConditionalNode(new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0), new LiteralNode('c', 0, 0), 0, 0);
        $define = new DefineNode(new LiteralNode('a', 0, 0), 0, 0);

        $this->assertSame(ReDoSSeverity::SAFE, $conditional->accept($visitor));
        $this->assertSame(ReDoSSeverity::SAFE, $define->accept($visitor));
    }

    public function test_overlapping_alternatives_handles_unknown_sets(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $alternation = new AlternationNode([new DotNode(0, 0), new LiteralNode('a', 0, 0)], 0, 0);

        $result = $this->invokePrivate($visitor, 'hasOverlappingAlternatives', [$alternation]);

        $this->assertIsBool($result);
    }

    public function test_overlapping_alternatives_does_not_assume_overlap_for_unknown_sets(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        // Create an alternation with unknown set (e.g., UnicodePropNode) and a literal
        // Should not trigger overlap since unknown doesn't mean overlap
        $alternation = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new UnicodePropNode('Unknown', false, 0, 0),
        ], 0, 0);

        $result = $this->invokePrivate($visitor, 'hasOverlappingAlternatives', [$alternation]);

        $this->assertFalse($result, 'Unknown sets should not cause assumed overlap');
    }

    public function test_prefix_signature_recurses_through_group(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();
        $group = new GroupNode(new DotNode(0, 0), GroupType::T_GROUP_NON_CAPTURING, null, null, 0, 0);

        $signature = $this->invokePrivate($visitor, 'getPrefixSignature', [$group]);

        $this->assertSame('DOT', $signature);
    }

    public function test_length_range_and_quantifier_bounds_helpers(): void
    {
        $visitor = new ReDoSProfileNodeVisitor();

        $zeroRange = $this->invokePrivate($visitor, 'lengthRange', [new PcreVerbNode('FAIL', 0, 0)]);
        $this->assertSame([0, 0], $zeroRange);

        $unknownRange = $this->invokePrivate($visitor, 'lengthRange', [new class implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function accept(NodeVisitorInterface $visitor): ReDoSSeverity
            {
                return ReDoSSeverity::SAFE;
            }

            public function getStartPosition(): int
            {
                return 0;
            }

            public function getEndPosition(): int
            {
                return 0;
            }
        }]);
        $this->assertSame([0, null], $unknownRange);

        $exact = $this->invokePrivate($visitor, 'quantifierBounds', ['{3}']);
        $this->assertSame([3, 3], $exact);

        $fallback = $this->invokePrivate($visitor, 'quantifierBounds', ['invalid']);
        $this->assertSame([0, null], $fallback);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($target);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invokeArgs($target, $args);
    }
}
