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

use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Validation\Validator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ValidatorSuccessTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function test_valid_backreferences_pass(): void
    {
        $this->expectNotToPerformAssertions();

        $regex = Regex::create()->parse('/(a)\1/');
        $regex->accept($this->validator);
    }

    public function test_valid_unicode_and_octal_pass(): void
    {
        $this->expectNotToPerformAssertions();

        // Valid Unicode
        (new CharLiteralNode('\x41', 0x41, CharLiteralType::Unicode, 0, 0))->accept($this->validator);
        (new CharLiteralNode('\u{00E9}', 0xE9, CharLiteralType::Unicode, 0, 0))->accept($this->validator);

        // Valid Octal
        (new CharLiteralNode('\o{77}', 0o77, CharLiteralType::Octal, 0, 0))->accept($this->validator);

        // Valid Legacy Octal
        (new CharLiteralNode('012', 0o12, CharLiteralType::OctalLegacy, 0, 0))->accept($this->validator);

        // Valid Unicode Prop (cached)
        (new UnicodePropNode('L', 0, 0))->accept($this->validator);
    }

    public function test_valid_subroutines_pass(): void
    {
        $this->expectNotToPerformAssertions();

        // (?R) and (?0) are always valid
        (new SubroutineNode('R', '', 0, 0))->accept($this->validator);
        (new SubroutineNode('0', '', 0, 0))->accept($this->validator);
    }
}
