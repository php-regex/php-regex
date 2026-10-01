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

namespace PhpRegex\Tests\Unit\Lsp\Handler;

use PhpRegex\LanguageServer\Document\DocumentManager;
use PhpRegex\LanguageServer\Document\RegexFinder;
use PhpRegex\LanguageServer\Handler\CodeActionHandler;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeActionHandlerTest extends TestCase
{
    private CodeActionHandler $handler;

    private DocumentManager $documents;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager(new RegexFinder());
        $this->handler = new CodeActionHandler($this->documents, Regex::create());
    }

    #[Test]
    public function test_handler_can_be_instantiated(): void
    {
        $handler = new CodeActionHandler($this->documents, Regex::create());

        $this->assertInstanceOf(CodeActionHandler::class, $handler);
    }

    #[Test]
    #[DataProvider('provideAddUnicodeFlagPatterns')]
    public function test_add_unicode_flag_correctly(string $input, string $expected): void
    {
        // Use reflection to test the private addUnicodeFlag method
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('addUnicodeFlag');

        $result = $method->invoke($this->handler, $input);

        $this->assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAddUnicodeFlagPatterns(): iterable
    {
        yield 'simple pattern' => ['/\\w+/', '/\\w+/u'];
        yield 'pattern with flags' => ['/\\w+/i', '/\\w+/ui'];
        yield 'pattern with multiple flags' => ['/\\w+/im', '/\\w+/uim'];
        yield 'hash delimiter' => ['#\\w+#', '#\\w+#u'];
        yield 'hash with flags' => ['#\\w+#i', '#\\w+#ui'];
        yield 'tilde delimiter' => ['~\\w+~', '~\\w+~u'];
    }

    #[Test]
    public function test_add_unicode_flag_returns_null_for_already_unicode(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('addUnicodeFlag');

        $result = $method->invoke($this->handler, '/\\w+/u');

        $this->assertNull($result);
    }

    #[Test]
    public function test_add_unicode_flag_returns_null_for_short_pattern(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('addUnicodeFlag');

        $result = $method->invoke($this->handler, '/');

        $this->assertNull($result);
    }

    #[Test]
    public function test_ranges_overlap_detection(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('rangesOverlap');

        // Overlapping ranges
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 0, 'character' => 10],
            ['line' => 0, 'character' => 5],
            ['line' => 0, 'character' => 15],
        );
        $this->assertTrue($result);

        // Non-overlapping ranges (range1 ends before range2 starts)
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 0, 'character' => 5],
            ['line' => 0, 'character' => 10],
            ['line' => 0, 'character' => 15],
        );
        $this->assertFalse($result);
    }

    #[Test]
    public function test_ranges_overlap_on_different_lines(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('rangesOverlap');

        // Non-overlapping: range1 on line 0, range2 on line 2
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 0, 'character' => 10],
            ['line' => 2, 'character' => 0],
            ['line' => 2, 'character' => 10],
        );
        $this->assertFalse($result);

        // Overlapping across lines
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 2, 'character' => 10],
            ['line' => 1, 'character' => 0],
            ['line' => 1, 'character' => 10],
        );
        $this->assertTrue($result);
    }

    #[Test]
    #[DataProvider('providePatternsToQuote')]
    public function test_a_replacement_is_php_the_editor_can_parse(string $pattern): void
    {
        $method = (new \ReflectionClass($this->handler))->getMethod('asPhpString');
        $literal = $method->invoke($this->handler, $pattern);

        $this->assertIsString($literal);

        // The literal has to read back as the pattern it came from, and the
        // file it lands in has to stay valid PHP.
        $evaluated = eval('return '.$literal.';');
        $this->assertSame($pattern, $evaluated);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatternsToQuote(): iterable
    {
        yield 'a plain pattern' => ['pattern' => '/\\d+/u'];

        // A regex is free to hold the quote that would delimit it.
        yield 'a pattern holding a quote' => ['pattern' => "/it's/u"];
        yield 'a pattern holding a backslash and a quote' => ['pattern' => "/\\d'\\w/u"];
        yield 'a pattern ending on a backslash' => ['pattern' => '/a\\\\/u'];
    }
}
