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

namespace PHPRegex\Tests\Unit\Lsp;

use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\LanguageServer\Server;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ServerTest extends TestCase
{
    /**
     * @var resource
     */
    private $output;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        if (false === $output) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        Response::writeTo($this->output);
    }

    protected function tearDown(): void
    {
        Response::writeTo(null);
        fclose($this->output);
    }

    #[Test]
    public function test_server_announces_the_library_version(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
        ]);

        $answer = $this->answerTo(1);

        $this->assertIsArray($answer['result']);
        $this->assertSame(
            ['name' => 'php-regex-lsp', 'version' => Regex::VERSION],
            $answer['result']['serverInfo'],
        );
    }

    #[Test]
    public function test_a_request_before_initialization_is_rejected(): void
    {
        $this->serve([
            ['id' => 7, 'method' => 'textDocument/hover', 'params' => []],
        ]);

        $this->assertSame(-32002, $this->errorCodeOf(7));
    }

    #[Test]
    public function test_a_failing_handler_answers_an_error_and_keeps_serving(): void
    {
        // A position the handler cannot read: the request fails, the session
        // must not.
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['id' => 2, 'method' => 'textDocument/completion', 'params' => [
                'textDocument' => ['uri' => 'file:///a.php'],
                'position' => ['line' => 'not-a-line', 'character' => null],
            ]],
            ['id' => 3, 'method' => 'textDocument/codeAction', 'params' => [
                'textDocument' => ['uri' => 'file:///a.php'],
                'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 0]],
            ]],
        ]);

        $this->assertSame(-32603, $this->errorCodeOf(2));
        $this->assertSame([], $this->answerTo(3)['result']);
    }

    #[Test]
    public function test_shutdown_stops_the_loop(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['id' => 2, 'method' => 'shutdown'],
            ['id' => 3, 'method' => 'initialize', 'params' => []],
        ]);

        $this->assertNull($this->findAnswer(3), 'The server kept reading after shutdown.');
    }

    #[Test]
    public function test_a_truncated_message_ends_the_loop(): void
    {
        $input = $this->stream("Content-Length: 120\r\n\r\n{\"jsonrpc\":\"2.0\"");

        (new Server(null, $input))->run();

        $this->assertSame('', $this->written());
    }

    #[Test]
    public function test_invalid_json_answers_a_parse_error_and_keeps_serving(): void
    {
        $this->serveRaw(
            self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
            ."Content-Length: 9\r\n\r\n{not json"
            .self::frame(['id' => 2, 'method' => 'shutdown']),
        );

        $this->assertStringContainsString(
            "Content-Length: 75\r\n\r\n{\"jsonrpc\":\"2.0\",\"id\":null,\"error\":{\"code\":-32700,\"message\":\"Parse error\"}}",
            $this->written(),
        );
        $this->assertArrayHasKey('result', $this->answerTo(2));
    }

    /**
     * A message the server cannot use costs one error answer, never the
     * session: the "shutdown" request after it is still answered.
     */
    #[Test]
    #[DataProvider('provideMalformedMessages')]
    public function test_a_malformed_message_is_answered_and_the_session_goes_on(string $frame, int $code, string $message, int|string|null $id): void
    {
        $this->serveRaw(
            self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
            .self::frame(['method' => 'initialized'])
            .$frame
            .self::frame(['id' => 2, 'method' => 'shutdown']),
        );

        $this->assertSame(
            [['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]],
            $this->errorsWritten(),
        );
        $this->assertArrayHasKey('result', $this->answerTo(2));
    }

    /**
     * @return iterable<string, array{frame: string, code: int, message: string, id: int|string|null}>
     */
    public static function provideMalformedMessages(): iterable
    {
        $parseError = ['code' => -32700, 'message' => 'Parse error', 'id' => null];
        $invalidRequest = ['code' => -32600, 'message' => 'Invalid Request', 'id' => null];

        yield 'invalid JSON' => ['frame' => self::body('{not json')] + $parseError;
        yield 'truncated JSON object' => ['frame' => self::body('{"jsonrpc":"2.0","id":5,')] + $parseError;
        yield 'zero content length' => ['frame' => "Content-Length: 0\r\n\r\n"] + $parseError;
        yield 'no content length' => ['frame' => "Content-Type: application/vscode-jsonrpc; charset=utf-8\r\n\r\n{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"initialize\"}"] + $parseError;
        yield 'content length not a number' => ['frame' => "Content-Length: many\r\n\r\n{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"initialize\"}"] + $parseError;
        yield 'negative content length' => ['frame' => "Content-Length: -4\r\n\r\n{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"initialize\"}"] + $parseError;
        yield 'JSON null' => ['frame' => self::body('null')] + $invalidRequest;
        yield 'JSON number' => ['frame' => self::body('42')] + $invalidRequest;
        yield 'JSON string' => ['frame' => self::body('"shutdown"')] + $invalidRequest;
        yield 'empty batch' => ['frame' => self::body('[]')] + $invalidRequest;
        yield 'batch' => ['frame' => self::body('[{"jsonrpc":"2.0","id":5,"method":"shutdown"}]')] + $invalidRequest;
        yield 'empty object' => ['frame' => self::body('{}')] + $invalidRequest;
        yield 'no jsonrpc member' => ['frame' => self::body('{"id":5,"method":"textDocument/hover"}'), 'id' => 5] + $invalidRequest;
        yield 'jsonrpc 1.0' => ['frame' => self::body('{"jsonrpc":"1.0","id":"a","method":"textDocument/hover"}'), 'id' => 'a'] + $invalidRequest;
        yield 'method not a string' => ['frame' => self::body('{"jsonrpc":"2.0","id":5,"method":5}'), 'id' => 5] + $invalidRequest;
        yield 'id neither a number nor a string' => ['frame' => self::body('{"jsonrpc":"2.0","id":{"n":5},"method":"textDocument/hover"}')] + $invalidRequest;
        yield 'fractional id' => ['frame' => self::body('{"jsonrpc":"2.0","id":5.5,"method":"textDocument/hover"}')] + $invalidRequest;
        yield 'params neither an object nor an array' => ['frame' => self::body('{"jsonrpc":"2.0","id":5,"method":"textDocument/hover","params":3}'), 'id' => 5] + $invalidRequest;
        yield 'neither a request nor a response' => ['frame' => self::body('{"jsonrpc":"2.0","id":5}'), 'id' => 5] + $invalidRequest;
    }

    /**
     * A response from the client is not a request: nothing answers it.
     */
    #[Test]
    public function test_a_response_from_the_client_is_not_answered(): void
    {
        $this->serveRaw(
            self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
            .self::body('{"jsonrpc":"2.0","id":9,"result":null}')
            .self::body('{"jsonrpc":"2.0","id":10,"error":{"code":-32601,"message":"Method not found"}}')
            .self::frame(['id' => 2, 'method' => 'shutdown']),
        );

        $this->assertSame([], $this->errorsWritten());
        $this->assertNull($this->findAnswer(9));
        $this->assertNull($this->findAnswer(10));
        $this->assertArrayHasKey('result', $this->answerTo(2));
    }

    #[Test]
    public function test_a_blank_line_between_messages_is_skipped(): void
    {
        $this->serveRaw(
            self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
            ."\r\n\r\n"
            .self::frame(['id' => 2, 'method' => 'shutdown']),
        );

        $this->assertSame([], $this->errorsWritten());
        $this->assertArrayHasKey('result', $this->answerTo(2));
    }

    #[Test]
    public function test_the_end_of_the_input_in_the_headers_ends_the_loop_silently(): void
    {
        $this->serveRaw("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\"}\n");

        $this->assertSame('', $this->written());
    }

    /**
     * After "shutdown" the server waits for "exit", and serves nothing in
     * between.
     */
    #[Test]
    public function test_shutdown_waits_for_the_exit_notification(): void
    {
        $untilExit = self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
            .self::frame(['method' => 'initialized'])
            .self::frame(['id' => 2, 'method' => 'shutdown'])
            .self::frame(['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
                'uri' => 'file:///a.php',
                'languageId' => 'php',
                'version' => 1,
                'text' => "<?php\npreg_match('/(/', \$s);\n",
            ]]])
            .self::frame(['method' => 'exit']);
        $input = $this->stream($untilExit.self::frame(['id' => 9, 'method' => 'initialize', 'params' => []]));

        $code = (new Server(null, $input))->run();

        $this->assertSame(\strlen($untilExit), ftell($input), 'The server did not read up to the "exit" notification.');
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('textDocument/publishDiagnostics', $this->written());
        $this->assertNull($this->findAnswer(9));
    }

    /**
     * The same document is published before "shutdown": the test above
     * checks the absence of something that does happen.
     */
    #[Test]
    public function test_a_document_opened_before_shutdown_is_published(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
                'uri' => 'file:///a.php',
                'languageId' => 'php',
                'version' => 1,
                'text' => "<?php\npreg_match('/(/', \$s);\n",
            ]]],
        ]);

        $this->assertStringContainsString('textDocument/publishDiagnostics', $this->written());
    }

    private function serveRaw(string $payload): void
    {
        (new Server(null, $this->stream($payload)))->run();
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function frame(array $message): string
    {
        return self::body((string) json_encode(['jsonrpc' => '2.0'] + $message));
    }

    private static function body(string $body): string
    {
        return 'Content-Length: '.\strlen($body)."\r\n\r\n".$body;
    }

    /**
     * The error answers written, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function errorsWritten(): array
    {
        $errors = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $this->written()) ?: [] as $body) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($body, true);
            if (\is_array($decoded) && \array_key_exists('error', $decoded)) {
                $errors[] = $decoded;
            }
        }

        return $errors;
    }

    /**
     * @param array<array<string, mixed>> $messages
     */
    private function serve(array $messages): void
    {
        $payload = '';
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            $payload .= 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
        }

        (new Server(null, $this->stream($payload)))->run();
    }

    /**
     * @return resource
     */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        if (false === $stream) {
            self::fail('Unable to open an in-memory stream.');
        }

        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function written(): string
    {
        rewind($this->output);

        return (string) stream_get_contents($this->output);
    }

    private function errorCodeOf(int $id): int
    {
        $error = $this->answerTo($id)['error'];
        $this->assertIsArray($error);
        $this->assertIsInt($error['code']);

        return $error['code'];
    }

    /**
     * @return array<string, mixed>
     */
    private function answerTo(int $id): array
    {
        $answer = $this->findAnswer($id);
        if (null === $answer) {
            self::fail(\sprintf('The server did not answer the request %d.', $id));
        }

        return $answer;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAnswer(int $id): ?array
    {
        foreach (explode('Content-Length: ', $this->written()) as $frame) {
            $json = strstr($frame, '{');
            if (false === $json) {
                continue;
            }

            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode($json, true);
            if (\is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
        }

        return null;
    }
}
