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
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\Exception\VisualContextInterface;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A limit of the library (pattern length, nesting depth) says the analysis
 * gave up, never that the pattern is invalid: PCRE compiles both patterns
 * below, and a grammar error handler must not see the limit.
 */
final class LimitOutcomeTest extends TestCase
{
    /**
     * @return iterable<string, array{options: array{max_pattern_length?: int, max_recursion_depth?: int, pcre_version?: string}, regex: string, exception: class-string<LimitException>, code: ErrorCode, option: string}>
     */
    public static function provideLimits(): iterable
    {
        yield 'pattern length' => [
            'options' => ['max_pattern_length' => 5],
            'regex' => '/abcdefghij/',
            'exception' => ResourceLimitException::class,
            'code' => ErrorCode::PatternTooLong,
            'option' => 'max_pattern_length',
        ];

        yield 'nesting depth' => [
            'options' => ['max_recursion_depth' => 3],
            'regex' => '/((((a))))/',
            'exception' => RecursionLimitException::class,
            'code' => ErrorCode::NestingTooDeep,
            'option' => 'max_recursion_depth',
        ];

        // Read through the extended class reader, which hands the limit to
        // the parser's own error ordering. (?[...]) is read for the PCRE2
        // that has it, whatever the host runs.
        yield 'extended class operations' => [
            'options' => ['max_recursion_depth' => 5, 'pcre_version' => '10.49'],
            'regex' => '/(?['.implode(' | ', array_fill(0, 12, '[a]')).'])/',
            'exception' => RecursionLimitException::class,
            'code' => ErrorCode::ExtendedClassTooComplex,
            'option' => 'max_recursion_depth',
        ];
    }

    #[Test]
    public function test_pcre_compiles_the_patterns_the_limits_stop_on(): void
    {
        $this->assertSame(1, preg_match('/abcdefghij/', 'abcdefghij'));
        $this->assertSame(1, preg_match('/((((a))))/', 'a'));
    }

    /**
     * @param array{max_pattern_length?: int, max_recursion_depth?: int, pcre_version?: string} $options
     * @param class-string<LimitException>                                                      $exception
     */
    #[Test]
    #[DataProvider('provideLimits')]
    public function test_validate_reports_a_limit_apart_from_an_invalid_pattern(array $options, string $regex, string $exception, ErrorCode $code, string $option): void
    {
        $result = RegexParser::create($options)->validate($regex);

        $this->assertFalse($result->isValid);
        $this->assertSame(ValidationErrorCategory::Limit, $result->category);
        $this->assertSame($code, $result->errorCode);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('"'.$option.'"', (string) $result->hint);
        $this->assertSame(ValidationErrorCategory::Syntax, RegexParser::create()->validate('/(a/')->category, 'control: a grammar error stays syntax');
    }

    /**
     * @param array{max_pattern_length?: int, max_recursion_depth?: int, pcre_version?: string} $options
     * @param class-string<LimitException>                                                      $exception
     */
    #[Test]
    #[DataProvider('provideLimits')]
    public function test_a_limit_is_not_a_parser_exception(array $options, string $regex, string $exception, ErrorCode $code, string $option): void
    {
        try {
            RegexParser::create($options)->parse($regex);
        } catch (ParserException $grammar) {
            $this->fail(\sprintf('A grammar error handler caught the limit: %s', $grammar->getMessage()));
        } catch (LimitException $limit) {
            $this->assertInstanceOf($exception, $limit);
            $this->assertInstanceOf(RegexException::class, $limit);
            $this->assertInstanceOf(ExceptionInterface::class, $limit);
            $this->assertInstanceOf(VisualContextInterface::class, $limit);
            $this->assertSame($code, $limit->errorCode);

            return;
        }

        $this->fail('The limit was not reached.');
    }

    /**
     * @param array{max_pattern_length?: int, max_recursion_depth?: int, pcre_version?: string} $options
     * @param class-string<LimitException>                                                      $exception
     */
    #[Test]
    #[DataProvider('provideLimits')]
    public function test_tolerant_parse_reports_the_limit_as_it_was_met(array $options, string $regex, string $exception, ErrorCode $code, string $option): void
    {
        $result = RegexParser::create($options)->parseTolerant($regex);

        $this->assertTrue($result->hasErrors());
        $this->assertCount(1, $result->errors);
        $this->assertInstanceOf($exception, $result->errors[0]);
    }

    /**
     * A pattern holding an error past where the library stops: the search
     * for an error PCRE meets first reads the pattern again, and meets the
     * limit there too. The limit is the verdict, and it never escapes.
     *
     * @return iterable<string, array{regex: string, code: ErrorCode}>
     */
    public static function provideLimitsBeforeAnError(): iterable
    {
        yield 'escape refused after the nesting' => ['regex' => '/((((a))))\x{zz}/', 'code' => ErrorCode::NestingTooDeep];
        yield 'reversed range after the nesting' => ['regex' => '/((((a))))[z-a]/', 'code' => ErrorCode::NestingTooDeep];
        yield 'escape refused in a class after the nesting' => ['regex' => '/((((a))))[\x{zz}]/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unknown POSIX class after the nesting' => ['regex' => '/((((a))))[[:foo:]]/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unclosed group around the nesting' => ['regex' => '/((((a)))/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unclosed property after the nesting' => ['regex' => '/((((a))))\p{Xx/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unclosed verb after the nesting' => ['regex' => '/((((a))))(*FOO/', 'code' => ErrorCode::NestingTooDeep];
        yield 'version condition after the nesting' => ['regex' => '/((((a))))(?(VERSION>=10.0)a)\x{zz}/', 'code' => ErrorCode::NestingTooDeep];
        yield 'nesting in a version condition' => ['regex' => '/(?(VERSION>=10.0)((((a)))))\x{zz}/', 'code' => ErrorCode::NestingTooDeep];
        yield 'nesting in an extended class' => ['regex' => '/(?[(((([a])))) | [b]])/', 'code' => ErrorCode::NestingTooDeep];
        yield 'nesting in a later operand' => ['regex' => '/(?[[a] | (((([b]))))])/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unclosed extended class past its operations' => ['regex' => '/(?[[a] | [b] | [c] | [d] | [e] | [f]]/', 'code' => ErrorCode::ExtendedClassTooComplex];
        yield 'escape refused past the operations' => ['regex' => '/(?[[a] | [b] | [c] | [d] | [e] | [f] | \x{zz}])/', 'code' => ErrorCode::ExtendedClassTooComplex];
        // Refused while tokenizing, before any parse: the pattern is read
        // again up to the fault, and meets the limit first.
        yield 'unclosed comment after the nesting' => ['regex' => '/((((a))))(?#abc/', 'code' => ErrorCode::NestingTooDeep];
        yield 'control escape at the end after the nesting' => ['regex' => '/((((a))))\c/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unclosed class after the nesting' => ['regex' => '/((((a))))[abc/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unclosed POSIX class after the nesting' => ['regex' => '/((((a))))[[:alpha:]/', 'code' => ErrorCode::NestingTooDeep];
        yield 'unclosed scan list after the nesting' => ['regex' => '/((((a))))(*scs:(/', 'code' => ErrorCode::NestingTooDeep];
        yield 'relative reference to no group before an unclosed comment' => ['regex' => '/((((a))))\g{-9}(?#abc/', 'code' => ErrorCode::NestingTooDeep];
    }

    /**
     * A version condition is read alone while the error PCRE meets first is
     * looked for: under a depth of one, that read meets the limit too.
     */
    #[Test]
    public function test_a_limit_met_reading_a_version_condition_apart_is_the_verdict(): void
    {
        $result = RegexParser::create(['max_recursion_depth' => 1])->validate('/(?(VERSION>=10.0)a)(?#x/');

        $this->assertSame(ValidationErrorCategory::Limit, $result->category);
        $this->assertSame(ErrorCode::NestingTooDeep, $result->errorCode);
    }

    #[Test]
    #[DataProvider('provideLimitsBeforeAnError')]
    public function test_a_limit_met_before_an_error_is_the_verdict(string $regex, ErrorCode $code): void
    {
        // The rows hold (?[...]) and (*scs:...): they are read for the PCRE2
        // that has them, whatever the host runs.
        $options = ['max_recursion_depth' => 3, 'pcre_version' => '10.49'];
        $result = RegexParser::create($options)->validate($regex);

        $this->assertSame(ValidationErrorCategory::Limit, $result->category);
        $this->assertSame($code, $result->errorCode);
        $this->assertNotInstanceOf(ParserException::class, RegexParser::create($options)->parseTolerant($regex)->errors[0]);
    }
}
