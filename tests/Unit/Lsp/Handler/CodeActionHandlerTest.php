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
use PHPRegex\LanguageServer\Handler\CodeActionHandler;
use PHPRegex\LanguageServer\Protocol\Message;
use PHPRegex\LanguageServer\Protocol\Response;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CodeActionHandlerTest extends TestCase
{
    private const URI = 'file:///a.php';

    private const FORMAT = 'Format pattern over lines';

    private const COMPACT = 'Compact pattern to one line';

    private const URL = '#^(?<scheme>https?)://(?:(?<user>[^:@/]+)(?::(?<pass>[^@/]*))?@)?(?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)(?::(?<port>\d+))?(?<path>/[^?\#]*)?(?:\?(?<query>[^\#]*))?(?:\#(?<fragment>.*))?$#i';

    private const URL_FORMATTED = <<<'REGEX'
        #
            ^
            (?<scheme>https?)
            ://
            (?:
                (?<user>[^:@/]+)
                (?::(?<pass>[^@/]*))?
                @
            )?
            (?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)
            (?::(?<port>\d+))?
            (?<path>/[^?\#]*)?
            (?:\?(?<query>[^\#]*))?
            (?:\#(?<fragment>.*))?
            $
        #ix
        REGEX;

    /**
     * The URL literal on a line indented 8 spaces, once the format action
     * is applied: every line after the first indented by 8 + 4 more, and the
     * pattern written back the way the existing rewrite actions write one,
     * var_export() of the string.
     */
    private const URL_FORMATTED_IN_SOURCE = <<<'PHP'
        <?php

                preg_match('#
                        ^
                        (?<scheme>https?)
                        ://
                        (?:
                            (?<user>[^:@/]+)
                            (?::(?<pass>[^@/]*))?
                            @
                        )?
                        (?<host>\\[[0-9a-f:.]+\\]|[^:/?\\#]+)
                        (?::(?<port>\\d+))?
                        (?<path>/[^?\\#]*)?
                        (?:\\?(?<query>[^\\#]*))?
                        (?:\\#(?<fragment>.*))?
                        $
                    #ix', $s);

        PHP;

    /**
     * Laid out by the formatter, comments included, as it lands on a line
     * indented 8 spaces.
     */
    private const LAID_OUT_AT_8 = "/\n                (?<year>\\d{4})  # year\n                -\n                (?<month>\\d{2})  # month\n            /x";

    /**
     * 91 bytes, not under x: too long to fit, and a{ 1,2 } is a quantifier
     * from PCRE2 10.43 on but a literal before it, so x cannot be added.
     */
    private const SPACED_QUANTIFIER = '/a{ 1,2 }bcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJ/';

    private CodeActionHandler $handler;

    private DocumentManager $documents;

    protected function setUp(): void
    {
        $this->documents = new DocumentManager(new RegexFinder());
        $this->handler = new CodeActionHandler($this->documents, Regex::create());
    }

    #[Test]
    public function test_handler_can_be_instantiated(): void
    {
        $handler = new CodeActionHandler($this->documents, Regex::create());

        $this->assertInstanceOf(CodeActionHandler::class, $handler);
    }

    #[Test]
    #[DataProvider('provideAddUnicodeFlagPatterns')]
    public function test_add_unicode_flag_correctly(string $input, string $expected): void
    {
        // Use reflection to test the private addUnicodeFlag method
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('addUnicodeFlag');

        $result = $method->invoke($this->handler, $input);

        $this->assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAddUnicodeFlagPatterns(): iterable
    {
        yield 'simple pattern' => ['/\\w+/', '/\\w+/u'];
        yield 'pattern with flags' => ['/\\w+/i', '/\\w+/ui'];
        yield 'pattern with multiple flags' => ['/\\w+/im', '/\\w+/uim'];
        yield 'hash delimiter' => ['#\\w+#', '#\\w+#u'];
        yield 'hash with flags' => ['#\\w+#i', '#\\w+#ui'];
        yield 'tilde delimiter' => ['~\\w+~', '~\\w+~u'];
    }

    #[Test]
    public function test_add_unicode_flag_returns_null_for_already_unicode(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('addUnicodeFlag');

        $result = $method->invoke($this->handler, '/\\w+/u');

        $this->assertNull($result);
    }

    #[Test]
    public function test_add_unicode_flag_returns_null_for_short_pattern(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('addUnicodeFlag');

        $result = $method->invoke($this->handler, '/');

        $this->assertNull($result);
    }

    #[Test]
    public function test_ranges_overlap_detection(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('rangesOverlap');

        // Overlapping ranges
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 0, 'character' => 10],
            ['line' => 0, 'character' => 5],
            ['line' => 0, 'character' => 15],
        );
        $this->assertTrue($result);

        // Non-overlapping ranges (range1 ends before range2 starts)
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 0, 'character' => 5],
            ['line' => 0, 'character' => 10],
            ['line' => 0, 'character' => 15],
        );
        $this->assertFalse($result);
    }

    #[Test]
    public function test_ranges_overlap_on_different_lines(): void
    {
        $reflection = new \ReflectionClass($this->handler);
        $method = $reflection->getMethod('rangesOverlap');

        // Non-overlapping: range1 on line 0, range2 on line 2
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 0, 'character' => 10],
            ['line' => 2, 'character' => 0],
            ['line' => 2, 'character' => 10],
        );
        $this->assertFalse($result);

        // Overlapping across lines
        $result = $method->invoke(
            $this->handler,
            ['line' => 0, 'character' => 0],
            ['line' => 2, 'character' => 10],
            ['line' => 1, 'character' => 0],
            ['line' => 1, 'character' => 10],
        );
        $this->assertTrue($result);
    }

    #[Test]
    #[DataProvider('providePatternsToQuote')]
    public function test_a_replacement_is_php_the_editor_can_parse(string $pattern): void
    {
        $method = (new \ReflectionClass($this->handler))->getMethod('asPhpString');
        $literal = $method->invoke($this->handler, $pattern);

        $this->assertIsString($literal);

        // The literal has to read back as the pattern it came from, and the
        // file it lands in has to stay valid PHP.
        $evaluated = eval('return '.$literal.';');
        $this->assertSame($pattern, $evaluated);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatternsToQuote(): iterable
    {
        yield 'a plain pattern' => ['pattern' => '/\\d+/u'];

        // A regex is free to hold the quote that would delimit it.
        yield 'a pattern holding a quote' => ['pattern' => "/it's/u"];
        yield 'a pattern holding a backslash and a quote' => ['pattern' => "/\\d'\\w/u"];
        yield 'a pattern ending on a backslash' => ['pattern' => '/a\\\\/u'];
    }

    #[Test]
    public function test_format_action_lays_out_a_long_literal_under_the_cursor(): void
    {
        $source = self::source(self::URL, 8);

        $actions = $this->codeActionsOnLiteral($source);
        $format = self::action($actions, self::FORMAT);

        $this->assertNotNull($format, 'No "'.self::FORMAT.'" action among: '.implode(', ', self::titles($actions)));
        $this->assertSame('refactor.rewrite', $format['kind'] ?? null);
        $this->assertSame(self::URL_FORMATTED_IN_SOURCE, self::applyEdit($source, $format));
    }

    /**
     * The edit replaces the whole string literal, quotes included, and
     * nothing else: the range the document manager reports for it.
     */
    #[Test]
    public function test_format_action_replaces_the_string_literal(): void
    {
        $source = self::source(self::URL, 8);

        $format = self::action($this->codeActionsOnLiteral($source), self::FORMAT);
        $this->assertNotNull($format);

        $occurrences = $this->documents->getOccurrences(self::URI);
        $this->assertCount(1, $occurrences);
        $edit = self::singleEdit($format);
        $this->assertSame(['start' => $occurrences[0]->start, 'end' => $occurrences[0]->end], $edit['range']);
    }

    /**
     * Every line break the formatter writes is layout, so the continuation
     * lines may be shifted to the literal's own indentation plus 4: the
     * replacement still reads back as a PHP string, and that string is the
     * formatted pattern with the shift, matching what the original matched.
     */
    #[Test]
    #[DataProvider('provideLineIndentations')]
    public function test_format_action_indents_continuation_lines_to_the_literal_line_plus_four(int $indent): void
    {
        $source = self::source(self::URL, $indent);

        $format = self::action($this->codeActionsOnLiteral($source), self::FORMAT);
        $this->assertNotNull($format);

        $newText = self::singleEdit($format)['newText'];
        $pattern = eval('return '.$newText.';');

        $expected = str_replace("\n", "\n".str_repeat(' ', $indent + 4), self::URL_FORMATTED);
        $this->assertSame($expected, $pattern);

        // The engine agrees the shift is only layout.
        $subject = 'https://bob:secret@[::1]:8080/a/b?x=1#frag';
        $this->assertSame(1, preg_match(self::URL, $subject, $before));
        $this->assertSame(1, preg_match($expected, $subject, $after));
        $this->assertSame($before, $after);
    }

    /**
     * @return iterable<string, array{indent: int}>
     */
    public static function provideLineIndentations(): iterable
    {
        yield 'a literal on an unindented line' => ['indent' => 0];
        yield 'a literal on a line indented 4 spaces' => ['indent' => 4];
        yield 'a literal on a line indented 8 spaces' => ['indent' => 8];
    }

    /**
     * Once the format action is applied, the literal is what the action
     * would write: it is not offered again, and compact takes it back to
     * the pattern it came from.
     */
    #[Test]
    public function test_format_action_is_not_offered_again_once_applied(): void
    {
        $actions = $this->codeActionsOnLiteral(self::URL_FORMATTED_IN_SOURCE);

        $this->assertNull(self::action($actions, self::FORMAT), 'The literal is already laid out the way the action would lay it out.');
        $compact = self::action($actions, self::COMPACT);
        $this->assertNotNull($compact, 'No "'.self::COMPACT.'" action among: '.implode(', ', self::titles($actions)));
        $this->assertSame(self::URL, eval('return '.self::singleEdit($compact)['newText'].';'));
    }

    #[Test]
    public function test_compact_action_puts_a_laid_out_literal_on_one_line(): void
    {
        $actions = $this->codeActionsOnLiteral(self::source(self::LAID_OUT_AT_8, 8));

        $compact = self::action($actions, self::COMPACT);
        $this->assertNotNull($compact, 'No "'.self::COMPACT.'" action among: '.implode(', ', self::titles($actions)));
        $this->assertSame('refactor.rewrite', $compact['kind'] ?? null);

        $newText = self::singleEdit($compact)['newText'];
        $this->assertStringNotContainsString("\n", $newText);
        $this->assertSame('/(?<year>\d{4})-(?<month>\d{2})/', eval('return '.$newText.';'));

        $this->assertNull(self::action($actions, self::FORMAT), 'The literal is already laid out the way the format action would lay it out.');
    }

    /**
     * A tree that disagrees with itself, as a cache could give back: "\x41"
     * spelled for the code point 0x42. The formatter cannot prove its output
     * the same pattern and throws, and the handler offers neither action
     * instead of failing.
     */
    #[Test]
    public function test_neither_layout_action_is_offered_when_the_formatter_cannot_prove_its_output(): void
    {
        $parsed = Regex::create(['cache' => null])->parse(self::URL);
        $pattern = new SequenceNode([new CharLiteralNode('\x41', 0x42, CharLiteralType::Unicode, 0, 4), $parsed->pattern], 0, $parsed->endPosition);
        $lying = new RegexNode($pattern, $parsed->flags, $parsed->delimiter, $parsed->startPosition, $parsed->endPosition);
        $cache = new class($lying) implements CacheInterface {
            public function __construct(private readonly RegexNode $ast) {}

            public function generateKey(string $seed): string
            {
                return 'key';
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): RegexNode
            {
                return $this->ast;
            }
        };
        $this->handler = new CodeActionHandler($this->documents, Regex::create(['cache' => $cache]));

        $actions = $this->codeActionsOnLiteral(self::source(self::URL, 8));

        $this->assertNull(self::action($actions, self::FORMAT));
        $this->assertNull(self::action($actions, self::COMPACT));
    }

    /**
     * A literal neither action would change gets neither action, in a file
     * where the other literals get theirs: the URL its format action, the
     * laid-out one its compact action, each exactly once and on its own
     * literal. A pattern that does not parse costs its own actions only.
     */
    #[Test]
    #[DataProvider('provideLiteralsLeftAlone')]
    public function test_neither_layout_action_is_offered_for_a_literal_it_would_not_change(string $pattern, int|false $engine): void
    {
        $this->assertSame($engine, @preg_match($pattern, ''), 'What PCRE makes of the literal.');

        $source = "<?php\n\n";
        foreach ([$pattern, self::URL, self::LAID_OUT_AT_8] as $literal) {
            $source .= '        preg_match('.var_export($literal, true).", \$s);\n";
        }
        $this->documents->open(self::URI, $source);
        $ranges = [];
        foreach ($this->documents->getOccurrences(self::URI) as $occurrence) {
            $ranges[$occurrence->pattern] = ['start' => $occurrence->start, 'end' => $occurrence->end];
        }
        $this->assertArrayHasKey(self::URL, $ranges);
        $this->assertArrayHasKey(self::LAID_OUT_AT_8, $ranges);

        $actions = $this->codeActions($source, ['line' => 0, 'character' => 0], ['line' => substr_count($source, "\n"), 'character' => 0]);

        $format = self::actionsTitled($actions, self::FORMAT);
        $this->assertCount(1, $format, 'One format action, for the URL only.');
        $this->assertSame($ranges[self::URL], self::singleEdit($format[0])['range']);

        $compact = self::actionsTitled($actions, self::COMPACT);
        $this->assertCount(1, $compact, 'One compact action, for the laid-out literal only.');
        $this->assertSame($ranges[self::LAID_OUT_AT_8], self::singleEdit($compact[0])['range']);
    }

    /**
     * @return iterable<string, array{pattern: string, engine: int|false}>
     */
    public static function provideLiteralsLeftAlone(): iterable
    {
        // It fits, and it is on one line already.
        yield 'a short pattern' => ['pattern' => '/^\d{3}-\d{4}$/', 'engine' => 0];
        // It compiles: the refusal is the formatter's, and compact has
        // nothing to remove.
        yield 'a pattern format refuses' => ['pattern' => self::SPACED_QUANTIFIER, 'engine' => 0];
        // PCRE refuses it too.
        yield 'a pattern that does not parse' => ['pattern' => '/(a/', 'engine' => false];
    }

    /**
     * A one-statement PHP file: the literal on a line indented $indent spaces.
     */
    private static function source(string $pattern, int $indent): string
    {
        return "<?php\n\n".str_repeat(' ', $indent).'preg_match('.var_export($pattern, true).", \$s);\n";
    }

    /**
     * The code actions offered with the cursor just inside the opening quote
     * of the file's only preg_match() literal.
     *
     * @return list<array<mixed>>
     */
    private function codeActionsOnLiteral(string $source): array
    {
        $offset = strpos($source, "preg_match('");
        $this->assertIsInt($offset);
        $offset += \strlen("preg_match('");
        $line = substr_count($source, "\n", 0, $offset);
        $character = $offset - (int) strrpos("\n".substr($source, 0, $offset), "\n");
        $position = ['line' => $line, 'character' => $character];

        return $this->codeActions($source, $position, $position);
    }

    /**
     * The code actions offered on a range of the document.
     *
     * @param array{line: int, character: int} $start
     * @param array{line: int, character: int} $end
     *
     * @return list<array<mixed>>
     */
    private function codeActions(string $source, array $start, array $end): array
    {
        $this->documents->open(self::URI, $source);

        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        Response::writeTo($stream);

        try {
            $this->handler->handle(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/codeAction',
                id: 1,
                params: [
                    'textDocument' => ['uri' => self::URI],
                    'range' => ['start' => $start, 'end' => $end],
                    'context' => ['diagnostics' => []],
                ],
            ));
        } finally {
            Response::writeTo(null);
        }

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        $body = json_decode(substr($output, (int) strpos($output, "\r\n\r\n") + 4), true);
        $this->assertIsArray($body);
        $this->assertIsArray($body['result'] ?? null);

        $actions = [];
        foreach ($body['result'] as $action) {
            $this->assertIsArray($action);
            $actions[] = $action;
        }

        return $actions;
    }

    /**
     * @param list<array<mixed>> $actions
     *
     * @return array<mixed>|null
     */
    private static function action(array $actions, string $title): ?array
    {
        foreach ($actions as $action) {
            if ($title === ($action['title'] ?? null)) {
                return $action;
            }
        }

        return null;
    }

    /**
     * @param list<array<mixed>> $actions
     *
     * @return list<array<mixed>>
     */
    private static function actionsTitled(array $actions, string $title): array
    {
        return array_values(array_filter($actions, static fn (array $action): bool => $title === ($action['title'] ?? null)));
    }

    /**
     * @param list<array<mixed>> $actions
     *
     * @return list<string>
     */
    private static function titles(array $actions): array
    {
        return array_map(static fn (array $action): string => \is_string($action['title'] ?? null) ? $action['title'] : '?', $actions);
    }

    /**
     * The one text edit of an action, on the one document.
     *
     * @param array<mixed> $action
     *
     * @return array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}
     */
    private static function singleEdit(array $action): array
    {
        $edit = $action['edit'] ?? null;
        self::assertIsArray($edit);
        $changes = $edit['changes'] ?? null;
        self::assertIsArray($changes);
        self::assertSame([self::URI], array_keys($changes));
        $edits = $changes[self::URI];
        self::assertIsArray($edits);
        self::assertCount(1, $edits);
        $single = $edits[0];
        self::assertIsArray($single);
        $newText = $single['newText'] ?? null;
        self::assertIsString($newText);
        $range = $single['range'] ?? null;
        self::assertIsArray($range);

        return [
            'range' => ['start' => self::position($range['start'] ?? null), 'end' => self::position($range['end'] ?? null)],
            'newText' => $newText,
        ];
    }

    /**
     * @return array{line: int, character: int}
     */
    private static function position(mixed $position): array
    {
        self::assertIsArray($position);
        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;
        self::assertIsInt($line);
        self::assertIsInt($character);

        return ['line' => $line, 'character' => $character];
    }

    /**
     * The source with the action's edit applied (ASCII only, so LSP
     * characters are bytes).
     *
     * @param array<mixed> $action
     */
    private static function applyEdit(string $source, array $action): string
    {
        $edit = self::singleEdit($action);
        $lines = explode("\n", $source);
        $offset = static function (array $position) use ($lines): int {
            $before = 0;
            for ($i = 0; $i < $position['line']; $i++) {
                $before += \strlen($lines[$i]) + 1;
            }

            return $before + $position['character'];
        };
        $start = $offset($edit['range']['start']);
        $end = $offset($edit['range']['end']);

        return substr($source, 0, $start).$edit['newText'].substr($source, $end);
    }
}
