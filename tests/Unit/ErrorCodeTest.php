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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\ErrorCode;
use RegexParser\Tests\TestUtils\ValidatorErrorCodes;

/**
 * The error codes form one closed enum. Every value reads
 * "regex.<area>.<problem>" in snake_case, the codes callers already match on
 * keep their exact values, and the catch-all codes are gone.
 */
final class ErrorCodeTest extends TestCase
{
    /**
     * The one code that predates the scheme with two segments only; every
     * other code names an area and a problem.
     */
    private const TWO_SEGMENT_VALUES = ['regex.complexity'];

    #[Test]
    public function test_error_code_is_a_string_backed_enum(): void
    {
        $enum = new \ReflectionEnum(ErrorCode::class);

        $this->assertTrue($enum->isBacked());
        $this->assertSame('string', (string) $enum->getBackingType());
        $this->assertNotSame([], ErrorCode::cases());
    }

    #[Test]
    public function test_error_code_values_follow_the_area_and_problem_scheme(): void
    {
        foreach (ErrorCode::cases() as $case) {
            if (\in_array($case->value, self::TWO_SEGMENT_VALUES, true)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^regex\.[a-z0-9_]+(\.[a-z0-9_]+)+$/',
                $case->value,
                \sprintf('%s does not read "regex.<area>.<problem>" in snake_case.', $case->name),
            );
        }
    }

    #[Test]
    public function test_error_code_values_never_end_in_a_catch_all_segment(): void
    {
        foreach (ErrorCode::cases() as $case) {
            $this->assertStringEndsNotWith('.error', $case->value, \sprintf('%s names no problem a user could fix.', $case->name));
        }
    }

    #[Test]
    public function test_error_code_two_segment_value_is_only_the_complexity_budget(): void
    {
        foreach (ErrorCode::cases() as $case) {
            if (1 === preg_match('/^regex\.[a-z_]+$/', $case->value)) {
                $this->assertContains($case->value, self::TWO_SEGMENT_VALUES, \sprintf('%s names an area but no problem.', $case->name));
            }
        }
    }

    #[Test]
    public function test_error_code_case_names_are_pascal_case(): void
    {
        foreach (ErrorCode::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[A-Z][A-Za-z0-9]*$/', $case->name);
        }
    }

    #[Test]
    public function test_error_code_every_case_has_a_one_line_doc_comment(): void
    {
        foreach ((new \ReflectionEnum(ErrorCode::class))->getCases() as $case) {
            $doc = $case->getDocComment();

            $this->assertIsString($doc, \sprintf('%s carries no doc comment.', $case->getName()));

            // The docblock may span three physical lines, as the code style
            // lays it out; its text is one line, the problem in user words.
            $lines = array_values(array_filter(
                array_map(
                    static fn (string $line): string => trim($line, " \t*"),
                    explode("\n", (string) preg_replace('~^/\*\*|\*/$~', '', $doc)),
                ),
                static fn (string $line): bool => '' !== $line,
            ));

            $this->assertNotSame([], $lines, \sprintf('%s has an empty doc comment.', $case->getName()));
            $this->assertCount(1, $lines, \sprintf('%s: the doc comment must hold exactly one line of text.', $case->getName()));
        }
    }

    #[Test]
    #[DataProvider('provideGenericValues')]
    public function test_error_code_has_no_generic_value(string $value): void
    {
        $this->assertNull(ErrorCode::tryFrom($value), \sprintf('The catch-all code "%s" must not survive as an enum case.', $value));
    }

    #[Test]
    #[DataProvider('provideValuesThatKeepTheirString')]
    public function test_error_code_keeps_the_value_callers_already_match_on(string $value): void
    {
        $this->assertSame($value, ErrorCode::from($value)->value);
    }

    #[Test]
    public function test_error_code_keeps_every_validator_code(): void
    {
        $values = array_map(static fn (ErrorCode $case): string => $case->value, ErrorCode::cases());

        $this->assertSame([], array_values(array_diff(ValidatorErrorCodes::VALUES, $values)), 'validator codes missing from the enum');
        $this->assertSame([], array_values(array_diff(ValidatorErrorCodes::OTHER_VALUES, $values)));
    }

    /**
     * @return iterable<string, array{value: string}>
     */
    public static function provideGenericValues(): iterable
    {
        foreach (ValidatorErrorCodes::GENERIC_VALUES as $value) {
            yield $value => ['value' => $value];
        }
    }

    /**
     * @return iterable<string, array{value: string}>
     */
    public static function provideValuesThatKeepTheirString(): iterable
    {
        foreach ([...ValidatorErrorCodes::VALUES, ...ValidatorErrorCodes::OTHER_VALUES] as $value) {
            yield $value => ['value' => $value];
        }
    }
}
