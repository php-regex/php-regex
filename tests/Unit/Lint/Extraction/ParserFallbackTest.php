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
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
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
 * A first-class callable on a pattern function holds no pattern and drops
 * nothing else of its file.
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

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);

        $this->assertSame(['/a+/', '/b+/'], array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $occurrences));
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

    #[Test]
    public function test_a_parse_error_falls_back_to_the_tokenizer(): void
    {
        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\nfunction (\n");

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);

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

        $occurrences = (new PhpParserExtractionStrategy())->extract([$file]);

        $this->assertCount(1, $occurrences);
        $this->assertNotNull($occurrences[0]->parserFallback);
        $this->assertSame(1, LintStatsCounter::count([], $occurrences)['parserFallbacks'] ?? null);
    }

    #[Test]
    public function test_a_bug_in_the_parser_strategy_is_not_swallowed(): void
    {
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

        $reflection = new \ReflectionClass(PhpParserExtractionStrategy::class);
        $strategy = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('parser')->setValue($strategy, $parser);
        $reflection->getProperty('registry')->setValue($strategy, PatternFunctionRegistry::defaults());

        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\n");

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A bug of the extractor.');

        $strategy->extract([$file]);
    }

    #[Test]
    public function test_a_fallback_survives_the_forked_workers(): void
    {
        $broken = $this->writeFile("<?php\npreg_match('/a+/', \$s);\nfunction (\n");
        $clean = $this->writeFile("<?php\npreg_match('/b+/', \$s);\n");

        $occurrences = (new PatternExtractor(new PhpParserExtractionStrategy()))->extract([$broken, $clean], [], null, 2);

        $fallbacks = array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->parserFallback));
        $this->assertCount(1, $fallbacks);
        $this->assertSame($broken, $fallbacks[0]->file);
        $this->assertCount(3, $occurrences);
    }

    #[Test]
    public function test_an_inline_ignore_on_the_first_line_keeps_the_fallback(): void
    {
        $file = $this->writeFile("<?php preg_match('/a+/', \$s); // @regex-ignore\nfunction (\n");

        $occurrences = (new PatternExtractor(new PhpParserExtractionStrategy()))->extract([$file], []);

        $fallbacks = array_values(array_filter($occurrences, static fn (PatternOccurrence $occurrence): bool => null !== $occurrence->parserFallback));
        $this->assertCount(1, $fallbacks);
        $this->assertFalse($fallbacks[0]->isIgnored);
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
