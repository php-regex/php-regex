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
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\VisualContextInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserExceptionTest extends TestCase
{
    /**
     * The snippet contract has a name: the exceptions that carry one
     * implement the interface, so a consumer can catch the shape instead
     * of the class.
     */
    #[Test]
    public function test_the_exception_declares_the_visual_context_interface(): void
    {
        $this->assertTrue(interface_exists(VisualContextInterface::class), VisualContextInterface::class.' must exist.');
        $this->assertTrue(is_a(ParserException::class, VisualContextInterface::class, true), ParserException::class.' must implement '.VisualContextInterface::class.'.');
        $this->assertTrue((new \ReflectionMethod(VisualContextInterface::class, 'getPattern'))->isPublic());
        $this->assertFalse(method_exists(VisualContextInterface::class, 'getVisualSnippet'), 'The snippet is read through RegexException::$snippet alone.');
    }

    #[Test]
    public function test_the_snippet_of_an_exception_without_context_is_empty(): void
    {
        $exception = new ParserException('Error', ErrorCode::TokenUnexpected);

        $this->assertSame('', $exception->snippet);
    }

    #[Test]
    public function test_visual_snippet_at_beginning(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 0, 'abc');

        $this->assertSame("Line 1: abc\n".str_repeat(' ', 8).'^', $exception->snippet);
    }

    #[Test]
    public function test_visual_snippet_at_end(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 3, 'abc');

        $this->assertSame("Line 1: abc\n".str_repeat(' ', 11).'^', $exception->snippet);
    }

    #[Test]
    public function test_visual_snippet_in_middle(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 4, 'foo(bar)baz');

        $this->assertSame("Line 1: foo(bar)baz\n".str_repeat(' ', 12).'^', $exception->snippet);
    }

    #[Test]
    public function test_visual_snippet_in_multiline_pattern(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 5, "foo\nbar\nbaz");

        $this->assertSame("Line 2: bar\n".str_repeat(' ', 9).'^', $exception->snippet);
    }

    #[Test]
    public function test_visual_snippet_truncates_long_patterns(): void
    {
        $pattern = str_repeat('a', 50).'X'.str_repeat('b', 50);
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 50, $pattern);

        $snippet = (string) $exception->snippet;
        [$line, $caretLine] = explode("\n", $snippet);

        $this->assertStringStartsWith('Line 1: ...', $line);
        $this->assertStringEndsWith('...', $line);
        $caretPos = strpos($caretLine, '^');
        $this->assertIsInt($caretPos);
        $this->assertSame('X', $line[$caretPos]);
        $this->assertLessThan(\strlen('Line 1: '.$pattern), \strlen($line));
    }
}
