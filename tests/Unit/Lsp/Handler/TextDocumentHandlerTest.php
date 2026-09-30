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

namespace RegexParser\Tests\Unit\Lsp\Handler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Lsp\Document\DocumentManager;
use RegexParser\Lsp\Document\RegexFinder;
use RegexParser\Lsp\Handler\TextDocumentHandler;
use RegexParser\Lsp\Protocol\Message;
use RegexParser\Lsp\Protocol\Response;
use RegexParser\Regex;

final class TextDocumentHandlerTest extends TestCase
{
    private DocumentManager $documents;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager(new RegexFinder());
        $handler = new TextDocumentHandler($this->documents, Regex::create());
    }

    #[Test]
    public function test_handler_can_be_instantiated(): void
    {
        $handler = new TextDocumentHandler($this->documents, Regex::create());

        $this->assertInstanceOf(TextDocumentHandler::class, $handler);
    }

    #[Test]
    public function test_handler_requires_dependencies(): void
    {
        $documents = new DocumentManager(new RegexFinder());
        $regex = Regex::create();
        $handler = new TextDocumentHandler($documents, $regex);

        $this->assertInstanceOf(TextDocumentHandler::class, $handler);
    }

    /**
     * @return iterable<string, array{pattern: string, code: string}>
     */
    public static function provideUnparsablePatterns(): iterable
    {
        yield 'parser error' => ['pattern' => '/a(/', 'code' => 'regex.group.unclosed'];
        yield 'lexer error' => ['pattern' => '/(*scs:(/', 'code' => 'regex.group_list.item_expected'];
    }

    #[Test]
    #[DataProvider('provideUnparsablePatterns')]
    public function test_a_pattern_that_fails_to_parse_is_published_with_its_error_code(string $pattern, string $code): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null])))->didOpen(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/didOpen',
                id: null,
                params: ['textDocument' => ['uri' => 'file:///a.php', 'text' => "<?php\npreg_match('".$pattern."', \$s);\n"]],
            ));
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);

        $this->assertSame('textDocument/publishDiagnostics', $payload['method'] ?? null);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);
        $this->assertIsArray($diagnostics[0] ?? null);
        $this->assertSame($code, $diagnostics[0]['code'] ?? null);
    }

    /**
     * Offsets count from the pattern body: the diagnostic starts on the
     * character at fault, past the quote and the opening delimiter.
     */
    #[Test]
    public function test_a_diagnostic_starts_on_the_character_at_fault(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/didOpen',
                id: null,
                params: ['textDocument' => ['uri' => 'file:///b.php', 'text' => "<?php\npreg_match('/ab)/', \$s);\n"]],
            ));
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);
        $this->assertIsArray($diagnostics[0] ?? null);

        // "preg_match('" is 12 characters: the "/" is at 12 and the body
        // starts at 13. PCRE2 10.49 reports the ")" past it, at offset 3.
        $this->assertSame('regex.group.unmatched_close', $diagnostics[0]['code'] ?? null);
        $range = $diagnostics[0]['range'] ?? null;
        $this->assertIsArray($range);
        $this->assertSame(['line' => 1, 'character' => 16], $range['start'] ?? null);
        // The range ends with the pattern, at the closing quote.
        $this->assertSame(['line' => 1, 'character' => 17], $range['end'] ?? null);
    }

    /**
     * A lint issue is placed from the body too: "[aa]" starts at offset 1
     * of the body "x[aa]".
     */
    #[Test]
    public function test_a_lint_issue_starts_on_the_character_it_names(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null])))->didOpen(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/didOpen',
                id: null,
                params: ['textDocument' => ['uri' => 'file:///c.php', 'text' => "<?php\npreg_match('/x[aa]/', \$s);\n"]],
            ));
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);
        $this->assertIsArray($diagnostics[0] ?? null);

        // The body starts at 13: "x" at 13, "[" at 14, marked alone.
        $this->assertSame('regex.lint.charclass.redundant', $diagnostics[0]['code'] ?? null);
        $range = $diagnostics[0]['range'] ?? null;
        $this->assertIsArray($range);
        $this->assertSame(['line' => 1, 'character' => 14], $range['start'] ?? null);
        $this->assertSame(['line' => 1, 'character' => 15], $range['end'] ?? null);
    }
}
