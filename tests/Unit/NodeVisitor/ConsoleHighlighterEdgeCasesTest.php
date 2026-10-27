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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsoleHighlighterEdgeCasesTest extends TestCase
{
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
