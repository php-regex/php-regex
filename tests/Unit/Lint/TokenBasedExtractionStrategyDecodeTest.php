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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Extraction\PhpTokens;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TokenBasedExtractionStrategyDecodeTest extends TestCase
{
    #[Test]
    public function test_decode_double_quoted_string_handles_control_escapes(): void
    {
        $decoded = PhpTokens::stringValue('"\\r\\t\\v\\e\\f\\\\\\""');

        $this->assertSame("\r\t\v\e\f\\\"", $decoded);
    }

    #[Test]
    public function test_decode_double_quoted_string_handles_octal_escape(): void
    {
        $decoded = PhpTokens::stringValue('"\\101"');

        $this->assertSame('A', $decoded);
    }

    #[Test]
    public function test_decode_double_quoted_string_handles_multiple_octal_cases(): void
    {
        $decoded = PhpTokens::stringValue('"\\2\\3\\4\\5\\6\\7"');

        $this->assertIsString($decoded);
        $this->assertSame('020304050607', bin2hex((string) $decoded));
    }

    #[Test]
    public function test_decode_double_quoted_string_handles_unknown_escape(): void
    {
        $decoded = PhpTokens::stringValue('"\\q"');

        $this->assertSame('\\q', $decoded);
    }

    #[Test]
    public function test_decode_double_quoted_string_handles_trailing_backslash(): void
    {
        $decoded = PhpTokens::stringValue('"\\"');

        $this->assertSame('\\', $decoded);
    }

    #[Test]
    public function test_parse_hex_escape_edge_cases(): void
    {
        $this->assertSame('\\x', PhpTokens::stringValue('"\\x"'));
        $this->assertSame('\\x{', PhpTokens::stringValue('"\\x{"'));
        $this->assertSame('\\xg', PhpTokens::stringValue('"\\xg"'));
    }

    #[Test]
    public function test_parse_unicode_escape_edge_cases(): void
    {
        $this->assertSame('\\u', PhpTokens::stringValue('"\\u"'));
        $this->assertSame('\\u{', PhpTokens::stringValue('"\\u{"'));
        $this->assertSame('\\u{ZZ}', PhpTokens::stringValue('"\\u{ZZ}"'));
    }

    #[Test]
    public function test_parse_octal_escape_no_digits(): void
    {
        $this->assertSame('\\9', PhpTokens::stringValue('"\\9"'));
    }

    #[Test]
    public function test_codepoint_to_utf8_branches(): void
    {
        $this->assertSame("\x7f", PhpTokens::stringValue('"\\u{7F}"'));
        $this->assertSame("\xdf\xbf", PhpTokens::stringValue('"\\u{7FF}"'));
        $this->assertSame("\xef\xbf\xbf", PhpTokens::stringValue('"\\u{FFFF}"'));
        $this->assertSame("\xf0\x90\x80\x80", PhpTokens::stringValue('"\\u{10000}"'));
    }
}
