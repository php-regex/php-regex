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

namespace PHPRegex\Tests\Integration\Lsp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The binary an editor starts, run as one: a child PHP process reading its
 * standard input from a file and writing its standard output and error to
 * files — never pipes, which a server that blocks on a read would deadlock
 * against. Every other test drives the server in-process over memory
 * streams; this is the one that sees the process, its exit code and every
 * byte it writes on stdout.
 */
final class RegexLspBinaryTest extends TestCase
{
    private const BINARY = __DIR__.'/../../../src/LanguageServer/bin/regex-lsp';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/regex-lsp-binary-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function test_garbage_bytes_are_answered_parse_error_and_the_session_survives(): void
    {
        $run = $this->serve(
            "Content-Length: 9\r\n\r\nnot json!"
            .self::frame(['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]])
            .self::frame(['id' => 2, 'method' => 'shutdown'])
            .self::frame(['method' => 'exit']),
        );

        $frames = $this->frames($run['stdout']);
        $this->assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], $frames[0]);
        $this->assertSame(1, $frames[1]['id'] ?? null);
        $this->assertArrayHasKey('result', $frames[1]);
        $this->assertContains(['jsonrpc' => '2.0', 'id' => 2, 'result' => null], $frames);
        $this->assertSame(0, $run['exit'], $run['stderr']);
    }

    #[Test]
    public function test_exit_without_shutdown_ends_the_process_with_one(): void
    {
        $run = $this->serve(
            self::frame(['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => []]])
            .self::frame(['method' => 'exit']),
        );

        $this->assertSame(1, $run['exit'], $run['stderr']);
    }

    #[Test]
    public function test_a_full_session_writes_nothing_but_frames_on_stdout(): void
    {
        $uri = 'file://'.$this->directory.'/a.php';
        $run = $this->serve(
            self::frame(['id' => 1, 'method' => 'initialize', 'params' => ['capabilities' => [], 'rootUri' => 'file://'.$this->directory, 'trace' => 'verbose']])
            .self::frame(['method' => 'initialized'])
            .self::frame(['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => "<?php\npreg_match('/(é/u', \$s);\n"]]])
            .self::frame(['id' => 2, 'method' => 'textDocument/hover', 'params' => ['textDocument' => ['uri' => $uri], 'position' => ['line' => 1, 'character' => 13]]])
            .self::frame(['id' => 3, 'method' => 'shutdown'])
            .self::frame(['method' => 'exit']),
        );

        // frames() fails on any byte that is not a frame.
        $frames = $this->frames($run['stdout']);

        $diagnostics = array_values(array_filter($frames, static fn (array $frame): bool => 'textDocument/publishDiagnostics' === ($frame['method'] ?? null)));
        $this->assertCount(1, $diagnostics);
        $this->assertIsArray($diagnostics[0]['params']);
        $this->assertSame($uri, $diagnostics[0]['params']['uri']);
        $this->assertSame(1, $diagnostics[0]['params']['version']);
        $this->assertNotSame([], $diagnostics[0]['params']['diagnostics']);
        $this->assertContains(['jsonrpc' => '2.0', 'id' => 3, 'result' => null], $frames);
        $this->assertSame(0, $run['exit'], $run['stderr']);

        // The frame log went to stderr, never to stdout.
        $this->assertStringContainsString('regex-lsp: trace: received request "textDocument/hover" (id 2)', $run['stderr']);
        $this->assertSame(1, preg_match('//u', $run['stderr']), 'stderr stays valid UTF-8.');
    }

    /**
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function serve(string $input): array
    {
        $stdin = $this->directory.'/stdin';
        $stdout = $this->directory.'/stdout';
        $stderr = $this->directory.'/stderr';
        file_put_contents($stdin, $input);

        $process = proc_open(
            [\PHP_BINARY, self::BINARY],
            [0 => ['file', $stdin, 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            $this->directory,
        );
        $this->assertIsResource($process);

        return [
            'exit' => proc_close($process),
            'stdout' => (string) file_get_contents($stdout),
            'stderr' => (string) file_get_contents($stderr),
        ];
    }

    /**
     * The frames on stdout, read by their declared length to the last byte.
     *
     * @return list<array<mixed>>
     */
    private function frames(string $stdout): array
    {
        $frames = [];
        $offset = 0;
        while ($offset < \strlen($stdout)) {
            $this->assertSame(1, preg_match('/\GContent-Length: (\d+)\r\n\r\n/', $stdout, $header, 0, $offset), 'A frame header at byte '.$offset.': '.substr($stdout, $offset, 80));
            $offset += \strlen($header[0]);
            $frame = json_decode(substr($stdout, $offset, (int) $header[1]), true);
            $this->assertIsArray($frame);
            $frames[] = $frame;
            $offset += (int) $header[1];
        }

        $this->assertSame(\strlen($stdout), $offset);

        return $frames;
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function frame(array $message): string
    {
        $json = (string) json_encode(['jsonrpc' => '2.0'] + $message, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return 'Content-Length: '.\strlen($json)."\r\n\r\n".$json;
    }
}
