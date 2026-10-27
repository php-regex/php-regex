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

use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\LanguageServer\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The frame log: the trace the protocol's "trace" setting and "$/setTrace"
 * turn on writes one stderr line per frame read or written, never a byte
 * between the frames on stdout.
 */
final class ServerTraceTest extends TestCase
{
    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $errors;

    private Transport $transport;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        $errors = fopen('php://memory', 'r+');
        if (false === $output || false === $errors) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->errors = $errors;
        $this->transport = new Transport($output);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->errors);
    }

    #[Test]
    public function test_nothing_is_traced_by_default(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['id' => 2, 'method' => 'shutdown'],
        ]);

        $this->assertSame([], $this->traceLines());
    }

    #[Test]
    public function test_the_initialize_trace_setting_logs_every_frame_from_initialize_on(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['trace' => 'messages']],
            ['method' => 'initialized'],
            ['id' => 2, 'method' => 'no/such/method'],
            ['id' => 3, 'method' => 'shutdown'],
        ]);

        $this->assertSame([
            'regex-lsp: trace: received request "initialize" (id 1)',
            'regex-lsp: trace: sent response (id 1)',
            'regex-lsp: trace: sent notification "window/logMessage"',
            'regex-lsp: trace: received notification "initialized"',
            'regex-lsp: trace: received request "no/such/method" (id 2)',
            'regex-lsp: trace: sent error response (id 2), -32601',
            'regex-lsp: trace: received request "shutdown" (id 3)',
            'regex-lsp: trace: sent response (id 3)',
        ], $this->traceLines());
    }

    #[Test]
    public function test_the_initialization_options_set_the_trace_over_the_protocol_setting(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['trace' => 'off', 'initializationOptions' => ['trace' => 'verbose']]],
        ]);

        $lines = $this->traceLines();
        $this->assertSame('regex-lsp: trace: received request "initialize" (id 1): {"trace":"off","initializationOptions":{"trace":"verbose"}}', $lines[0] ?? null);
        $this->assertStringStartsWith('regex-lsp: trace: sent response (id 1): {"capabilities":', $lines[1] ?? '');
    }

    #[Test]
    public function test_set_trace_turns_the_frame_log_on_and_off(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => '$/setTrace', 'params' => ['value' => 'messages']],
            ['id' => 2, 'method' => 'no/such/method'],
            ['method' => '$/setTrace', 'params' => ['value' => 'off']],
            ['id' => 3, 'method' => 'no/such/method'],
        ]);

        $this->assertSame([
            'regex-lsp: trace: received request "no/such/method" (id 2)',
            'regex-lsp: trace: sent error response (id 2), -32601',
            'regex-lsp: trace: received notification "$/setTrace"',
        ], $this->traceLines());
    }

    #[Test]
    public function test_a_trace_value_that_is_not_a_level_is_ignored_with_a_warning(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['trace' => 'messages', 'initializationOptions' => ['trace' => 'all']]],
            ['method' => '$/setTrace', 'params' => ['value' => 42]],
            ['id' => 2, 'method' => 'shutdown'],
        ]);

        // The protocol setting holds: the option that cannot be read is
        // the one ignored, and so is the notification.
        $this->assertContains('regex-lsp: trace: sent response (id 2)', $this->traceLines());
        $this->assertContains('initializationOptions.trace "all" is not off, messages or verbose: ignored.', $this->logMessages());
        $this->assertStringContainsString("regex-lsp: \$/setTrace value int is not off, messages or verbose: ignored.\n", $this->errorText());
    }

    #[Test]
    public function test_a_set_trace_level_that_is_not_one_keeps_the_level(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['trace' => 'messages']],
            ['method' => '$/setTrace', 'params' => ['value' => 'loud']],
            ['id' => 2, 'method' => 'shutdown'],
        ]);

        $lines = array_values(array_filter(explode("\n", $this->errorText()), static fn (string $line): bool => !str_starts_with($line, 'regex-lsp: trace: ') && '' !== $line));
        $this->assertSame(['regex-lsp: $/setTrace value "loud" is not off, messages or verbose: ignored.'], $lines);
        $this->assertContains('regex-lsp: trace: sent response (id 2)', $this->traceLines());
    }

    /**
     * The frame log is the session's: once it ends, a frame written by
     * anything else is not traced.
     */
    #[Test]
    public function test_the_frame_log_ends_with_the_session(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['trace' => 'messages']],
        ]);
        $traced = \count($this->traceLines());

        $this->transport->notification('window/logMessage', ['type' => 3, 'message' => 'after the session']);

        $this->assertCount($traced, $this->traceLines());
    }

    #[Test]
    public function test_a_traced_session_writes_only_frames_on_stdout(): void
    {
        $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => ['trace' => 'verbose']],
            ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => 'file:///a.php', 'version' => 1, 'text' => "<?php preg_match('/[é(/u', \$s);"]]],
            ['id' => 2, 'method' => 'shutdown'],
        ]);

        $written = $this->read($this->output);
        $this->assertStringNotContainsString('trace:', $written);
        // Frame after frame, each its declared length, to the last byte.
        $offset = 0;
        while ($offset < \strlen($written)) {
            $this->assertSame(1, preg_match('/\GContent-Length: (\d+)\r\n\r\n/', $written, $header, 0, $offset), 'A frame header at byte '.$offset);
            $offset += \strlen($header[0]) + (int) $header[1];
        }
        $this->assertSame(\strlen($written), $offset);
        $this->assertContains('regex-lsp: trace: sent notification "textDocument/publishDiagnostics"', array_map(
            static fn (string $line): string => (string) preg_replace('/: \{.*$/', '', $line),
            $this->traceLines(),
        ));
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function serve(array $messages): void
    {
        $payload = '';
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $payload .= 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
        }

        $input = fopen('php://memory', 'r+');
        $this->assertIsResource($input);
        fwrite($input, $payload);
        rewind($input);

        (new Server(null, $input, transport: $this->transport, errorStream: $this->errors))->run();
    }

    /**
     * @return list<string>
     */
    private function traceLines(): array
    {
        return array_values(array_filter(
            explode("\n", $this->errorText()),
            static fn (string $line): bool => str_starts_with($line, 'regex-lsp: trace: '),
        ));
    }

    private function errorText(): string
    {
        return $this->read($this->errors);
    }

    /**
     * @return list<string>
     */
    private function logMessages(): array
    {
        $messages = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $this->read($this->output)) ?: [] as $body) {
            $decoded = json_decode($body, true);
            $params = \is_array($decoded) ? ($decoded['params'] ?? null) : null;
            if (\is_array($decoded) && 'window/logMessage' === ($decoded['method'] ?? null) && \is_array($params) && \is_string($params['message'] ?? null)) {
                $messages[] = $params['message'];
            }
        }

        return $messages;
    }

    /**
     * @param resource $stream
     */
    private function read($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
