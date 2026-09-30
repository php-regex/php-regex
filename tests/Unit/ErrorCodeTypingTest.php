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
use RegexParser\Exception\LexerException;
use RegexParser\Exception\ParserException;
use RegexParser\Exception\RegexException;
use RegexParser\Exception\SyntaxErrorException;
use RegexParser\RegexParser;
use RegexParser\ValidationResult;

/**
 * The code travels as an ErrorCode, never as a string: an exception always
 * carries one, a validation result carries one exactly when the pattern is
 * refused, and a syntax exception cannot be raised without one.
 */
final class ErrorCodeTypingTest extends TestCase
{
    #[Test]
    public function test_syntax_exception_carries_an_error_code(): void
    {
        try {
            RegexParser::create(['cache' => null])->parse('/a(/');
        } catch (SyntaxErrorException $e) {
            $this->assertSame(ErrorCode::from('regex.group.unclosed'), $e->getErrorCode());

            return;
        }

        $this->fail('"/a(/" must raise a syntax error.');
    }

    #[Test]
    public function test_lexer_exception_carries_an_error_code(): void
    {
        try {
            RegexParser::create(['cache' => null])->parse('/[a/');
        } catch (LexerException $e) {
            $this->assertSame(ErrorCode::from('regex.charclass.unclosed'), $e->getErrorCode());

            return;
        }

        $this->fail('"/[a/" must raise a lexer error.');
    }

    #[Test]
    public function test_delimiter_exception_carries_an_error_code(): void
    {
        try {
            RegexParser::create(['cache' => null])->parse('/abc');
        } catch (ParserException $e) {
            $this->assertSame(ErrorCode::from('regex.delimiter.unclosed'), $e->getErrorCode());

            return;
        }

        $this->fail('"/abc" must raise a parser error.');
    }

    #[Test]
    public function test_regex_exception_error_code_is_never_null(): void
    {
        $type = (new \ReflectionMethod(RegexException::class, 'getErrorCode'))->getReturnType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(ErrorCode::class, $type->getName());
        $this->assertFalse($type->allowsNull());
    }

    /**
     * @param class-string<RegexException> $exception
     */
    #[Test]
    #[DataProvider('provideExceptionsThatRequireACode')]
    public function test_syntax_exception_constructor_requires_an_error_code(string $exception): void
    {
        $required = array_filter(
            (new \ReflectionMethod($exception, '__construct'))->getParameters(),
            static function (\ReflectionParameter $parameter): bool {
                $type = $parameter->getType();

                return $type instanceof \ReflectionNamedType
                    && ErrorCode::class === $type->getName()
                    && !$type->allowsNull()
                    && !$parameter->isOptional();
            },
        );

        $this->assertCount(1, $required, \sprintf('%s must take exactly one required, non-nullable ErrorCode.', $exception));
    }

    #[Test]
    public function test_validation_result_error_code_is_a_nullable_error_code(): void
    {
        $type = (new \ReflectionProperty(ValidationResult::class, 'errorCode'))->getType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame(ErrorCode::class, $type->getName());
        $this->assertTrue($type->allowsNull());
    }

    #[Test]
    public function test_validation_result_of_a_refused_pattern_carries_an_error_code(): void
    {
        $result = RegexParser::create(['cache' => null])->validate('/a(/');

        $this->assertFalse($result->isValid);
        $this->assertSame(ErrorCode::from('regex.group.unclosed'), $result->errorCode);
        $this->assertSame($result->errorCode, $result->getErrorCode());
    }

    /**
     * Holds before and after the enum: a valid pattern never carries a code,
     * whatever its type. The at-limit patterns pin the boundary the refused
     * ones sit one step past.
     *
     * @param array<string, mixed> $options
     */
    #[Test]
    #[DataProvider('provideValidPatterns')]
    public function test_validation_result_of_a_valid_pattern_has_no_error_code(string $pattern, array $options): void
    {
        $result = RegexParser::create(['cache' => null] + $options)->validate($pattern);

        $this->assertTrue($result->isValid, (string) $result->error);
        $this->assertNull($result->errorCode);
        $this->assertNull($result->getErrorCode());
    }

    /**
     * @return iterable<string, array{exception: class-string<RegexException>}>
     */
    public static function provideExceptionsThatRequireACode(): iterable
    {
        yield 'lexer' => ['exception' => LexerException::class];
        yield 'parser' => ['exception' => ParserException::class];
        yield 'syntax' => ['exception' => SyntaxErrorException::class];
    }

    /**
     * @return iterable<string, array{pattern: string, options: array<string, mixed>}>
     */
    public static function provideValidPatterns(): iterable
    {
        yield 'plain literal' => ['pattern' => '/abc/', 'options' => []];
        yield 'exactly the maximum length' => ['pattern' => '/abcdefgh/', 'options' => ['max_pattern_length' => 10]];
        yield 'exactly the nesting limit' => ['pattern' => '/((((a))))/', 'options' => ['max_recursion_depth' => 5]];
    }
}
