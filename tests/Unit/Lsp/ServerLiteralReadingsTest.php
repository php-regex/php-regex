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
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One literal read two ways gets the diagnostics of each reading, on the
 * literal. "/\xFF/" compiles; Nette's utf8: true makes it "/\xFF/u", which
 * PHP refuses: preg_match("/\xFF/u", 'x') returns false, the pattern being
 * no valid UTF-8.
 */
final class ServerLiteralReadingsTest extends TestCase
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

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'r+');
        $input = fopen('php://memory', 'r+');
        if (false === $output || false === $input) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->input = $input;

        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-readings-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->input);

        self::remove($this->root);
    }

    #[Test]
    public function test_the_utf8_reading_of_a_literal_gets_its_own_diagnostic(): void
    {
        $this->assertSame([], $this->utf8Diagnostics(''));
        $this->assertSame([5], $this->utf8Diagnostics("\n        Strings::match(\$s, self::RE, utf8: true);"));
    }

    /**
     * The lines of the diagnostics saying the pattern is no valid UTF-8.
     *
     * @return list<int>
     */
    private function utf8Diagnostics(string $secondCall): array
    {
        $uri = FileUri::of($this->root.'/src/M.php');
        // The source spells the byte with an escape, as an editor holds it:
        // a raw 0xff byte is no UTF-8, which the protocol carries.
        $text = "<?php\nuse Nette\\Utils\\Strings;\n\nfinal class M\n{\n    private const RE = \"/\\xFF/\";\n\n    public function run(string \$s): void\n    {\n        preg_match(self::RE, \$s);".$secondCall."\n    }\n}\n";

        ftruncate($this->output, 0);
        rewind($this->output);
        $server = new Server(null, $this->input, transport: new Transport($this->output));
        $messages = [
            ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => FileUri::of($this->root)]],
            ['method' => 'initialized', 'params' => []],
            ['method' => 'textDocument/didOpen', 'params' => ['textDocument' => ['uri' => $uri, 'languageId' => 'php', 'version' => 1, 'text' => $text]]],
        ];
        $start = ftell($this->input);
        foreach ($messages as $message) {
            $json = (string) json_encode(['jsonrpc' => '2.0'] + $message);
            fwrite($this->input, 'Content-Length: '.\strlen($json)."\r\n\r\n".$json);
        }
        fseek($this->input, (int) $start);
        $server->run();

        rewind($this->output);
        $lines = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($this->output)) ?: [] as $body) {
            $message = json_decode($body, true);
            if (!\is_array($message) || 'textDocument/publishDiagnostics' !== ($message['method'] ?? null)) {
                continue;
            }

            $lines = [];
            $params = $message['params'] ?? null;
            $this->assertIsArray($params);
            $this->assertIsArray($params['diagnostics'] ?? null);
            foreach ($params['diagnostics'] as $diagnostic) {
                $this->assertIsArray($diagnostic);
                $range = $diagnostic['range'] ?? null;
                $this->assertIsArray($range);
                $this->assertIsArray($range['start'] ?? null);
                $text = $diagnostic['message'] ?? null;
                $this->assertIsString($text);
                if (str_contains($text, 'UTF-8')) {
                    $line = $range['start']['line'] ?? null;
                    $this->assertIsInt($line);
                    $lines[] = $line;
                }
            }
        }

        return $lines;
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
