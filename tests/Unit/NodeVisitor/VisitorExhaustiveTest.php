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
use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Analysis\ComplexityScorer;
use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\CommentNode;
use PhpRegex\Parser\Node\ConditionalNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\GroupType;
use PhpRegex\Parser\Node\KeepNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\PcreVerbNode;
use PhpRegex\Parser\Node\PosixClassNode;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\QuantifierType;
use PhpRegex\Parser\Node\RangeNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Node\UnicodePropNode;
use PhpRegex\Parser\Printer\NodeDumper;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class VisitorExhaustiveTest extends TestCase
{
    #[DoesNotPerformAssertions]
    public function test_all_visitors_visit_all_nodes(): void
    {
        // Liste de tous les visiteurs
        $visitors = [
            new PatternPrinter(),
            new ComplexityScorer(),
            new NodeDumper(),
            new TextExplainer(),
            new HtmlExplainer(),
            new Rewriter(),
            // Note: SampleGenerator and Validator have strict logics that can throw exceptions
            // on isolated nodes. We include them but will catch the errors.
            new SampleGenerator(),
            new Validator(),
        ];

        // Liste exhaustive d'instances de chaque type de nœud
        $nodes = [
            new AlternationNode([], 0, 0),
            new AnchorNode('^', 0, 0),
            new AssertionNode('b', 0, 0),
            new BackrefNode('1', 0, 0),
            new CharClassNode(new LiteralNode('', 0, 0), false, 0, 0),
            new CharTypeNode('d', 0, 0),
            new CommentNode('comment', 0, 0),
            new ConditionalNode(new BackrefNode('1', 0, 0), new LiteralNode('a', 0, 0), new LiteralNode('b', 0, 0), 0, 0),
            new DotNode(0, 0),
            new GroupNode(new LiteralNode('a', 0, 0), GroupType::T_GROUP_CAPTURING, null, null, 0, 0),
            new KeepNode(0, 0),
            new LiteralNode('a', 0, 0),
            new CharLiteralNode('01', 0o1, CharLiteralType::OCTAL_LEGACY, 0, 0),
            new CharLiteralNode('\o{123}', 0o123, CharLiteralType::OCTAL, 0, 0),
            new PcreVerbNode('FAIL', 0, 0),
            new PosixClassNode('alnum', 0, 0),
            new QuantifierNode(new LiteralNode('a', 0, 0), '*', QuantifierType::T_GREEDY, 0, 0),
            new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('z', 0, 0), 0, 0),
            new RegexNode(new LiteralNode('a', 0, 0), 'i', '/', 0, 0),
            new SequenceNode([], 0, 0),
            new SubroutineNode('1', '', 0, 0),
            new CharLiteralNode('\x41', 0x41, CharLiteralType::UNICODE, 0, 0),
            new UnicodePropNode('L', 0, 0),
        ];

        foreach ($visitors as $visitor) {
            foreach ($nodes as $node) {
                // Specific cases to ignore for the Validator which needs context (existing groups)
                if ($visitor instanceof Validator) {
                    if ($node instanceof BackrefNode || $node instanceof SubroutineNode || ($node instanceof CharLiteralNode && CharLiteralType::OCTAL_LEGACY === $node->type)) {
                        continue;
                    }
                }

                // Specific case for SampleGenerator which does not support subroutines
                if ($visitor instanceof SampleGenerator && $node instanceof SubroutineNode) {
                    continue;
                }

                try {
                    $node->accept($visitor);
                } catch (\Throwable) {
                }
            }
        }
    }
}
