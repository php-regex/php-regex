<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Lsp;

use PhpRegex\LanguageServer\Protocol\Response;
use PhpRegex\LanguageServer\Server;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "exit" notification ends the session with the code the protocol
 * names: 0 after a "shutdown" request, 1 without one. The server returns
 * that code; the binary exits with it.
 */
final class ServerExitCodeTest extends TestCase
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
    public function test_exit_after_shutdown_returns_zero(): void
    {
        $code = $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'initialized'],
            ['id' => 2, 'method' => 'shutdown'],
            ['method' => 'exit'],
        ]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('"id":2', $this->written());
    }

    /**
     * Run apart: the server this replaces ended the whole process here.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_exit_without_shutdown_returns_one_and_reads_no_further(): void
    {
        $code = $this->serve([
            ['id' => 1, 'method' => 'initialize', 'params' => []],
            ['method' => 'exit'],
            ['id' => 9, 'method' => 'initialize', 'params' => []],
        ]);

        $this->assertSame(1, $code);
        $this->assertStringNotContainsString('"id":9', $this->written());
    }

    /**
     * The binary exits with the code the server returned. Green before the
     * change as well (the server itself exited): it guards the wiring.
     */
    #[Test]
    public function test_the_binary_exits_with_the_code_the_server_returns(): void
    {
        foreach ([
            0 => [['id' => 1, 'method' => 'initialize', 'params' => []], ['id' => 2, 'method' => 'shutdown'], ['method' => 'exit']],
            1 => [['id' => 1, 'method' => 'initialize', 'params' => []], ['method' => 'exit']],
        ] as $expected => $messages) {
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../../../bin/regex-lsp'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            $this->assertIsResource($process);

            fwrite($pipes[0], self::frames($messages));
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $this->assertSame($expected, proc_close($process));
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function serve(array $messages): int
    {
        $stream = fopen('php://memory', 'r+');
        if (false === $stream) {
            self::fail('Unable to open an in-memory stream.');
        }

        fwrite($stream, self::frames($messages));
        rewind($stream);

        return (new Server(null, $stream))->run();
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private static function frames(array $messages): string
    {
        $payload = '';
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            $payload .= 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
        }

        return $payload;
    }

    private function written(): string
    {
        rewind($this->output);

        return (string) stream_get_contents($this->output);
    }
}
