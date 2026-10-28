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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
    #[Test]
    public function test_construct_with_minimal_parameters(): void
    {
        $result = new ValidationResult(true);

        $this->assertTrue($result->isValid);
        $this->assertNull($result->error);
        $this->assertSame(0, $result->complexityScore);
        $this->assertNotInstanceOf(ValidationErrorCategory::class, $result->category);
        $this->assertNull($result->offset);
        $this->assertNull($result->caretSnippet);
        $this->assertNull($result->hint);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->ast);
    }

    #[Test]
    public function test_validate_hands_back_the_tree_of_a_valid_pattern(): void
    {
        $result = RegexParser::create()->validate('/a(b)/i');

        $this->assertTrue($result->isValid);
        $this->assertInstanceOf(RegexNode::class, $result->ast);
        $this->assertSame('a(b)', $result->ast->source);
        $this->assertSame('i', $result->ast->flags);
    }

    #[Test]
    public function test_validate_hands_back_no_tree_for_a_pattern_it_refuses(): void
    {
        $result = RegexParser::create()->validate('/(a/');

        $this->assertFalse($result->isValid);
        $this->assertNull($result->ast);
    }

    #[Test]
    public function test_validate_hands_back_no_tree_for_a_pattern_the_target_refuses(): void
    {
        // \x{ 41 } needs PCRE2 10.43, which PHP bundles from 8.4: the tree
        // parses, the target refuses it, and the result carries no tree
        $result = RegexParser::create(['php_version' => 80300])->validate('/a\x{ 41 }/');

        $this->assertFalse($result->isValid);
        $this->assertNull($result->ast);
    }

    #[Test]
    public function test_validate_hands_back_no_tree_past_a_limit_of_its_own(): void
    {
        $result = RegexParser::create(['max_pattern_length' => 4])->validate('/abcdef/');

        $this->assertTrue($result->isLimit());
        $this->assertNull($result->ast);
    }

    #[Test]
    public function test_json_serialization_leaves_the_tree_out(): void
    {
        $result = RegexParser::create()->validate('/a(b)/');

        $this->assertArrayNotHasKey('ast', $result->jsonSerialize());
    }

    #[Test]
    public function test_construct_with_all_parameters(): void
    {
        $result = new ValidationResult(
            isValid: false,
            error: 'Test error message',
            complexityScore: 42,
            category: ValidationErrorCategory::Syntax,
            offset: 10,
            caretSnippet: 'pattern with error',
            hint: 'Fix the syntax',
            errorCode: ErrorCode::TokenUnexpected,
        );

        $this->assertFalse($result->isValid);
        $this->assertSame('Test error message', $result->error);
        $this->assertSame(42, $result->complexityScore);
        $this->assertSame(ValidationErrorCategory::Syntax, $result->category);
        $this->assertSame(10, $result->offset);
        $this->assertSame('pattern with error', $result->caretSnippet);
        $this->assertSame('Fix the syntax', $result->hint);
        $this->assertSame(ErrorCode::TokenUnexpected, $result->errorCode);
    }

    /**
     * The two accessors 1.x carried beside the public properties are gone:
     * a caller reading them gets a fatal error, not a deprecated mirror.
     * The other accessors stay: they carry names the properties do not.
     */
    #[Test]
    public function test_the_is_valid_and_error_message_accessors_are_gone(): void
    {
        $this->assertFalse(
            method_exists(ValidationResult::class, 'isValid'),
            'ValidationResult::isValid() must be gone: read the public $isValid property.',
        );
        $this->assertFalse(
            method_exists(ValidationResult::class, 'getErrorMessage'),
            'ValidationResult::getErrorMessage() must be gone: read the public $error property.',
        );
    }

    #[Test]
    public function test_accessors_mirror_properties(): void
    {
        $result = new ValidationResult(false, 'error', 123);

        $this->assertFalse($result->isValid);
        $this->assertSame('error', $result->error);
        $this->assertSame(123, $result->complexityScore);

        $this->assertSame(123, $result->complexityScore);
    }

    #[Test]
    public function test_get_error_category(): void
    {
        $result = new ValidationResult(
            isValid: false,
            category: ValidationErrorCategory::Semantic,
        );

        $this->assertSame(ValidationErrorCategory::Semantic, $result->category);
        $this->assertSame(ValidationErrorCategory::Semantic, $result->category);
    }

    #[Test]
    public function test_get_error_offset(): void
    {
        $result = new ValidationResult(
            isValid: false,
            offset: 25,
        );

        $this->assertSame(25, $result->offset);
        $this->assertSame(25, $result->offset);
    }

    #[Test]
    public function test_get_caret_snippet(): void
    {
        $result = new ValidationResult(
            isValid: false,
            caretSnippet: 'error at this position',
        );

        $this->assertSame('error at this position', $result->caretSnippet);
        $this->assertSame('error at this position', $result->caretSnippet);
    }

    #[Test]
    public function test_get_hint(): void
    {
        $result = new ValidationResult(
            isValid: false,
            hint: 'Consider using atomic groups',
        );

        $this->assertSame('Consider using atomic groups', $result->hint);
        $this->assertSame('Consider using atomic groups', $result->hint);
    }

    #[Test]
    public function test_get_error_code(): void
    {
        $result = new ValidationResult(
            isValid: false,
            errorCode: ErrorCode::GroupUnclosed,
        );

        $this->assertSame(ErrorCode::GroupUnclosed, $result->errorCode);
        $this->assertSame(ErrorCode::GroupUnclosed, $result->errorCode);
    }

    #[Test]
    public function test_null_values(): void
    {
        $result = new ValidationResult(true);

        $this->assertNull($result->error);
        $this->assertNotInstanceOf(ValidationErrorCategory::class, $result->category);
        $this->assertNull($result->offset);
        $this->assertNull($result->caretSnippet);
        $this->assertNull($result->hint);
        $this->assertNull($result->errorCode);
    }

    #[Test]
    public function test_valid_result(): void
    {
        $result = new ValidationResult(
            isValid: true,
            complexityScore: 5,
        );

        $this->assertTrue($result->isValid);
        $this->assertIsBool($result->isValid);
        $this->assertSame(5, $result->complexityScore);
        $this->assertSame(5, $result->complexityScore);
        $this->assertNull($result->error);
    }

    #[Test]
    public function test_all_error_categories(): void
    {
        $categories = [
            ValidationErrorCategory::Syntax,
            ValidationErrorCategory::Semantic,
            ValidationErrorCategory::PcreRuntime,
        ];

        foreach ($categories as $category) {
            $result = new ValidationResult(
                isValid: false,
                category: $category,
            );

            $this->assertSame($category, $result->category);
            $this->assertSame($category, $result->category);
        }
    }

    #[Test]
    public function test_zero_complexity_score(): void
    {
        $result = new ValidationResult(true, null, 0);

        $this->assertSame(0, $result->complexityScore);
        $this->assertSame(0, $result->complexityScore);
    }

    #[Test]
    public function test_negative_offset(): void
    {
        $result = new ValidationResult(
            isValid: false,
            offset: -1,
        );

        $this->assertSame(-1, $result->offset);
        $this->assertSame(-1, $result->offset);
    }

    #[Test]
    public function test_empty_strings(): void
    {
        $result = new ValidationResult(
            isValid: false,
            error: '',
            caretSnippet: '',
            hint: '',
            errorCode: ErrorCode::PatternEmpty,
        );

        $this->assertSame('', $result->error);
        $this->assertSame('', $result->caretSnippet);
        $this->assertSame('', $result->hint);
        // An error code is an enum case now, never an empty string.
        $this->assertSame(ErrorCode::PatternEmpty, $result->errorCode);
    }

    /**
     * The message, the line of the pattern with its caret, then the hint:
     * the block every surface prints, written once.
     */
    #[Test]
    public function test_render_writes_the_message_the_caret_and_the_hint(): void
    {
        $result = RegexParser::create()->validate('/a{2,1}/');

        $this->assertSame(
            "Invalid quantifier range \"{2,1}\": min > max.\n"
            ."Line 1: a{2,1}\n"
            ."             ^\n"
            .'Hint: Write the smaller count first: "{1,2}".',
            $result->render(),
        );
    }

    #[Test]
    public function test_render_writes_nothing_for_a_valid_pattern(): void
    {
        $this->assertSame('', RegexParser::create()->validate('/a+/')->render());
    }

    /**
     * Each part the result does not carry is left out, with its line.
     */
    #[Test]
    public function test_render_leaves_out_what_the_result_does_not_carry(): void
    {
        $this->assertSame('Bare message.', (new ValidationResult(false, 'Bare message.'))->render());
        $this->assertSame("Bare message.\nHint: Do this.", (new ValidationResult(false, 'Bare message.', hint: 'Do this.'))->render());
        $this->assertSame("Line 1: a(\n         ^", (new ValidationResult(false, caretSnippet: "Line 1: a(\n         ^"))->render());
    }

    /**
     * A limit of the library reads as one too: the message says what was
     * exceeded, the hint which option to raise.
     */
    #[Test]
    public function test_render_a_limit_names_the_option_to_raise(): void
    {
        $result = RegexParser::create(['max_pattern_length' => 5])->validate('/abcdefghij/');

        $this->assertSame(ValidationErrorCategory::Limit, $result->category);
        $this->assertNotNull($result->hint);
        $this->assertStringStartsWith("Regex pattern exceeds the maximum length of 5 bytes, delimiters and modifiers included.\nLine 1: /abcdefghij/\n", $result->render());
        $this->assertStringEndsWith("\nHint: ".$result->hint, $result->render());
    }
}
