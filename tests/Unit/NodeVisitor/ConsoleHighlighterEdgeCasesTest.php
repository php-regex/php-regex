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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleHighlighterEdgeCasesTest extends TestCase
{
    #[Test]
    public function test_comment_controls_are_visible_before_ansi_formatting(): void
    {
        $comment = "café\\d\x1B]0;title\x07\r\n\t\x08\x7F\u{9B}\u{202E}";
        $highlighted = Regex::create(['cache' => null])->parse('/(?#'.$comment.')a/')->accept(new ConsoleHighlighter());
        $plain = preg_replace('/\\x1B\\[[0-9;]*m/', '', $highlighted);

        $this->assertSame('(?#café\\d\\x1B]0;title\\x07\\r\\n\\t\\x08\\x7F\\x{9B}\\x{202E})a', $plain);
        $this->assertStringContainsString("\033[38;2;106;153;85m", $highlighted);
    }

    #[Test]
    public function test_console_highlighter_wrap_with_empty_content(): void
    {
        $visitor = new ConsoleHighlighter();
        $reflection = new \ReflectionClass($visitor);
        $wrapMethod = $reflection->getMethod('wrap');

        $result = $wrapMethod->invoke($visitor, '', 'literal');

        $this->assertSame('', $result);
    }

    #[Test]
    public function test_console_highlighter_wrap_with_unknown_type(): void
    {
        $visitor = new ConsoleHighlighter();
        $reflection = new \ReflectionClass($visitor);
        $wrapMethod = $reflection->getMethod('wrap');

        $result = $wrapMethod->invoke($visitor, 'test', 'unknown_type');

        $this->assertSame('test', $result);
    }

    #[Test]
    public function test_html_highlighter_wrap_with_empty_content(): void
    {
        $visitor = new HtmlHighlighter();
        $reflection = new \ReflectionClass($visitor);
        $wrapMethod = $reflection->getMethod('wrap');

        $result = $wrapMethod->invoke($visitor, '', 'literal');

        $this->assertSame('', $result);
    }
}
