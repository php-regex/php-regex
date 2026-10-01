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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\Validation\ValidationErrorCategory;
use PhpRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
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
    }

    public function test_construct_with_all_parameters(): void
    {
        $result = new ValidationResult(
            isValid: false,
            error: 'Test error message',
            complexityScore: 42,
            category: ValidationErrorCategory::SYNTAX,
            offset: 10,
            caretSnippet: 'pattern with error',
            hint: 'Fix the syntax',
            errorCode: ErrorCode::TokenUnexpected,
        );

        $this->assertFalse($result->isValid);
        $this->assertSame('Test error message', $result->error);
        $this->assertSame(42, $result->complexityScore);
        $this->assertSame(ValidationErrorCategory::SYNTAX, $result->category);
        $this->assertSame(10, $result->offset);
        $this->assertSame('pattern with error', $result->caretSnippet);
        $this->assertSame('Fix the syntax', $result->hint);
        $this->assertSame(ErrorCode::TokenUnexpected, $result->errorCode);
    }

    public function test_accessors_mirror_properties(): void
    {
        $result = new ValidationResult(false, 'error', 123);

        $this->assertFalse($result->isValid);
        $this->assertSame('error', $result->error);
        $this->assertSame(123, $result->complexityScore);

        $this->assertFalse($result->isValid());
        $this->assertSame('error', $result->getErrorMessage());
        $this->assertSame(123, $result->getComplexityScore());
    }

    public function test_get_error_category(): void
    {
        $result = new ValidationResult(
            isValid: false,
            category: ValidationErrorCategory::SEMANTIC,
        );

        $this->assertSame(ValidationErrorCategory::SEMANTIC, $result->category);
        $this->assertSame(ValidationErrorCategory::SEMANTIC, $result->getErrorCategory());
    }

    public function test_get_error_offset(): void
    {
        $result = new ValidationResult(
            isValid: false,
            offset: 25,
        );

        $this->assertSame(25, $result->offset);
        $this->assertSame(25, $result->getErrorOffset());
    }

    public function test_get_caret_snippet(): void
    {
        $result = new ValidationResult(
            isValid: false,
            caretSnippet: 'error at this position',
        );

        $this->assertSame('error at this position', $result->caretSnippet);
        $this->assertSame('error at this position', $result->getCaretSnippet());
    }

    public function test_get_hint(): void
    {
        $result = new ValidationResult(
            isValid: false,
            hint: 'Consider using atomic groups',
        );

        $this->assertSame('Consider using atomic groups', $result->hint);
        $this->assertSame('Consider using atomic groups', $result->getHint());
    }

    public function test_get_error_code(): void
    {
        $result = new ValidationResult(
            isValid: false,
            errorCode: ErrorCode::GroupUnclosed,
        );

        $this->assertSame(ErrorCode::GroupUnclosed, $result->errorCode);
        $this->assertSame(ErrorCode::GroupUnclosed, $result->getErrorCode());
    }

    public function test_null_values(): void
    {
        $result = new ValidationResult(true);

        $this->assertNull($result->getErrorMessage());
        $this->assertNotInstanceOf(ValidationErrorCategory::class, $result->getErrorCategory());
        $this->assertNull($result->getErrorOffset());
        $this->assertNull($result->getCaretSnippet());
        $this->assertNull($result->getHint());
        $this->assertNull($result->getErrorCode());
    }

    public function test_valid_result(): void
    {
        $result = new ValidationResult(
            isValid: true,
            complexityScore: 5,
        );

        $this->assertTrue($result->isValid());
        $this->assertTrue($result->isValid);
        $this->assertSame(5, $result->getComplexityScore());
        $this->assertSame(5, $result->complexityScore);
        $this->assertNull($result->getErrorMessage());
    }

    public function test_all_error_categories(): void
    {
        $categories = [
            ValidationErrorCategory::SYNTAX,
            ValidationErrorCategory::SEMANTIC,
            ValidationErrorCategory::PCRE_RUNTIME,
        ];

        foreach ($categories as $category) {
            $result = new ValidationResult(
                isValid: false,
                category: $category,
            );

            $this->assertSame($category, $result->getErrorCategory());
            $this->assertSame($category, $result->category);
        }
    }

    public function test_zero_complexity_score(): void
    {
        $result = new ValidationResult(true, null, 0);

        $this->assertSame(0, $result->getComplexityScore());
        $this->assertSame(0, $result->complexityScore);
    }

    public function test_negative_offset(): void
    {
        $result = new ValidationResult(
            isValid: false,
            offset: -1,
        );

        $this->assertSame(-1, $result->getErrorOffset());
        $this->assertSame(-1, $result->offset);
    }

    public function test_empty_strings(): void
    {
        $result = new ValidationResult(
            isValid: false,
            error: '',
            caretSnippet: '',
            hint: '',
            errorCode: ErrorCode::PatternEmpty,
        );

        $this->assertSame('', $result->getErrorMessage());
        $this->assertSame('', $result->getCaretSnippet());
        $this->assertSame('', $result->getHint());
        // An error code is an enum case now, never an empty string.
        $this->assertSame(ErrorCode::PatternEmpty, $result->getErrorCode());
    }
}
