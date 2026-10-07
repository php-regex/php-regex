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
use PHPRegex\LanguageServer\Handler\CompletionHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompletionHandlerTest extends TestCase
{
    private DocumentManager $documents;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager(new RegexFinder());
    }

    #[Test]
    public function test_handler_can_be_instantiated(): void
    {
        $handler = new CompletionHandler($this->documents);

        $this->assertInstanceOf(CompletionHandler::class, $handler);
    }

    #[Test]
    public function test_handler_requires_document_manager(): void
    {
        $documents = new DocumentManager(new RegexFinder());
        $handler = new CompletionHandler($documents);

        $this->assertInstanceOf(CompletionHandler::class, $handler);
    }

    /**
     * The completion offered inside a "\p{" property, a POSIX class, or the
     * flags after the closing delimiter. The cursor stands one character
     * before the end of what each row has typed, a place where the offered
     * kind is the same whether or not the character after the cursor is
     * counted.
     *
     * @param list<string> $offered
     * @param list<string> $notOffered
     */
    #[Test]
    #[DataProvider('provideCursorContexts')]
    public function test_completion_offers_the_items_of_the_context_at_the_cursor(string $pattern, int $typed, array $offered, array $notOffered, string $labelShape): void
    {
        $labels = $this->complete($pattern, $typed);

        $this->assertNotSame([], $labels);
        foreach ($offered as $label) {
            $this->assertContains($label, $labels);
        }
        foreach ($notOffered as $label) {
            $this->assertNotContains($label, $labels);
        }
        foreach ($labels as $label) {
            $this->assertMatchesRegularExpression($labelShape, $label);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, typed: int, offered: list<string>, notOffered: list<string>, labelShape: string}>
     */
    public static function provideCursorContexts(): iterable
    {
        yield 'inside a unicode property' => ['pattern' => '/\\p{Gr/', 'typed' => 5, 'offered' => ['\\p{L}', '\\p{Script=Greek}'], 'notOffered' => ['\\d', '[:alpha:]'], 'labelShape' => '/^\\\\[pP]\\{[^}]++\\}$/'];
        yield 'inside a POSIX class' => ['pattern' => '/[[:al]]/', 'typed' => 4, 'offered' => ['[:alnum:]', '[:alpha:]'], 'notOffered' => ['\\d', '\\p{L}'], 'labelShape' => '/^\\[:[a-z]++:\\]$/'];
        yield 'among the flags' => ['pattern' => '/abc/ix', 'typed' => 6, 'offered' => ['m', 's'], 'notOffered' => ['i', '\\d'], 'labelShape' => '/^[a-zA-Z]$/'];
    }

    /**
     * @return list<string>
     */
    private function complete(string $pattern, int $typed): array
    {
        $prefix = "preg_match('";
        $this->documents->open('file:///a.php', "<?php\n".$prefix.$pattern."', \$s);\n");
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            (new CompletionHandler($this->documents))->handle(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/completion',
                id: 1,
                params: ['textDocument' => ['uri' => 'file:///a.php'], 'position' => ['line' => 1, 'character' => \strlen($prefix) + $typed]],
            ));
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        $body = json_decode(substr($output, (int) strpos($output, "\r\n\r\n") + 4), true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['result'] ?? null);
        $this->assertIsArray($body['result']['items'] ?? null);

        $labels = [];
        foreach ($body['result']['items'] as $item) {
            $this->assertIsArray($item);
            $this->assertIsString($item['label'] ?? null);
            $labels[] = $item['label'];
        }

        return $labels;
    }
}
