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
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Exception\SemanticErrorException;
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
        yield 'position' => ['class' => ParserException::class, 'getter' => 'getPosition', 'property' => 'position'];
        yield 'snippet' => ['class' => RegexException::class, 'getter' => 'getSnippet', 'property' => 'snippet'];
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
            $this->assertSame(2, $exception->position);
            $this->assertSame("Line 1: a(\n".str_repeat(' ', 10).'^', $exception->snippet);
        }
    }

    /**
     * The exceptions that show the pattern are built one way: the caret
     * stands at the snippet position when one is given, at the position
     * otherwise, and the position stays what it was.
     *
     * @param class-string<LexerException|LimitException|ParserException> $class
     */
    #[Test]
    #[DataProvider('provideExceptionsShowingThePattern')]
    public function test_the_caret_stands_at_the_snippet_position(string $class): void
    {
        $shifted = $class::withContext('Bad.', ErrorCode::GroupUnclosed, 5, '/abcdefgh/', null, 2);
        $atTwo = $class::withContext('Bad.', ErrorCode::GroupUnclosed, 2, '/abcdefgh/');
        $atFive = $class::withContext('Bad.', ErrorCode::GroupUnclosed, 5, '/abcdefgh/');

        $this->assertInstanceOf($class, $shifted);
        $this->assertSame(5, $shifted->position);
        $this->assertSame('/abcdefgh/', $shifted->getPattern());
        $this->assertSame($atTwo->snippet, $shifted->snippet);
        $this->assertNotSame($atFive->snippet, $shifted->snippet);
        $this->assertStringEndsWith(str_repeat(' ', 13).'^', (string) $atFive->snippet);
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
