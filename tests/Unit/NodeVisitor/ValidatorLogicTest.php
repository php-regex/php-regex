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

use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorLogicTest extends TestCase
{
    public function test_octal_invalid_digits(): void
    {
        $validator = new Validator();

        // \o{8} contains invalid octal digit, but use large codePoint for test
        $node = new CharLiteralNode('\o{8}', 0x100, CharLiteralType::Octal, 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid octal codepoint');
        $node->accept($validator);
    }

    public function test_quantifier_bounds_logic(): void
    {
        $this->expectNotToPerformAssertions();

        // This tests the Validator's internal parsing logic directly via a QuantifierNode
        // constructed with a raw string that might not come from standard parsing.
        $validator = new Validator();

        // {n} case
        $node = new QuantifierNode(new LiteralNode('a', 0, 0), '{5}', QuantifierType::Greedy, 0, 0);
        $node->accept($validator); // Should not throw
    }

    public function test_quantifier_bounds_fallback(): void
    {
        $this->expectNotToPerformAssertions();

        // Testing the "default" match in parseQuantifierBounds
        // {5,}
        $validator = new Validator();
        $node = new QuantifierNode(new LiteralNode('a', 0, 0), '{5,}', QuantifierType::Greedy, 0, 0);
        $node->accept($validator);

        // {5,10}
        $node = new QuantifierNode(new LiteralNode('a', 0, 0), '{5,10}', QuantifierType::Greedy, 0, 0);
        $node->accept($validator);
    }
}
