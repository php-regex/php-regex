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

use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
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
        $decoded = $this->invoke('decodeStringToken', '"\\r\\t\\v\\e\\f\\\\\\""');

        $this->assertSame("\r\t\v\e\f\\\"", $decoded);
    }

    public function test_decode_double_quoted_string_handles_octal_escape(): void
    {
        $decoded = $this->invoke('decodeStringToken', '"\\101"');

        $this->assertSame('A', $decoded);
    }

    public function test_decode_double_quoted_string_handles_multiple_octal_cases(): void
    {
        $decoded = $this->invoke('decodeStringToken', '"\\2\\3\\4\\5\\6\\7"');

        $this->assertIsString($decoded);
        $this->assertSame('020304050607', bin2hex((string) $decoded));
    }

    public function test_decode_double_quoted_string_handles_unknown_escape(): void
    {
        $decoded = $this->invoke('decodeStringToken', '"\\q"');

        $this->assertSame('\\q', $decoded);
    }

    public function test_decode_double_quoted_string_handles_trailing_backslash(): void
    {
        $decoded = $this->invoke('decodeStringToken', '"\\"');

        $this->assertSame('\\', $decoded);
    }

    public function test_parse_hex_escape_edge_cases(): void
    {
        $this->assertSame('\\x', $this->invoke('decodeStringToken', '"\\x"'));
        $this->assertSame('\\x{', $this->invoke('decodeStringToken', '"\\x{"'));
        $this->assertSame('\\xg', $this->invoke('decodeStringToken', '"\\xg"'));
    }

    public function test_parse_unicode_escape_edge_cases(): void
    {
        $this->assertSame('\\u', $this->invoke('decodeStringToken', '"\\u"'));
        $this->assertSame('\\u{', $this->invoke('decodeStringToken', '"\\u{"'));
        $this->assertSame('\\u{ZZ}', $this->invoke('decodeStringToken', '"\\u{ZZ}"'));
    }

    public function test_parse_octal_escape_no_digits(): void
    {
        $this->assertSame('\\9', $this->invoke('decodeStringToken', '"\\9"'));
    }

    public function test_codepoint_to_utf8_branches(): void
    {
        $this->assertSame("\x7f", $this->invoke('decodeStringToken', '"\\u{7F}"'));
        $this->assertSame("\xdf\xbf", $this->invoke('decodeStringToken', '"\\u{7FF}"'));
        $this->assertSame("\xef\xbf\xbf", $this->invoke('decodeStringToken', '"\\u{FFFF}"'));
        $this->assertSame("\xf0\x90\x80\x80", $this->invoke('decodeStringToken', '"\\u{10000}"'));
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($this->strategy);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invoke($this->strategy, ...$args);
    }
}
