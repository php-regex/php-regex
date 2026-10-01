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

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPUnit\Framework\TestCase;

final class TokenBasedExtractionStrategyDecodeTest extends TestCase
{
    private TokenBasedExtractionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TokenBasedExtractionStrategy();
    }

    public function test_decode_double_quoted_string_handles_control_escapes(): void
    {
        $decoded = $this->invoke('decodeDoubleQuotedString', '\\r\\t\\v\\e\\f\\\\\\"');

        $this->assertSame("\r\t\v\e\f\\\"", $decoded);
    }

    public function test_decode_double_quoted_string_handles_octal_escape(): void
    {
        $decoded = $this->invoke('decodeDoubleQuotedString', '\\101');

        $this->assertSame('A', $decoded);
    }

    public function test_decode_double_quoted_string_handles_multiple_octal_cases(): void
    {
        $decoded = $this->invoke('decodeDoubleQuotedString', '\\2\\3\\4\\5\\6\\7');

        $this->assertIsString($decoded);
        $this->assertSame('020304050607', bin2hex((string) $decoded));
    }

    public function test_decode_double_quoted_string_handles_unknown_escape(): void
    {
        $decoded = $this->invoke('decodeDoubleQuotedString', '\\q');

        $this->assertSame('\\q', $decoded);
    }

    public function test_decode_double_quoted_string_handles_trailing_backslash(): void
    {
        $decoded = $this->invoke('decodeDoubleQuotedString', '\\');

        $this->assertSame('\\', $decoded);
    }

    public function test_parse_hex_escape_edge_cases(): void
    {
        $result = $this->invoke('parseHexEscape', '\\x', 0, 2);
        $this->assertSame(['value' => '\\x', 'newIndex' => 2], $result);

        $result = $this->invoke('parseHexEscape', '\\x{', 0, 3);
        $this->assertSame(['value' => '\\x{', 'newIndex' => 3], $result);

        $result = $this->invoke('parseHexEscape', '\\xg', 0, 3);
        $this->assertSame(['value' => '\\x', 'newIndex' => 2], $result);
    }

    public function test_parse_unicode_escape_edge_cases(): void
    {
        $result = $this->invoke('parseUnicodeEscape', '\\u', 0, 2);
        $this->assertSame(['value' => '\\u', 'newIndex' => 2], $result);

        $result = $this->invoke('parseUnicodeEscape', '\\u{', 0, 3);
        $this->assertSame(['value' => '\\u{', 'newIndex' => 3], $result);

        $result = $this->invoke('parseUnicodeEscape', '\\u{ZZ}', 0, 6);
        $this->assertSame(['value' => '\\u{ZZ}', 'newIndex' => 6], $result);
    }

    public function test_parse_octal_escape_no_digits(): void
    {
        $result = $this->invoke('parseOctalEscape', '\\9', 0, 2);

        $this->assertSame(['value' => '\\', 'newIndex' => 1], $result);
    }

    public function test_codepoint_to_utf8_branches(): void
    {
        $this->assertSame("\x7f", $this->invoke('codepointToUtf8', 0x7F));
        $this->assertSame("\xdf\xbf", $this->invoke('codepointToUtf8', 0x7FF));
        $this->assertSame("\xef\xbf\xbf", $this->invoke('codepointToUtf8', 0xFFFF));
        $this->assertSame("\xf0\x90\x80\x80", $this->invoke('codepointToUtf8', 0x10000));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($this->strategy);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invoke($this->strategy, ...$args);
    }
}
