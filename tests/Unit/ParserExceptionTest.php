<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Parser\Exception\ParserException;
use PHPUnit\Framework\TestCase;

final class ParserExceptionTest extends TestCase
{
    public function test_visual_snippet_at_beginning(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 0, 'abc');

        $this->assertSame("Line 1: abc\n".str_repeat(' ', 8).'^', $exception->getVisualSnippet());
    }

    public function test_visual_snippet_at_end(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 3, 'abc');

        $this->assertSame("Line 1: abc\n".str_repeat(' ', 11).'^', $exception->getVisualSnippet());
    }

    public function test_visual_snippet_in_middle(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 4, 'foo(bar)baz');

        $this->assertSame("Line 1: foo(bar)baz\n".str_repeat(' ', 12).'^', $exception->getVisualSnippet());
    }

    public function test_visual_snippet_in_multiline_pattern(): void
    {
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 5, "foo\nbar\nbaz");

        $this->assertSame("Line 2: bar\n".str_repeat(' ', 9).'^', $exception->getVisualSnippet());
    }

    public function test_visual_snippet_truncates_long_patterns(): void
    {
        $pattern = str_repeat('a', 50).'X'.str_repeat('b', 50);
        $exception = ParserException::withContext('Error', ErrorCode::TokenUnexpected, 50, $pattern);

        $snippet = $exception->getVisualSnippet();
        [$line, $caretLine] = explode("\n", $snippet);

        $this->assertStringStartsWith('Line 1: ...', $line);
        $this->assertStringEndsWith('...', $line);
        $caretPos = strpos($caretLine, '^');
        $this->assertIsInt($caretPos);
        $this->assertSame('X', $line[$caretPos]);
        $this->assertLessThan(\strlen('Line 1: '.$pattern), \strlen($line));
    }
}
