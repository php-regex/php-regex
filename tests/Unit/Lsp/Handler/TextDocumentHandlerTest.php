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
use PHPRegex\LanguageServer\Protocol\Transport;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
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

    /**
     * The versions a "@regex-target" marker keeps are judged with the
     * server's options, its locale included, by one Regex per version kept,
     * whichever marker asks for it.
     */
    #[Test]
    public function test_a_marker_keeps_the_options_of_the_server_and_one_regex_per_version(): void
    {
        $regex = Regex::create(['cache' => null, 'max_recursion_depth' => 64, 'locale' => 'C']);
        $target = ProjectTarget::fromSources(['composer.json' => '8.2'], [], null, [], ['regex.json' => 'C']);
        $handler = new TextDocumentHandler(new Transport(), $this->documents, $regex->withTarget($target->target()), judged: $target->judgedRange($regex->parser()));
        $regexFor = new \ReflectionMethod($handler, 'regexFor');

        $narrowed = $regexFor->invoke($handler, TargetConstraint::phpFrom(80205, null));
        $this->assertInstanceOf(Regex::class, $narrowed);
        $this->assertSame(80205, $narrowed->target()->phpVersionId);
        $this->assertSame('C', $narrowed->target()->locale);
        $this->assertSame(64, (new \ReflectionProperty($narrowed->parser(), 'maxRecursionDepth'))->getValue($narrowed->parser()));
        $this->assertSame($narrowed, $regexFor->invoke($handler, TargetConstraint::phpFrom(80205, 80250)));
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

        // A substring scan is read from PCRE2 10.45: judged for 10.49.
        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///a.php', 'text' => "<?php\npreg_match('".$pattern."', \$s);\n"]],
        ));

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
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $handler = new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null, 'max_recursion_depth' => 3]));
        $this->documents->open('file:///a.php', "<?php\npreg_match('/((((a))))/', \$s);\n");

        $handler->hover(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/hover',
            id: 1,
            params: ['textDocument' => ['uri' => 'file:///a.php'], 'position' => ['line' => 1, 'character' => 15]],
        ));

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $result = $payload['result'] ?? null;
        $this->assertIsArray($result);
        $contents = $result['contents'] ?? null;
        $this->assertIsArray($contents);
        $this->assertSame("**Not analyzed**\n\nRecursion limit of 3 exceeded.", $contents['value'] ?? null);
    }

    /**
     * Hovering a pattern that does not parse, or that the lexer refuses,
     * says its error; hovering outside every pattern answers nothing.
     */
    #[Test]
    public function test_hovering_answers_the_error_of_a_pattern_and_nothing_outside_one(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);
        $handler = new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null]));
        $this->documents->open('file:///a.php', "<?php\npreg_match('/a(/', \$s);\npreg_match('/\\c/', \$s);\n");

        foreach ([1 => [1, 14], 2 => [1, 2], 3 => [2, 13]] as $id => [$line, $character]) {
            $handler->hover(new Message(
                jsonrpc: '2.0',
                method: 'textDocument/hover',
                id: $id,
                params: ['textDocument' => ['uri' => 'file:///a.php'], 'position' => ['line' => $line, 'character' => $character]],
            ));
        }

        rewind($stream);
        $frames = preg_split('/Content-Length: \d+\r\n\r\n/', (string) stream_get_contents($stream), -1, \PREG_SPLIT_NO_EMPTY);
        $this->assertIsArray($frames);
        $this->assertSame([
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['contents' => ['kind' => 'markdown', 'value' => "**Regex Error**\n\nExpected ) at end of input (found eof)."]]],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => null],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['contents' => ['kind' => 'markdown', 'value' => "**Regex Error**\n\n\\c must be followed by a character it can read."]]],
        ], array_map(static fn (string $frame): mixed => json_decode($frame, true, 512, \JSON_THROW_ON_ERROR), $frames));
    }

    /**
     * A limit of the library is no error in the pattern: the editor shows
     * it as a warning — the severity `regex lint` reports for the same
     * "Not analyzed" outcome — never as an error.
     */
    #[Test]
    public function test_a_pattern_past_a_limit_is_published_as_not_analyzed(): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null, 'max_recursion_depth' => 3])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///a.php', 'text' => "<?php\npreg_match('/((((a))))/', \$s);\n"]],
        ));

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
        $this->assertSame(2, $diagnostics[0]['severity'] ?? null, 'Warning, as `regex lint` reports it, never an Error.');
        $this->assertIsString($diagnostics[0]['message'] ?? null);
        $this->assertStringStartsWith('Not analyzed: ', $diagnostics[0]['message']);
        $this->assertStringContainsString('Recursion limit of 3 exceeded', (string) $diagnostics[0]['message']);
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

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///c.php', 'text' => "<?php\npreg_match('".$pattern."', \$s);\n"]],
        ));

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

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///fix.php', 'text' => "<?php\npreg_match('".$pattern."', \$s);\n"]],
        ));

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

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49'])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///b.php', 'text' => "<?php\npreg_match('/ab)/', \$s);\n"]],
        ));

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
     * What the editor underlines, read back from the line in UTF-16 code
     * units: an error marks the character `regex validate` puts its caret
     * under (the closing delimiter when the input ended), a lint issue the
     * whole node it names, a limit the whole body, a ReDoS verdict the risky
     * part. Offsets go through the literal's escapes and multibyte text.
     *
     * @return iterable<string, array{line: string, code: string, underlined: string, options?: array{max_recursion_depth?: int}}>
     */
    public static function provideUnderlinedText(): iterable
    {
        yield 'a ")" PCRE2 reports past itself' => ['line' => "preg_match('/abc)/i', \$s);", 'code' => 'regex.group.unmatched_close', 'underlined' => '/'];
        yield 'a reversed quantifier range' => ['line' => "preg_match('/a{2,1}/', \$s);", 'code' => 'regex.quantifier.invalid_range', 'underlined' => '}'];
        yield 'multibyte text before the error' => ['line' => "preg_match('/é(a/u', \$s);", 'code' => 'regex.group.unclosed', 'underlined' => '/'];
        yield 'an escape the literal spells' => ['line' => 'preg_match("/\t(a/", $s);', 'code' => 'regex.group.unclosed', 'underlined' => '/'];
        yield 'a surrogate pair before and in the pattern' => ['line' => "\$e = '😀'; preg_match('/😀ab)/u', \$s);", 'code' => 'regex.group.unmatched_close', 'underlined' => '/'];
        yield 'a lint issue over its node' => ['line' => "preg_match('/x[aa]/', \$s);", 'code' => 'regex.lint.charclass.redundant', 'underlined' => '[aa]'];
        yield 'a lint issue over a node an escape opens' => ['line' => 'preg_match("/x\x5baa]/", $s);', 'code' => 'regex.lint.charclass.redundant', 'underlined' => '\x5baa]'];
        yield 'a nested quantifier' => ['line' => "preg_match('/(a+)+\$/', \$s);", 'code' => 'regex.lint.quantifier.nested', 'underlined' => '(a+)+'];
        yield 'an error on a multibyte character, whole' => ['line' => "preg_match('/a)é/u', \$s);", 'code' => 'regex.group.unmatched_close', 'underlined' => 'é'];
        yield 'a node starting inside a character, without u' => ['line' => "preg_match('/é+/', \$s);", 'code' => 'regex.lint.unicode.quantifiedMultibyteWithoutU', 'underlined' => 'é+'];
        yield 'a limit over the body' => ['line' => "preg_match('/((((a))))/i', \$s);", 'code' => 'regex.nesting.too_deep', 'underlined' => '((((a))))', 'options' => ['max_recursion_depth' => 3]];
        yield 'a "|" delimiter' => ['line' => "preg_match('|(a|', \$s);", 'code' => 'regex.group.unclosed', 'underlined' => '|'];
        yield 'a "+" delimiter' => ['line' => "preg_match('+x[aa]+i', \$s);", 'code' => 'regex.lint.charclass.redundant', 'underlined' => '[aa]'];
        yield 'a double-quote delimiter' => ['line' => "preg_match('\"x[aa]\"', \$s);", 'code' => 'regex.lint.charclass.redundant', 'underlined' => '[aa]'];
        yield 'the spaces PHP skips before the delimiter' => ['line' => "preg_match(' /x[aa]/', \$s);", 'code' => 'regex.lint.charclass.redundant', 'underlined' => '[aa]'];
        yield 'an error after the spaces PHP skips' => ['line' => 'preg_match("\\t |a)|", $s);', 'code' => 'regex.group.unmatched_close', 'underlined' => '|'];
    }

    /**
     * @param array{max_recursion_depth?: int} $options
     */
    #[Test]
    #[DataProvider('provideUnderlinedText')]
    public function test_a_diagnostic_underlines_the_text_at_fault(string $line, string $code, string $underlined, array $options = []): void
    {
        $diagnostic = null;
        foreach ($this->publish($line, $options) as $published) {
            if (\is_array($published) && $code === ($published['code'] ?? null)) {
                $diagnostic = $published;
            }
        }

        $this->assertIsArray($diagnostic, 'No '.$code.' diagnostic was published.');
        $this->assertSame($underlined, self::underlined($line, $diagnostic));
    }

    /**
     * A string with no delimiter PHP accepts, after the spaces it skips, is
     * not taken for a pattern: nothing is published on it.
     *
     * @return iterable<string, array{line: string}>
     */
    public static function provideStringsThatAreNotPatterns(): iterable
    {
        yield 'a word' => ['line' => "preg_match('abc', \$s);"];
        yield 'one letter' => ['line' => "preg_match('a', \$s);"];
        yield 'a digit first' => ['line' => "preg_match('1a1', \$s);"];
        yield 'a backslash first' => ['line' => "preg_match('\\\\d\\\\', \$s);"];
        yield 'an empty string' => ['line' => "preg_match('', \$s);"];
        yield 'spaces only' => ['line' => "preg_match('  ', \$s);"];
        yield 'a NUL byte first' => ['line' => 'preg_match("\0(a\0", $s);'];
    }

    #[Test]
    #[DataProvider('provideStringsThatAreNotPatterns')]
    public function test_a_string_that_is_not_a_pattern_is_not_published(string $line): void
    {
        $this->assertSame([], $this->publish($line));
    }

    #[Test]
    public function test_a_redos_verdict_underlines_the_risky_part(): void
    {
        $line = "preg_match('/x(a+)+\$/', \$s);";
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null]), [], RedosMode::Theoretical, RedosSeverity::Low))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///r.php', 'text' => "<?php\n".$line."\n"]],
        ));

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);
        $redos = array_values(array_filter($diagnostics, static fn (mixed $diagnostic): bool => \is_array($diagnostic) && 'regex.lint.redos' === ($diagnostic['code'] ?? null)));
        $this->assertCount(1, $redos);
        $this->assertIsArray($redos[0]);
        $this->assertSame('a+', self::underlined($line, $redos[0]));
    }

    /**
     * @return iterable<string, array{identifier: string, lintRules: array<string, bool>, reported: bool}>
     */
    public static function provideLeftOffIdentifiers(): iterable
    {
        yield 'the search cost, its rule off' => ['identifier' => 'regex.lint.redos.search', 'lintRules' => ['redos.search' => false], 'reported' => false];
        yield 'the search cost, its rule off by its full name' => ['identifier' => 'regex.lint.redos.search', 'lintRules' => ['regex.lint.redos.search' => false], 'reported' => false];
        yield 'the search cost, its rule on' => ['identifier' => 'regex.lint.redos.search', 'lintRules' => [], 'reported' => true];
        yield 'the complexity score, never reported' => ['identifier' => 'regex.lint.complexity', 'lintRules' => [], 'reported' => false];
        yield 'the change of meaning, never reported' => ['identifier' => 'regex.lint.compat.meaningChanges', 'lintRules' => [], 'reported' => false];
        yield 'a lint rule turned off' => ['identifier' => 'regex.lint.charclass.single', 'lintRules' => ['charclass.single' => false], 'reported' => false];
        yield 'a lint rule turned on' => ['identifier' => 'regex.lint.charclass.single', 'lintRules' => ['charclass.single' => true], 'reported' => true];
        yield 'no rule' => ['identifier' => 'regex.lint.no.such.rule', 'lintRules' => [], 'reported' => true];
    }

    /**
     * An identifier of a check the editor leaves off is passed over; one
     * that names no check is reported, even turned off in the rules map.
     *
     * @param array<string, bool> $lintRules
     */
    #[Test]
    #[DataProvider('provideLeftOffIdentifiers')]
    public function test_an_identifier_of_a_check_left_off_is_passed_over(string $identifier, array $lintRules, bool $reported): void
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null]), $lintRules, RedosMode::Theoretical))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///i.php', 'text' => "<?php\npreg_match('/x/', \$s); // @regex-ignore ".$identifier."\n"]],
        ));

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);
        $codes = array_map(static fn (mixed $diagnostic): mixed => \is_array($diagnostic) ? ($diagnostic['code'] ?? null) : null, $diagnostics);
        $this->assertSame($reported ? ['regex.ignore.unmatched'] : [], $codes);
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

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///c.php', 'text' => "<?php\npreg_match('/x[aa]/', \$s);\n"]],
        ));

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);
        $this->assertIsArray($diagnostics[0] ?? null);

        // The body starts at 13: "x" at 13, "[aa]" from 14 to 18.
        $this->assertSame('regex.lint.charclass.redundant', $diagnostics[0]['code'] ?? null);
        $range = $diagnostics[0]['range'] ?? null;
        $this->assertIsArray($range);
        $this->assertSame(['line' => 1, 'character' => 14], $range['start'] ?? null);
        $this->assertSame(['line' => 1, 'character' => 18], $range['end'] ?? null);
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

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///d.php', 'text' => "<?php\npreg_match(".$literal.", \$s);\n"]],
        ));

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

    /**
     * The pushes carry the version of the document they diagnose, as the
     * client numbered it: the editor drops a push that names a version the
     * document no longer has.
     */
    #[Test]
    public function test_a_push_carries_the_version_of_the_document_it_diagnoses(): void
    {
        $text = "<?php\npreg_match('/(/', \$s);\n";
        [$open] = $this->handle(
            new Message(jsonrpc: '2.0', method: 'textDocument/didOpen', id: null, params: ['textDocument' => ['uri' => 'file:///v.php', 'text' => $text, 'version' => 3]]),
        );

        $this->assertSame(3, $open['version'] ?? null);

        [$change] = $this->handle(
            new Message(jsonrpc: '2.0', method: 'textDocument/didChange', id: null, params: [
                'textDocument' => ['uri' => 'file:///v.php', 'version' => 4],
                'contentChanges' => [['text' => $text]],
            ]),
        );

        $this->assertSame(4, $change['version'] ?? null);
    }

    /**
     * A version that is not an integer names no document state: none is
     * invented for the push.
     *
     * @return iterable<string, array{textDocument: array<string, mixed>}>
     */
    public static function provideVersionsThatAreNoInt(): iterable
    {
        yield 'absent' => ['textDocument' => ['uri' => 'file:///v.php', 'text' => "<?php\npreg_match('/(/', \$s);\n"]];
        yield 'a string' => ['textDocument' => ['uri' => 'file:///v.php', 'text' => "<?php\npreg_match('/(/', \$s);\n", 'version' => '3']];
        yield 'null' => ['textDocument' => ['uri' => 'file:///v.php', 'text' => "<?php\npreg_match('/(/', \$s);\n", 'version' => null]];
    }

    /**
     * @param array<string, mixed> $textDocument
     */
    #[Test]
    #[DataProvider('provideVersionsThatAreNoInt')]
    public function test_a_version_that_is_no_int_is_left_out(array $textDocument): void
    {
        [$push] = $this->handle(new Message(jsonrpc: '2.0', method: 'textDocument/didOpen', id: null, params: ['textDocument' => $textDocument]));

        $this->assertIsArray($push);
        $this->assertArrayNotHasKey('version', $push);
    }

    /**
     * The push that clears a closed document is empty and carries no
     * version: VS Code ignores one there, and the empty push is all the
     * editor needs to drop the diagnostics it holds.
     */
    #[Test]
    public function test_the_push_that_clears_a_closed_document_is_empty_and_has_no_version(): void
    {
        [, $clear] = $this->handle(
            new Message(jsonrpc: '2.0', method: 'textDocument/didOpen', id: null, params: ['textDocument' => ['uri' => 'file:///v.php', 'text' => "<?php\npreg_match('/(/', \$s);\n", 'version' => 3]]),
            new Message(jsonrpc: '2.0', method: 'textDocument/didClose', id: null, params: ['textDocument' => ['uri' => 'file:///v.php']]),
        );

        $this->assertIsArray($clear);
        $this->assertSame(['uri' => 'file:///v.php', 'diagnostics' => []], $clear);
    }

    /**
     * The params of the publishDiagnostics pushes a sequence of messages
     * writes, in order.
     *
     * @return non-empty-list<array<string, mixed>>
     */
    private function handle(Message ...$messages): array
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        $handler = new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null]));
        foreach ($messages as $message) {
            $method = substr((string) $message->method, (int) strrpos((string) $message->method, '/') + 1);
            $handler->{$method}($message);
        }

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $params = [];
        foreach (preg_split('/Content-Length: \d+\r\n\r\n/', $written) ?: [] as $body) {
            $decoded = json_decode($body, true);
            if (\is_array($decoded) && 'textDocument/publishDiagnostics' === ($decoded['method'] ?? null) && \is_array($decoded['params'] ?? null)) {
                /** @var array<string, mixed> $push */
                $push = $decoded['params'];
                $params[] = $push;
            }
        }

        $this->assertNotSame([], $params, 'No diagnostics were published.');

        return $params;
    }

    /**
     * The diagnostics published for a document holding the line after
     * "<?php".
     *
     * @param array{max_recursion_depth?: int} $options
     *
     * @return array<mixed>
     */
    private function publish(string $line, array $options = []): array
    {
        $stream = fopen('php://memory', 'w+');
        $this->assertIsResource($stream);

        (new TextDocumentHandler(new Transport($stream), $this->documents, Regex::create(['cache' => null, 'pcre_version' => '10.49', ...$options])))->didOpen(new Message(
            jsonrpc: '2.0',
            method: 'textDocument/didOpen',
            id: null,
            params: ['textDocument' => ['uri' => 'file:///u.php', 'text' => "<?php\n".$line."\n"]],
        ));

        rewind($stream);
        $written = (string) stream_get_contents($stream);
        $payload = json_decode(substr($written, (int) strpos($written, "\r\n\r\n") + 4), true);
        $this->assertIsArray($payload);
        $params = $payload['params'] ?? null;
        $this->assertIsArray($params);
        $diagnostics = $params['diagnostics'] ?? null;
        $this->assertIsArray($diagnostics);

        return $diagnostics;
    }

    /**
     * The text a diagnostic's range covers on the line, the columns read
     * as UTF-16 code units as the protocol counts them.
     *
     * @param array<mixed> $diagnostic
     */
    private static function underlined(string $line, array $diagnostic): string
    {
        $range = $diagnostic['range'] ?? null;
        self::assertIsArray($range);
        $start = $range['start'] ?? null;
        $end = $range['end'] ?? null;
        self::assertIsArray($start);
        self::assertIsArray($end);
        self::assertSame(1, $start['line'] ?? null);
        self::assertSame(1, $end['line'] ?? null);
        self::assertIsInt($start['character'] ?? null);
        self::assertIsInt($end['character'] ?? null);

        $units = mb_convert_encoding($line, 'UTF-16LE', 'UTF-8');

        return mb_convert_encoding(substr($units, 2 * $start['character'], 2 * ($end['character'] - $start['character'])), 'UTF-8', 'UTF-16LE');
    }
}
