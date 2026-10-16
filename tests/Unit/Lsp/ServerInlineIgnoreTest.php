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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "@regex-ignore" and "@regex-ignore-next-line" silence a pattern in the
 * editor as they do in `regex lint`: the marker sits on the line of the
 * literal, the declaration's for a pattern the file proves.
 *
 * "/(a/" is the broken pattern: preg_match() refuses it, "missing closing
 * parenthesis at offset 2".
 */
final class ServerInlineIgnoreTest extends TestCase
{
    /**
     * @var resource
     */
    private $output;

    /**
     * @var resource
     */
    private $input;

    private string $root;

    private ?Server $server = null;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        $input = fopen('php://memory', 'r+');
        if (false === $output || false === $input) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->input = $input;
        Response::writeTo($this->output);

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-ignore-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        Response::writeTo(null);
        fclose($this->output);
        fclose($this->input);

        self::remove($this->root);
    }

    #[Test]
    public function test_an_ignored_pattern_gets_no_diagnostic(): void
    {
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, <<<'CODE'
            <?php

            final class Matcher
            {
                private const SAME = '/(a/'; // @regex-ignore
                // @regex-ignore-next-line
                private const NEXT = '/(a/';
                private const KEPT = '/(a/';

                public function run(string $s): bool
                {
                    $re = '/(a/'; // @regex-ignore
                    preg_match('/(a/', $s); // @regex-ignore
                    /** @regex-ignore-next-line */
                    preg_match('/(a/', $s);
                    preg_match('/(a/', $s);

                    return 1 === preg_match(self::SAME, $s) && 1 === preg_match(self::NEXT, $s) && 1 === preg_match($re, $s) && 1 === preg_match(self::KEPT, $s); // @regex-ignore
                }
            }
            CODE));

        // A marker on the call does nothing for a pattern its declaration holds.
        $this->assertSame([7, 15], $this->diagnosedLines($uri));
    }

    /**
     * Typing the marker silences the pattern; removing it brings the
     * diagnostic back.
     */
    #[Test]
    public function test_a_marker_typed_or_removed_is_read(): void
    {
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, "<?php

preg_match('/(a/', \$subject);
"));
        $this->assertSame([2], $this->diagnosedLines($uri));

        $this->send($this->didChange($uri, "<?php

preg_match('/(a/', \$subject); // @regex-ignore
"));
        $this->assertSame([], $this->diagnosedLines($uri));

        $this->send($this->didChange($uri, "<?php

preg_match('/(a/', \$subject);
"));
        $this->assertSame([2], $this->diagnosedLines($uri));
    }

    /**
     * @return array<string, mixed>
     */
    private function didChange(string $uri, string $text): array
    {
        return ['method' => 'textDocument/didChange', 'params' => [
            'textDocument' => ['uri' => $uri, 'version' => 2],
            'contentChanges' => [['text' => $text]],
        ]];
    }

    private function initialize(): void
    {
        $this->server = new Server(null, $this->input);
        $this->send(
            ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => 'file://'.$this->root]],
            ['method' => 'initialized', 'params' => []],
        );
    }

    /**
     * Hands the server the messages and lets it read them, as many times as
     * a test needs: the input stream is written past what it has read.
     *
     * @param array<string, mixed> ...$messages
     */
    private function send(array ...$messages): void
    {
        $this->assertInstanceOf(Server::class, $this->server);

        $start = ftell($this->input);
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($this->input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        fseek($this->input, (int) $start);

        $this->server->run();
    }

    /**
     * @return array<string, mixed>
     */
    private function didOpen(string $uri, string $text): array
    {
        return ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => [
            'uri' => $uri,
            'languageId' => 'php',
            'version' => 1,
            'text' => $text,
        ]]];
    }

    private function uri(string $relative): string
    {
        return 'file://'.$this->root.'/'.$relative;
    }

    /**
     * The lines of the diagnostics last published for a document.
     *
     * @return list<int>
     */
    private function diagnosedLines(string $uri): array
    {
        $published = null;
        foreach ($this->notifications('textDocument/publishDiagnostics') as $params) {
            if ($uri === ($params['uri'] ?? null) && \is_array($params['diagnostics'] ?? null)) {
                $published = $params['diagnostics'];
            }
        }
        $this->assertIsArray($published, 'The server published no diagnostics for '.$uri.'.');

        $lines = [];
        foreach ($published as $diagnostic) {
            $this->assertIsArray($diagnostic);
            $this->assertSame('php-regex', $diagnostic['source'] ?? null);
            $range = $diagnostic['range'] ?? null;
            $this->assertIsArray($range);
            $start = $range['start'] ?? null;
            $this->assertIsArray($start);
            $line = $start['line'] ?? null;
            $this->assertIsInt($line);
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function notifications(string $method): array
    {
        rewind($this->output);
        $written = (string) stream_get_contents($this->output);

        $found = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $written) ?: [] as $body) {
            $message = json_decode($body, true);
            if (\is_array($message) && $method === ($message['method'] ?? null) && \is_array($message['params'] ?? null)) {
                /** @var array<string, mixed> $params */
                $params = $message['params'];
                $found[] = $params;
            }
        }

        return $found;
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::remove($path.'/'.$entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
