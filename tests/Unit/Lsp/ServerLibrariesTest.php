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

use PHPRegex\LanguageServer\Document\PatternDeclarations;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\LanguageServer\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The libraries of vendor/ are read for their declarations at "initialize",
 * and again when the editor reports one of their files changed.
 *
 * "/(a/" is the broken pattern: preg_match() refuses it, "missing closing
 * parenthesis at offset 2".
 */
final class ServerLibrariesTest extends TestCase
{
    private const DECLARATIONS = <<<'CODE'
        <?php

        namespace Acme\Support;

        use PHPRegex\Parser\Attribute\RegexPattern;

        final class Str
        {
            public static function matches(string $subject, #[RegexPattern] string $regex): bool
            {
                return true;
            }
        }
        CODE;

    private const CALLS = <<<'CODE'
        <?php

        namespace App\Http;

        use Acme\Support\Str;

        Str::matches($subject, '/(a/');
        CODE;

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

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-libraries-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        Response::writeTo(null);
        fclose($this->output);
        fclose($this->input);

        self::remove($this->root);
    }

    /**
     * A package installed while the editor runs: Composer rewrites
     * vendor/composer/installed.json, and the libraries are read again.
     */
    #[Test]
    public function test_a_package_installed_later_is_read(): void
    {
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->write('vendor/acme/support/src/Str.php', self::DECLARATIONS);
        $this->write('vendor/composer/installed.json', '{"packages": []}');
        $this->send(['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [['uri' => $this->uri('vendor/composer/installed.json'), 'type' => 2]]]]);

        $this->assertSame([6], $this->diagnosedLines($calls));
    }

    #[Test]
    public function test_libraries_too_large_to_read_whole_are_reported(): void
    {
        $this->write('vendor/a/A.php', '<?php');
        $this->write('vendor/b/B.php', '<?php');

        $this->initialize(new PatternDeclarations(1));

        $warnings = array_filter(
            $this->notifications('window/logMessage'),
            static fn (array $params): bool => 2 === ($params['type'] ?? null) && \is_string($params['message'] ?? null) && str_contains($params['message'], 'vendor/'),
        );
        $this->assertCount(1, $warnings);
    }

    private function initialize(?PatternDeclarations $declarations = null): void
    {
        $this->server = new Server(null, $this->input, $declarations);
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
