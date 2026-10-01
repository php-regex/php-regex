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

use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A string callout writes its delimiter twice to hold it: "(?C"a""b")" carries
 * the text a"b. Compiled back, the text must be written the same way, or the
 * callout ends early and the rest of it becomes pattern (testinput2 of the
 * PCRE2 suite; PHP refused what the compiler wrote).
 */
final class CalloutStringCompilationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCallouts')]
    public function test_a_string_callout_compiles_back_as_written(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $compiled = Regex::create(['cache' => null])->parse($pattern)->accept(new PatternPrinter());

        $this->assertSame($pattern, $compiled);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideCallouts(): iterable
    {
        yield 'doubled quote' => ['pattern' => '/a(?C"a)b""c")/'];
        yield 'doubled backtick' => ['pattern' => '/(?C`a``b`)x/'];
        yield 'doubled apostrophe' => ['pattern' => "/(?C'a''b')x/"];
        yield 'doubled caret' => ['pattern' => '/(?C^a^^b^)x/'];
        yield 'doubled percent' => ['pattern' => '/(?C%a%%b%)x/'];
        yield 'doubled hash' => ['pattern' => '/(?C#a##b#)x/'];
        yield 'doubled dollar' => ['pattern' => '/(?C$a$$b$)x/'];
        yield 'doubled brace' => ['pattern' => '/(?C{a}}b})x/'];
    }

    #[Test]
    public function test_a_callout_built_without_source_doubles_its_quotes(): void
    {
        $tree = new RegexNode(new CalloutNode('a"b', true, 0, 9), '', '/', 0, 9);

        $compiled = $tree->accept(new PatternPrinter());

        $this->assertSame('/(?C"a""b")/', $compiled);
        $this->assertNotFalse(@preg_match($compiled, ''));
    }
}
