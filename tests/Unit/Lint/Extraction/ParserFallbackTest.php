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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PhpParser\ErrorHandler;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Internal\LintStatsCounter;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file the parser strategy cannot read is read again with the tokenizer:
 * its patterns are linted, and the run counts the fallback, never an error.
 * A first-class callable or a partial application of a pattern function
 * drops nothing else of its file.
 *
 * Without nikic/php-parser the parser strategy reads nothing, and the
 * tokenizer reads every file: each test checks the tokenizer always, and
 * the parser strategy only where the parser is installed.
 */
final class ParserFallbackTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    #[Test]
    #[DataProvider('provideFirstClassCallables')]
    public function test_a_first_class_callable_does_not_drop_the_file(string $callable): void
    {
        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\n\$f = {$callable};\npreg_replace('/b+/', '', \$s);\n");

        $this->assertSame(['/a+/', '/b+/'], self::patternsOf((new TokenBasedExtractionStrategy())->extract([$file])));

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);
        if (!self::parserAvailable()) {
            $this->assertSame([], $occurrences);

            return;
        }

        $this->assertSame(['/a+/', '/b+/'], self::patternsOf($occurrences));
        $this->assertSame([null, null], array_map(static fn (PatternOccurrence $occurrence): ?string => $occurrence->parserFallback, $occurrences));
    }

    /**
     * @return iterable<string, array{callable: string}>
     */
    public static function provideFirstClassCallables(): iterable
    {
        yield 'function' => ['callable' => 'preg_match(...)'];
        yield 'static method' => ['callable' => '\Composer\Pcre\Preg::match(...)'];
    }

    /**
     * A "?" or a trailing "..." placeholder: PHP 8.4 refuses the syntax,
     * nikic/php-parser 5.9 reads it, and its getArgs() asserts on it.
     */
    #[Test]
    #[DataProvider('providePartialApplications')]
    public function test_a_partial_application_does_not_abort_the_file(string $call): void
    {
        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\n\$f = {$call};\npreg_replace('/b+/', '', \$s);\n");

        $tokens = self::patternsOf((new TokenBasedExtractionStrategy())->extract([$file]));
        $this->assertContains('/a+/', $tokens);
        $this->assertContains('/b+/', $tokens);

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);
        if (!self::parserAvailable()) {
            $this->assertSame([], $occurrences);

            return;
        }

        $this->assertSame(['/a+/', '/b+/'], self::patternsOf($occurrences));
    }

    /**
     * @return iterable<string, array{call: string}>
     */
    public static function providePartialApplications(): iterable
    {
        yield 'placeholder for the pattern' => ['call' => 'preg_match(?, "abc")'];
        yield 'placeholders after the pattern' => ['call' => 'preg_replace("/(a/", ?, ?)'];
        yield 'static method' => ['call' => '\Composer\Pcre\Preg::match("/(a/", ?)'];
        yield 'named arguments' => ['call' => 'preg_match(subject: ?, pattern: "/(a/")'];
        yield 'variadic placeholder after the pattern' => ['call' => 'preg_match("/(a/", ...)'];
    }

    #[Test]
    public function test_a_parse_error_falls_back_to_the_tokenizer(): void
    {
        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\nfunction (\n");

        $this->assertSame(['/a+/'], self::patternsOf((new TokenBasedExtractionStrategy())->extract([$file])));

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);
        if (!self::parserAvailable()) {
            $this->assertSame([], $occurrences);

            return;
        }

        $patterns = array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null === $occurrence->parserFallback));
        $fallbacks = array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->parserFallback));

        $this->assertCount(1, $patterns);
        $this->assertSame('/a+/', $patterns[0]->pattern);
        $this->assertSame(2, $patterns[0]->line);
        $this->assertCount(1, $fallbacks);
        $this->assertSame($file, $fallbacks[0]->file);
        $this->assertStringStartsWith('Syntax error', (string) $fallbacks[0]->parserFallback);
        $this->assertNull($fallbacks[0]->unread);
    }

    #[Test]
    public function test_a_parse_error_in_a_file_without_patterns_is_still_counted(): void
    {
        $file = $this->writeFile("<?php\npreg_match(\$pattern\n");

        $this->assertSame([], (new TokenBasedExtractionStrategy())->extract([$file]));

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);
        if (!self::parserAvailable()) {
            $this->assertSame([], $occurrences);

            return;
        }

        $this->assertCount(1, $occurrences);
        $this->assertNotNull($occurrences[0]->parserFallback);
        $this->assertSame(1, LintStatsCounter::count([], $occurrences)['parserFallbacks'] ?? null);
    }

    /**
     * The tokenizer passes over a file holding a NUL byte: a file the parser
     * refuses for the same reason is then not linted at all, and said so.
     */
    #[Test]
    public function test_a_file_neither_reader_takes_is_reported_unread(): void
    {
        $file = $this->writeFile("<?php preg_match(\"/(nul/\", \$s); \$x=1;\x00");

        $this->assertSame([], (new TokenBasedExtractionStrategy())->extract([$file]));

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);
        if (!self::parserAvailable()) {
            $this->assertSame([], $occurrences);

            return;
        }

        $this->assertCount(1, $occurrences);
        $this->assertNull($occurrences[0]->parserFallback);
        $this->assertStringStartsWith('Not linted: ', (string) $occurrences[0]->unread);
        $this->assertStringContainsString('NUL byte', (string) $occurrences[0]->unread);
    }

    #[Test]
    public function test_a_bug_in_the_parser_strategy_is_not_swallowed(): void
    {
        if (!self::parserAvailable()) {
            $this->assertSame([], (new PhpParserExtractionStrategy())->extract([__FILE__]));

            return;
        }

        $parser = new class implements Parser {
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                throw new \LogicException('A bug of the extractor.');
            }

            public function getTokens(): array
            {
                return [];
            }
        };

        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\n");

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A bug of the extractor.');

        self::strategyWith($parser)->extract([$file]);
    }

    /**
     * A PHP newer than the installed nikic/php-parser lexes tokens the
     * parser has no entry for: the parser's failure, not ours.
     */
    #[Test]
    public function test_a_lexer_newer_than_the_parser_falls_back_to_the_tokenizer(): void
    {
        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\n");

        $this->assertSame(['/a+/'], self::patternsOf((new TokenBasedExtractionStrategy())->extract([$file])));

        if (!self::parserAvailable()) {
            $this->assertSame([], (new PhpParserExtractionStrategy())->extract([$file]));

            return;
        }

        $parser = new class implements Parser {
            public function parse(string $code, ?ErrorHandler $errorHandler = null): ?array
            {
                throw new \RangeException('The lexer returned an invalid token (id=400, value=x)');
            }

            public function getTokens(): array
            {
                return [];
            }
        };

        $occurrences = self::strategyWith($parser)->extract([$file]);

        $this->assertCount(2, $occurrences);
        $this->assertSame('The lexer returned an invalid token (id=400, value=x)', $occurrences[0]->parserFallback);
        $this->assertSame('/a+/', $occurrences[1]->pattern);
    }

    #[Test]
    public function test_a_fallback_survives_the_forked_workers(): void
    {
        $broken = $this->writeFile("<?php\npreg_match('/a+/', \$s);\nfunction (\n");
        $clean = $this->writeFile("<?php\npreg_match('/b+/', \$s);\n");

        $this->assertSame(['/a+/', '/b+/'], self::patternsOf((new PatternExtractor(new TokenBasedExtractionStrategy()))->extract([$broken, $clean], [], null, 2)));

        $occurrences = (new PatternExtractor(new PhpParserExtractionStrategy()))->extract([$broken, $clean], [], null, 2);
        if (!self::parserAvailable()) {
            $this->assertSame([], $occurrences);

            return;
        }

        $fallbacks = array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->parserFallback));
        $this->assertCount(1, $fallbacks);
        $this->assertSame($broken, $fallbacks[0]->file);
        $this->assertCount(3, $occurrences);
    }

    #[Test]
    public function test_an_inline_ignore_on_the_first_line_keeps_the_fallback(): void
    {
        $file = $this->writeFile("<?php preg_match('/a+/', \$s); // @regex-ignore\nfunction (\n");

        $tokens = (new PatternExtractor(new TokenBasedExtractionStrategy()))->extract([$file], []);
        $this->assertCount(1, $tokens);
        $this->assertTrue($tokens[0]->isIgnored);

        $occurrences = (new PatternExtractor(new PhpParserExtractionStrategy()))->extract([$file], []);
        if (!self::parserAvailable()) {
            $this->assertSame([], $occurrences);

            return;
        }

        $fallbacks = array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->parserFallback));
        $this->assertCount(1, $fallbacks);
        $this->assertFalse($fallbacks[0]->isIgnored);
    }

    /**
     * An inline ignore on line 1 silences the patterns of that line, never
     * the marker of a file that was not linted.
     */
    #[Test]
    public function test_an_inline_ignore_on_the_first_line_keeps_an_unread_marker(): void
    {
        $file = $this->writeFile("<?php preg_match('/a+/', \$s); // @regex-ignore\n");
        $extractor = new class implements ExtractorInterface {
            public function extract(array $files): array
            {
                return [PatternOccurrence::unread($files[0], 'Not linted.')];
            }
        };

        $occurrences = (new PatternExtractor($extractor))->extract([$file], []);

        $this->assertCount(1, $occurrences);
        $this->assertSame('Not linted.', $occurrences[0]->unread);
        $this->assertFalse($occurrences[0]->isIgnored);
    }

    #[Test]
    public function test_the_analyses_pass_a_fallback_over(): void
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]));
        $fallback = [PatternOccurrence::parserFallback('/x.php', 'Syntax error, unexpected EOF on line 3')];

        $steps = 0;
        $issues = $service->lint($fallback, static function () use (&$steps): void {
            $steps++;
        });

        $this->assertSame([], $issues);
        $this->assertSame(1, $steps);
        $this->assertSame([], $service->suggestOptimizations($fallback, 1));
        $this->assertSame([], $service->analyzeRedos($fallback, RedosSeverity::Low));
    }

    #[Test]
    public function test_the_stats_count_each_fallback_and_leave_the_count_out_at_zero(): void
    {
        $patterns = [
            new PatternOccurrence('/a+/', '/x.php', 2, 'preg_match()'),
            PatternOccurrence::parserFallback('/x.php', 'Syntax error'),
            PatternOccurrence::unread('/y.php', 'Not linted.'),
            PatternOccurrence::parserFallback('/z.php', 'Syntax error'),
        ];

        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'parserFallbacks' => 2], LintStatsCounter::count([], $patterns));
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0], LintStatsCounter::count([], [$patterns[0]]));
    }

    /**
     * A file a run is given twice, as a path and inside a directory, is one
     * file read with the tokenizer.
     */
    #[Test]
    public function test_the_stats_count_a_file_read_twice_once(): void
    {
        $patterns = [
            PatternOccurrence::parserFallback('/x.php', 'Syntax error'),
            PatternOccurrence::parserFallback('/x.php', 'Syntax error'),
            PatternOccurrence::parserFallback('/z.php', 'Syntax error'),
        ];

        $this->assertSame(2, LintStatsCounter::count([], $patterns)['parserFallbacks'] ?? null);
    }

    private static function parserAvailable(): bool
    {
        return class_exists(ParserFactory::class);
    }

    private static function strategyWith(Parser $parser): PhpParserExtractionStrategy
    {
        $reflection = new \ReflectionClass(PhpParserExtractionStrategy::class);
        $strategy = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('parser')->setValue($strategy, $parser);
        $reflection->getProperty('registry')->setValue($strategy, PatternFunctionRegistry::defaults());

        return $strategy;
    }

    /**
     * @param array<PatternOccurrence> $occurrences
     *
     * @return list<string>
     */
    private static function patternsOf(array $occurrences): array
    {
        $patterns = [];
        foreach ($occurrences as $occurrence) {
            if (null === $occurrence->parserFallback && null === $occurrence->unread) {
                $patterns[] = $occurrence->pattern;
            }
        }

        return $patterns;
    }

    private function writeFile(string $content): string
    {
        $base = tempnam(sys_get_temp_dir(), 'regex-fallback-');
        $this->assertIsString($base);
        unlink($base);
        file_put_contents($base.'.php', $content);
        $this->files[] = $base.'.php';

        return $base.'.php';
    }
}
