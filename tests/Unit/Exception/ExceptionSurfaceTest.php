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

namespace PHPRegex\Tests\Unit\Exception;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An exception of the library reads as its results do: through public
 * readonly properties, no getter repeating one.
 */
final class ExceptionSurfaceTest extends TestCase
{
    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('provideRepeatingGetters')]
    public function test_no_getter_repeats_a_property(string $class, string $getter, string $property): void
    {
        $reflection = new \ReflectionClass($class);

        $this->assertFalse($reflection->hasMethod($getter), $class.'::'.$getter.'()');
        $this->assertTrue($reflection->getProperty($property)->isPublic());
        $this->assertTrue($reflection->getProperty($property)->isReadOnly());
    }

    /**
     * @return iterable<string, array{class: class-string, getter: string, property: string}>
     */
    public static function provideRepeatingGetters(): iterable
    {
        yield 'error code' => ['class' => RegexException::class, 'getter' => 'getErrorCode', 'property' => 'errorCode'];
        yield 'offset' => ['class' => ParserException::class, 'getter' => 'getOffset', 'property' => 'offset'];
        yield 'caret snippet' => ['class' => RegexException::class, 'getter' => 'getCaretSnippet', 'property' => 'caretSnippet'];
        yield 'hint' => ['class' => SemanticErrorException::class, 'getter' => 'getHint', 'property' => 'hint'];
    }

    #[Test]
    public function test_a_parse_failure_reads_through_its_properties(): void
    {
        try {
            Regex::create()->parse('/a(/');
            $this->fail('"/a(/" does not parse.');
        } catch (ParserException $exception) {
            $this->assertSame(ErrorCode::GroupUnclosed, $exception->errorCode);
            $this->assertSame(2, $exception->offset);
            $this->assertSame("Line 1: a(\n".str_repeat(' ', 10).'^', $exception->caretSnippet);
        }
    }

    /**
     * One catch holds every pattern parse() refuses as malformed, whichever
     * part of the parser met the fault: its delimiters, its tokens or its
     * grammar.
     *
     * @param class-string<ParserException> $thrown
     */
    #[Test]
    #[DataProvider('provideMalformedPatterns')]
    public function test_a_parser_exception_holds_every_malformed_pattern(string $regex, string $thrown): void
    {
        try {
            Regex::create()->parse($regex);
            $this->fail($regex.' does not parse.');
        } catch (ParserException $exception) {
            $this->assertInstanceOf($thrown, $exception);
        }
    }

    /**
     * @return iterable<string, array{regex: string, thrown: class-string<ParserException>}>
     */
    public static function provideMalformedPatterns(): iterable
    {
        yield 'no delimiter' => ['regex' => 'abc', 'thrown' => ParserException::class];
        yield 'a class left open, met while reading the tokens' => ['regex' => '/[a/', 'thrown' => LexerException::class];
        yield 'a group left open, met while reading the grammar' => ['regex' => '/a(/', 'thrown' => SyntaxErrorException::class];
    }

    /**
     * A limit of the library is no verdict on the pattern: a handler for
     * malformed patterns does not take it for one.
     */
    #[Test]
    public function test_a_limit_is_no_parser_exception(): void
    {
        try {
            Regex::create()->parse('/'.str_repeat('(', 600).str_repeat(')', 600).'/');
            $this->fail('600 nested groups pass the nesting limit.');
        } catch (ParserException $malformed) {
            $this->fail('A limit was taken for a malformed pattern: '.$malformed->getMessage());
        } catch (LimitException $limit) {
            $this->assertInstanceOf(RecursionLimitException::class, $limit);
            $this->assertSame(ErrorCode::NestingTooDeep, $limit->errorCode);
        }
    }

    /**
     * The exceptions that show the pattern are built one way: the caret
     * stands at the caret offset when one is given, at the offset
     * otherwise, and the offset stays what it was.
     *
     * @param class-string<LexerException|LimitException|ParserException> $class
     */
    #[Test]
    #[DataProvider('provideExceptionsShowingThePattern')]
    public function test_the_caret_stands_at_the_caret_offset(string $class): void
    {
        $shifted = $class::withContext('Bad.', ErrorCode::GroupUnclosed, 5, '/abcdefgh/', null, 2);
        $atTwo = $class::withContext('Bad.', ErrorCode::GroupUnclosed, 2, '/abcdefgh/');
        $atFive = $class::withContext('Bad.', ErrorCode::GroupUnclosed, 5, '/abcdefgh/');

        $this->assertInstanceOf($class, $shifted);
        $this->assertSame(5, $shifted->offset);
        $this->assertSame('/abcdefgh/', $shifted->getPattern());
        $this->assertSame($atTwo->caretSnippet, $shifted->caretSnippet);
        $this->assertNotSame($atFive->caretSnippet, $shifted->caretSnippet);
        $this->assertStringEndsWith(str_repeat(' ', 13).'^', (string) $atFive->caretSnippet);
    }

    /**
     * @return iterable<string, array{class: class-string<LexerException|LimitException|ParserException>}>
     */
    public static function provideExceptionsShowingThePattern(): iterable
    {
        yield 'lexer' => ['class' => LexerException::class];
        yield 'parser' => ['class' => ParserException::class];
        yield 'limit' => ['class' => LimitException::class];
    }
}
