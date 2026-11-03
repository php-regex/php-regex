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

namespace PHPRegex\Tests\Integration\Mcp;

use PHPRegex\Mcp\Application;
use PHPRegex\Mcp\StderrLogger;
use PHPRegex\Mcp\ToolCatalog;
use PHPRegex\Mcp\ToolDefinition;
use PHPRegex\Mcp\ToolInterface;
use PHPRegex\Mcp\ToolResult;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The server driven the way a client drives it over stdio: newline-delimited
 * JSON-RPC in, the same out. The input is a socket pair, which the transport
 * selects on (a memory stream cannot be selected); the output is a file,
 * read once the session ended.
 */
final class StdioServerTest extends TestCase
{
    private const INITIALIZE = ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '0']];

    #[Test]
    public function test_initialize_lists_the_five_tools(): void
    {
        $answers = $this->serve([
            self::request(1, 'initialize', self::INITIALIZE),
            self::notification('notifications/initialized'),
            self::request(2, 'tools/list'),
        ]);

        $this->assertSame(['name' => 'php-regex', 'version' => Regex::VERSION], self::at($answers, 1, 'result', 'serverInfo'));
        $tools = JsonContract::asArray(self::at($answers, 2, 'result', 'tools'));
        $names = [];
        foreach ($tools as $tool) {
            $tool = JsonContract::asArray($tool);
            $names[] = $tool['name'];
            // The SDK writes the title into the annotations as well.
            $this->assertSame(['title' => $tool['title'], 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false], $tool['annotations']);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertArrayHasKey('outputSchema', $tool);
        }
        $this->assertSame(['regex_validate', 'regex_explain', 'regex_format', 'regex_transpile', 'regex_redos'], $names);
    }

    #[Test]
    public function test_tools_call_answers_the_text_and_the_structured_document(): void
    {
        $answers = $this->serve([
            self::request(1, 'initialize', self::INITIALIZE),
            self::notification('notifications/initialized'),
            self::request(2, 'tools/call', ['name' => 'regex_validate', 'arguments' => ['pattern' => '/a+/']]),
            self::request(3, 'tools/call', ['name' => 'regex_transpile', 'arguments' => ['pattern' => '/(?>a+)b/', 'target' => 'js']]),
        ]);

        $valid = JsonContract::asArray(self::at($answers, 2, 'result'));
        $this->assertNotTrue($valid['isError'] ?? false);
        $this->assertSame('Valid.', self::at($answers, 2, 'result', 'content', '0', 'text'));
        $this->assertTrue(self::at($answers, 2, 'result', 'structuredContent', 'validation', 'is_valid'));

        $this->assertTrue(self::at($answers, 3, 'result', 'isError'));
        $this->assertSame('Atomic groups are not supported in JavaScript.', self::at($answers, 3, 'result', 'content', '0', 'text'));
        $this->assertSame(['error' => 'Atomic groups are not supported in JavaScript.', 'stage' => 'pattern'], self::at($answers, 3, 'result', 'structuredContent'));
    }

    #[Test]
    public function test_the_server_target_is_the_default_of_a_call(): void
    {
        $answers = $this->serve([
            self::request(1, 'initialize', self::INITIALIZE),
            self::notification('notifications/initialized'),
            self::request(2, 'tools/call', ['name' => 'regex_validate', 'arguments' => ['pattern' => '/\p{Script=Latin}/u']]),
            self::request(3, 'tools/call', ['name' => 'regex_validate', 'arguments' => ['pattern' => '/\p{Script=Latin}/u', 'php_version' => '8.2']]),
        ], ['php_version' => '8.0']);

        $this->assertFalse(self::at($answers, 2, 'result', 'structuredContent', 'validation', 'is_valid'));
        $this->assertTrue(self::at($answers, 3, 'result', 'structuredContent', 'validation', 'is_valid'));
    }

    #[Test]
    public function test_an_unknown_tool_is_a_json_rpc_error_not_a_tool_result(): void
    {
        $answers = $this->serve([
            self::request(1, 'initialize', self::INITIALIZE),
            self::notification('notifications/initialized'),
            self::request(2, 'tools/call', ['name' => 'regex_lint', 'arguments' => ['pattern' => '/a/']]),
        ]);

        $this->assertArrayNotHasKey('result', $answers[2]);
        $this->assertSame(-32602, self::at($answers, 2, 'error', 'code'));
    }

    #[Test]
    public function test_an_argument_the_schema_refuses_is_a_json_rpc_error(): void
    {
        $answers = $this->serve([
            self::request(1, 'initialize', self::INITIALIZE),
            self::notification('notifications/initialized'),
            self::request(2, 'tools/call', ['name' => 'regex_redos', 'arguments' => ['pattern' => '/a/', 'mode' => 'off']]),
        ]);

        $this->assertArrayNotHasKey('result', $answers[2]);
        $this->assertSame(-32602, self::at($answers, 2, 'error', 'code'));
    }

    #[Test]
    public function test_a_tool_that_throws_keeps_the_server_serving(): void
    {
        $throwing = new class implements ToolInterface {
            public function execute(array $arguments): ToolResult
            {
                throw new \RuntimeException('boom');
            }
        };
        $catalog = ToolCatalog::v1()->with(new ToolDefinition('regex_boom', 'Boom', 'Throws.', ['type' => 'object', 'properties' => [], 'additionalProperties' => false], ['type' => 'object'], $throwing));

        $answers = $this->serve([
            self::request(1, 'initialize', self::INITIALIZE),
            self::notification('notifications/initialized'),
            self::request(2, 'tools/call', ['name' => 'regex_boom', 'arguments' => []]),
            self::request(3, 'tools/call', ['name' => 'regex_validate', 'arguments' => ['pattern' => '/a/']]),
        ], [], $catalog);

        $this->assertSame(-32603, self::at($answers, 2, 'error', 'code'));
        $this->assertSame('Valid.', self::at($answers, 3, 'result', 'content', '0', 'text'));
    }

    #[Test]
    public function test_an_input_the_transport_cannot_select_on_exits_with_one_and_logs_why(): void
    {
        // A memory stream cannot be selected on: the transport's loop fails,
        // the failure is logged, and the process gets an exit code, never a
        // stack trace on the channel.
        $input = fopen('php://memory', 'r+');
        $output = fopen('php://memory', 'w+');
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($input);
        $this->assertIsResource($output);
        $this->assertIsResource($errors);

        $exitCode = (new Application(null, new StderrLogger($errors)))->run([], $input, $output);

        rewind($errors);
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[error] The MCP server stopped on a transport failure: ', (string) stream_get_contents($errors));
    }

    #[Test]
    public function test_closed_streams_exit_with_one(): void
    {
        $closed = fopen('php://memory', 'r+');
        $this->assertIsResource($closed);
        fclose($closed);

        $this->assertSame(1, (new Application())->run([], $closed, $closed));
    }

    /**
     * One closed stream is enough to refuse the session, and the refusal
     * is silent: nothing was tried, so nothing is logged.
     */
    #[Test]
    public function test_one_closed_stream_exits_with_one_before_any_log(): void
    {
        foreach (['input', 'output'] as $which) {
            $closed = fopen('php://memory', 'r+');
            $open = fopen('php://memory', 'r+');
            $errors = fopen('php://memory', 'w+');
            $this->assertIsResource($closed);
            $this->assertIsResource($open);
            $this->assertIsResource($errors);
            fclose($closed);

            $input = 'input' === $which ? $closed : $open;
            $output = 'output' === $which ? $closed : $open;
            $this->assertSame(1, (new Application(null, new StderrLogger($errors)))->run([], $input, $output), $which);
            rewind($errors);
            $this->assertSame('', stream_get_contents($errors), 'A closed '.$which.' is refused before the server runs, so nothing is logged.');
        }
    }

    /**
     * The boundary of the process: a transport that fails is logged with
     * its cause and answered with an exit code, never a stack trace.
     */
    #[Test]
    public function test_a_transport_failure_is_logged_with_its_cause(): void
    {
        $input = fopen('php://memory', 'w+');
        $readOnly = fopen('php://memory', 'r');
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($input);
        $this->assertIsResource($readOnly);
        $this->assertIsResource($errors);
        fwrite($input, self::request(1, 'initialize', self::INITIALIZE)."\n");
        rewind($input);

        $exitCode = (new Application(null, new StderrLogger($errors)))->run([], $input, $readOnly);
        rewind($errors);
        $log = (string) stream_get_contents($errors);

        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith('[error] The MCP server stopped on a transport failure: ', $log);
    }

    /**
     * A warning PHP prints on stdout would be read as protocol: the session
     * sends PHP's own output to stderr.
     */
    #[Test]
    public function test_the_session_sends_phps_warnings_to_stderr(): void
    {
        $before = ini_get('display_errors');
        $closed = fopen('php://memory', 'r+');
        $this->assertIsResource($closed);
        fclose($closed);

        try {
            ini_set('display_errors', '1');
            (new Application())->run([], $closed, $closed);

            $this->assertSame('stderr', ini_get('display_errors'));
        } finally {
            ini_set('display_errors', (string) $before);
        }
    }

    #[Test]
    public function test_the_end_of_input_ends_the_session_with_zero(): void
    {
        [$exitCode] = $this->serveRaw([self::request(1, 'ping')]);

        $this->assertSame(0, $exitCode);
    }

    /**
     * The value at a path of keys inside the answer to a request.
     *
     * @param array<int, array<string, mixed>> $answers
     */
    private static function at(array $answers, int $id, string ...$keys): mixed
    {
        self::assertArrayHasKey($id, $answers, 'No answer to request '.$id);
        $value = $answers[$id];
        foreach ($keys as $key) {
            $value = JsonContract::asArray($value, 'No '.$key.' in the answer to request '.$id);
            self::assertArrayHasKey($key, $value, 'No '.$key.' in the answer to request '.$id);
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * @param list<string>                                                        $lines
     * @param array{php_version?: string, pcre_version?: string, locale?: string} $target
     *
     * @return array<int, array<string, mixed>> the answers, by request id
     */
    private function serve(array $lines, array $target = [], ?ToolCatalog $catalog = null): array
    {
        [$exitCode, $stdout] = $this->serveRaw($lines, $target, $catalog);
        $this->assertSame(0, $exitCode);

        $answers = [];
        foreach (explode("\n", rtrim($stdout, "\n")) as $line) {
            $this->assertJson($line, 'One JSON document per line, nothing else on stdout.');
            $answer = JsonContract::asArray(json_decode($line, true, 512, \JSON_THROW_ON_ERROR));
            $id = $answer['id'] ?? null;
            if (!\is_int($id)) {
                continue;
            }
            $typed = [];
            foreach ($answer as $key => $value) {
                $typed[(string) $key] = $value;
            }
            $answers[$id] = $typed;
        }

        return $answers;
    }

    /**
     * @param list<string>                                                        $lines
     * @param array{php_version?: string, pcre_version?: string, locale?: string} $target
     *
     * @return array{int, string}
     */
    private function serveRaw(array $lines, array $target = [], ?ToolCatalog $catalog = null): array
    {
        // The input is a socket pair, which the transport selects on; the
        // output is a file, read once the session ended: a socket read only
        // after the session would fill under a long answer and block the
        // transport's write forever, the test being its only reader.
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        [$clientWrites, $serverReads] = $pair;
        $path = (string) tempnam(sys_get_temp_dir(), 'regex-mcp-out-');
        $serverWrites = fopen($path, 'w');
        $this->assertIsResource($serverWrites);

        fwrite($clientWrites, implode("\n", $lines)."\n");
        fclose($clientWrites);

        try {
            // The server's log goes to a stream of the test's, never to the
            // process's stderr: a refused tool is logged as an error, and a
            // byte on stderr fails a run under the mutation tool.
            $errors = fopen('php://memory', 'w+');
            $this->assertIsResource($errors);
            $exitCode = (new Application($catalog, new StderrLogger($errors)))->run($target, $serverReads, $serverWrites);
            // The transport closes the streams it was given when the session ends.
            foreach ([$serverReads, $serverWrites] as $stream) {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
            $stdout = (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }

        return [$exitCode, $stdout];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function request(int $id, string $method, array $params = []): string
    {
        return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
    }

    private static function notification(string $method): string
    {
        return (string) json_encode(['jsonrpc' => '2.0', 'method' => $method]);
    }
}
