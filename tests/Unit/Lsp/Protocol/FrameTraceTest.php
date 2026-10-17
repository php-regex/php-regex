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

namespace PHPRegex\Tests\Unit\Lsp\Protocol;

use PHPRegex\LanguageServer\Protocol\FrameTrace;
use PHPRegex\LanguageServer\Protocol\InvalidMessage;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\TraceLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameTraceTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTruncations')]
    public function test_a_payload_is_cut_on_a_character_boundary(string $text, int $maxBytes, string $expected): void
    {
        $cut = FrameTrace::truncate($text, $maxBytes);

        $this->assertSame($expected, $cut);
        $this->assertSame(1, preg_match('//u', $cut), 'The cut text stays valid UTF-8.');
    }

    /**
     * @return iterable<string, array{text: string, maxBytes: int, expected: string}>
     */
    public static function provideTruncations(): iterable
    {
        yield 'shorter than the cap' => ['text' => 'abc', 'maxBytes' => 3, 'expected' => 'abc'];
        yield 'ascii past the cap' => ['text' => 'abcdef', 'maxBytes' => 3, 'expected' => 'abc… (3 more bytes)'];
        // "é" is two bytes: a cap after its first byte keeps it out whole.
        yield 'a two-byte character across the cap' => ['text' => 'aé', 'maxBytes' => 2, 'expected' => 'a… (2 more bytes)'];
        yield 'a two-byte character at the cap' => ['text' => 'aéb', 'maxBytes' => 3, 'expected' => 'aé… (1 more byte)'];
        // A four-byte emoji cut after its first, second or third byte.
        yield 'a four-byte character cut after one byte' => ['text' => 'a😀', 'maxBytes' => 2, 'expected' => 'a… (4 more bytes)'];
        yield 'a four-byte character cut after three bytes' => ['text' => 'a😀', 'maxBytes' => 4, 'expected' => 'a… (4 more bytes)'];
        yield 'a cap before the first character' => ['text' => 'é', 'maxBytes' => 1, 'expected' => '… (2 more bytes)'];
        yield 'a pattern with a multibyte class' => ['text' => '/[éà]+/u', 'maxBytes' => 4, 'expected' => '/[é… (6 more bytes)'];
    }

    #[Test]
    public function test_nothing_is_traced_when_the_trace_is_off(): void
    {
        $this->assertNull(FrameTrace::inbound(new Message('2.0', 'initialized'), TraceLevel::Off));
        $this->assertNull(FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]), TraceLevel::Off));
    }

    #[Test]
    public function test_each_kind_of_frame_is_named_without_its_payload_under_messages(): void
    {
        $this->assertSame('trace: received request "textDocument/hover" (id 2)', FrameTrace::inbound(new Message('2.0', 'textDocument/hover', 2, ['a' => 1]), TraceLevel::Messages));
        $this->assertSame('trace: received notification "initialized"', FrameTrace::inbound(new Message('2.0', 'initialized'), TraceLevel::Messages));
        $this->assertSame('trace: received response (id "r1")', FrameTrace::inbound(new Message('2.0', null, 'r1'), TraceLevel::Messages));
        $this->assertSame('trace: received an invalid message, answered -32700 Parse error', FrameTrace::inbound(InvalidMessage::parseError(), TraceLevel::Messages));
        $this->assertSame('trace: sent response (id 1)', FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['x' => 1]]), TraceLevel::Messages));
        $this->assertSame('trace: sent error response (id 3), -32601', FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => -32601, 'message' => 'Method not found']]), TraceLevel::Messages));
        $this->assertSame('trace: sent error response (id null), -32700', FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]), TraceLevel::Messages));
        $this->assertSame('trace: sent notification "window/logMessage"', FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'method' => 'window/logMessage', 'params' => ['type' => 3]]), TraceLevel::Messages));
        $this->assertSame('trace: sent request "client/registerCapability" (id 1)', FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'client/registerCapability']), TraceLevel::Messages));
    }

    #[Test]
    public function test_verbose_adds_the_payload_as_json(): void
    {
        $this->assertSame(
            'trace: received request "textDocument/hover" (id 2): {"position":{"line":0}}',
            FrameTrace::inbound(new Message('2.0', 'textDocument/hover', 2, ['position' => ['line' => 0]]), TraceLevel::Verbose),
        );
        $this->assertSame('trace: received notification "exit"', FrameTrace::inbound(new Message('2.0', 'exit'), TraceLevel::Verbose));
        $this->assertSame('trace: sent response (id 1): null', FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'id' => 1, 'result' => null]), TraceLevel::Verbose));
        $this->assertSame(
            'trace: sent error response (id 3), -32601: {"code":-32601,"message":"Method not found: x/y"}',
            FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'id' => 3, 'error' => ['code' => -32601, 'message' => 'Method not found: x/y']]), TraceLevel::Verbose),
        );
        $this->assertSame(
            'trace: sent notification "window/logMessage": {"message":"/[é]/u"}',
            FrameTrace::outbound(self::json(['jsonrpc' => '2.0', 'method' => 'window/logMessage', 'params' => ['message' => '/[é]/u']]), TraceLevel::Verbose),
        );
    }

    #[Test]
    public function test_a_verbose_payload_past_the_cap_is_cut(): void
    {
        $text = str_repeat('é', FrameTrace::MAX_PAYLOAD_BYTES);
        $line = (string) FrameTrace::inbound(new Message('2.0', 'textDocument/didOpen', null, ['text' => $text]), TraceLevel::Verbose);

        $this->assertStringStartsWith('trace: received notification "textDocument/didOpen": {"text":"éé', $line);
        $this->assertMatchesRegularExpression('/… \(\d+ more bytes\)$/', $line);
        $this->assertLessThan(FrameTrace::MAX_PAYLOAD_BYTES + 200, \strlen($line));
        $this->assertSame(1, preg_match('//u', $line));
    }

    #[Test]
    #[DataProvider('provideLevels')]
    public function test_the_levels_are_the_protocol_trace_values(string $value, ?TraceLevel $level): void
    {
        $this->assertSame($level, TraceLevel::tryFrom($value));
    }

    /**
     * @return iterable<string, array{value: string, level: ?TraceLevel}>
     */
    public static function provideLevels(): iterable
    {
        yield 'off' => ['value' => 'off', 'level' => TraceLevel::Off];
        yield 'messages' => ['value' => 'messages', 'level' => TraceLevel::Messages];
        yield 'verbose' => ['value' => 'verbose', 'level' => TraceLevel::Verbose];
        yield 'not a level' => ['value' => 'all', 'level' => null];
        yield 'not in its case' => ['value' => 'Verbose', 'level' => null];
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function json(array $message): string
    {
        return (string) json_encode($message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
