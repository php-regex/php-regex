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
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\TestUtils\FileUri;
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
    use InstalledLocales;
    use PcreRule;

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

        $this->root = Path::normalize(sys_get_temp_dir()).'/regex-parser-lsp-ignore-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/src', 0o700, true);
    }

    protected function tearDown(): void
    {
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

        // A marker on the call does nothing for a pattern its declaration
        // holds: it silences nothing, and is reported on its own line.
        $this->assertSame([7, 15, 17], $this->diagnosedLines($uri));
    }

    /**
     * A marker naming an identifier silences only that issue: "/\d+[11]/i"
     * also reports the "1" written twice in its class.
     */
    #[Test]
    public function test_a_marker_naming_an_identifier_silences_only_that_issue(): void
    {
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, "<?php\npreg_match('/\\d+[11]/i', \$s); // @regex-ignore regex.lint.flag.useless.i\n"));

        $this->assertSame([[1, 'regex.lint.charclass.redundant', 2]], $this->diagnosed($uri));
    }

    /**
     * A marker that silences nothing is a warning on the marker itself; an
     * identifier that silences nothing too, named in the message.
     */
    #[Test]
    public function test_a_marker_that_silences_nothing_is_a_warning_on_the_marker(): void
    {
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, "<?php\n\$kept = 1; // @regex-ignore\npreg_match('/(a/', \$s); // @regex-ignore regex.lint.no.such.rule\npreg_match('/x/', \$s); // @regex-ignore regex.lint.redos, regex.optimization, regex.lint.charclass.single\n"));

        $this->assertSame([
            [1, 'regex.ignore.unmatched', 2],
            [2, 'regex.group.unclosed', 1],
            [2, 'regex.ignore.unmatched', 2],
        ], $this->diagnosed($uri));
        $unmatched = array_values(array_filter($this->published($uri), static fn (array $diagnostic): bool => 'regex.ignore.unmatched' === ($diagnostic['code'] ?? null)));
        $this->assertCount(2, $unmatched);
        $this->assertSame(['start' => ['line' => 1, 'character' => 14], 'end' => ['line' => 1, 'character' => 27]], $unmatched[0]['range'] ?? null);
        $this->assertSame('The @regex-ignore marker silences nothing: no issue is reported on line 2. Remove it.', $unmatched[0]['message'] ?? null);
        $this->assertSame('The @regex-ignore marker names regex.lint.no.such.rule, which no issue reports on line 3. Remove the identifier.', $unmatched[1]['message'] ?? null);
    }

    /**
     * "@regex-target" narrows the versions a pattern is judged for, as in
     * `regex lint`: under composer.json's "^8.2", "(?a)" (which PHP 8.2
     * and 8.3 refuse) is judged for 8.4 alone under "php>=8.4"; a marker
     * that keeps no version of the range is an error on the marker, and so
     * is one that cannot be read.
     */
    #[Test]
    public function test_a_target_marker_narrows_the_versions_judged(): void
    {
        $this->requiresBundledPcre2Within(80200, 80700, __FUNCTION__);
        file_put_contents($this->root.'/composer.json', '{"require": {"php": "^8.2"}}');
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, <<<'CODE'
            <?php
            preg_match('/(?a)\d+/', $s); // @regex-target php>=8.4
            preg_match('/(?a)\d+/', $s);
            preg_match('/(a/', $s); // @regex-target php>=9.0
            preg_match('/(a/', $s); // @regex-target php>=eight
            preg_match('/(?a)\d+/', $s); // @regex-target php<8.4
            CODE));

        $this->assertSame([
            [2, 'regex.group.syntax', 1],
            [3, 'regex.target.empty', 1],
            [4, 'regex.group.unclosed', 1],
            [4, 'regex.target.invalid', 1],
            [5, 'regex.group.syntax', 1],
        ], $this->diagnosed($uri));
        $empty = array_values(array_filter($this->published($uri), static fn (array $diagnostic): bool => 'regex.target.empty' === ($diagnostic['code'] ?? null)));
        $this->assertSame(['start' => ['line' => 3, 'character' => 27], 'end' => ['line' => 3, 'character' => 40]], $empty[0]['range'] ?? null);
    }

    /**
     * The versions a marker keeps are judged under the locale the editor
     * names: "(?:[\Wa-zA-Z0-9_]){8000}" is too large under fr_FR.ISO8859-1,
     * where "\W" leaves the Latin-1 letters out, and compiles to 56007 units
     * under C (pcre2test 10.49 with "locale=", PHP 8.4.26 under setlocale()).
     */
    #[Test]
    public function test_a_target_marker_keeps_the_locale_named(): void
    {
        $this->requiresLocale('fr_FR.ISO8859-1', 'The language server under a Latin-1 locale');
        file_put_contents($this->root.'/composer.json', '{"require": {"php": "^8.2"}}');
        $uri = $this->uri('src/Matcher.php');
        $code = <<<'CODE'
            <?php
            preg_match('/(?:[\Wa-zA-Z0-9_]){8000}/', $s); // @regex-target php>=8.4
            preg_match('/(?:[\Wa-zA-Z0-9_]){8000}/', $s);
            CODE;

        $this->initialize(['pcreVersion' => '10.49', 'locale' => 'fr_FR.ISO8859-1']);
        $this->send($this->didOpen($uri, $code));

        $this->assertSame([[1, 'regex.pattern.too_large', 1], [2, 'regex.pattern.too_large', 1]], $this->diagnosed($uri));

        $this->initialize(['pcreVersion' => '10.49', 'locale' => 'C']);
        $this->send($this->didOpen($uri, $code));

        $this->assertSame([], array_values(array_filter($this->diagnosed($uri), static fn (array $diagnostic): bool => 'regex.pattern.too_large' === $diagnostic[1])));
    }

    /**
     * A locale regex.json names that this machine cannot set leaves the
     * session without a target: the editor is told, and nothing is judged.
     */
    #[Test]
    public function test_a_locale_the_machine_cannot_set_judges_nothing(): void
    {
        file_put_contents($this->root.'/regex.json', '{"locale": "xx_YY.NOWHERE"}');
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, "<?php\npreg_match('/(a/', \$s);\n"));

        $messages = array_column($this->notifications('window/showMessage'), 'message');
        $this->assertCount(1, $messages);
        $this->assertIsString($messages[0]);
        $this->assertStringContainsString('"locale" names "xx_YY.NOWHERE"', (string) $messages[0]);
        $this->assertStringEndsWith('No pattern is judged.', $messages[0]);
        $this->assertSame([], $this->notifications('textDocument/publishDiagnostics'));
    }

    /**
     * A guard on PHP_VERSION_ID narrows the versions a pattern is judged
     * for, as in `regex lint`, which reads the same tokens.
     */
    #[Test]
    public function test_a_guard_narrows_the_versions_judged(): void
    {
        $this->requiresBundledPcre2Within(80200, 80700, __FUNCTION__);
        file_put_contents($this->root.'/composer.json', '{"require": {"php": "^8.2"}}');
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, <<<'CODE'
            <?php
            if (PHP_VERSION_ID >= 80400) {
                preg_match('/(?a)\d+/', $s);
            }
            preg_match('/(?a)\d+/', $s);
            if (PHP_VERSION_ID >= 80300) {
                preg_match('/(?a)\d+/', $s); // @regex-target php<8.4
                preg_match('/(?a)\d+/', $s); // @regex-target php>=8.4
            }
            CODE));

        $this->assertSame([[4, 'regex.group.syntax', 1], [6, 'regex.group.syntax', 1]], $this->diagnosed($uri));
    }

    #[Test]
    public function test_regex_json_turns_the_unmatched_report_off(): void
    {
        file_put_contents($this->root.'/regex.json', '{"reportUnmatchedIgnores": false}');
        $uri = $this->uri('src/Matcher.php');

        $this->initialize();
        $this->send($this->didOpen($uri, "<?php\n\$kept = 1; // @regex-ignore\n"));

        $this->assertSame([], $this->diagnosedLines($uri));
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
     * The line, code and severity of each diagnostic last published, sorted.
     *
     * @return list<array{int, mixed, mixed}>
     */
    private function diagnosed(string $uri): array
    {
        $diagnosed = [];
        foreach ($this->published($uri) as $diagnostic) {
            $range = $diagnostic['range'] ?? null;
            $this->assertIsArray($range);
            $start = $range['start'] ?? null;
            $this->assertIsArray($start);
            $line = $start['line'] ?? null;
            $this->assertIsInt($line);
            $diagnosed[] = [$line, $diagnostic['code'] ?? null, $diagnostic['severity'] ?? null];
        }
        sort($diagnosed);

        return $diagnosed;
    }

    /**
     * The diagnostics last published for a document, in the order sent.
     *
     * @return list<array<array-key, mixed>>
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
     * @param array<string, string> $options the initializationOptions
     */
    private function initialize(array $options = []): void
    {
        $this->server = new Server(null, $this->input, transport: new Transport($this->output));
        $this->send(
            ['id' => 1, 'method' => 'initialize', 'params' => ['rootUri' => FileUri::of($this->root)] + ([] === $options ? [] : ['initializationOptions' => $options])],
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
