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

use PHPRegex\LanguageServer\Protocol\MessageLimits;
use PHPRegex\LanguageServer\Protocol\Transport;
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
    }

    protected function tearDown(): void
    {
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

    /**
     * A pattern spelled with "\xFF" in a double-quoted string holds a byte
     * JSON cannot carry: its hover, which quotes the pattern, cannot be
     * encoded. The request is answered all the same, with an internal
     * error, where the client would have waited for an answer forever.
     */
    #[Test]
    public function test_a_response_that_cannot_be_encoded_is_answered_with_an_internal_error(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.php', 'languageId' => 'php', 'version' => 1, 'text' => "<?php\npreg_match(\"/\\xFF\\xFEa+/\", \$s);\n"],
            ]],
            ['id' => 2, 'method' => 'textDocument/hover', 'params' => [
                'textDocument' => ['uri' => 'file:///a.php'],
                'position' => ['line' => 1, 'character' => 16],
            ]],
            ['id' => 3, 'method' => 'shutdown'],
        ]);

        $this->assertSame(-32603, $this->errorCodeOf(2));
        $error = $this->answerTo(2)['error'];
        $this->assertIsArray($error);
        $this->assertSame('The response could not be encoded as JSON: Malformed UTF-8 characters, possibly incorrectly encoded.', $error['message']);
        $this->assertArrayHasKey('result', $this->answerTo(3), 'The session did not go on after the unencodable response.');
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
        // A position the handler cannot read: the request fails, the
        // session must not — and the failure leaves one stderr trace,
        // captured here, never leaked to the real one.
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);

        try {
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
        } finally {
            Server::writeErrorsTo(null);
        }

        rewind($errors);
        $lines = (string) stream_get_contents($errors);
        fclose($errors);
        $this->assertSame(1, substr_count($lines, "\n"), 'One stderr trace for the failed request.');

        $this->assertSame(-32603, $this->errorCodeOf(2));
        $this->assertSame([], $this->answerTo(3)['result']);
    }

    /**
     * After shutdown the protocol serves nothing until "exit": a request,
     * a second shutdown included, is answered Invalid Request, and a
     * notification gets no answer, as ever.
     */
    #[Test]
    public function test_a_request_after_shutdown_is_answered_invalid_request(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['id' => 2, 'method' => 'shutdown'],
            ['method' => 'textDocument/didOpen', 'params' => [
                'textDocument' => ['uri' => 'file:///a.php', 'languageId' => 'php', 'version' => 1, 'text' => "<?php preg_match('/(a+)+$/', \$s);"],
            ]],
            ['id' => 3, 'method' => 'initialize', 'params' => []],
            ['id' => 4, 'method' => 'shutdown'],
        ]);

        $this->assertNull($this->answerTo(2)['result'] ?? null);
        $this->assertArrayNotHasKey('error', $this->answerTo(2));
        $this->assertSame(-32600, $this->errorCodeOf(3));
        $this->assertSame(-32600, $this->errorCodeOf(4));
        $this->assertStringNotContainsString('publishDiagnostics', $this->written(), 'A notification after shutdown was served.');
    }

    #[Test]
    public function test_a_truncated_message_ends_the_loop(): void
    {
        $input = $this->stream("Content-Length: 120\r\n\r\n{\"jsonrpc\":\"2.0\"");

        (new Server(null, $input, transport: new Transport($this->output)))->run();

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
        yield 'no jsonrpc member' => array_replace(['frame' => self::body('{"id":5,"method":"textDocument/hover"}')] + $invalidRequest, ['id' => 5]);
        yield 'jsonrpc 1.0' => array_replace(['frame' => self::body('{"jsonrpc":"1.0","id":"a","method":"textDocument/hover"}')] + $invalidRequest, ['id' => 'a']);
        yield 'method not a string' => array_replace(['frame' => self::body('{"jsonrpc":"2.0","id":5,"method":5}')] + $invalidRequest, ['id' => 5]);
        yield 'id neither a number nor a string' => ['frame' => self::body('{"jsonrpc":"2.0","id":{"n":5},"method":"textDocument/hover"}')] + $invalidRequest;
        yield 'fractional id' => ['frame' => self::body('{"jsonrpc":"2.0","id":5.5,"method":"textDocument/hover"}')] + $invalidRequest;
        yield 'params neither an object nor an array' => array_replace(['frame' => self::body('{"jsonrpc":"2.0","id":5,"method":"textDocument/hover","params":3}')] + $invalidRequest, ['id' => 5]);
        yield 'neither a request nor a response' => array_replace(['frame' => self::body('{"jsonrpc":"2.0","id":5}')] + $invalidRequest, ['id' => 5]);
    }

    /**
     * A response from the client is not a request: nothing answers it.
     */
    #[Test]
    public function test_a_response_from_the_client_is_not_answered(): void
    {
        // The stray responses are traced on stderr once: captured here,
        // never leaked to the real one — a byte of stderr is enough for
        // tooling to read the run as failed.
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);

        try {
            $this->serveRaw(
                self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
                .self::body('{"jsonrpc":"2.0","id":9,"result":null}')
                .self::body('{"jsonrpc":"2.0","id":10,"error":{"code":-32601,"message":"Method not found"}}')
                .self::frame(['id' => 2, 'method' => 'shutdown']),
            );
        } finally {
            Server::writeErrorsTo(null);
        }

        rewind($errors);
        $lines = (string) stream_get_contents($errors);
        fclose($errors);
        $this->assertSame(1, substr_count($lines, "\n"), 'One trace for both stray responses.');

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

        $code = (new Server(null, $input, transport: new Transport($this->output)))->run();

        $this->assertSame(\strlen($untilExit), ftell($input), 'The server did not read up to the "exit" notification.');
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('textDocument/publishDiagnostics', $this->written());
        $this->assertNull($this->findAnswer(9));
    }

    /**
     * What regex.json holds that the server ignores is logged, each
     * warning naming the file.
     */
    #[Test]
    public function test_a_warning_about_regex_json_names_the_file(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-server-'.bin2hex(random_bytes(6));
        mkdir($root, 0o700, true);
        file_put_contents($root.'/regex.json', '{"extraction": {"functions": ["x#a"]}}');

        try {
            $this->serve([
                ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => 'file://'.$root]],
                ['method' => 'initialized'],
            ]);
        } finally {
            unlink($root.'/regex.json');
            rmdir($root);
        }

        $messages = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $this->written()) ?: [] as $body) {
            $decoded = json_decode($body, true);
            if (\is_array($decoded) && 'window/logMessage' === ($decoded['method'] ?? null) && \is_array($decoded['params'] ?? null)) {
                $messages[] = $decoded['params']['message'] ?? null;
            }
        }

        $this->assertContains('regex.json: Malformed pattern function "x#a", ignored.', $messages);
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

    /**
     * A second "initialize" re-serves nothing: no configuration is loaded
     * again, the workspace is not read again, and the session answers what
     * follows as ever.
     */
    #[Test]
    public function test_a_second_initialize_request_is_answered_invalid_request(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['id' => 2, 'method' => 'initialize', 'params' => []],
            ['id' => 3, 'method' => 'textDocument/hover', 'params' => [
                'textDocument' => ['uri' => 'file:///a.php'],
                'position' => ['line' => 0, 'character' => 0],
            ]],
        ]);

        $this->assertSame(-32600, $this->errorCodeOf(2));
        $error = $this->answerTo(2)['error'] ?? null;
        $this->assertIsArray($error);
        $this->assertSame('The server is already initialized.', $error['message'] ?? null);
        $this->assertCount(1, $this->targetLogs(), 'The workspace was judged a second time.');
        $this->assertArrayHasKey('result', $this->answerTo(3), 'A hover after the refused initialize was not answered.');
    }

    /**
     * An "initialize" the client sends as a notification after the session
     * started does no work either — no target is resolved again, nothing is
     * read again — and costs one line on stderr.
     */
    #[Test]
    public function test_an_initialize_notification_after_initialization_does_nothing(): void
    {
        // The seam the drop lines are written through; null restores
        // stderr.
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);

        try {
            $this->serve([
                ['id' => 1, 'method' => 'initialize', 'params' => []],
                ['method' => 'initialized'],
                ['method' => 'initialize', 'params' => []],
                ['id' => 2, 'method' => 'shutdown'],
            ]);
        } finally {
            Server::writeErrorsTo(null);
        }

        $this->assertArrayHasKey('result', $this->answerTo(2));
        $this->assertCount(1, $this->targetLogs(), 'The notification initialized the session a second time.');
        $this->assertSame(1, substr_count($this->linesWrittenTo($errors), "\n"), 'One stderr line was expected for the dropped notification.');
        fclose($errors);
    }

    /**
     * A notification that arrives before "initialize" is dropped: it costs
     * one line on stderr and publishes nothing, so a mis-wired harness sees
     * why its early didOpen gives no diagnostics.
     */
    #[Test]
    public function test_a_notification_before_initialize_is_dropped(): void
    {
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);

        try {
            $this->serve([
                ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
                    'uri' => 'file:///a.php',
                    'languageId' => 'php',
                    'version' => 1,
                    'text' => "<?php\npreg_match('/(/', \$s);\n",
                ]]],
                ['id' => 1, 'method' => 'initialize', 'params' => []],
                ['method' => 'initialized'],
                ['id' => 2, 'method' => 'shutdown'],
            ]);
        } finally {
            Server::writeErrorsTo(null);
        }

        $this->assertStringNotContainsString('textDocument/publishDiagnostics', $this->written(), 'A notification before initialize was served.');
        $this->assertArrayHasKey('result', $this->answerTo(2));
        $this->assertSame(1, substr_count($this->linesWrittenTo($errors), "\n"), 'One stderr line was expected for the dropped notification.');
        fclose($errors);
    }

    /**
     * A frame past the caps the session reads with: one Invalid Request,
     * the declared bytes drained — skipping them desyncs the stream — and
     * the frame after it served as ever. Exactly at the cap is not past it.
     *
     * @param list<string> $frames the frames between "initialized" and the closing "shutdown"
     */
    #[Test]
    #[DataProvider('provideCappedMessages')]
    public function test_a_message_past_the_content_length_cap_is_answered_and_the_stream_goes_on(array $frames, int $code, string $message): void
    {
        $payload = self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
            .self::frame(['method' => 'initialized'])
            .implode('', $frames)
            .self::frame(['id' => 2, 'method' => 'shutdown']);
        $input = $this->stream($payload);

        (new Server(null, $input, null, new MessageLimits(maxContentLength: 64), transport: new Transport($this->output)))->run();

        $this->assertSame([['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => $code, 'message' => $message]]], $this->errorsWritten());
        $this->assertArrayHasKey('result', $this->answerTo(2), 'The frame after the rejected one was not served.');
    }

    /**
     * @return iterable<string, array{frames: list<string>, code: int, message: string}>
     */
    public static function provideCappedMessages(): iterable
    {
        yield 'a body one byte past the cap' => ['frames' => ["Content-Length: 65\r\n\r\n".str_repeat('a', 65)], 'code' => -32600, 'message' => 'Invalid Request'];
        // Exactly 64 bytes: past the cap check, then refused as ever for
        // the JSON it holds, not for its length.
        yield 'a body exactly at the cap' => ['frames' => [self::body(str_pad('{not json', 64))], 'code' => -32700, 'message' => 'Parse error'];
    }

    /**
     * A header block that grows past its cap without ever naming a
     * Content-Length ends the session: one Invalid Request, nothing after
     * it read, exit 0 — exit 1 stays the crash signal, not the answer to
     * garbage.
     */
    #[Test]
    public function test_a_header_block_past_its_cap_ends_the_session_with_zero(): void
    {
        // The header block counts every byte through the blank line that
        // ends it: 104 here for a cap of 64.
        $flood = 'X-Flood: '.str_repeat('a', 96)."\r\n\r\n";
        $payload = self::frame(['id' => 1, 'method' => 'initialize', 'params' => []])
            .self::frame(['method' => 'initialized'])
            .$flood
            .self::frame(['id' => 2, 'method' => 'shutdown']);
        $input = $this->stream($payload);

        $code = (new Server(null, $input, null, new MessageLimits(maxContentLength: 64, maxHeaderBlock: 64), transport: new Transport($this->output)))->run();

        $this->assertSame(0, $code);
        $this->assertSame([['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid Request']]], $this->errorsWritten());
        $this->assertNull($this->findAnswer(2), 'The session read past the flood it had refused.');
    }

    /**
     * A client that offers dynamic registration of watched files is asked
     * to watch exactly the files the server reads: the workspace's PHP
     * files and Composer's manifest, once.
     */
    #[Test]
    public function test_a_client_offering_dynamic_registration_is_asked_to_watch_the_registered_files(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]],
            ['method' => 'initialized'],
        ]);

        $registrations = $this->registrationRequests();
        $this->assertCount(1, $registrations, 'Exactly one client/registerCapability request was expected.');

        [$request] = $registrations;
        $this->assertSame('2.0', $request['jsonrpc'] ?? null);
        $this->assertIsInt($request['id'] ?? null);
        $params = $request['params'] ?? null;
        $this->assertIsArray($params);
        $entries = $params['registrations'] ?? null;
        $this->assertIsArray($entries);

        $globs = [];
        foreach ($entries as $entry) {
            $this->assertIsArray($entry);
            $this->assertSame('workspace/didChangeWatchedFiles', $entry['method'] ?? null);
            $options = $entry['registerOptions'] ?? null;
            $this->assertIsArray($options);
            $watchers = $options['watchers'] ?? [];
            $this->assertIsArray($watchers);
            foreach ($watchers as $watcher) {
                $this->assertIsArray($watcher);
                $kind = $watcher['kind'] ?? null;
                $this->assertTrue(null === $kind || \in_array($kind, [1, 2, 3], true), 'A watcher kind must be Create, Change or Delete.');
                $globs[] = $watcher['globPattern'] ?? null;
            }
        }

        $this->assertContains('**/*.php', $globs);
        $this->assertContains('vendor/composer/installed.json', $globs);
    }

    /**
     * A client that refuses the registration is told so once on stderr, and
     * never asked again; its refusal is matched to the request by the id
     * the server sent.
     */
    #[Test]
    public function test_a_registration_refusal_is_logged_once_and_not_retried(): void
    {
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);
        $input = $this->stream('');

        try {
            $server = new Server(null, $input, transport: new Transport($this->output));
            $this->sendTo($server, $input,
                ['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]],
                ['method' => 'initialized'],
            );

            $registrations = $this->registrationRequests();
            $this->assertCount(1, $registrations);
            $id = $registrations[0]['id'];
            $this->assertNotNull($id);

            // The refusal carries the id the server chose, as a response.
            $this->sendTo($server, $input, ['id' => $id, 'error' => ['code' => -32601, 'message' => 'Window/showMessage is not supported.']]);
        } finally {
            Server::writeErrorsTo(null);
        }

        $this->assertCount(1, $this->registrationRequests(), 'The registration was retried or duplicated.');
        $this->assertSame(1, substr_count($this->linesWrittenTo($errors), "\n"), 'One stderr warning was expected for the refusal.');
        fclose($errors);
    }

    /**
     * A refusal to watch the registered files is a warning on stderr that
     * names what it leaves behind.
     */
    #[Test]
    public function test_a_registration_refusal_warns_on_stderr(): void
    {
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);
        $input = $this->stream('');

        try {
            $server = new Server(null, $input, transport: new Transport($this->output));
            $this->sendTo($server, $input,
                ['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]],
                ['method' => 'initialized'],
            );
            $registrations = $this->registrationRequests();
            $this->assertCount(1, $registrations);
            $id = $registrations[0]['id'];
            $this->assertNotNull($id);
            $this->sendTo($server, $input, ['id' => $id, 'error' => ['code' => -32601, 'message' => 'Not supported.']]);
        } finally {
            Server::writeErrorsTo(null);
        }

        $lines = $this->linesWrittenTo($errors);
        fclose($errors);
        $this->assertSame(1, substr_count($lines, "\n"));
        $this->assertStringContainsString('refused', $lines, 'A refusal must say it was refused.');
    }

    /**
     * A response the client sends without the id the protocol requires is
     * read, told apart from an answer to a request of ours, and ignored
     * with one line.
     */
    #[Test]
    public function test_a_response_with_no_id_is_ignored_with_one_line(): void
    {
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);
        $input = $this->stream('');

        try {
            $server = new Server(null, $input, transport: new Transport($this->output));
            $this->sendTo($server, $input,
                ['id' => 1, 'method' => 'initialize', 'params' => []],
                ['method' => 'initialized'],
                ['result' => null],
            );
        } finally {
            Server::writeErrorsTo(null);
        }

        $lines = $this->linesWrittenTo($errors);
        fclose($errors);
        $this->assertSame(1, substr_count($lines, "\n"));
        $this->assertStringContainsString('no id', $lines);
    }

    /**
     * A session that refused its target judges nothing: it asks for no
     * watchers either, for the events they would invite are dead air.
     */
    #[Test]
    public function test_a_refused_session_registers_no_watchers(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => [
                'initializationOptions' => ['phpVersion' => '7.2'],
                'capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]],
            ]],
            ['method' => 'initialized'],
        ]);

        $this->assertSame([], $this->registrationRequests(), 'A refused session asked the client to watch files.');
    }

    /**
     * An "initialized" notification that arrives before any "initialize"
     * must not poison the session: the first real initialize is served.
     */
    #[Test]
    public function test_a_stray_initialized_notification_does_not_poison_the_session(): void
    {
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);

        try {
            $this->serve([
                ['method' => 'initialized'],
                ['id' => 1, 'method' => 'initialize', 'params' => []],
            ]);
        } finally {
            Server::writeErrorsTo(null);
        }

        $lines = $this->linesWrittenTo($errors);
        fclose($errors);
        $this->assertSame(1, substr_count($lines, "\n"));
        $this->assertStringContainsString('dropped', $lines);

        $this->assertArrayHasKey('result', $this->answerTo(1), 'The first initialize was refused as a second one.');
    }

    /**
     * Responses the server never asked for are logged once, however many
     * arrive: the first line says the channel is noisy, the rest would
     * only flood the editor's log panel.
     */
    #[Test]
    public function test_stray_client_responses_are_logged_once(): void
    {
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);
        $input = $this->stream('');

        try {
            $server = new Server(null, $input, transport: new Transport($this->output));
            $this->sendTo($server, $input,
                ['id' => 1, 'method' => 'initialize', 'params' => []],
                ['method' => 'initialized'],
                ['id' => 77, 'result' => null],
                ['id' => 78, 'result' => null],
                ['id' => 79, 'result' => null],
            );
        } finally {
            Server::writeErrorsTo(null);
        }

        $lines = $this->linesWrittenTo($errors);
        fclose($errors);
        $this->assertSame(1, substr_count($lines, "\n"), 'Every stray response was logged.');
    }

    /**
     * An acceptance of the same registration is one plain line, no
     * warning: the client watches the files, that is the expected path.
     */
    #[Test]
    public function test_a_registration_acceptance_is_a_plain_line(): void
    {
        $errors = fopen('php://memory', 'r+');
        $this->assertIsResource($errors);
        Server::writeErrorsTo($errors);
        $input = $this->stream('');

        try {
            $server = new Server(null, $input, transport: new Transport($this->output));
            $this->sendTo($server, $input,
                ['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]],
                ['method' => 'initialized'],
            );
            $registrations = $this->registrationRequests();
            $this->assertCount(1, $registrations);
            $id = $registrations[0]['id'];
            $this->assertNotNull($id);
            $this->sendTo($server, $input, ['id' => $id, 'result' => null]);
        } finally {
            Server::writeErrorsTo(null);
        }

        $lines = $this->linesWrittenTo($errors);
        fclose($errors);
        $this->assertSame(1, substr_count($lines, "\n"));
        $this->assertStringNotContainsString('refused', $lines, 'An acceptance is not a warning.');
    }

    /**
     * A client that offers no dynamic registration is asked for nothing:
     * no frame leaves the server uninvited.
     */
    #[Test]
    public function test_a_client_without_dynamic_registration_is_asked_for_nothing(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => ['workspace' => []]]],
            ['method' => 'initialized'],
        ]);

        $this->assertSame([], $this->registrationRequests());
    }

    /**
     * The client capabilities are read where the protocol's InitializeParams
     * puts them, "capabilities": a key of another name offers nothing.
     */
    #[Test]
    public function test_capabilities_under_another_key_offer_no_dynamic_registration(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['clientCapabilities' => ['workspace' => ['didChangeWatchedFiles' => ['dynamicRegistration' => true]]]]],
            ['method' => 'initialized'],
        ]);

        $this->assertSame([], $this->registrationRequests());
    }

    private function serveRaw(string $payload): void
    {
        (new Server(null, $this->stream($payload), transport: new Transport($this->output)))->run();
    }

    /**
     * The window/logMessage notifications that name the target the session
     * judges for, once per "initialize" that did its work.
     *
     * @return list<string>
     */
    private function targetLogs(): array
    {
        $logs = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $this->written()) ?: [] as $body) {
            $decoded = json_decode($body, true);
            $message = \is_array($decoded) && \is_array($decoded['params'] ?? null) ? ($decoded['params']['message'] ?? null) : null;
            if (\is_array($decoded) && 'window/logMessage' === ($decoded['method'] ?? null) && \is_string($message) && str_contains($message, 'Target:')) {
                $logs[] = $message;
            }
        }

        return $logs;
    }

    /**
     * The client/registerCapability requests on the wire, in order.
     *
     * @return list<non-empty-array<mixed>>
     */
    private function registrationRequests(): array
    {
        $requests = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $this->written()) ?: [] as $body) {
            $decoded = json_decode($body, true);
            if (\is_array($decoded) && 'client/registerCapability' === ($decoded['method'] ?? null)) {
                $requests[] = $decoded;
            }
        }

        return $requests;
    }

    /**
     * Hands the server the messages and lets it read them, as many times as
     * a test needs: the input stream is written past what it has read.
     *
     * @param resource             $input
     * @param array<string, mixed> ...$messages
     */
    private function sendTo(Server $server, $input, array ...$messages): void
    {
        $start = ftell($input);
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        fseek($input, (int) $start);

        $server->run();
    }

    /**
     * @param resource $stream
     */
    private function linesWrittenTo($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
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
     * @return list<non-empty-array<mixed>>
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

        (new Server(null, $this->stream($payload), transport: new Transport($this->output)))->run();
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
