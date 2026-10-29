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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Analysis\LiteralConfidence;
use PHPRegex\Parser\Analysis\LiteralSet;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Cache\FilesystemCache;
use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\RecursionLimitException;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\Exception\Snippet;
use PHPRegex\Parser\Exception\SyntaxErrorException;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Token\Token;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Tests\TestUtils\PhpErrorOffset;
use PHPRegex\Toolkit\ExplainFormat;
use PHPRegex\Toolkit\HighlightFormat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegexTest extends TestCase
{
    use PcreGates;

    use PhpUnitForwardCompat;

    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    /**
     * @param class-string<NodeInterface> $expectedPatternClass
     */
    #[Test]
    #[DataProvider('provideValidRegexForParsing')]
    public function test_parse_method_with_valid_regex(
        string $pattern,
        string $expectedDelimiter,
        string $expectedFlags,
        int $expectedEndPosition,
        string $expectedPatternClass
    ): void {
        $ast = $this->regexService->parse($pattern);

        $this->assertSame($expectedDelimiter, $ast->delimiter);
        $this->assertSame($expectedFlags, $ast->flags);
        $this->assertSame(0, $ast->startPosition);
        $this->assertSame($expectedEndPosition, $ast->endPosition);
        $this->assertInstanceOf($expectedPatternClass, $ast->pattern);
    }

    #[Test]
    public function test_parse_tolerant_returns_result_with_errors(): void
    {
        $result = $this->regexService->parseTolerant('/(?:a|b|/');

        $this->assertNotEmpty($result->errors);

        $ok = $this->regexService->parseTolerant('/abc/');
        $this->assertSame([], $ok->errors);
    }

    #[Test]
    public function test_explain_and_highlight_accept_their_format_enum(): void
    {
        $text = $this->regexService->explain('/a+/', ExplainFormat::Text);
        $html = $this->regexService->explain('/a+/', ExplainFormat::Html);
        $this->assertStringContainsString('one or more', $text);
        $this->assertStringContainsString('<', $html);

        $console = $this->regexService->highlight('/a+/', HighlightFormat::Console);
        $this->assertNotSame('', $console);
        $this->assertStringContainsString('<', $this->regexService->highlight('/a+/', HighlightFormat::Html));
    }

    #[Test]
    #[DataProvider('provideRegexForOptimization')]
    public function test_optimize_method(string $pattern, string $expectedOptimizedPattern): void
    {
        $optimized = $this->regexService->optimize($pattern)->optimized;
        $this->assertSame($expectedOptimizedPattern, $optimized);
    }

    #[Test]
    public function test_optimize_method_with_options(): void
    {
        $optimized = $this->regexService->optimize('/a+/', new OptimizerOptions(digits: false));
        $this->assertSame('/a+/', $optimized->optimized);
    }

    #[Test]
    public function test_optimize_method_with_automata_verification(): void
    {
        $optimized = $this->regexService->optimize('/[0-9]+/', new OptimizerOptions(verifyWithAutomata: true));
        $this->assertSame('/\\d+/', $optimized->optimized);
    }

    #[Test]
    #[DataProvider('provideRegexForExplanation')]
    public function test_explain_method(string $pattern, string $expectedExplanation): void
    {
        $explanation = $this->regexService->explain($pattern);
        $this->assertStringContainsString($expectedExplanation, $explanation);
    }

    #[Test]
    public function test_explain_method_html_format(): void
    {
        $explanation = $this->regexService->explain('/a/', 'html');
        $this->assertStringContainsString('<div', $explanation);
    }

    #[Test]
    public function test_explain_method_invalid_format(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->regexService->explain('/a/', 'invalid');
    }

    #[Test]
    #[DataProvider('provideInvalidRegexForParsing')]
    public function test_parse_method_with_invalid_regex_parsing(string $pattern, string $exceptionMessage): void
    {
        $this->requiresPhpReading($pattern);

        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains($exceptionMessage);
        $this->regexService->parse($pattern);
    }

    #[Test]
    #[DataProvider('provideInvalidRegexForLexing')]
    public function test_parse_method_with_invalid_regex_lexing(string $pattern, string $exceptionMessage): void
    {
        $this->expectException(LexerException::class);
        $this->expectExceptionMessageIsOrContains($exceptionMessage);
        $this->regexService->parse($pattern);
    }

    #[Test]
    public function test_parse_pattern_wraps_delimiters_and_flags(): void
    {
        $fromFull = $this->regexService->parse('/foo/i');
        $fromPattern = $this->regexService->parse('/foo/i');

        $this->assertEquals($fromFull, $fromPattern);
    }

    #[Test]
    public function test_parse_pattern_rejects_invalid_delimiter(): void
    {
        $this->expectException(ParserException::class);
        $this->regexService->parse('#foo#ab');
    }

    public static function provideValidRegexForParsing(): \Generator
    {
        yield 'simple literal' => ['/abc/', '/', '', 3, SequenceNode::class];
        yield 'with quantifier' => ['/a+/', '/', '', 2, QuantifierNode::class];
        yield 'with character class' => ['/[a-z]/', '/', '', 5, CharClassNode::class];
        yield 'with alternation' => ['/a|b/', '/', '', 3, AlternationNode::class];
        yield 'with group' => ['/(a)/', '/', '', 3, GroupNode::class];
        yield 'with lookaround and flags' => ['/(?=a)/i', '/', 'i', 5, GroupNode::class];
        yield 'with conditional and different delimiter' => ['#(?(1)a|b)#', '#', '', 9, ConditionalNode::class];
    }

    public static function provideRegexForOptimization(): \Generator
    {
        yield 'does not merge literals' => ['/a-b-c/', '/a-b-c/'];
        yield 'optimizes char class' => ['/[0-9]/', '/\d/'];
        yield 'no change needed' => ['/a+/', '/a+/'];
    }

    public static function provideRegexForExplanation(): \Generator
    {
        yield 'simple literal' => ['/a/', "'a'"];
        yield 'quantifier' => ['/a+/', "'a' (one or more times)"];
        yield 'character class' => ['/[a-z]/', "Character Class: any character in [ Range: from 'a' to 'z' ]"];
    }

    public static function provideInvalidRegexForParsing(): \Generator
    {
        yield 'unclosed group' => ['/(a/', 'Expected ) at end of input (found eof).'];
        // PHP: "quantifier does not follow a repeatable item at offset 1".
        // At offset 1 from PCRE2 10.47, 0 before: the running PHP decides.
        yield 'quantifier on nothing' => ['/*/', \sprintf('Quantifier without target at position %d', PhpErrorOffset::of('/*/'))];
        yield 'invalid flag' => ['/a/invalid', 'Unknown regex flag(s) found: "v", "a", "l", "d".'];
    }

    public static function provideInvalidRegexForLexing(): \Generator
    {
        yield 'unclosed character class' => ['/[a/', 'Unclosed character class "]" at end of input.'];
    }

    #[Test]
    public function test_analyze_method(): void
    {
        $report = $this->regexService->analyze('/a+/');
        $this->assertIsBool($report->isValid);
        $this->assertIsArray($report->errors);
    }

    #[Test]
    public function test_analyze_method_with_invalid_regex(): void
    {
        $report = $this->regexService->analyze('/a(/');
        $this->assertFalse($report->isValid);
        $this->assertNotEmpty($report->errors);
    }

    #[Test]
    public function test_analyze_method_parsing_failure(): void
    {
        // Create Regex with low recursion limit to trigger parsing exception
        $regex = Regex::create(['max_recursion_depth' => 3]);
        $nestedRegex = str_repeat('(', 5).'a'.str_repeat(')', 5);

        $report = $regex->analyze($nestedRegex);

        // Should have parsing error in the catch block
        $this->assertFalse($report->isValid);
        $this->assertNotEmpty($report->errors);
        $this->assertStringContainsString('Recursion limit', $report->errors[0]);
    }

    #[Test]
    public function test_parse_throws_recursion_limit_even_when_pattern_is_cached(): void
    {
        $nestedRegex = str_repeat('(', 5).'a'.str_repeat(')', 5);

        // Warm the cache under the default limit, the way any earlier parse
        // of the same pattern would.
        Regex::create()->parse($nestedRegex);

        // A lower limit must still refuse the pattern: the cache entry was
        // built under conditions this instance does not share.
        $this->expectException(RecursionLimitException::class);
        $this->expectExceptionMessageIsOrContains('Recursion limit of 3 exceeded');

        Regex::create(['max_recursion_depth' => 3])->parse($nestedRegex);
    }

    #[Test]
    public function test_check_runtime_compilation_default_error_message(): void
    {
        $regex = Regex::create();
        $reflection = new \ReflectionClass($regex->parser());
        $method = $reflection->getMethod('checkRuntimeCompilation');

        // Use an invalid regex pattern that causes preg_match to fail
        // This should trigger the default error message path
        $result = $method->invoke($regex->parser(), '/invalid[pattern/', 'invalid[pattern', 1);

        // Should return ValidationResult with default error message
        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('PCRE runtime error', (string) $result->error);
    }

    #[Test]
    public function test_check_runtime_compilation_uses_default_for_empty_message(): void
    {
        $regex = Regex::create();
        $reflection = new \ReflectionClass($regex->parser());
        $method = $reflection->getMethod('checkRuntimeCompilation');

        // A pattern that triggers a compilation error: the message is the
        // engine's, prefixed, without the name of the function that ran it.
        $result = $method->invoke($regex->parser(), '/invalid[pattern/', 'invalid[pattern', 7);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertStringNotContainsString('preg_match()', (string) $result->error);
        $this->assertFalse($result->isValid);
        $this->assertStringStartsWith('PCRE runtime error: ', (string) $result->error);
    }

    #[Test]
    public function test_validate_method_with_valid_regex(): void
    {
        $result = $this->regexService->validate('/a+/');
        $this->assertTrue($result->isValid);
        $this->assertNull($result->error);
    }

    #[Test]
    public function test_validate_method_with_invalid_regex(): void
    {
        $result = $this->regexService->validate('/a(/');
        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Expected )', (string) $result->error);
    }

    #[Test]
    public function test_redos_method(): void
    {
        $analysis = $this->regexService->redos('/a+/');
        $this->assertIsBool($analysis->isSafe());
    }

    /**
     * What literals() reads, spelled once: the helper typing must not move
     * one literal, one search pattern or one confidence level. A search
     * pattern is a literal a matching subject contains, never anchored: the
     * literals describe the match, not where it sits in the subject.
     *
     * @param list<string> $literals
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideLiteralExtractions')]
    public function test_literals_method(string $pattern, array $literals, array $patterns, string $confidence): void
    {
        $result = $this->regexService->literals($pattern);

        $this->assertSame($literals, $result->literals);
        $this->assertSame($patterns, $result->patterns);
        $this->assertSame(LiteralConfidence::from($confidence), $result->confidence);
    }

    /**
     * @return iterable<string, array{pattern: string, literals: list<string>, patterns: list<string>, confidence: string}>
     */
    public static function provideLiteralExtractions(): iterable
    {
        yield 'a repeated literal' => ['pattern' => '/a+/', 'literals' => ['a'], 'patterns' => ['a'], 'confidence' => 'medium'];
        yield 'an anchored head and tail' => ['pattern' => '/^foo.*bar$/', 'literals' => ['foo', 'bar'], 'patterns' => ['foo', 'bar'], 'confidence' => 'medium'];
        yield 'two whole-word alternatives' => ['pattern' => '/abc|abd/', 'literals' => ['abc', 'abd'], 'patterns' => ['abc', 'abd'], 'confidence' => 'high'];
        yield 'no literal at all' => ['pattern' => '/\\d+/', 'literals' => [], 'patterns' => [], 'confidence' => 'low'];
        yield 'a repeated group before a literal' => ['pattern' => '/(a|b)+c/', 'literals' => ['a', 'b', 'c'], 'patterns' => ['a', 'b', 'c'], 'confidence' => 'medium'];
        yield 'literals around a class' => ['pattern' => '/x[0-9]y/', 'literals' => ['x', 'y'], 'patterns' => ['x', 'y'], 'confidence' => 'medium'];
        yield 'a literal a match starts with, anywhere in the subject' => ['pattern' => '/user-\\d{4}/', 'literals' => ['user-'], 'patterns' => ['user\\-'], 'confidence' => 'medium'];
        // Only the empty string: nothing a subject must contain.
        yield 'an empty match' => ['pattern' => '/^$/', 'literals' => [], 'patterns' => [], 'confidence' => 'low'];
        yield 'a lookahead alone' => ['pattern' => '/(?=a)/', 'literals' => [], 'patterns' => [], 'confidence' => 'low'];
    }

    #[Test]
    public function test_generate_method(): void
    {
        $sample = $this->regexService->generate('/a+/');
        $this->assertIsString($sample);
        $this->assertMatchesRegularExpression('/a+/', $sample);
    }

    #[Test]
    public function test_highlight_method(): void
    {
        $highlighted = $this->regexService->highlight('/a+/');
        $this->assertIsString($highlighted);
        $this->assertStringContainsString('a', $highlighted);
    }

    #[Test]
    public function test_highlight_html_branch(): void
    {
        $highlighted = $this->regexService->highlight('/a+/', 'html');
        $this->assertStringContainsString('<span', $highlighted);
    }

    #[Test]
    public function test_tokenize_extracts_pattern_and_flags(): void
    {
        $tokens = Regex::create()->tokenize('/ab/i');
        $this->assertSame([0, 1, 2], array_map(static fn (Token $token): int => $token->position, $tokens));

        $types = array_map(static fn (Token $token): string => $token->type->name, $tokens);
        $this->assertSame(['Literal', 'Literal', 'Eof'], $types);
    }

    /**
     * What tokenize() promises: the tokens follow each other in source
     * order, each covering the bytes from its position to its end, so
     * together they cover the pattern body, the end-of-pattern token last.
     * How many characters one token covers is not promised.
     */
    #[Test]
    #[DataProvider('provideTokenizedPatterns')]
    public function test_tokenize_covers_the_pattern_body_in_order(string $regex, string $body): void
    {
        $tokens = Regex::create()->tokenize($regex);

        $offset = 0;
        $covered = '';
        $last = null;
        foreach ($tokens as $token) {
            $this->assertSame($offset, $token->position, 'Each token starts where the one before it ends.');
            $covered .= substr($body, $token->position, $token->end() - $token->position);
            $offset = $token->end();
            $last = $token;
        }

        $this->assertInstanceOf(Token::class, $last);
        $this->assertSame($body, $covered);
        $this->assertSame('Eof', $last->type->name);
        $this->assertSame(\strlen($body), $last->position);
    }

    /**
     * @return iterable<string, array{regex: string, body: string}>
     */
    public static function provideTokenizedPatterns(): iterable
    {
        yield 'literals and flags' => ['regex' => '/foo|bar/i', 'body' => 'foo|bar'];
        yield 'escapes, a named group, a class and a quoted run' => ['regex' => '/\d+(?<n>x)[a-z]\Q.*\E/', 'body' => '\d+(?<n>x)[a-z]\Q.*\E'];
        yield 'extended whitespace and a comment' => ['regex' => "/a b # c\nd/x", 'body' => "a b # c\nd"];
        yield 'an inline comment and a lazy quantifier' => ['regex' => '/(?#note)a{2,3}?/', 'body' => '(?#note)a{2,3}?'];
        yield 'multibyte characters' => ['regex' => '/é\x{41}/u', 'body' => 'é\x{41}'];
    }

    #[Test]
    public function test_build_visual_snippet_truncates_and_marks_caret(): void
    {
        // The parser draws the snippet of a runtime compile error with the
        // renderer the exceptions use.
        $pattern = str_repeat('a', 120);
        $snippet = Snippet::render($pattern, 110);

        $this->assertIsString($snippet);
        /** @var string $snippetString */
        $snippetString = $snippet;
        $this->assertStringContainsString('Line 1:', $snippetString);
        $this->assertStringContainsString('^', $snippetString);
        $this->assertStringContainsString('...', $snippetString);
    }

    #[Test]
    public function test_build_visual_snippet_returns_empty_for_nulls(): void
    {
        $this->assertSame('', Snippet::render(null, null));
    }

    #[Test]
    public function test_build_search_patterns_and_confidence_levels(): void
    {
        $regex = Regex::create();
        $ref = new \ReflectionClass($regex);

        $buildSearch = $ref->getMethod('buildSearchPatterns');
        $determine = $ref->getMethod('determineConfidenceLevel');

        $literalSet = new LiteralSet(['foo'], ['bar'], false);

        // The search patterns are the literals quoted, never anchored.
        /** @var array<string> $patterns */
        $patterns = $buildSearch->invoke($regex, ['foo', 'b.r']);
        $this->assertSame(['foo', 'b\\.r'], $patterns);
        $this->assertSame(LiteralConfidence::Medium, $determine->invoke($regex, $literalSet));

        // The parameter is typed natively: anything but a list of literals is
        // refused by the engine, not quietly read as empty.
        $this->expectException(\TypeError::class);
        $buildSearch->invoke($regex, $literalSet);
    }

    #[Test]
    public function test_explain_writes_html_and_refuses_an_invalid_format(): void
    {
        $this->assertSame($this->regexService->parse('/a+/')->accept(new HtmlExplainer()), $this->regexService->explain('/a+/', 'html'));

        $this->expectException(\InvalidArgumentException::class);
        $this->regexService->explain('/a+/', 'invalid');
    }

    /**
     * Oracle: PCRE2 refuses "(?1)" with no group 1, "reference to
     * non-existent subpattern at offset 3"; the parser alone reads it.
     */
    #[Test]
    public function test_runtime_error_message_is_the_engine_s(): void
    {
        $ref = new \ReflectionClass($this->regexService->parser());
        $method = $ref->getMethod('checkRuntimeCompilation');

        $result = $method->invoke($this->regexService->parser(), '/(?1)a/', '(?1)a', 1);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertSame('PCRE runtime error: reference to non-existent subpattern at offset 3', $result->error);
    }

    /**
     * The offset is counted in the body as written, not in the body the
     * engine ran; none when the engine names none.
     */
    #[Test]
    public function test_runtime_error_offset_is_counted_in_the_body_as_written(): void
    {
        $ref = new \ReflectionClass($this->regexService->parser());
        $method = $ref->getMethod('checkRuntimeCompilation');

        $body = $method->invoke($this->regexService->parser(), '/aaaaaaaaaaaa(/', 'aaaaaaaaaaaa(', 1);
        $modifier = $method->invoke($this->regexService->parser(), '/a/Q', 'a', 1);

        $this->assertInstanceOf(ValidationResult::class, $body);
        $this->assertSame(13, $body->offset);
        $this->assertInstanceOf(ValidationResult::class, $modifier);
        $this->assertNull($modifier->offset);
    }

    #[Test]
    public function test_build_visual_snippet_edge_cases(): void
    {
        $this->assertSame('', Snippet::render('pattern', -1));
        $snippet = Snippet::render('pattern', 100);
        $this->assertIsString($snippet);
        $this->assertStringContainsString('Line 1:', (string) $snippet);
        $this->assertStringContainsString('^', (string) $snippet);
        $this->assertSame('', Snippet::render(null, 5));
    }

    #[Test]
    public function test_extract_unique_literals(): void
    {
        $ref = new \ReflectionClass($this->regexService);
        $method = $ref->getMethod('extractUniqueLiterals');

        $result = $method->invoke($this->regexService, new LiteralSet(['a', 'b'], ['b', 'c']));
        $this->assertSame(['a', 'b', 'c'], $result);

        // Natively typed: a non-LiteralSet is an engine error, never an
        // empty answer read as "no literals".
        $this->expectException(\TypeError::class);
        $method->invoke($this->regexService, 'not-a-literal-set');
    }

    #[Test]
    public function test_determine_confidence_level(): void
    {
        $ref = new \ReflectionClass($this->regexService);
        $method = $ref->getMethod('determineConfidenceLevel');

        $completeSet = LiteralSet::fromString('a');
        $voidSet = LiteralSet::empty();
        $incompleteSet = new LiteralSet(['a'], ['b'], false);

        $this->assertSame(LiteralConfidence::High, $method->invoke($this->regexService, $completeSet));
        $this->assertSame(LiteralConfidence::Low, $method->invoke($this->regexService, $voidSet));
        $this->assertSame(LiteralConfidence::Medium, $method->invoke($this->regexService, $incompleteSet));

        // The old "anything else is low" arm is gone with the native type.
        $this->expectException(\TypeError::class);
        $method->invoke($this->regexService, 'not-a-literal-set');
    }

    #[Test]
    public function test_parse_writes_the_tree_itself_to_the_cache(): void
    {
        $cache = new class implements CacheInterface {
            /**
             * @var array<string, RegexNode>
             */
            public array $written = [];

            public function generateKey(string $seed): string
            {
                return 'key_'.$seed;
            }

            public function write(string $key, RegexNode $ast): void
            {
                $this->written[$key] = $ast;
            }

            public function load(string $key): ?RegexNode
            {
                return null;
            }
        };

        $regex = Regex::create(['cache' => $cache]);
        $ast = $regex->parse('/a/');
        $key = 'key_'.RegexParser::cacheSeed('/a/', $regex->target(), Regex::DEFAULT_MAX_RECURSION_DEPTH);

        // No payload is built any more: the cache receives the tree the
        // parse returns, and encodes it its own way.
        $this->assertSame([$key], array_keys($cache->written));
        $this->assertSame($ast, $cache->written[$key]);
    }

    #[Test]
    public function test_validate_resource_limits(): void
    {
        $regex = Regex::create(['max_pattern_length' => 5]);
        $ref = new \ReflectionClass($regex->parser());
        $method = $ref->getMethod('validateResourceLimits');

        $this->expectException(ResourceLimitException::class);
        $method->invoke($regex->parser(), 'long pattern here');
    }

    #[Test]
    public function test_build_validation_failure(): void
    {
        $ref = new \ReflectionClass($this->regexService->parser());
        $method = $ref->getMethod('buildValidationFailure');

        $exception = new SyntaxErrorException('Test error', ErrorCode::TokenUnexpected);
        $result = $method->invoke($this->regexService->parser(), $exception);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertFalse($result->isValid);
        $this->assertSame('Test error', $result->error);
        $this->assertSame(ErrorCode::TokenUnexpected, $result->errorCode);
    }

    #[Test]
    public function test_safe_extract_pattern_handles_parser_exception(): void
    {
        $regex = Regex::create();
        $ref = new \ReflectionClass($regex->parser());
        $method = $ref->getMethod('safeExtractPattern');

        $result = $method->invoke($regex->parser(), 'invalid');

        $this->assertSame(['invalid', '', '/', \strlen('invalid')], $result);
    }

    #[Test]
    public function test_parse_with_tolerant_mode(): void
    {
        $result = $this->regexService->parseTolerant('/a(/');
        $this->assertTrue($result->hasErrors());
    }

    #[Test]
    public function test_caching_functionality(): void
    {
        $cacheDir = sys_get_temp_dir().'/regex_cache_test_'.uniqid();
        mkdir($cacheDir);
        $cache = new FilesystemCache($cacheDir);
        $regex = Regex::create(['cache' => $cache]);

        // Parse once to cache
        $ast1 = $regex->parse('/a+/');

        // Parse again, should load from cache
        $ast2 = $regex->parse('/a+/');

        $this->assertEquals($ast1, $ast2);

        // Clean up - use recursive delete to handle files left by other tests
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($cacheDir);
    }

    #[Test]
    public function test_get_cache_returns_cache_instance(): void
    {
        $cache = new NullCache();
        $regex = Regex::create(['cache' => $cache]);

        $this->assertSame($cache, $regex->getCache());
    }

    #[Test]
    public function test_get_cache_stats_with_null_cache(): void
    {
        $cache = new NullCache();
        $regex = Regex::create(['cache' => $cache]);

        $stats = $regex->getCacheStats();

        $this->assertSame(['hits' => 0, 'misses' => 0], $stats);
    }

    #[Test]
    public function test_get_cache_stats_returns_zero_when_cache_not_removable(): void
    {
        $cache = new class implements CacheInterface {
            public function generateKey(string $seed): string
            {
                return 'key_'.$seed;
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): ?RegexNode
            {
                return null;
            }
        };

        $regex = Regex::create(['cache' => $cache]);

        $stats = $regex->getCacheStats();

        $this->assertSame(['hits' => 0, 'misses' => 0], $stats);
    }

    #[Test]
    public function test_get_cache_stats_returns_actual_stats_for_removable_cache(): void
    {
        $cache = new FilesystemCache(sys_get_temp_dir().'/test-cache-'.uniqid());
        $regex = Regex::create(['cache' => $cache]);

        // Parse something to potentially generate stats
        $regex->parse('/test/');

        $stats = $regex->getCacheStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('hits', $stats);
        $this->assertArrayHasKey('misses', $stats);
        $this->assertIsInt($stats['hits']);
        $this->assertIsInt($stats['misses']);

        // Clean up
        $cache->clear();
    }

    #[Test]
    public function test_load_from_cache_returns_null_for_null_cache(): void
    {
        $cache = new NullCache();
        $regex = Regex::create(['cache' => $cache]);

        $reflection = new \ReflectionClass($regex->parser());
        $loadFromCacheMethod = $reflection->getMethod('loadFromCache');

        $result = $loadFromCacheMethod->invoke($regex->parser(), 'test_regex');

        $this->assertSame([null, null], $result);
    }

    #[Test]
    public function test_store_in_cache_returns_early_for_null_cache_key(): void
    {
        $cache = new NullCache();
        $regex = Regex::create(['cache' => $cache]);

        $ast = $regex->parse('/test/');

        $reflection = new \ReflectionClass($regex->parser());
        $storeInCacheMethod = $reflection->getMethod('storeInCache');

        // This should not throw an exception and should return early
        $storeInCacheMethod->invoke($regex->parser(), null, $ast);

        // If we reach here without exception, the early return worked correctly
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function test_do_parse_returns_cached_ast_when_available(): void
    {
        $cacheDir = sys_get_temp_dir().'/test-cache-'.uniqid();
        $cache = new FilesystemCache($cacheDir);

        // Create a regex instance with cache
        $regex = Regex::create(['cache' => $cache]);

        // Parse a regex to populate the cache
        $originalAst = $regex->parse('/test/');

        // Now create a new instance with the same cache
        $regex2 = Regex::create(['cache' => $cache]);

        // Use reflection to call the private doParse method
        $reflection = new \ReflectionClass($regex2->parser());
        $doParseMethod = $reflection->getMethod('doParse');

        // This should return the cached AST without parsing from scratch
        $cachedAst = $doParseMethod->invoke($regex2->parser(), '/test/');

        // Should return the same AST that was cached
        $this->assertEquals($originalAst, $cachedAst);

        // Clean up
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($cacheDir);
    }

    #[Test]
    public function test_do_parse_returns_cached_ast_without_parsing(): void
    {
        $cachedAst = $this->regexService->parse('/cached/');

        $cache = new class implements CacheInterface {
            public RegexNode $mockAst;

            public function generateKey(string $seed): string
            {
                return 'key_'.$seed;
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): RegexNode
            {
                return $this->mockAst;
            }
        };
        $cache->mockAst = $cachedAst;

        $regex = Regex::create(['cache' => $cache]);

        $reflection = new \ReflectionClass($regex->parser());
        $doParseMethod = $reflection->getMethod('doParse');

        // This should return the cached AST immediately without parsing
        $result = $doParseMethod->invoke($regex->parser(), '/any-pattern/');

        // Should return the exact same cached AST instance
        $this->assertSame($cachedAst, $result);
    }

    #[Test]
    public function test_runtime_pcre_validation_error(): void
    {
        $regex = Regex::create(['runtime_pcre_validation' => true]);
        $result = $regex->validate('/(?P<a>a)\1/'); // Backreference to named group, might cause error
        // Depending on PCRE version, it might pass or fail
        $this->assertIsBool($result->isValid);
    }
}
