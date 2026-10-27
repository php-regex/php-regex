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
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\PatternEquivalence;
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

    /**
     * @var resource
     */
    private $output;

    protected function setUp(): void
    {
        $output = fopen('php://memory', 'w+');
        if (false === $output) {
            self::fail('Unable to open an in-memory stream.');
        }

        $this->output = $output;
        $this->documents = new DocumentManager(new RegexFinder());
        $this->handler = new CodeActionHandler(new Transport($this->output), $this->documents, Regex::create());
    }

    protected function tearDown(): void
    {
        fclose($this->output);
    }

    /**
     * A pattern past a limit of the library is not analyzed: no action is
     * offered on it, and the request is still answered.
     */
    #[Test]
    public function test_no_action_is_offered_on_a_pattern_past_a_limit(): void
    {
        $this->handler = new CodeActionHandler(new Transport($this->output), $this->documents, Regex::create(['max_recursion_depth' => 3]));

        $this->assertSame([], $this->codeActions("<?php\npreg_match('/((((a))))/', \$s);\n", ['line' => 1, 'character' => 12], ['line' => 1, 'character' => 24]));
    }

    /**
     * The quick fix offers a rewrite only once the automata prove it, as the
     * lint checks the rewrites it suggests: a class respelled reads as the
     * pattern does, a run spelled as a repeat beside a back reference, which
     * the match solver does not read, is proven alone, while the same run
     * beside a subroutine call, which may run any group again, is not
     * proven.
     */
    #[Test]
    #[DataProvider('provideOptimizationsToProve')]
    public function test_the_optimization_action_offers_only_a_proven_rewrite(string $pattern, ?string $offered): void
    {
        $this->assertNotSame($pattern, Regex::create()->optimize($pattern, new OptimizerOptions(verifyWithAutomata: false))->optimized, 'Unchecked, the pattern is rewritten.');

        $action = self::action($this->codeActionsOnLiteral(self::source($pattern, 0)), 'Apply regex optimization');

        if (null === $offered) {
            $this->assertNull($action);

            return;
        }

        $this->assertNotNull($action);
        $this->assertSame(var_export($offered, true), self::singleEdit($action)['newText']);
    }

    /**
     * @return iterable<string, array{pattern: string, offered: string|null}>
     */
    public static function provideOptimizationsToProve(): iterable
    {
        yield 'a class respelled' => ['pattern' => '/[0-9]+/', 'offered' => '/\d+/'];
        yield 'a class respelled beside a back reference' => ['pattern' => '/(a)\1[0-9]/', 'offered' => '/(a)\1\d/'];
        yield 'a run spelled as a repeat beside a back reference' => ['pattern' => '/(a)\1[0-9]aaaa/', 'offered' => '/(a)\1\da{4}/'];
        yield 'a run spelled as a repeat beside a subroutine call' => ['pattern' => '/(a)(?1)[0-9]aaaa/', 'offered' => null];
    }

    /**
     * A request without a document or a range is answered with no action;
     * a notification under the id 0, as it always was.
     */
    #[Test]
    public function test_a_request_without_a_document_or_a_range_is_offered_no_action(): void
    {
        foreach ([[4, ['textDocument' => ['uri' => self::URI]]], [5, ['range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]]]], [null, []]] as [$id, $params]) {
            $this->handler->handle(new Message(jsonrpc: '2.0', method: 'textDocument/codeAction', id: $id, params: $params));
        }

        rewind($this->output);
        $frames = preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($this->output), -1, \PREG_SPLIT_NO_EMPTY);
        $this->assertIsArray($frames);
        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 4, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 5, 'result' => []],
            ['jsonrpc' => '2.0', 'id' => 0, 'result' => []],
        ], array_map(static fn (string $frame): mixed => json_decode($frame, true, 512, \JSON_THROW_ON_ERROR), $frames));
    }

    #[Test]
    public function test_handler_can_be_instantiated(): void
    {
        $this->expectNotToPerformAssertions();
        new CodeActionHandler(new Transport($this->output), $this->documents, Regex::create());
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
     * The literal's own indentation comes first, tabs included, then the
     * four spaces of a continuation line. On the first line of a document,
     * behind the indentation of a template's "<?php", that indentation is
     * the line's too.
     */
    #[Test]
    #[DataProvider('provideLiteralLines')]
    public function test_format_action_shifts_continuation_lines_by_the_literal_line_indentation_then_four_spaces(string $source, string $indent): void
    {
        $format = self::action($this->codeActionsOnLiteral($source), self::FORMAT);
        $this->assertNotNull($format);

        $pattern = eval('return '.self::singleEdit($format)['newText'].';');

        $this->assertSame(str_replace("\n", "\n".$indent.'    ', self::URL_FORMATTED), $pattern);
        $subject = 'https://bob:secret@[::1]:8080/a/b?x=1#frag';
        $this->assertSame(1, preg_match(self::URL, $subject, $before));
        $this->assertSame(1, preg_match((string) $pattern, $subject, $after), 'The engine agrees the shift is only layout.');
        $this->assertSame($before, $after);
    }

    /**
     * @return iterable<string, array{source: string, indent: string}>
     */
    public static function provideLiteralLines(): iterable
    {
        $call = 'preg_match('.var_export(self::URL, true).', $s);';

        yield 'a line indented with a tab' => ['source' => "<?php\n\n\t{$call}\n", 'indent' => "\t"];
        yield 'a line indented with spaces, then a tab' => ['source' => "<?php\n\n  \t{$call}\n", 'indent' => "  \t"];
        yield 'the first line of the document' => ['source' => "  <?php {$call}\n", 'indent' => '  '];
    }

    /**
     * A literal laid out over lines another way than the format action lays
     * it out gets both actions, the format action first.
     */
    #[Test]
    public function test_both_layout_actions_are_offered_for_a_literal_laid_out_another_way(): void
    {
        $pattern = str_replace('    ', '  ', self::URL_FORMATTED);
        $subject = 'https://bob:secret@[::1]:8080/a/b?x=1#frag';
        $this->assertSame(1, preg_match(self::URL, $subject, $expected));
        $this->assertSame(1, preg_match($pattern, $subject, $matches), 'The oracle on the input.');
        $this->assertSame($expected, $matches);

        $actions = $this->codeActionsOnLiteral(self::source($pattern, 0));

        $layout = array_values(array_filter(self::titles($actions), static fn (string $title): bool => \in_array($title, [self::FORMAT, self::COMPACT], true)));
        $this->assertSame([self::FORMAT, self::COMPACT], $layout);
        $compact = self::action($actions, self::COMPACT);
        $this->assertNotNull($compact);
        $this->assertSame(self::URL, eval('return '.self::singleEdit($compact)['newText'].';'));
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
        $this->handler = new CodeActionHandler(new Transport($this->output), $this->documents, Regex::create(['cache' => $cache]));

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
        // The lexer refuses it before the parser does.
        yield 'a pattern with a class left open' => ['pattern' => '/[a/', 'engine' => false];
    }

    /**
     * A raw line break in a pattern that is not under x where it stands is
     * a literal, not layout: shifted to the literal's indentation, it would
     * carry spaces into the pattern. A layout action is either not offered
     * or keeps what the literal matches, subject for subject.
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideLiteralsHoldingARawLineBreak')]
    public function test_no_layout_action_changes_what_a_literal_holding_a_raw_line_break_matches(string $pattern, array $subjects): void
    {
        foreach ($subjects as $subject => $expected) {
            $this->assertSame($expected, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
        }

        $actions = $this->codeActionsOnLiteral(self::source($pattern, 8));

        foreach ([self::FORMAT, self::COMPACT] as $title) {
            $action = self::action($actions, $title);
            if (null === $action) {
                continue;
            }

            $rewritten = eval('return '.self::singleEdit($action)['newText'].';');
            $this->assertIsString($rewritten);
            foreach ($subjects as $subject => $expected) {
                $this->assertSame($expected, preg_match($rewritten, (string) $subject), $title.': '.json_encode($rewritten).' on '.json_encode((string) $subject));
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: array<string, int>}>
     */
    public static function provideLiteralsHoldingARawLineBreak(): iterable
    {
        $shifted = "\n".str_repeat(' ', 12);

        yield 'a short pattern without x' => ['pattern' => "/a\nb/", 'subjects' => ["a\nb" => 1, "a{$shifted}b" => 0, 'ab' => 0]];
        yield 'after (?-x) in a group of an x pattern' => ['pattern' => "/(?:(?-x)a\nb)(c)(d)/x", 'subjects' => ["a\nbcd" => 1, "a{$shifted}bcd" => 0, 'abcd' => 0]];
    }

    /**
     * A blank line the formatter keeps stays empty once the continuation
     * lines are shifted: no line of the edit is indentation alone.
     */
    #[Test]
    public function test_format_action_writes_no_line_of_indentation_only(): void
    {
        $pattern = "/^a # one\n\n b # two\n\n\n c\$/x";
        $this->assertSame(1, preg_match($pattern, 'abc'), 'The oracle on the input.');

        $actions = $this->codeActionsOnLiteral(self::source($pattern, 8));
        $format = self::action($actions, self::FORMAT);
        $this->assertNotNull($format, 'No "'.self::FORMAT.'" action among: '.implode(', ', self::titles($actions)));

        $rewritten = eval('return '.self::singleEdit($format)['newText'].';');
        $this->assertIsString($rewritten);
        foreach (explode("\n", $rewritten) as $number => $line) {
            $this->assertDoesNotMatchRegularExpression('/^[ \t]+$/', $line, \sprintf('Line %d of %s', $number + 1, json_encode($rewritten)));
        }
        $this->assertStringContainsString("\n\n", (string) $rewritten, 'The blank line the author left is kept.');
        $this->assertSame(1, preg_match($rewritten, 'abc'));
    }

    /**
     * The handler formats for the target its Regex reads for: for PCRE2
     * 10.40 "{,2}" is literal text, a quantifier from 10.43 on. The action
     * is offered, and its pattern is the literal's for that target.
     */
    #[Test]
    public function test_format_action_is_offered_for_the_target_the_handler_reads_for(): void
    {
        // The oracle: "/^b{,2}$/" matches "b{,2}" only before 10.43.
        $this->assertSame(PcreTarget::runtime()->pcreAtLeast('10.43') ? 0 : 1, preg_match('/^b{,2}$/', 'b{,2}'));
        $regex = Regex::create(['cache' => new NullCache(), 'pcre_version' => '10.40']);
        $this->handler = new CodeActionHandler(new Transport($this->output), $this->documents, $regex);
        $pattern = '/'.str_repeat('(?:ab)', 14).'b{,2}/';

        $actions = $this->codeActionsOnLiteral(self::source($pattern, 8));
        $format = self::action($actions, self::FORMAT);

        $this->assertNotNull($format, 'No "'.self::FORMAT.'" action among: '.implode(', ', self::titles($actions)));
        $rewritten = eval('return '.self::singleEdit($format)['newText'].';');
        $this->assertIsString($rewritten);
        $this->assertSame(PatternEquivalence::structure($regex->parse($pattern)), PatternEquivalence::structure($regex->parse($rewritten)));
    }

    /**
     * The line breaks inside a "(?#...)" comment are layout too: once the
     * format action is applied, the literal is what the action writes, so
     * applying it again changes nothing, however far the continuation lines
     * were shifted. A comment matches nothing: PCRE reads the result as the
     * input.
     */
    #[Test]
    public function test_format_action_applied_twice_leaves_a_comment_group_holding_a_line_feed_where_the_first_put_it(): void
    {
        $pattern = "/^(?<year>\\d{4})-(?<month>\\d{2})-(?<day>\\d{2})(?#the date,\n   then the time)T(?<hour>\\d{2}):(?<minute>\\d{2})\$/";
        $subject = '2026-10-14T09:30';
        $this->assertSame(1, preg_match($pattern, $subject, $expected), 'The oracle on the input.');
        // A literal holding a raw line break, single-quoted as an editor has it.
        $source = "<?php\n\n        preg_match('".$pattern."', \$s);\n";

        $first = self::action($this->codeActionsOnLiteral($source), self::FORMAT);
        $this->assertNotNull($first, 'No "'.self::FORMAT.'" action on the literal.');
        $once = self::applyEdit($source, $first);

        $second = self::action($this->codeActionsOnLiteral($once), self::FORMAT);
        $twice = null === $second ? $once : self::applyEdit($once, $second);

        $this->assertSame($once, $twice, 'A second format action moved the comment.');
        $rewritten = eval('return '.self::singleEdit($first)['newText'].';');
        $this->assertIsString($rewritten);
        $this->assertSame(1, preg_match($rewritten, $subject, $matches), json_encode($rewritten, \JSON_THROW_ON_ERROR));
        $this->assertSame($expected, $matches);
    }

    /**
     * The format action is withheld from a pattern the formatter refuses
     * for its spaced quantifier, whatever PCRE2 the handler reads for.
     */
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_format_action_is_not_offered_for_a_spaced_quantifier_whatever_the_target(?string $pcreVersion): void
    {
        $this->assertSame(0, preg_match(self::SPACED_QUANTIFIER, ''), 'The pattern compiles.');
        $this->handler = new CodeActionHandler(new Transport($this->output), $this->documents, Regex::create(null === $pcreVersion ? ['cache' => new NullCache()] : ['cache' => new NullCache(), 'pcre_version' => $pcreVersion]));

        $actions = $this->codeActionsOnLiteral(self::source(self::SPACED_QUANTIFIER, 8));

        $this->assertNull(self::action($actions, self::FORMAT), 'Offered: '.implode(', ', self::titles($actions)));
    }

    /**
     * @return iterable<string, array{pcreVersion: ?string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'the running engine' => ['pcreVersion' => null];
        yield 'PCRE2 10.40' => ['pcreVersion' => '10.40'];
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

        // The answer to this request alone.
        $stream = $this->output;
        ftruncate($stream, 0);
        rewind($stream);

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
