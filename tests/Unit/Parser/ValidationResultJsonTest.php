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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationResultJsonTest extends TestCase
{
    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('provideResults')]
    public function test_json_serialize_writes_every_key_in_snake_case(ValidationResult $result, array $expected): void
    {
        $this->assertSame($expected, $result->jsonSerialize());
    }

    /**
     * @return iterable<string, array{result: ValidationResult, expected: array<string, mixed>}>
     */
    public static function provideResults(): iterable
    {
        yield 'valid, every optional key null' => [
            'result' => new ValidationResult(true, complexityScore: 3),
            'expected' => [
                'is_valid' => true,
                'error' => null,
                'complexity_score' => 3,
                'category' => null,
                'offset' => null,
                'caret_snippet' => null,
                'hint' => null,
                'error_code' => null,
            ],
        ];

        yield 'syntax error, enums as their values' => [
            'result' => new ValidationResult(false, 'Unclosed group', 0, ValidationErrorCategory::Syntax, 4, "/(ab/\n    ^", 'Close the group.', ErrorCode::GroupUnclosed),
            'expected' => [
                'is_valid' => false,
                'error' => 'Unclosed group',
                'complexity_score' => 0,
                'category' => 'syntax',
                'offset' => 4,
                'caret_snippet' => "/(ab/\n    ^",
                'hint' => 'Close the group.',
                'error_code' => 'regex.group.unclosed',
            ],
        ];

        yield 'runtime error, raw bytes kept' => [
            'result' => new ValidationResult(false, "bad \xff byte", 0, ValidationErrorCategory::PcreRuntime, 0),
            'expected' => [
                'is_valid' => false,
                'error' => "bad \xff byte",
                'complexity_score' => 0,
                'category' => 'pcre-runtime',
                'offset' => 0,
                'caret_snippet' => null,
                'hint' => null,
                'error_code' => null,
            ],
        ];
    }
}
