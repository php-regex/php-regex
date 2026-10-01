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

namespace PhpRegex\Tests\Unit\Transpiler;

use PhpRegex\Toolkit\Regex;
use PhpRegex\Transpiler\TranspileException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JavaScriptTranspilerTest extends TestCase
{
    #[Test]
    public function test_transpiles_basic_pattern_to_js_literal(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/foo/i', 'javascript');

        $this->assertSame('javascript', $result->target);
        $this->assertSame('foo', $result->pattern);
        $this->assertSame('i', $result->flags);
        $this->assertSame('/foo/i', $result->literal);
    }

    #[Test]
    public function test_transpiles_named_groups_and_backrefs(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/(?P<word>\\w+)\\k{word}/', 'javascript');

        $this->assertSame('/(?<word>\\w+)\\k<word>/', $result->literal);
    }

    #[Test]
    public function test_transpiles_g_numeric_backreference(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/(\\d)\\g{1}/', 'javascript');

        $this->assertSame('/(\\d)\\1/', $result->literal);
    }

    #[Test]
    public function test_adds_unicode_flag_for_codepoint_escapes(): void
    {
        $regex = Regex::create();
        $result = $regex->transpile('/\\x{1F600}/', 'javascript');

        $this->assertSame('/\\u{1F600}/u', $result->literal);
        $this->assertContains('Added /u for Unicode code point escapes.', $result->warnings);
    }

    #[Test]
    public function test_drops_extended_mode_comments(): void
    {
        $regex = Regex::create();
        $pattern = "/a # comment\nb/x";
        $result = $regex->transpile($pattern, 'javascript');

        $this->assertSame('/ab/', $result->literal);
        $this->assertContains('Applied /x (extended mode): whitespace and comments were removed during compilation.', $result->notes);
        $this->assertContains('Dropped /x comments during transpilation.', $result->notes);
    }

    #[Test]
    public function test_rejects_possessive_quantifiers(): void
    {
        $regex = Regex::create();

        $this->expectException(TranspileException::class);
        $regex->transpile('/a++/', 'javascript');
    }
}
