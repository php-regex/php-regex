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

use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Exception\SemanticErrorException;
use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Validation\Validator;
use PhpRegex\Toolkit\Regex;
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
        $node = new CharLiteralNode('BAD', -1, CharLiteralType::UNICODE, 0, 0);
        $generator = new SampleGenerator();

        // Should hit the '?' fallback
        $this->assertSame('?', $node->accept($generator));
    }

    public function test_sample_generator_bad_octal_node(): void
    {
        // Inject CharLiteralNode with bad value
        $node = new CharLiteralNode('BAD', -1, CharLiteralType::OCTAL, 0, 0);
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
