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
            ['jsonrpc', 'method', 'id', 'params'],
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
