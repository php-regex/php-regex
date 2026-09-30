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
}
