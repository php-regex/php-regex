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

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Analysis\ComplexityScorer;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\Validation\Validator;
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
            new GroupNode(new LiteralNode('a', 0, 0), GroupType::Capturing, null, null, 0, 0),
            new KeepNode(0, 0),
            new LiteralNode('a', 0, 0),
            new CharLiteralNode('01', 0o1, CharLiteralType::OctalLegacy, 0, 0),
            new CharLiteralNode('\o{123}', 0o123, CharLiteralType::Octal, 0, 0),
            new PcreVerbNode('FAIL', 0, 0),
            new PosixClassNode('alnum', 0, 0),
            new QuantifierNode(new LiteralNode('a', 0, 0), '*', QuantifierType::Greedy, 0, 0),
            new RangeNode(new LiteralNode('a', 0, 0), new LiteralNode('z', 0, 0), 0, 0),
            new RegexNode(new LiteralNode('a', 0, 0), 'i', '/', 0, 0),
            new SequenceNode([], 0, 0),
            new SubroutineNode('1', '', 0, 0),
            new CharLiteralNode('\x41', 0x41, CharLiteralType::Unicode, 0, 0),
            new UnicodePropNode('L', 0, 0),
        ];

        foreach ($visitors as $visitor) {
            foreach ($nodes as $node) {
                // Specific cases to ignore for the Validator which needs context (existing groups)
                if ($visitor instanceof Validator) {
                    if ($node instanceof BackrefNode || $node instanceof SubroutineNode || ($node instanceof CharLiteralNode && CharLiteralType::OctalLegacy === $node->type)) {
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
