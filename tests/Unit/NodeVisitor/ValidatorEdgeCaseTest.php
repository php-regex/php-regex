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

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use RegexParser\Exception\SemanticErrorException;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;
use RegexParser\Regex;

final class ValidatorEdgeCaseTest extends TestCase
{
    private Regex $regex;

    private ValidatorNodeVisitor $validator;

    protected function setUp(): void
    {
        $this->regex = Regex::create();
        $this->validator = new ValidatorNodeVisitor();
    }

    public function test_invalid_quantifier_range(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('min > max');
        $this->validate('/a{5,2}/');
    }

    #[DoesNotPerformAssertions]
    public function test_allows_octal_zero_escape(): void
    {
        // \0 is now allowed as it represents the null byte
        $this->validate('/\0/');
    }

    public function test_invalid_backreference_out_of_bounds(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Backreference to non-existent group');
        $this->validate('/\5/'); // Group 5 doesn't exist
    }

    public function test_invalid_relative_backref(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('relative reference');
        $this->validate('/(a)\g{-5}/');
    }

    public function test_invalid_named_backref(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('non-existent named group');
        $this->validate('/(a)\k<foo>/');
    }

    public function test_variable_quantifier_in_lookbehind(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Lookbehind is unbounded');
        $this->validate('/(?<=a*)/');
    }

    public function test_keep_in_lookbehind(): void
    {
        // PHP compiles it: php-src sets PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.
        $this->assertTrue($this->regex->validate('/(?<=a\K)/')->isValid);
    }

    public function test_invalid_posix_class(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid POSIX class');
        $this->validate('/[[:fake:]]/');
    }

    public function test_posix_negation_of_word_is_valid(): void
    {
        $this->validate('/[[:^word:]]/');

        $this->assertNotFalse(@preg_match('/[[:^word:]]/', ''));
    }

    public function test_invalid_conditional_condition(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid conditional construct');
        // Literal 'a' is not a valid condition
        $this->validate('/(?(a)b)/');
    }

    public function test_invalid_unicode_codepoint(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('out of range');
        $this->validate('/\u{110000}/u');
    }

    public function test_invalid_unicode_property(): void
    {
        $this->expectException(SemanticErrorException::class);
        $this->expectExceptionMessage('Invalid or unsupported Unicode property');
        $this->validate('/\p{InvalidProp}/u');
    }

    private function validate(string $regex): void
    {
        $ast = $this->regex->parse($regex);
        $ast->accept($this->validator);
    }
}
