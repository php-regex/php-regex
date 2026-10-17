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

use PHPRegex\LanguageServer\Protocol\InvalidMessage;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\MessageLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MessageTest extends TestCase
{
    #[Test]
    public function test_create_message_with_all_properties(): void
    {
        $message = new Message(
            jsonrpc: '2.0',
            method: 'initialize',
            id: 1,
            params: ['foo' => 'bar'],
        );

        $this->assertSame('2.0', $message->jsonrpc);
        $this->assertSame('initialize', $message->method);
        $this->assertSame(1, $message->id);
        $this->assertSame(['foo' => 'bar'], $message->params);
    }

    /**
     * The server answers requests and notifications: the result or error
     * of a response a client sends is never read, so it is not kept.
     */
    #[Test]
    public function test_a_message_keeps_only_what_the_server_reads(): void
    {
        $this->assertSame(
            ['jsonrpc', 'method', 'id', 'params', 'clientError'],
            array_map(static fn (\ReflectionProperty $property): string => $property->getName(), (new \ReflectionClass(Message::class))->getProperties()),
        );
    }

    #[Test]
    public function test_a_response_a_client_sends_is_read_as_a_response(): void
    {
        $body = '{"jsonrpc":"2.0","id":3,"result":{"applied":true}}';
        $stream = $this->streamOf('Content-Length: '.\strlen($body)."\r\n\r\n".$body);

        $message = Message::readFrom($stream);
        $this->assertInstanceOf(Message::class, $message);
        $this->assertTrue($message->isResponse());
        $this->assertSame(3, $message->id);

        fclose($stream);
    }

    #[Test]
    public function test_create_message_with_string_id(): void
    {
        $message = new Message(
            jsonrpc: '2.0',
            method: 'test',
            id: 'abc-123',
        );

        $this->assertSame('abc-123', $message->id);
    }

    #[Test]
    public function test_is_request_returns_true_for_request(): void
    {
        $message = new Message(
            jsonrpc: '2.0',
            method: 'textDocument/hover',
            id: 1,
        );

        $this->assertTrue($message->isRequest());
        $this->assertFalse($message->isNotification());
        $this->assertFalse($message->isResponse());
    }

    #[Test]
    public function test_is_notification_returns_true_for_notification(): void
    {
        $message = new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
        );

        $this->assertFalse($message->isRequest());
        $this->assertTrue($message->isNotification());
        $this->assertFalse($message->isResponse());
    }

    #[Test]
    public function test_is_response_returns_true_for_response(): void
    {
        $message = new Message(
            jsonrpc: '2.0',
            method: null,
            id: 1,
        );

        $this->assertFalse($message->isRequest());
        $this->assertFalse($message->isNotification());
        $this->assertTrue($message->isResponse());
    }

    #[Test]
    #[DataProvider('provideMessageTypes')]
    public function test_message_type_detection(
        ?string $method,
        int|string|null $id,
        bool $expectedRequest,
        bool $expectedNotification,
        bool $expectedResponse,
    ): void {
        $message = new Message(
            jsonrpc: '2.0',
            method: $method,
            id: $id,
        );

        $this->assertSame($expectedRequest, $message->isRequest());
        $this->assertSame($expectedNotification, $message->isNotification());
        $this->assertSame($expectedResponse, $message->isResponse());
    }

    /**
     * @return iterable<string, array{?string, int|string|null, bool, bool, bool}>
     */
    public static function provideMessageTypes(): iterable
    {
        // method, id, isRequest, isNotification, isResponse
        yield 'request with int id' => ['initialize', 1, true, false, false];
        yield 'request with string id' => ['initialize', 'abc', true, false, false];
        yield 'notification' => ['textDocument/didOpen', null, false, true, false];
        yield 'response' => [null, 1, false, false, true];
        yield 'response with string id' => [null, 'abc', false, false, true];
        yield 'invalid (no method, no id)' => [null, null, false, false, false];
    }

    #[Test]
    public function test_message_is_readonly(): void
    {
        $this->assertTrue(self::everyPropertyIsReadonly(Message::class));
    }

    #[Test]
    public function test_an_error_response_is_told_apart_from_a_result_response(): void
    {
        $refusal = Message::readFrom($this->streamOf(self::frame(['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32601, 'message' => 'Not supported.']])));

        $this->assertInstanceOf(Message::class, $refusal);
        $this->assertTrue($refusal->isErrorResponse());

        $success = Message::readFrom($this->streamOf(self::frame(['jsonrpc' => '2.0', 'id' => 2, 'result' => null])));

        $this->assertInstanceOf(Message::class, $success);
        $this->assertFalse($success->isErrorResponse());

        $request = Message::readFrom($this->streamOf(self::frame(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown'])));

        $this->assertInstanceOf(Message::class, $request);
        $this->assertFalse($request->isErrorResponse());
    }

    #[Test]
    public function test_a_drain_cut_short_by_the_end_of_the_stream_still_refuses_the_frame(): void
    {
        $stream = $this->streamOf("Content-Length: 65\r\n\r\nonly ten!");

        $refused = Message::readFrom($stream, new MessageLimits(maxContentLength: 64));
        $this->assertInstanceOf(InvalidMessage::class, $refused);

        $this->assertNull(Message::readFrom($stream, new MessageLimits(maxContentLength: 64)));

        fclose($stream);
    }

    #[Test]
    public function test_a_single_header_line_past_the_cap_is_refused_without_reading_it_whole(): void
    {
        $stream = tmpfile();
        $this->assertIsResource($stream);
        fwrite($stream, 'X-Flood: ');
        $chunk = str_repeat('a', 1024 * 1024);
        for ($i = 0; $i < 32; $i++) {
            fwrite($stream, $chunk);
        }
        rewind($stream);

        $before = memory_get_peak_usage(true);
        $refused = Message::readFrom($stream, new MessageLimits());
        $this->assertInstanceOf(InvalidMessage::class, $refused);
        $this->assertLessThan(
            8 * 1024 * 1024,
            memory_get_peak_usage(true) - $before,
            'The endless header line was read into memory before being refused.',
        );

        fclose($stream);
    }

    #[Test]
    public function test_params_are_nullable(): void
    {
        $message = new Message(
            jsonrpc: '2.0',
            method: 'shutdown',
            id: 1,
            params: null,
        );

        $this->assertNull($message->params);
    }

    #[Test]
    public function test_empty_params_array(): void
    {
        $message = new Message(
            jsonrpc: '2.0',
            method: 'initialize',
            id: 1,
            params: [],
        );

        $this->assertSame([], $message->params);
    }

    #[Test]
    public function test_nested_params(): void
    {
        $params = [
            'textDocument' => [
                'uri' => 'file:///test.php',
                'text' => "<?php\necho 'hello';",
            ],
        ];

        $message = new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: $params,
        );

        $this->assertSame($params, $message->params);
    }

    #[Test]
    public function test_reading_stops_when_the_body_never_arrives(): void
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, "Content-Length: 64\r\n\r\n{\"jsonrpc\"");
        rewind($stream);

        // A closed connection reads as an empty string forever: the reader
        // has to give up rather than spin.
        $this->assertNotInstanceOf(Message::class, Message::readFrom($stream));

        fclose($stream);
    }

    #[Test]
    public function test_reading_a_whole_message_from_a_stream(): void
    {
        $json = '{"jsonrpc":"2.0","id":4,"method":"initialize","params":{"rootUri":null}}';
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        rewind($stream);

        $message = Message::readFrom($stream);

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('initialize', $message->method);
        $this->assertSame(4, $message->id);
        $this->assertSame(['rootUri' => null], $message->params);

        fclose($stream);
    }

    #[Test]
    public function test_the_end_of_the_input_reads_as_null(): void
    {
        $stream = $this->streamOf('');

        $this->assertNull(Message::readFrom($stream));

        fclose($stream);
    }

    #[Test]
    public function test_invalid_json_reads_as_a_parse_error_not_as_the_end(): void
    {
        $json = '{"jsonrpc":"2.0","id":2,"method":"shutdown"}';
        $stream = $this->streamOf("Content-Length: 9\r\n\r\n{not jsonContent-Length: ".\strlen($json)."\r\n\r\n".$json);

        $invalid = Message::readFrom($stream);
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32700, $invalid->code);
        $this->assertSame('Parse error', $invalid->message);
        $this->assertNull($invalid->id);

        $next = Message::readFrom($stream);
        $this->assertInstanceOf(Message::class, $next);
        $this->assertSame('shutdown', $next->method);
        $this->assertSame(2, $next->id);

        fclose($stream);
    }

    #[Test]
    public function test_an_invalid_request_keeps_the_id_it_carries(): void
    {
        $body = '{"jsonrpc":"2.0","id":"r-1","method":["hover"]}';
        $stream = $this->streamOf('Content-Length: '.\strlen($body)."\r\n\r\n".$body);

        $invalid = Message::readFrom($stream);
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32600, $invalid->code);
        $this->assertSame('Invalid Request', $invalid->message);
        $this->assertSame('r-1', $invalid->id);

        fclose($stream);
    }

    /**
     * A body sent without a Content-Length has no known end: the reader
     * reports it, then finds the next message at the Content-Length header
     * that closes the line the body runs into.
     */
    #[Test]
    public function test_a_message_without_content_length_is_reported_and_the_next_one_read(): void
    {
        $lost = '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"text":"Content-Length: 3"}}';
        $json = '{"jsonrpc":"2.0","id":2,"method":"shutdown"}';
        $stream = $this->streamOf("Content-Type: application/vscode-jsonrpc\r\n\r\n".$lost.'Content-Length: '.\strlen($json)."\r\n\r\n".$json);

        $invalid = Message::readFrom($stream);
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32700, $invalid->code);
        $this->assertNull($invalid->id);

        $next = Message::readFrom($stream);
        $this->assertInstanceOf(Message::class, $next);
        $this->assertSame('shutdown', $next->method);
        $this->assertSame(2, $next->id);

        $this->assertNull(Message::readFrom($stream));

        fclose($stream);
    }

    /**
     * A frame the caps refuse costs its declared bytes, not the session:
     * the bytes are drained — skipping them desyncs the stream — and the
     * frame after them in the same stream is read as ever.
     */
    #[Test]
    public function test_a_content_length_past_the_cap_is_rejected_and_its_bytes_drained(): void
    {
        $next = '{"jsonrpc":"2.0","id":2,"method":"shutdown"}';
        $stream = $this->streamOf(
            "Content-Length: 65\r\n\r\n".str_repeat('a', 65)
            .'Content-Length: '.\strlen($next)."\r\n\r\n".$next,
        );

        $limits = new MessageLimits(maxContentLength: 64);
        $invalid = Message::readFrom($stream, $limits);
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32600, $invalid->code);
        $this->assertNull($invalid->id);

        $following = Message::readFrom($stream, $limits);
        $this->assertInstanceOf(Message::class, $following);
        $this->assertSame('shutdown', $following->method);
        $this->assertSame(2, $following->id);

        fclose($stream);
    }

    /**
     * Exactly at the cap is not past it: the frame is read and refused for
     * what it holds, here JSON that does not parse, never for its length.
     */
    #[Test]
    public function test_a_content_length_exactly_at_the_cap_is_read(): void
    {
        $stream = $this->streamOf("Content-Length: 64\r\n\r\n".str_pad('{not json', 64));

        $invalid = Message::readFrom($stream, new MessageLimits(maxContentLength: 64));
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32700, $invalid->code);

        fclose($stream);
    }

    /**
     * A header block that grows past its cap without ever naming a
     * Content-Length is refused, and reading stops: the frame that follows
     * in the stream is never read.
     */
    #[Test]
    public function test_a_header_block_past_its_cap_is_refused_and_reading_stops(): void
    {
        $next = '{"jsonrpc":"2.0","id":2,"method":"shutdown"}';
        $over = 'X-Cap: '.str_repeat('a', 65 - \strlen('X-Cap: ') - 4)."\r\n\r\n";
        $this->assertSame(65, \strlen($over));
        $stream = $this->streamOf($over.'Content-Length: '.\strlen($next)."\r\n\r\n".$next);

        $limits = new MessageLimits(maxHeaderBlock: 64);
        $invalid = Message::readFrom($stream, $limits);
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32600, $invalid->code);
        $this->assertNull(Message::readFrom($stream, $limits), 'The reader went on past the block it had refused.');

        fclose($stream);
    }

    /**
     * The block counts every byte through the blank line that ends it:
     * exactly at the cap it passes, and is refused for the header it holds
     * — no Content-Length — as ever.
     */
    #[Test]
    public function test_a_header_block_exactly_at_its_cap_is_read(): void
    {
        $atCap = 'X-Cap: '.str_repeat('a', 64 - \strlen('X-Cap: ') - 4)."\r\n\r\n";
        $this->assertSame(64, \strlen($atCap));
        $stream = $this->streamOf($atCap);

        $invalid = Message::readFrom($stream, new MessageLimits(maxHeaderBlock: 64));
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32700, $invalid->code);

        fclose($stream);
    }

    /**
     * A flood that never ends is refused at the cap rather than read to the
     * end of the input: the answer says why the session stopped.
     */
    #[Test]
    public function test_an_endingless_header_flood_is_refused_at_the_cap(): void
    {
        $stream = $this->streamOf('X-Flood: '.str_repeat('a', 96));

        $invalid = Message::readFrom($stream, new MessageLimits(maxHeaderBlock: 64));
        $this->assertInstanceOf(InvalidMessage::class, $invalid);
        $this->assertSame(-32600, $invalid->code);

        fclose($stream);
    }

    #[Test]
    public function test_a_header_block_at_the_cap_whose_blank_line_is_not_read_yet_is_refused(): void
    {
        // Header lines that already cost the whole cap, the blank line
        // that would end the block still ahead: the block cannot end
        // within the cap, so it is refused — not ended in silence.
        $first = "Content-Length: 42\r\n";
        $second = 'B: '.str_repeat('b', 64 - \strlen($first) - 3 - 2)."\r\n";
        $stream = $this->streamOf($first.$second."\r\n{}");

        $refused = Message::readFrom($stream, new MessageLimits(maxHeaderBlock: 64));

        $this->assertInstanceOf(InvalidMessage::class, $refused);
        $this->assertSame(-32600, $refused->code);

        fclose($stream);
    }

    /**
     * @param array<mixed> $body
     */
    private static function frame(array $body): string
    {
        $json = json_encode($body);
        \assert(\is_string($json));

        return \sprintf("Content-Length: %d\r\n\r\n%s", \strlen($json), $json);
    }

    /**
     * @return resource
     */
    private function streamOf(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * @param class-string $class
     */
    private static function everyPropertyIsReadonly(string $class): bool
    {
        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            if (!$property->isReadOnly()) {
                return false;
            }
        }

        return true;
    }
}
