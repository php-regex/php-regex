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

use PHPRegex\Generator\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPUnit\Framework\TestCase;

final class VisitorManualInjectionTest extends TestCase
{
    public function test_optimizer_handles_empty_alternation(): void
    {
        // Cas impossible via parser : AlternationNode vide
        $node = new AlternationNode([], 0, 0);
        $optimizer = new Rewriter();

        // Should return the node as is or not crash
        $result = $node->accept($optimizer);
        $this->assertSame($node, $result); // ou assertion selon ta logique
    }

    public function test_compiler_handles_literal_bracket_outside_char_class(): void
    {
        // The parser handles ']' as a literal if there's no open '[',
        // but let's explicitly test that the compiler doesn't escape it unnecessarily
        $node = new LiteralNode(']', 0, 0);
        $compiler = new PatternPrinter();

        $this->assertSame(']', $node->accept($compiler));
    }

    public function test_sample_generator_fallback_on_empty_char_class(): void
    {
        // An empty class [] is normally a parsing error,
        // but if we construct it manually:
        $node = new CharClassNode(new AlternationNode([], 0, 0), false, 0, 0);
        $generator = new SampleGenerator();

        $this->expectException(SampleGenerationException::class);
        $node->accept($generator);
    }

    public function test_compiler_quantifier_on_alternation_adds_non_capturing_group(): void
    {
        // (a|b)* -> the compiler must add (?:...) around a|b
        $alt = new AlternationNode([
            new LiteralNode('a', 0, 0),
            new LiteralNode('b', 0, 0)
        ], 0, 0);

        $quantifier = new QuantifierNode($alt, '*', QuantifierType::Greedy, 0, 0);
        $compiler = new PatternPrinter();

        // Must produce (?:a|b)*
        $this->assertSame('(?:a|b)*', $quantifier->accept($compiler));
    }
}
