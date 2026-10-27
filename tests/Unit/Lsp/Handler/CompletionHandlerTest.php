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

use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPRegex\LanguageServer\Document\DocumentManager;
use PHPRegex\LanguageServer\Document\RegexFinder;
use PHPRegex\LanguageServer\Handler\CompletionHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Transport;
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
        // The cursor right at the end of what is typed: the occurrence
        // starts at the opening quote, one character before the pattern.
        yield 'right after the closing delimiter' => ['pattern' => '/abc/', 'typed' => 5, 'offered' => ['i', 'm'], 'notOffered' => ['\\d'], 'labelShape' => '/^[a-zA-Z]$/'];
        yield 'right after a property name begins' => ['pattern' => '/\\p{Gr/', 'typed' => 6, 'offered' => ['\\p{Script=Greek}'], 'notOffered' => ['\\d'], 'labelShape' => '/^\\\\[pP]\\{[^}]++\\}$/'];
        // A backslash typed last offers the escapes of a character type alone: no anchor, no group.
        yield 'right after a backslash' => ['pattern' => '/a\\d/', 'typed' => 3, 'offered' => ['\\d', '\\w', '\\X'], 'notOffered' => ['\\A', '(?:...)', '\\p{L}'], 'labelShape' => '/^\\\\[a-zA-Z]$/'];
        // "(?" typed last offers the groups alone.
        yield 'right after a group opener' => ['pattern' => '/(?:a)/', 'typed' => 3, 'offered' => ['(?:...)', '(?<name>...)', '(?|...)'], 'notOffered' => ['\\d', '+'], 'labelShape' => '/^\\(.*\\)$/'];
    }

    /**
     * The context at the cursor, computed on the pattern's bytes. The source
     * counts UTF-16 code units of what the literal spells — "é" one unit,
     * "\x5b" four — while the pattern counts bytes of what PHP reads from
     * it; any column arithmetic between the two skews the prefix as soon as
     * a multibyte character or an escape stands before the cursor, on the
     * cursor's own line or the line the pattern starts on.
     *
     * @param list<string> $offered
     * @param list<string> $notOffered
     */
    #[Test]
    #[DataProvider('provideDocumentsBeforeTheCursor')]
    public function test_completion_computes_the_context_on_pattern_bytes(string $content, string $upTo, array $offered, array $notOffered, string $labelShape): void
    {
        $labels = $this->completeAt($content, $upTo);

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
     * The control rows pin the boundary: multibyte text or invalid UTF-8
     * outside the literal, and a "b" before the quote, skew nothing.
     *
     * @return iterable<string, array{content: string, upTo: string, offered: list<string>, notOffered: list<string>, labelShape: string}>
     */
    public static function provideDocumentsBeforeTheCursor(): iterable
    {
        $property = '/^\\\\[pP]\\{[^}]++\\}$/';
        $flag = '/^[a-zA-Z]$/';

        yield 'a multibyte character inside the pattern' => [
            'content' => "<?php\n\$x = 'é'; preg_match('/é/ix', \$s);\n",
            'upTo' => '/é/i',
            'offered' => ['m'],
            'notOffered' => ['i', '\\d'],
            'labelShape' => $flag,
        ];
        yield 'a multibyte character on the line, before the literal only' => [
            'content' => "<?php\n\$x = 'é'; preg_match('/ab/ix', \$s);\n",
            'upTo' => '/ab/i',
            'offered' => ['m'],
            'notOffered' => ['i', '\\d'],
            'labelShape' => $flag,
        ];
        yield 'an escape the literal spells before the cursor' => [
            'content' => "<?php\npreg_match(\"/ab\\x5b/i\", \$s);\n",
            'upTo' => '/ab\\x5b/i',
            'offered' => ['m'],
            'notOffered' => ['i', '\\d'],
            'labelShape' => $flag,
        ];
        yield 'a unicode escape before a property' => [
            'content' => "<?php\npreg_match(\"/\\u{e9}\\p{Gr/\", \$s);\n",
            'upTo' => '\\p{G',
            'offered' => ['\\p{L}', '\\p{Script=Greek}'],
            'notOffered' => ['\\d', '[:alpha:]'],
            'labelShape' => $property,
        ];
        yield 'a pattern spanning lines, the cursor on the second one' => [
            'content' => "<?php\npreg_match(\"/a\n\\p{Gr/\", \$s);\n",
            'upTo' => '\\p{G',
            'offered' => ['\\p{L}', '\\p{Script=Greek}'],
            'notOffered' => ['\\d', '[:alpha:]'],
            'labelShape' => $property,
        ];
        yield 'a line that is not valid UTF-8 before the literal' => [
            'content' => "<?php\n\$x = \"\xFF\"; preg_match('/\\p{Gr/', \$s);\n",
            'upTo' => '\\p{G',
            'offered' => ['\\p{L}', '\\p{Script=Greek}'],
            'notOffered' => ['\\d', '[:alpha:]'],
            'labelShape' => $property,
        ];
        yield 'a b before the quote' => [
            'content' => "<?php\npreg_match(b'/\\p{Gr/', \$s);\n",
            'upTo' => '\\p{G',
            'offered' => ['\\p{L}', '\\p{Script=Greek}'],
            'notOffered' => ['\\d', '[:alpha:]'],
            'labelShape' => $property,
        ];
    }

    /**
     * The cursor inside an escape's spelling rounds down: the prefix
     * excludes the partially typed escape, so the context offered is the
     * one before it, never one reading the escape's characters as pattern
     * text.
     *
     * @param list<string> $offered
     * @param list<string> $notOffered
     */
    #[Test]
    #[DataProvider('provideCursorsInsideAnEscapeSpelling')]
    public function test_completion_excludes_a_partially_typed_escape_from_the_prefix(string $content, string $upTo, array $offered, array $notOffered, string $labelShape): void
    {
        $labels = $this->completeAt($content, $upTo);

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
     * @return iterable<string, array{content: string, upTo: string, offered: list<string>, notOffered: list<string>, labelShape: string}>
     */
    public static function provideCursorsInsideAnEscapeSpelling(): iterable
    {
        yield 'inside a unicode escape' => [
            'content' => "<?php\npreg_match(\"/\\u{e9}\\p{Gr/\", \$s);\n",
            'upTo' => '\\u{e9',
            'offered' => ['\\d', '(?:...)'],
            'notOffered' => ['\\p{L}', '[:alpha:]'],
            'labelShape' => '/^\S+$/',
        ];
        yield 'right after the backslash of a hexadecimal escape' => [
            'content' => "<?php\npreg_match(\"/x\\x5b\\p{Gr/\", \$s);\n",
            'upTo' => '/x\\',
            'offered' => ['\\d', '(?:...)'],
            'notOffered' => ['\\p{L}', '[:alpha:]'],
            'labelShape' => '/^\S+$/',
        ];
    }

    /**
     * A request without a document or a position, and one outside every
     * pattern, is answered with no completion; a notification is answered
     * under the id 0, as it always was.
     */
    #[Test]
    public function test_completion_answers_nothing_where_it_cannot_complete(): void
    {
        $this->documents->open('file:///a.php', "<?php\npreg_match('/a/', \$s);\n");
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $handler = new CompletionHandler(new Transport($stream), $this->documents);

        foreach ([
            [1, ['textDocument' => ['uri' => 'file:///a.php']]],
            [2, ['position' => ['line' => 1, 'character' => 13]]],
            [3, ['textDocument' => ['uri' => 'file:///a.php'], 'position' => ['line' => 1, 'character' => 2]]],
            [null, ['textDocument' => ['uri' => 'file:///a.php'], 'position' => ['line' => 1, 'character' => 13]]],
        ] as [$id, $params]) {
            $handler->handle(new Message(jsonrpc: '2.0', method: 'textDocument/completion', id: $id, params: $params));
        }

        rewind($stream);
        $frames = preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($stream), -1, \PREG_SPLIT_NO_EMPTY);
        $this->assertIsArray($frames);
        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => null],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => null],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => null],
            ['jsonrpc' => '2.0', 'id' => 0, 'result' => null],
        ], array_map(static fn (string $frame): mixed => json_decode($frame, true, 512, \JSON_THROW_ON_ERROR), $frames));
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

        (new CompletionHandler(new Transport($stream), $this->documents))->handle(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/completion',
            id: 1,
            params: ['textDocument' => ['uri' => 'file:///a.php'], 'position' => ['line' => 1, 'character' => \strlen($prefix) + $typed]],
        ));

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        $body = json_decode(substr($output, (int) strpos($output, "\r\n\r\n") + 4), true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['result'] ?? null);
        $this->assertFalse($body['result']['isIncomplete'] ?? null, 'The list is the whole of what the context offers.');
        $this->assertIsArray($body['result']['items'] ?? null);

        $labels = [];
        foreach ($body['result']['items'] as $item) {
            $this->assertIsArray($item);
            $this->assertIsString($item['label'] ?? null);
            $labels[] = $item['label'];
        }

        return $labels;
    }

    /**
     * The labels offered with the cursor just after $upTo in $content, the
     * position derived the way an editor derives it: UTF-16 columns of the
     * document's bytes, bytes alone on a line that is not valid UTF-8.
     *
     * @return list<string>
     */
    private function completeAt(string $content, string $upTo): array
    {
        $cursor = strpos($content, $upTo);
        $this->assertIsInt($cursor, 'The cursor marker is not in the document.');
        $cursor += \strlen($upTo);
        $position = (new PositionConverter($content))->offsetToPosition($cursor);

        $this->documents->open('file:///a.php', $content);
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        (new CompletionHandler(new Transport($stream), $this->documents))->handle(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/completion',
            id: 1,
            params: ['textDocument' => ['uri' => 'file:///a.php'], 'position' => $position],
        ));

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        $body = json_decode(substr($output, (int) strpos($output, "\r\n\r\n") + 4), true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['result'] ?? null);
        $this->assertFalse($body['result']['isIncomplete'] ?? null, 'The list is the whole of what the context offers.');
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
