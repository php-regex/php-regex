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

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class VisitorFallbackTest extends TestCase
{
    public function test_sample_generator_backref_not_found(): void
    {
        // Access a backreference that hasn't been captured yet
        // e.g. \1 when group 1 hasn't matched
        $regex = Regex::create();
        $ast = $regex->parse('/\1/');

        $generator = new SampleGenerator();
        // Should return empty string (fallback)
        $this->assertSame('', $ast->accept($generator));
    }

    public function test_sample_generator_bad_unicode_node(): void
    {
        // Inject a CharLiteralNode with a bad value
        $node = new CharLiteralNode('BAD', -1, CharLiteralType::Unicode, 0, 0);
        $generator = new SampleGenerator();

        // Should hit the '?' fallback
        $this->assertSame('?', $node->accept($generator));
    }

    public function test_sample_generator_bad_octal_node(): void
    {
        // Inject CharLiteralNode with bad value
        $node = new CharLiteralNode('BAD', -1, CharLiteralType::Octal, 0, 0);
        $generator = new SampleGenerator();

        // Should hit the '?' fallback
        $this->assertSame('?', $node->accept($generator));
    }

    public function test_validator_bad_backref_syntax(): void
    {
        // Inject BackrefNode with value that fails internal validator regex
        // Use a value with invalid characters that won't match any valid pattern
        $node = new BackrefNode('BAD-REF', 0, 0);
        $validator = new Validator();

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid backreference syntax');

        $node->accept($validator);
    }

    public function test_optimizer_char_class_parts_change(): void
    {
        // The Rewriter::visitCharClass has logic: if ($optimizedPart !== $part) { $hasChanged = true; }
        // But currently, parts (Literals/Ranges) are never optimized, so this block is dead code.
        // We force it by mocking a NodeInterface that returns a DIFFERENT instance when visited.

        $mockPart = $this->createStub(NodeInterface::class);
        $mockPart
            ->method('accept')
            ->willReturn(new LiteralNode('changed', 0, 0)); // Return different instance

        $node = new CharClassNode($mockPart, false, 0, 0);
        $optimizer = new Rewriter();

        $result = $node->accept($optimizer);

        // Assert that we got a NEW CharClassNode (meaning $hasChanged was true)
        $this->assertNotSame($node, $result);
        $this->assertInstanceOf(CharClassNode::class, $result);
        $this->assertInstanceOf(LiteralNode::class, $result->expression);
        $this->assertSame('changed', $result->expression->value);
    }
}
