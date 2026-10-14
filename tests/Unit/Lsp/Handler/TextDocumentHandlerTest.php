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

namespace PHPRegex\Tests\Unit\Lsp\Handler;

use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Handler\TextDocumentHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextDocumentHandlerTest extends TestCase
{
    private DocumentManager $documents;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager(new RegexFinder());
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
            // A substring scan is read from PCRE2 10.45: judged for 10.49.
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
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
     * Hovering a pattern past a limit says it was not analyzed, not that it
     * holds an error.
     */
    #[Test]
    public function test_hovering_a_pattern_past_a_limit_says_it_was_not_analyzed(): void
    {
        $handler = new TextDocumentHandler($this->documents, Regex::create(['cache' => null, 'max_recursion_depth' => 3]));
        $this->documents->open('file:///a.php', "<?php\npreg_match('/((((a))))/', \$s);\n");

        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            $handler->hover(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/hover',
                id: 1,
                params: ['textDocument' => ['uri' => 'file:///a.php'], 'position' => ['line' => 1, 'character' => 15]],
            ));
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $result = $payload['result'] ?? null;
        $this->assertIsArray($result);
        $contents = $result['contents'] ?? null;
        $this->assertIsArray($contents);
        $this->assertSame("**Not analyzed**\n\nRecursion limit of 3 exceeded", $contents['value'] ?? null);
    }

    /**
     * A limit of the library is no error in the pattern: the editor shows
     * it as information, the pattern not analyzed, never as an error.
     */
    #[Test]
    public function test_a_pattern_past_a_limit_is_published_as_not_analyzed(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null, 'max_recursion_depth' => 3])))->didOpen(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/didOpen',
                id: null,
                params: ['textDocument' => ['uri' => 'file:///a.php', 'text' => "<?php\npreg_match('/((((a))))/', \$s);\n"]],
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
        $this->assertCount(1, $diagnostics);
        $this->assertIsArray($diagnostics[0]);
        $this->assertSame('regex.nesting.too_deep', $diagnostics[0]['code'] ?? null);
        $this->assertSame(3, $diagnostics[0]['severity'] ?? null, 'Information, not Error.');
        $this->assertIsString($diagnostics[0]['message'] ?? null);
        $this->assertStringStartsWith('Not analyzed: ', $diagnostics[0]['message']);
    }

    /**
     * A pattern that parses yet fails validation is published with the
     * validator's error, and no lint issue beside it.
     *
     * @return iterable<string, array{pattern: string, code: string, character: int}>
     */
    public static function provideInvalidPatterns(): iterable
    {
        // "preg_match('/" is 13 characters: the body starts at 13.
        yield 'unbounded lookbehind' => ['pattern' => '/(?<=a+)b/', 'code' => 'regex.lookbehind.unbounded', 'character' => 13];
        yield 'reference to a missing name' => ['pattern' => '/\\k<n>/', 'code' => 'regex.backref.missing_named_group', 'character' => 16];
    }

    #[Test]
    #[DataProvider('provideInvalidPatterns')]
    public function test_a_pattern_that_fails_validation_is_published_with_its_error_code(string $pattern, string $code, int $character): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/didOpen',
                id: null,
                params: ['textDocument' => ['uri' => 'file:///c.php', 'text' => "<?php\npreg_match('".$pattern."', \$s);\n"]],
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
        $this->assertCount(1, $diagnostics);
        $this->assertIsArray($diagnostics[0]);
        $this->assertSame($code, $diagnostics[0]['code'] ?? null);
        $range = $diagnostics[0]['range'] ?? null;
        $this->assertIsArray($range);
        $this->assertSame(['line' => 1, 'character' => $character], $range['start'] ?? null);
    }

    /**
     * The fix the validation names reads on the line under the error, once:
     * a near miss the message already suggests is not repeated.
     *
     * @return iterable<string, array{pattern: string, message: string}>
     */
    public static function provideMistakesWithAFix(): iterable
    {
        yield 'parse error' => ['pattern' => '/[abc/', 'message' => "Unclosed character class \"]\" at end of input.\nClose the class with \"]\", or write \"\\[\" to match a literal \"[\"."];
        yield 'validation error' => ['pattern' => '/a{2,1}/', 'message' => "Invalid quantifier range \"{2,1}\": min > max.\nWrite the smaller count first: \"{1,2}\"."];
        yield 'near miss already suggested' => ['pattern' => '/(?<name>a)\\k<nam>/', 'message' => 'Backreference to non-existent named group: "nam". Did you mean: name?'];
    }

    #[Test]
    #[DataProvider('provideMistakesWithAFix')]
    public function test_an_invalid_pattern_is_published_with_its_fix(string $pattern, string $message): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/didOpen',
                id: null,
                params: ['textDocument' => ['uri' => 'file:///fix.php', 'text' => "<?php\npreg_match('".$pattern."', \$s);\n"]],
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
        $this->assertCount(1, $diagnostics);
        $this->assertIsArray($diagnostics[0]);
        $this->assertSame($message, $diagnostics[0]['message'] ?? null);
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

    /**
     * A double-quoted "\t" reaches the pattern as a raw tab, though the
     * document spells it: the raw character is reported only where the
     * literal holds it as written. The code is built with PHP's own escapes,
     * so that this file holds no raw tab.
     *
     * @return iterable<string, array{literal: string, reported: bool}>
     */
    public static function provideTabSpellings(): iterable
    {
        yield 'a raw tab in single quotes' => ['literal' => "'/a\tb/'", 'reported' => true];
        yield 'a tab escape in double quotes' => ['literal' => '"/a\tb/"', 'reported' => false];
    }

    #[Test]
    #[DataProvider('provideTabSpellings')]
    public function test_a_raw_character_is_published_only_where_the_document_holds_it_raw(string $literal, bool $reported): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new TextDocumentHandler($this->documents, Regex::create(['cache' => null])))->didOpen(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/didOpen',
                id: null,
                params: ['textDocument' => ['uri' => 'file:///d.php', 'text' => "<?php\npreg_match(".$literal.", \$s);\n"]],
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

        $codes = array_map(static fn (mixed $diagnostic): mixed => \is_array($diagnostic) ? ($diagnostic['code'] ?? null) : null, $diagnostics);
        $this->assertSame($reported, \in_array('regex.lint.literal.invisible', $codes, true));
    }
}
