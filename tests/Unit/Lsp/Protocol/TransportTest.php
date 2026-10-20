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

use PHPRegex\LanguageServer\Protocol\Transport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The frames the server writes, each on the stream its transport was given:
 * a response, an error, a notification and a request to the client. A
 * response that cannot be encoded is answered with an internal error, so
 * the client never waits for it; a notification or a request that cannot be
 * encoded is dropped, and said so.
 */
final class TransportTest extends TestCase
{
    /**
     * @var resource
     */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+');
        if (false === $stream) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->stream = $stream;
    }

    protected function tearDown(): void
    {
        fclose($this->stream);
    }

    #[Test]
    public function test_transport_writes_a_response_with_its_content_length(): void
    {
        (new Transport($this->stream))->success(3, ['contents' => 'é/a']);

        $json = '{"jsonrpc":"2.0","id":3,"result":{"contents":"é/a"}}';
        $this->assertSame('Content-Length: '.\strlen($json)."\r\n\r\n".$json, $this->written());
    }

    #[Test]
    public function test_transport_writes_an_error_with_its_code_and_message_only(): void
    {
        (new Transport($this->stream))->error(7, -32601, 'Method not found: foo');

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32601, 'message' => 'Method not found: foo']]], $this->frames());
    }

    #[Test]
    public function test_transport_writes_an_error_whose_request_id_cannot_be_read(): void
    {
        (new Transport($this->stream))->error(null, -32700, 'Parse error');

        $this->assertSame([['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']]], $this->frames());
    }

    #[Test]
    public function test_transport_writes_a_notification_with_or_without_params(): void
    {
        $transport = new Transport($this->stream);
        $transport->notification('window/logMessage', ['type' => 3, 'message' => 'Target']);
        $transport->notification('exit');

        $this->assertSame([
            ['jsonrpc' => '2.0', 'method' => 'window/logMessage', 'params' => ['type' => 3, 'message' => 'Target']],
            ['jsonrpc' => '2.0', 'method' => 'exit'],
        ], $this->frames());
    }

    #[Test]
    public function test_transport_writes_a_request_with_its_id_and_params(): void
    {
        $transport = new Transport($this->stream);
        $transport->request(41, 'client/registerCapability', ['registrations' => []]);
        $transport->request('b', 'workspace/configuration');

        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 41, 'method' => 'client/registerCapability', 'params' => ['registrations' => []]],
            ['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'workspace/configuration'],
        ], $this->frames());
    }

    #[Test]
    public function test_transport_hands_each_frame_written_to_its_observer(): void
    {
        $seen = [];
        $transport = new Transport($this->stream);
        $transport->observeWith(static function (string $json) use (&$seen): void {
            $seen[] = $json;
        });
        $transport->success(1, null);
        $transport->observeWith(null);
        $transport->success(2, null);

        $this->assertSame(['{"jsonrpc":"2.0","id":1,"result":null}'], $seen);
        $this->assertCount(2, $this->frames());
    }

    /**
     * @param \Closure(Transport): void $send
     */
    #[Test]
    #[DataProvider('provideUnencodableResponses')]
    public function test_transport_answers_a_response_it_cannot_encode_with_an_internal_error(\Closure $send, int|string $id): void
    {
        $dropped = [];
        $transport = new Transport($this->stream);
        $transport->observeWith(null, static function (string $line) use (&$dropped): void {
            $dropped[] = $line;
        });
        $send($transport);

        $this->assertSame([['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'The response could not be encoded as JSON: Malformed UTF-8 characters, possibly incorrectly encoded.']]], $this->frames());
        $this->assertSame([], $dropped);
    }

    /**
     * @return iterable<string, array{send: \Closure(Transport): void, id: int|string}>
     */
    public static function provideUnencodableResponses(): iterable
    {
        yield 'a result' => ['send' => static fn (Transport $transport) => $transport->success(4, ['value' => "\xFF"]), 'id' => 4];
        yield 'an error message' => ['send' => static fn (Transport $transport) => $transport->error('c', -32603, "\xFF failed"), 'id' => 'c'];
        yield 'a character cut short' => ['send' => static fn (Transport $transport) => $transport->success(5, ['contents' => "a\xC3"]), 'id' => 5];
    }

    #[Test]
    public function test_transport_drops_a_notification_it_cannot_encode_and_says_so(): void
    {
        $dropped = [];
        $sent = [];
        $transport = new Transport($this->stream);
        $transport->observeWith(static function (string $json) use (&$sent): void {
            $sent[] = $json;
        }, static function (string $line) use (&$dropped): void {
            $dropped[] = $line;
        });
        $transport->notification('textDocument/publishDiagnostics', ['message' => "\xFF"]);
        $transport->request(9, 'client/registerCapability', ['id' => "\xFE"]);

        $this->assertSame('', $this->written());
        $this->assertSame([], $sent);
        $this->assertSame([
            'the textDocument/publishDiagnostics notification could not be encoded as JSON: Malformed UTF-8 characters, possibly incorrectly encoded. It was not sent.',
            'the client/registerCapability request could not be encoded as JSON: Malformed UTF-8 characters, possibly incorrectly encoded. It was not sent.',
        ], $dropped);
    }

    #[Test]
    public function test_transport_drops_silently_without_an_observer(): void
    {
        $transport = new Transport($this->stream);
        $transport->notification('window/logMessage', ['message' => "\xFF"]);
        $transport->success(1, true);

        $this->assertSame([['jsonrpc' => '2.0', 'id' => 1, 'result' => true]], $this->frames());
    }

    private function written(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @return list<mixed>
     */
    private function frames(): array
    {
        $frames = [];
        $written = $this->written();
        while (1 === preg_match('/\AContent-Length: (\d+)\r\n\r\n/', $written, $header)) {
            $length = (int) $header[1];
            $frames[] = json_decode(substr($written, \strlen($header[0]), $length), true, 512, \JSON_THROW_ON_ERROR);
            $written = substr($written, \strlen($header[0]) + $length);
        }

        $this->assertSame('', $written, 'A frame was not whole.');

        return $frames;
    }
}
