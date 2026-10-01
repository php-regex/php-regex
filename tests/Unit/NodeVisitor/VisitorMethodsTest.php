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

use PhpRegex\Explain\HtmlExplainer;
use PhpRegex\Explain\TextExplainer;
use PhpRegex\Generator\SampleGenerationException;
use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class VisitorMethodsTest extends TestCase
{
    public function test_optimizer_leaf_nodes_return_same_instance(): void
    {
        $optimizer = new Rewriter();

        $nodes = [
            new LiteralNode('a', 0, 0),
            new CharTypeNode('d', 0, 0),
            new DotNode(0, 0),
            new AnchorNode('^', 0, 0),
            new AssertionNode('b', 0, 0),
            new KeepNode(0, 0),
            new BackrefNode('1', 0, 0),
            new CharLiteralNode('\x00', 0, CharLiteralType::UNICODE, 0, 0),
            new UnicodePropNode('L', 0, 0),
            new CharLiteralNode('\o{10}', 0o10, CharLiteralType::OCTAL, 0, 0),
            new CharLiteralNode('10', 0o10, CharLiteralType::OCTAL_LEGACY, 0, 0),
            new PosixClassNode('alnum', 0, 0),
            new CommentNode('foo', 0, 0),
            new SubroutineNode('1', '', 0, 0),
            new PcreVerbNode('FAIL', 0, 0),
        ];

        foreach ($nodes as $node) {
            $result = $node->accept($optimizer);
            $this->assertSame($node, $result, \sprintf('Optimizer should return same instance for leaf node %s', $node::class));
        }
    }

    public function test_sample_generator_ignored_nodes_return_empty_string(): void
    {
        $generator = new SampleGenerator();

        $nodes = [
            new AnchorNode('^', 0, 0),
            new AssertionNode('b', 0, 0),
            new KeepNode(0, 0),
            new CommentNode('foo', 0, 0),
            new PcreVerbNode('FAIL', 0, 0),
        ];

        foreach ($nodes as $node) {
            $result = $node->accept($generator);
            $this->assertSame('', $result, \sprintf('SampleGenerator should return empty string for node %s', $node::class));
        }
    }

    public function test_complexity_score_leaf_nodes(): void
    {
        $scorer = new ComplexityScorer();

        // Base score of 1
        $baseNodes = [
            new LiteralNode('a', 0, 0),
            new CharTypeNode('d', 0, 0),
            new DotNode(0, 0),
            new AnchorNode('^', 0, 0),
            new AssertionNode('b', 0, 0),
            new KeepNode(0, 0),
            new CharLiteralNode('x', 0, CharLiteralType::UNICODE, 0, 0),
            new UnicodePropNode('L', 0, 0),
            new CharLiteralNode('1', 1, CharLiteralType::OCTAL, 0, 0),
            new CharLiteralNode('1', 1, CharLiteralType::OCTAL_LEGACY, 0, 0),
            new PosixClassNode('digit', 0, 0),
        ];

        foreach ($baseNodes as $node) {
            $this->assertSame(1, $node->accept($scorer));
        }

        // Zero score
        $this->assertSame(0, (new CommentNode('', 0, 0))->accept($scorer));

        // Complex score (5)
        $this->assertSame(5, (new BackrefNode('1', 0, 0))->accept($scorer));
        $this->assertSame(5, (new PcreVerbNode('FAIL', 0, 0))->accept($scorer));
    }

    public function test_validator_leaf_nodes_valid(): void
    {
        $this->expectNotToPerformAssertions();

        $validator = new Validator();

        $nodes = [
            new LiteralNode('a', 0, 0),
            new CharTypeNode('d', 0, 0),
            new DotNode(0, 0),
            new AnchorNode('^', 0, 0),
            new CommentNode('foo', 0, 0),
        ];

        foreach ($nodes as $node) {
            // Should simply not throw
            $node->accept($validator);
        }
    }

    /**
     * This test forces the call of each visit*() method for each visitor
     * with simple leaf nodes.
     */
    public function test_all_visitors_handle_all_leaf_nodes(): void
    {
        $nodes = [
            new AnchorNode('^', 0, 0),
            new AssertionNode('b', 0, 0),
            new BackrefNode('1', 0, 0),
            new CharTypeNode('d', 0, 0),
            new CommentNode('comment', 0, 0),
            new DotNode(0, 0),
            new KeepNode(0, 0),
            new LiteralNode('a', 0, 0),
            new CharLiteralNode('0', 0, CharLiteralType::OCTAL_LEGACY, 0, 0),
            new CharLiteralNode('123', 0o123, CharLiteralType::OCTAL, 0, 0),
            new PcreVerbNode('FAIL', 0, 0),
            new PosixClassNode('alnum', 0, 0),
            new SubroutineNode('1', '', 0, 0),
            new CharLiteralNode('FFFF', 0xFFFF, CharLiteralType::UNICODE, 0, 0),
            new UnicodePropNode('L', 0, 0),
        ];

        $visitors = [
            new TextExplainer(),
            new HtmlExplainer(),
            new Rewriter(),
            new ComplexityScorer(),
            new Validator(),
            new SampleGenerator(),
        ];

        foreach ($visitors as $visitor) {
            foreach ($nodes as $node) {
                // SampleGenerator does not support subroutines
                if ($visitor instanceof SampleGenerator && $node instanceof SubroutineNode) {
                    continue;
                }

                // Validator requires group context for backreferences
                if ($visitor instanceof Validator && $node instanceof BackrefNode) {
                    continue;
                }

                // Validator treats CharLiteralNode with OCTAL_LEGACY '0' as invalid backreference \0
                if ($visitor instanceof Validator && $node instanceof CharLiteralNode && CharLiteralType::OCTAL_LEGACY === $node->type && 0 === $node->codePoint) {
                    continue;
                }

                // Validator requires group context for subroutines
                if ($visitor instanceof Validator && $node instanceof SubroutineNode) {
                    continue;
                }

                $result = $node->accept($visitor);

                if ($visitor instanceof Validator) {
                    // The validator returns void (null)
                    $this->assertNull($result);
                } else {
                    // The others must return something (string, int, Node)
                    $this->assertNotNull($result, \sprintf('Visitor %s returned null for node %s', $visitor::class, $node::class));
                }
            }
        }
    }

    public function test_sample_generator_throws_on_subroutine(): void
    {
        $visitor = new SampleGenerator();
        $node = new SubroutineNode('R', '', 0, 0);

        $this->expectException(SampleGenerationException::class);
        $node->accept($visitor);
    }
}
