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
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\LanguageServer\Server;
use PHPRegex\Parser\Text\Path;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The language server reads the calls `regex lint` reads: regex.json's
 * "extraction.interop" and "extraction.functions" name them, every preset
 * when it says nothing, and a pattern the file proves is diagnosed at its
 * literal.
 *
 * "/(a/" is the broken pattern: preg_match() refuses it, "missing closing
 * parenthesis at offset 2".
 */
final class ServerExtractionConfigTest extends TestCase
{
    private const CALLS = <<<'CODE'
        <?php

        namespace App\Http;

        use Nette\Utils\Strings;
        use Webmozart\Assert\Assert;

        Strings::match($subject, '/(a/');
        Assert::regex($subject, '/(a/');
        \App\grep([], '/(a/');
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

        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-extraction-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->input);

        self::remove($this->root);
    }

    #[Test]
    public function test_every_preset_is_read_without_regex_json(): void
    {
        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS));

        $this->assertSame([7, 8], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    #[Test]
    public function test_regex_json_extraction_names_the_presets_and_functions(): void
    {
        $this->write('regex.json', (string) json_encode(['extraction' => ['interop' => ['nette-utils'], 'functions' => ['App\\grep#1']]]));

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS));

        $this->assertSame([7, 9], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    #[Test]
    public function test_an_empty_interop_reads_the_native_calls_only(): void
    {
        $this->write('regex.json', (string) json_encode(['extraction' => ['interop' => []]]));

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS."\npreg_match('/(a/', \$subject);\n\\Composer\\Pcre\\Preg::match('/(a/', \$subject);\n"));

        $this->assertSame([10], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    /**
     * An unknown preset makes regex.json an error, shown to the user and
     * naming the preset it most likely means; the server reads as without
     * the file, every preset included, rather than a list that silently
     * reads fewer calls than meant.
     */
    #[Test]
    public function test_an_unknown_preset_is_shown_as_an_error_and_no_preset_is_lost(): void
    {
        $this->write('regex.json', (string) json_encode(['extraction' => ['interop' => ['laravel-str']]]));

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS));

        $errors = array_values(array_filter(
            $this->notifications('window/showMessage'),
            static fn (array $params): bool => 1 === ($params['type'] ?? null) && \is_string($params['message'] ?? null) && str_contains($params['message'], 'unknown preset "laravel-str"; did you mean "illuminate-support"?'),
        ));
        $this->assertCount(1, $errors);
        $message = $errors[0]['message'];
        $this->assertIsString($message);
        $this->assertStringStartsWith('regex.json ignored: ', $message);
        $this->assertSame([7, 8], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    /**
     * The diagnostic of a pattern the file proves lands on its literal.
     */
    #[Test]
    public function test_a_resolved_pattern_is_diagnosed_at_its_declaration(): void
    {
        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Matcher.php'), <<<'CODE'
            <?php

            final class Matcher
            {
                private const RE = '/(a/';

                public function run(string $s): bool
                {
                    return 1 === preg_match(self::RE, $s);
                }
            }
            CODE));

        $this->assertSame([4], $this->diagnosedLines($this->uri('src/Matcher.php')));
    }

    private function initialize(?PatternDeclarations $declarations = null): void
    {
        $this->server = new Server(null, $this->input, $declarations, transport: new Transport($this->output));
        $this->send(
            ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => FileUri::of($this->root)]],
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
        return FileUri::of($this->root.'/'.$relative);
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
