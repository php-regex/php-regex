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

use PhpRegex\Parser\Exception\SemanticErrorException;
use PhpRegex\Parser\Node\CharLiteralNode;
use PhpRegex\Parser\Node\CharLiteralType;
use PhpRegex\Parser\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorImpossibleTest extends TestCase
{
    public function test_unicode_out_of_bounds_manual(): void
    {
        $validator = new Validator();

        // \u{110000} (Too large for Unicode, max is 10FFFF)
        // We pass the raw string that matches the regex check inside Validator
        $node = new CharLiteralNode('\u{110000}', 0x110000, CharLiteralType::Unicode, 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('out of range');
        $node->accept($validator);
    }

    public function test_octal_out_of_bounds_manual(): void
    {
        $validator = new Validator();

        // \o{4000000} (Too large)
        $node = new CharLiteralNode('\o{4000000}', 0x4000000, CharLiteralType::Octal, 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid octal codepoint');
        $node->accept($validator);
    }

    public function test_octal_invalid_format_manual(): void
    {
        $validator = new Validator();

        // \o{9} (Invalid octal digit, but since parser validates, use large value)
        $node = new CharLiteralNode('\o{9}', 0x100, CharLiteralType::Octal, 0, 0);

        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid octal codepoint');
        $node->accept($validator);
    }
}
