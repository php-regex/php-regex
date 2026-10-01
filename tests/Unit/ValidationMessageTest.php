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

use PhpRegex\Parser\Validation\ValidationResult;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A failed validation carries the message and the caret snippet apart: the
 * message is one line a tool can print or compare, the snippet is the
 * picture under it, and neither holds the other.
 */
final class ValidationMessageTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_error_message_holds_no_snippet(string $pattern, string $message): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($message, $result->error, $pattern);
        $this->assertNotNull($result->caretSnippet, $pattern);
        $this->assertStringContainsString('^', (string) $result->caretSnippet, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, message: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'syntax error' => ['pattern' => '/[unclosed/', 'message' => 'Unclosed character class "]" at end of input.'];
        yield 'semantic error' => ['pattern' => '/(?<=a+)b/', 'message' => 'Lookbehind is unbounded. PCRE requires a bounded maximum length.'];
        yield 'modifier error' => ['pattern' => '/a/Q', 'message' => 'Unknown regex flag(s) found: "Q"'];
    }

    #[Test]
    public function test_runtime_error_message_holds_no_snippet(): void
    {
        $regex = Regex::create();
        $method = (new \ReflectionClass($regex->parser()))->getMethod('checkRuntimeCompilation');

        $result = $method->invoke($regex->parser(), '/invalid[pattern/', 'invalid[pattern', 1);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertStringStartsWith('PCRE runtime error: ', (string) $result->error);
        $this->assertStringNotContainsString("\n", (string) $result->error);
        $this->assertNotNull($result->caretSnippet);
    }
}
