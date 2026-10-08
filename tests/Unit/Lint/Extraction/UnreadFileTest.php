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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A file the extractor cannot read, or that does not fit in the memory
 * limit, holds patterns nobody linted: it is reported as
 * regex.lint.source.unreadable, never left out as if it were clean.
 */
final class UnreadFileTest extends TestCase
{
    private ?string $file = null;

    private string|false $memoryLimit = false;

    protected function tearDown(): void
    {
        if (false !== $this->memoryLimit) {
            ini_set('memory_limit', $this->memoryLimit);
        }

        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    #[Test]
    public function test_a_file_over_the_memory_budget_is_reported_unread(): void
    {
        $occurrences = $this->extractUnderALowLimit();

        $this->assertCount(1, $occurrences);
        $this->assertSame($this->file, $occurrences[0]->file);
        $this->assertNotNull($occurrences[0]->unread);
        $this->assertStringContainsString('memory_limit', (string) $occurrences[0]->unread);
    }

    /**
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_each_strategy_reports_a_file_over_the_memory_budget(\Closure $strategy): void
    {
        $occurrences = $this->extractUnderALowLimit($strategy());

        $this->assertCount(1, $occurrences);
        $this->assertStringContainsString('memory_limit', (string) $occurrences[0]->unread);
    }

    /**
     * @param \Closure(): ExtractorInterface $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_each_strategy_reports_a_file_it_cannot_read(\Closure $strategy): void
    {
        $file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\n");
        $this->file = $file;
        chmod($file, 0o000);

        // Root reads a file whatever its mode: then the file is linted.
        $readable = is_readable($file);

        try {
            $occurrences = array_values($strategy()->extract([$file]));
        } finally {
            chmod($file, 0o644);
        }

        if ($readable) {
            $this->assertNotSame([], $occurrences);

            return;
        }

        $this->assertCount(1, $occurrences);
        $this->assertSame('Not linted: the file could not be read.', $occurrences[0]->unread);
    }

    /**
     * @return iterable<string, array{strategy: \Closure(): ExtractorInterface}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokens' => ['strategy' => static fn (): ExtractorInterface => new TokenBasedExtractionStrategy()];
        yield 'php-parser' => ['strategy' => static fn (): ExtractorInterface => new PhpParserExtractionStrategy()];
    }

    #[Test]
    public function test_an_unread_file_counts_as_one_step_of_progress(): void
    {
        $steps = 0;
        (new AnalysisService(RegexParser::create(['cache' => null])))->lint(
            [PatternOccurrence::unread('/x.php', 'Not linted.')],
            static function () use (&$steps): void {
                $steps++;
            },
        );

        $this->assertSame(1, $steps);
    }

    #[Test]
    public function test_the_lint_reports_an_unread_file_as_an_error(): void
    {
        $occurrences = $this->extractUnderALowLimit();
        ini_set('memory_limit', (string) $this->memoryLimit);
        $this->memoryLimit = false;

        $issues = (new AnalysisService(RegexParser::create(['cache' => null])))->lint($occurrences);

        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['type']);
        $this->assertSame('regex.lint.source.unreadable', $issues[0]['issueId'] ?? null);
        $this->assertSame($this->file, $issues[0]['file']);
    }

    #[Test]
    public function test_the_other_analyses_pass_an_unread_file_over(): void
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]));
        $unread = [new PatternOccurrence('', '/x.php', 1, 'php', unread: 'Not linted.')];

        $this->assertSame([], $service->suggestOptimizations($unread, 1));
        $this->assertSame([], $service->analyzeRedos($unread, RedosSeverity::Low));
    }

    /**
     * @return list<PatternOccurrence>
     */
    private function extractUnderALowLimit(?ExtractorInterface $strategy = null): array
    {
        // A megabyte of source needs some 60 MB of tokens.
        $this->file = $this->writeFile("<?php\npreg_match('/a+/', \$s);\n".str_repeat("// padding\n", 100_000));

        $this->memoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 8 * 1024 * 1024));

        return array_values(($strategy ?? new TokenBasedExtractionStrategy())->extract([$this->file]));
    }

    private function writeFile(string $content): string
    {
        $base = tempnam(sys_get_temp_dir(), 'regex-unread-');
        $this->assertIsString($base);
        unlink($base);
        file_put_contents($base.'.php', $content);

        return $base.'.php';
    }
}
