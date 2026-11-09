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
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\TestUtils\FileUri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A call naming a constant of another file of the workspace, as
 * `preg_match(Patterns::BROKEN, $s)`, is read through the constants of the
 * workspace, as `regex lint` reads it. The lint reports the pattern at its
 * literal; the server, showing the file that calls, reports it at the
 * argument, naming the constant and where it is written. The constants
 * follow the files on the disk and the open documents that stand for them.
 *
 * "/(a/" is the broken pattern: preg_match() refuses it, "missing closing
 * parenthesis at offset 2", and the library says "Expected ) at end of
 * input".
 */
final class ServerConstantsOfOtherFilesTest extends TestCase
{
    private const PATTERNS = <<<'CODE'
        <?php

        namespace App\Support;

        final class Patterns
        {
            public const EMAIL = '/^[^@]+@[^@]+$/';

            public const BROKEN = '/(a/';
        }
        CODE;

    private const CALLS = <<<'CODE'
        <?php

        namespace App\Http;

        use App\Support\Patterns;

        $ok = preg_match(Patterns::EMAIL, $subject);
        $broken = preg_match(Patterns::BROKEN, $subject);
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

        $this->root = Path::fromHost(sys_get_temp_dir()).'/regex-parser-lsp-constants-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
        fclose($this->output);
        fclose($this->input);

        self::remove($this->root);
    }

    #[Test]
    public function test_a_pattern_of_another_file_is_reported_at_the_argument_naming_it(): void
    {
        $this->assertFalse(EngineProbe::compiles('/(a/'), 'Oracle: PCRE refuses the broken pattern.');
        $this->assertTrue(EngineProbe::compiles('/^[^@]+@[^@]+$/'), 'Oracle: PCRE compiles the other one.');
        $this->write('src/Support/Patterns.php', self::PATTERNS);

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS));

        $diagnostics = $this->published($this->uri('src/Http/Controller.php'));
        $this->assertCount(1, $diagnostics);
        $this->assertSame(['start' => ['line' => 7, 'character' => 21], 'end' => ['line' => 7, 'character' => 37]], $diagnostics[0]['range'] ?? null);
        $this->assertSame(1, $diagnostics[0]['severity'] ?? null);
        $this->assertSame('Patterns::BROKEN, written in src/Support/Patterns.php on line 9: Expected ) at end of input (found eof).', $diagnostics[0]['message'] ?? null);
    }

    /**
     * The lint reports the pattern once, at its literal, whatever calls
     * name it: so does the file that writes it, and a document naming it
     * twice reports it at the first argument.
     */
    #[Test]
    public function test_a_pattern_named_twice_is_reported_at_the_first_argument(): void
    {
        $this->write('src/Support/Patterns.php', self::PATTERNS);

        $this->initialize();
        $this->send($this->didOpen($this->uri('src/Http/Controller.php'), self::CALLS."\n\$again = preg_match(Patterns::BROKEN, \$other);\n"));

        $this->assertSame([7], $this->diagnosedLines($this->uri('src/Http/Controller.php')));
    }

    #[Test]
    public function test_a_change_of_the_other_file_on_the_disk_is_followed(): void
    {
        $this->write('src/Support/Patterns.php', self::PATTERNS);
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));
        $this->assertSame([7], $this->diagnosedLines($calls));

        $this->write('src/Support/Patterns.php', str_replace("'/(a/'", "'/(a)/'", self::PATTERNS));
        $this->send($this->watched($this->uri('src/Support/Patterns.php'), 2));
        $this->assertSame([], $this->diagnosedLines($calls));

        unlink($this->root.'/src/Support/Patterns.php');
        $this->write('src/Support/Moved.php', str_replace('class Patterns', 'class Moved', self::PATTERNS));
        $this->send($this->watched($this->uri('src/Support/Patterns.php'), 3), $this->watched($this->uri('src/Support/Moved.php'), 1));
        $this->assertSame([], $this->diagnosedLines($calls), 'A class no file declares any more names no pattern.');
    }

    /**
     * An open document stands for its file: an edit to the constant, not
     * saved yet, is read by the documents that name it.
     */
    #[Test]
    public function test_an_open_document_stands_for_the_file_declaring_the_constant(): void
    {
        $this->write('src/Support/Patterns.php', self::PATTERNS);
        $calls = $this->uri('src/Http/Controller.php');
        $patterns = $this->uri('src/Support/Patterns.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS), $this->didOpen($patterns, self::PATTERNS));
        $this->assertSame([7], $this->diagnosedLines($calls));

        $this->send($this->didChange($patterns, str_replace("'/(a/'", "'/(a)/'", self::PATTERNS)));
        $this->assertSame([], $this->diagnosedLines($calls));
    }

    /**
     * The quick fixes and the rewrites edit a literal: the argument holds
     * none, and gets none.
     */
    #[Test]
    public function test_an_argument_naming_a_pattern_gets_no_code_action(): void
    {
        $this->write('src/Support/Patterns.php', str_replace("'/(a/'", "'/[0-9]/'", self::PATTERNS));
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send(
            $this->didOpen($calls, self::CALLS),
            ['id' => 2, 'method' => 'textDocument/codeAction', 'params' => [
                'textDocument' => ['uri' => $calls],
                'range' => ['start' => ['line' => 7, 'character' => 25], 'end' => ['line' => 7, 'character' => 25]],
                'context' => ['diagnostics' => []],
            ]],
        );

        $this->assertSame([], $this->response(2));
    }

    /**
     * The markers of the document read the argument's line: "@regex-ignore"
     * there silences the pattern, and is no marker that silences nothing.
     */
    #[Test]
    public function test_a_marker_on_the_line_of_the_argument_silences_it(): void
    {
        $this->write('src/Support/Patterns.php', self::PATTERNS);
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, str_replace('$broken = preg_match(Patterns::BROKEN, $subject);', '$broken = preg_match(Patterns::BROKEN, $subject); // @regex-ignore', self::CALLS)));

        $this->assertSame([], $this->diagnosedLines($calls));
    }

    /**
     * Closed, a document hands the constants back to its file on the disk.
     */
    #[Test]
    public function test_a_closed_document_hands_the_constants_back_to_its_file(): void
    {
        $this->write('src/Support/Patterns.php', self::PATTERNS);
        $calls = $this->uri('src/Http/Controller.php');
        $patterns = $this->uri('src/Support/Patterns.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS), $this->didOpen($patterns, str_replace("'/(a/'", "'/(a)/'", self::PATTERNS)));
        $this->assertSame([], $this->diagnosedLines($calls));

        $this->send(['method' => 'textDocument/didClose', 'params' => ['textDocument' => ['uri' => $patterns]]]);
        $this->assertSame([7], $this->diagnosedLines($calls));
    }

    /**
     * The libraries in vendor/ declare constants too, as `regex lint` reads
     * them.
     */
    #[Test]
    public function test_a_constant_of_a_library_is_read(): void
    {
        $this->write('vendor/acme/regex/src/Patterns.php', str_replace('App\\Support', 'Acme\\Regex', self::PATTERNS));
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, "<?php\n\npreg_match(\\Acme\\Regex\\Patterns::BROKEN, \$subject);\n"));

        $diagnostics = $this->published($calls);
        $this->assertCount(1, $diagnostics);
        $this->assertSame(['start' => ['line' => 2, 'character' => 11], 'end' => ['line' => 2, 'character' => 39]], $diagnostics[0]['range'] ?? null);
        $this->assertSame('\Acme\Regex\Patterns::BROKEN, written in vendor/acme/regex/src/Patterns.php on line 9: Expected ) at end of input (found eof).', $diagnostics[0]['message'] ?? null);
    }

    /**
     * An edit inside a literal of the document is read in place: the
     * argument after it on its line moves with the edit.
     */
    #[Test]
    public function test_an_argument_after_a_literal_edited_in_place_moves_with_it(): void
    {
        $this->write('src/Support/Patterns.php', self::PATTERNS);
        $calls = $this->uri('src/Http/Controller.php');
        $line = "<?php\n\npreg_match('/x/', \$a) && preg_match(Patterns::BROKEN, \$s);\n";

        $this->initialize();
        $this->send($this->didOpen($calls, str_replace('<?php', "<?php\nuse App\\Support\\Patterns;", $line)));
        $this->assertSame([['line' => 3, 'character' => 36]], array_map(static fn (array $diagnostic): mixed => \is_array($diagnostic['range'] ?? null) ? $diagnostic['range']['start'] ?? null : null, $this->published($calls)));

        $this->send($this->didChange($calls, str_replace(['<?php', "'/x/'"], ["<?php\nuse App\\Support\\Patterns;", "'/xyz/'"], $line)));
        $this->assertSame([['line' => 3, 'character' => 38]], array_map(static fn (array $diagnostic): mixed => \is_array($diagnostic['range'] ?? null) ? $diagnostic['range']['start'] ?? null : null, $this->published($calls)));
    }

    /**
     * A constant that holds no delimited pattern is no pattern the server
     * reads, as a literal holding none is not; a pattern of the document
     * beside it is reported at its literal, as before.
     */
    #[Test]
    public function test_only_a_delimited_pattern_of_another_file_is_read(): void
    {
        $this->write('src/Support/Patterns.php', str_replace("'/(a/'", "'plain text'", self::PATTERNS));
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS."\npreg_match('/(b/', \$subject);\n"));

        $this->assertSame([8], $this->diagnosedLines($calls));
    }

    /**
     * The literal of another file spells its characters its own way: a
     * raw invisible character is that file's to report.
     */
    #[Test]
    public function test_an_invisible_character_of_another_file_is_that_files_to_report(): void
    {
        $this->write('src/Support/Patterns.php', str_replace("'/(a/'", "'/a\u{200B}b/'", self::PATTERNS));
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));

        $this->assertSame([], $this->diagnosedLines($calls));
    }

    /**
     * The raw character of another file is left to that file; the issue
     * after it in the pattern is still reported at the call.
     */
    #[Test]
    public function test_an_invisible_character_of_another_file_keeps_the_issues_after_it(): void
    {
        $this->write('src/Support/Patterns.php', str_replace("'/(a/'", "'/a\u{200B}b{1}/'", self::PATTERNS));
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send($this->didOpen($calls, self::CALLS));

        $this->assertSame([7], $this->diagnosedLines($calls));
    }

    #[Test]
    public function test_hovering_the_argument_shows_the_pattern_and_where_it_is_written(): void
    {
        $this->write('src/Support/Patterns.php', self::PATTERNS);
        $calls = $this->uri('src/Http/Controller.php');

        $this->initialize();
        $this->send(
            $this->didOpen($calls, self::CALLS),
            ['id' => 2, 'method' => 'textDocument/hover', 'params' => ['textDocument' => ['uri' => $calls], 'position' => ['line' => 6, 'character' => 25]]],
            ['id' => 3, 'method' => 'textDocument/completion', 'params' => ['textDocument' => ['uri' => $calls], 'position' => ['line' => 6, 'character' => 25]]],
        );

        $hover = $this->response(2);
        $this->assertIsArray($hover);
        $this->assertSame(['start' => ['line' => 6, 'character' => 17], 'end' => ['line' => 6, 'character' => 32]], $hover['range'] ?? null);
        $contents = $hover['contents'] ?? null;
        $this->assertIsArray($contents);
        $value = $contents['value'] ?? null;
        $this->assertIsString($value);
        $this->assertStringContainsString("```\n/^[^@]+@[^@]+$/\n```\n\nPatterns::EMAIL, written in src/Support/Patterns.php on line 7\n\n**Explanation**", (string) $value);
        $this->assertNull($this->response(3), 'An argument holds no pattern text to complete.');
    }

    /**
     * The diagnostics last published for a document.
     *
     * @return list<array<mixed>>
     */
    private function published(string $uri): array
    {
        $published = null;
        foreach ($this->notifications('textDocument/publishDiagnostics') as $params) {
            if ($uri === ($params['uri'] ?? null) && \is_array($params['diagnostics'] ?? null)) {
                $published = $params['diagnostics'];
            }
        }
        $this->assertIsArray($published, 'The server published no diagnostics for '.$uri.'.');

        $diagnostics = [];
        foreach ($published as $diagnostic) {
            $this->assertIsArray($diagnostic);
            $diagnostics[] = $diagnostic;
        }

        return $diagnostics;
    }

    private function response(int $id): mixed
    {
        rewind($this->output);
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($this->output)) ?: [] as $body) {
            $message = json_decode($body, true);
            if (\is_array($message) && $id === ($message['id'] ?? null)) {
                return $message['result'] ?? null;
            }
        }

        self::fail('The server gave no answer to request '.$id.'.');
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
