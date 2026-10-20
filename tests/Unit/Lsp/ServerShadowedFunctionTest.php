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
 * An unqualified call in a namespace reaches that namespace's own function
 * first: one typed in a document, or saved on the disk, shadows a global
 * pattern function for every open document, as `regex lint` reads it.
 *
 * "/(a/" is the broken pattern: preg_match() refuses it, "missing closing
 * parenthesis at offset 2".
 */
final class ServerShadowedFunctionTest extends TestCase
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

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-shadow-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->input);

        self::remove($this->root);
    }

    #[Test]
    public function test_a_namespace_function_typed_in_one_document_shadows_a_configured_one_in_the_others(): void
    {
        $calls = $this->uri('src/Calls.php');
        $shadow = $this->uri('src/Shadow.php');
        $this->write('regex.json', (string) json_encode(['extraction' => ['functions' => ['grep']]]));

        $this->initialize();
        $this->send($this->didOpen($calls, "<?php\n\nnamespace App;\n\ngrep('/(a/');\n\\grep('/(a/');\n"));
        $this->assertSame([4, 5], $this->diagnosedLines($calls));

        $this->send($this->didOpen($shadow, "<?php\n\nnamespace App;\n\nfunction grep(string \$subject) {}\n"));
        $this->assertSame([5], $this->diagnosedLines($calls));

        $this->send($this->didChange($shadow, "<?php\n\nnamespace App;\n\nfunction other(string \$subject) {}\n"));
        $this->assertSame([4, 5], $this->diagnosedLines($calls));
    }

    #[Test]
    public function test_a_namespace_function_of_the_workspace_shadows_a_configured_one(): void
    {
        $calls = $this->uri('src/Calls.php');
        $this->write('regex.json', (string) json_encode(['extraction' => ['functions' => ['grep']]]));
        $this->write('src/Shadow.php', "<?php\n\nnamespace App;\n\nfunction grep(string \$subject) {}\n");

        $this->initialize();
        $this->send($this->didOpen($calls, "<?php\n\nnamespace App;\n\ngrep('/(a/');\n\\grep('/(a/');\n"));

        $this->assertSame([5], $this->diagnosedLines($calls));
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
        $this->server = new Server(null, $this->input, transport: new Transport($this->output));
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

    private function write(string $relative, string $content): void
    {
        $path = $this->root.'/'.$relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $content);
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
