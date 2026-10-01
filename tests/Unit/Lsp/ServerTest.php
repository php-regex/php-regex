<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lsp;

use PhpRegex\LanguageServer\Protocol\Response;
use PhpRegex\LanguageServer\Server;
use PhpRegex\Toolkit\Regex;
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
