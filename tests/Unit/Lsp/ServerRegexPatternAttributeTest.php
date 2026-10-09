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
 * The language server reads a call to a function or static method that
 * declares a parameter with #[RegexPattern], as `regex lint` does: the
 * declarations come from the PHP files of the workspace, read at
 * "initialize" within regex.json's "paths" and "exclude", and from the open
 * documents, read on every change.
 *
 * "/(a/" is the broken pattern: preg_match() refuses it, "missing closing
 * parenthesis at offset 2".
 */
final class ServerRegexPatternAttributeTest extends TestCase
{
    private const DECLARATIONS = <<<'CODE'
        <?php

        namespace App\Support;

        use PHPRegex\Parser\Attribute\RegexPattern;

        final class Str
        {
            public static function matches(string $subject, #[RegexPattern] string $regex): bool
            {
                return 1 === preg_match($regex, $subject);
            }

            public function onInstance(#[RegexPattern] string $regex): void
            {
            }
        }
        CODE;

    private const CALLS = <<<'CODE'
        <?php

        namespace App\Http;

        use App\Support\Str;

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

        $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/regex-parser-lsp-attribute-'.bin2hex(random_bytes(6));
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
    public function test_a_function_declared_in_the_same_document_is_read(): void
    {
        $document = <<<'CODE'
            <?php

            namespace App;

            use PHPRegex\Parser\Attribute\RegexPattern;

            function grep(#[RegexPattern] string $regex, array $lines): array
            {
                return [];
            }

            final class Str
            {
                public static function matches(string $subject, #[RegexPattern] string $regex): bool
                {
                    return true;
                }
            }

            grep('/(a/', []);
            Str::matches($subject, '/(a/');
            CODE;

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Grep.php'), $document));

        $this->assertSame([19, 20], $this->diagnosedLines($this->uri('src/Grep.php')));
    }

    #[Test]
    public function test_a_function_declared_in_another_workspace_file_is_read(): void
    {
        $this->write('src/Support/Str.php', self::DECLARATIONS);

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS));

        $this->assertSame([6], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    /**
     * An instance call names no class the server can know.
     */
    #[Test]
    public function test_an_instance_call_is_not_read(): void
    {
        $this->write('src/Support/Str.php', self::DECLARATIONS);

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), <<<'CODE'
            <?php

            namespace App\Http;

            use App\Support\Str;

            (new Str())->onInstance('/(a/');
            $str->matches($subject, '/(a/');
            CODE));

        $this->assertSame([], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    #[Test]
    public function test_a_declaration_regex_json_leaves_out_is_not_read(): void
    {
        $this->write('regex.json', (string) json_encode(['paths' => ['src'], 'exclude' => ['legacy']]));
        $this->write('lib/Support/Str.php', self::DECLARATIONS);
        $this->write('src/legacy/Support/Str.php', self::DECLARATIONS);

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS));

        $this->assertSame([], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    /**
     * "paths" and "exclude" may each be one string.
     */
    #[Test]
    public function test_regex_json_may_name_one_path_and_one_exclusion(): void
    {
        $this->write('regex.json', (string) json_encode(['paths' => 'src', 'exclude' => 'legacy']));
        $this->write('src/Support/Str.php', self::DECLARATIONS);
        $this->write('src/legacy/Other.php', str_replace('class Str', 'class Other', self::DECLARATIONS));

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS."\nApp\\Support\\Other::matches(\$subject, '/(b/');\n"));

        $this->assertSame([6], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    /**
     * Without regex.json the whole root is read, but vendor/.
     */
    #[Test]
    public function test_vendor_is_left_out_by_default(): void
    {
        $this->write('vendor/acme/Support/Str.php', self::DECLARATIONS);

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS));

        $this->assertSame([], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    /**
     * The declarations of a document count from the moment it opens, and as
     * it changes: the other open documents are published again.
     */
    #[Test]
    public function test_a_declaration_typed_in_one_document_reaches_the_others(): void
    {
        $calls = $this->uri('src/Http/Controller.php');
        $declaring = $this->uri('src/Support/Str.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->send($this->didOpen($declaring, self::DECLARATIONS));
        $this->assertSame([6], $this->diagnosedLines($calls));

        $this->send($this->didChange($declaring, str_replace('#[RegexPattern] ', '', self::DECLARATIONS)));
        $this->assertSame([], $this->diagnosedLines($calls));
    }

    /**
     * Closing a document hands its file back to the disk: an unsaved edit
     * is gone, a saved one stays.
     */
    #[Test]
    public function test_a_closed_document_leaves_the_declarations_of_its_saved_file(): void
    {
        $calls = $this->uri('src/Http/Controller.php');
        $declaring = $this->uri('src/Support/Str.php');
        $this->write('src/Support/Str.php', self::DECLARATIONS);
        $withoutAttribute = str_replace('#[RegexPattern] ', '', self::DECLARATIONS);

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->send($this->didOpen($declaring, self::DECLARATIONS));
        $this->send($this->didChange($declaring, $withoutAttribute));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->send(['method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => $declaring]]]);
        $this->assertSame([6], $this->diagnosedLines($calls), 'The edit was not saved.');

        $this->send($this->didOpen($declaring, self::DECLARATIONS));
        $this->send($this->didChange($declaring, $withoutAttribute));
        $this->send(['method' => 'textDocument/didSave', 'params' => ['textDocument' => ['uri' => $declaring], 'text' => $withoutAttribute]]);
        $this->send(['method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => $declaring]]]);
        $this->assertSame([], $this->diagnosedLines($calls), 'The saved text holds no declaration.');
    }

    /**
     * A file created, changed or deleted outside the editor is read again
     * when the editor reports it.
     */
    #[Test]
    public function test_a_watched_file_change_is_read(): void
    {
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->write('src/Support/Str.php', self::DECLARATIONS);
        $this->send($this->watched($this->uri('src/Support/Str.php'), 1));
        $this->assertSame([6], $this->diagnosedLines($calls));

        unlink($this->root.'/src/Support/Str.php');
        $this->send(
            ['method' => 'workspace/didChangeWatchedFiles', 'params' => []],
            $this->watched('untitled:Untitled-1', 1),
            $this->watched($this->uri('src/Support/Str.php'), 3),
        );
        $this->assertSame([], $this->diagnosedLines($calls));
    }

    #[Test]
    public function test_a_workspace_too_large_to_read_whole_is_reported(): void
    {
        $this->write('src/A.php', '<?php');
        $this->write('src/B.php', '<?php');

        $this->initialize(new PatternDeclarations(1));

        $warnings = array_filter(
            $this->notifications('window/logMessage'),
            static fn (array $params): bool => 2 === ($params['type'] ?? null) && \is_string($params['message'] ?? null) && str_contains($params['message'], '#[RegexPattern]'),
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

    /**
     * @return array<string, mixed>
     */
    private function watched(string $uri, int $type): array
    {
        return ['method' => 'workspace/didChangeWatchedFiles', 'params' => ['changes' => [['uri' => $uri, 'type' => $type]]]];
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
