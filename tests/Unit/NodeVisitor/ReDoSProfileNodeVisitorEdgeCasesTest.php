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

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\CalloutNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DefineNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LimitMatchNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\NodeVisitorInterface;
use PhpRegex\Redos\RedosConfidence;
use PhpRegex\Redos\RedosProfiler;
use PhpRegex\Redos\RedosSeverity;
use PHPUnit\Framework\TestCase;

final class ReDoSProfileNodeVisitorEdgeCasesTest extends TestCase
{
    public function test_get_result_includes_message_without_suggested_rewrite(): void
    {
        $visitor = new RedosProfiler();
        $this->invokePrivate($visitor, 'addVulnerability', [
            RedosSeverity::Low,
            'Test risk',
            new LiteralNode('a', 0, 0),
            null,
            RedosConfidence::Low,
            null,
        ]);

        $result = $visitor->getResult();

        $this->assertSame(['Test risk'], $result['recommendations']);
    }

    public function test_get_result_includes_hint_for_suggested_rewrite(): void
    {
        $visitor = new RedosProfiler();
        $this->invokePrivate($visitor, 'addVulnerability', [
            RedosSeverity::Medium,
            'Test risk with suggestion',
            new LiteralNode('a', 0, 0),
            'Use possessive quantifiers',
            RedosConfidence::Medium,
            null,
        ]);

        $result = $visitor->getResult();

        $this->assertSame(['Test risk with suggestion Suggested (verify behavior): Use possessive quantifiers'], $result['recommendations']);
    }

    public function test_large_bounded_quantifier_adds_low_risk(): void
    {
        $visitor = new RedosProfiler();
        $quantifier = new QuantifierNode(new LiteralNode('a', 0, 0), '{1,2001}', QuantifierType::Greedy, 0, 0);

        $severity = $quantifier->accept($visitor);

        $this->assertSame(RedosSeverity::Low, $severity);
    }

    public function test_star_height_critical_when_child_returns_high(): void
    {
        $visitor = new RedosProfiler();
        $highNode = new class implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function accept(NodeVisitorInterface $visitor): RedosSeverity|string
            {
                if ($visitor instanceof RedosProfiler) {
                    return RedosSeverity::High;
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

        $quantifier = new QuantifierNode($highNode, '*', QuantifierType::Greedy, 0, 0);
        $severity = $quantifier->accept($visitor);

        $this->assertSame(RedosSeverity::Critical, $severity);
    }

    public function test_safe_nodes_return_safe_severity(): void
    {
        $visitor = new RedosProfiler();
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
            $this->assertSame(RedosSeverity::Safe, $node->accept($visitor));
        }
    }

    public function test_conditional_and_define_delegate_to_children(): void
    {
        $visitor = new RedosProfiler();
        $conditional = new ConditionalNode(new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0), new LiteralNode('c', 0, 0), 0, 0);
        $define = new DefineNode(new LiteralNode('a', 0, 0), 0, 0);

        $this->assertSame(RedosSeverity::Safe, $conditional->accept($visitor));
        $this->assertSame(RedosSeverity::Safe, $define->accept($visitor));
    }

    public function test_overlapping_alternatives_handles_unknown_sets(): void
    {
        $visitor = new RedosProfiler();
        $alternation = new AlternationNode([new DotNode(0, 0), new LiteralNode('a', 0, 0)], 0, 0);

        $result = $this->invokePrivate($visitor, 'hasOverlappingAlternatives', [$alternation]);

        $this->assertIsBool($result);
    }

    public function test_overlapping_alternatives_does_not_assume_overlap_for_unknown_sets(): void
    {
        $visitor = new RedosProfiler();
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
        $visitor = new RedosProfiler();
        $group = new GroupNode(new DotNode(0, 0), GroupType::NonCapturing, null, null, 0, 0);

        $signature = $this->invokePrivate($visitor, 'getPrefixSignature', [$group]);

        $this->assertSame('DOT', $signature);
    }

    public function test_length_range_and_quantifier_bounds_helpers(): void
    {
        $visitor = new RedosProfiler();

        $zeroRange = $this->invokePrivate($visitor, 'lengthRange', [new PcreVerbNode('FAIL', 0, 0)]);
        $this->assertSame([0, 0], $zeroRange);

        $unknownRange = $this->invokePrivate($visitor, 'lengthRange', [new class implements NodeInterface {
            public function getChildren(): array
            {
                return [];
            }

            public function accept(NodeVisitorInterface $visitor): RedosSeverity
            {
                return RedosSeverity::Safe;
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
