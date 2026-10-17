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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\RecordingLintProgress;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The service reports each phase of a run as a step: the collection steps
 * through the PHP source, then the analysis, then the optimizations when
 * the request asks for them. Each step is finished before the next starts,
 * and advanced once per item it covers, in the parent alone or on workers.
 */
final class LintServiceProgressTest extends TestCase
{
    use TemporaryProject;

    private const CALLER = "<?php\npreg_match('/(a+)+/', \$s);\npreg_match('/[0-9]/', \$s);\n";

    /**
     * @return iterable<string, array{checkOptimizations: bool, expected: list<array{string, ?int, int}>}>
     */
    public static function provideAnalysisSteps(): iterable
    {
        yield 'with optimizations' => [
            'checkOptimizations' => true,
            'expected' => [[LintProgress::STEP_ANALYSIS, 4, 4], [LintProgress::STEP_OPTIMIZATIONS, 4, 4]],
        ];
        yield 'without optimizations' => [
            'checkOptimizations' => false,
            'expected' => [[LintProgress::STEP_ANALYSIS, 4, 4]],
        ];
    }

    /**
     * @param list<array{string, ?int, int}> $expected
     */
    #[Test]
    #[DataProvider('provideAnalysisSteps')]
    public function test_analyze_reports_the_analysis_step_then_the_optimizations_step(bool $checkOptimizations, array $expected): void
    {
        $progress = new RecordingLintProgress();

        self::service()->analyze(self::patterns(), new LintRequest(['.'], [], 0, checkOptimizations: $checkOptimizations), $progress);

        $this->assertSame($expected, $progress->stepSums());
        $this->assertSame(array_column($expected, 0), $progress->finishedSteps());
        $this->assertSame(0, $progress->advancedOutsideSteps());
    }

    /**
     * No pattern: each step is started at zero and finished.
     */
    #[Test]
    public function test_analyze_with_no_patterns_starts_and_finishes_each_step_at_zero(): void
    {
        $progress = new RecordingLintProgress();

        $report = self::service()->analyze([], new LintRequest(['.'], [], 0), $progress);

        $this->assertSame([], $report->results);
        $this->assertSame([[LintProgress::STEP_ANALYSIS, 0, 0], [LintProgress::STEP_OPTIMIZATIONS, 0, 0]], $progress->stepSums());
        $this->assertSame([LintProgress::STEP_ANALYSIS, LintProgress::STEP_OPTIMIZATIONS], $progress->finishedSteps());
    }

    /**
     * On workers: the same report, the same steps with the same sums, and
     * each step names the workers it started.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_analyze_on_workers_gives_the_serial_report_and_steps(): void
    {
        $serialProgress = new RecordingLintProgress();
        $parallelProgress = new RecordingLintProgress();

        $serial = self::service()->analyze(self::patterns(), new LintRequest(['.'], [], 0, analysisWorkers: 1), $serialProgress);
        $parallel = self::service()->analyze(self::patterns(), new LintRequest(['.'], [], 0, analysisWorkers: 2), $parallelProgress);

        $this->assertEquals($serial, $parallel);
        $this->assertSame($serialProgress->stepSums(), $parallelProgress->stepSums());
        $this->assertSame([], $serialProgress->workerCounts());
        $this->assertSame([2], $parallelProgress->workerCountsIn(LintProgress::STEP_ANALYSIS));
        $this->assertSame([2], $parallelProgress->workerCountsIn(LintProgress::STEP_OPTIMIZATIONS));
    }

    /**
     * @return iterable<string, array{workers: int}>
     */
    public static function provideCollectionWorkers(): iterable
    {
        yield 'in the parent' => ['workers' => 1];
        yield 'on three workers' => ['workers' => 3];
    }

    /**
     * The collection hands the progress to the PHP source, which reports
     * the file walk, the declarations and the extraction.
     */
    #[Test]
    #[DataProvider('provideCollectionWorkers')]
    #[RequiresFunction('pcntl_fork')]
    public function test_collect_patterns_reports_the_collection_steps(int $workers): void
    {
        $project = $this->makeProject(['lib/a.php' => self::CALLER, 'lib/b.php' => "<?php\n"]);
        $service = new LintService(
            new AnalysisService(RegexParser::create()),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy()))]),
        );
        $progress = new RecordingLintProgress();

        $patterns = $service->collectPatterns(new LintRequest([$project.'/lib'], [], 0, analysisWorkers: $workers), $progress);

        $this->assertSame(['/(a+)+/', '/[0-9]/'], array_values(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, array_filter($patterns, static fn (PatternOccurrence $occurrence): bool => null === $occurrence->ignores))));
        // Each file read comes with its inline markers, a file without a pattern too.
        $scanned = array_values(array_map(static fn (PatternOccurrence $occurrence): string => basename($occurrence->file), array_filter($patterns, static fn (PatternOccurrence $occurrence): bool => [] === $occurrence->ignores)));
        sort($scanned);
        $this->assertSame(['a.php', 'b.php'], $scanned);
        $this->assertSame([
            [LintProgress::STEP_FILES, null, 2],
            [LintProgress::STEP_DECLARATIONS, 2, 2],
            [LintProgress::STEP_PATTERNS, 2, 2],
        ], $progress->stepSums());
        $this->assertSame([LintProgress::STEP_FILES, LintProgress::STEP_DECLARATIONS, LintProgress::STEP_PATTERNS], $progress->finishedSteps());
    }

    /**
     * More workers allowed than patterns: one worker per pattern starts,
     * no idle one, and the report is the serial one.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_analyze_on_more_workers_than_patterns_starts_one_per_pattern(): void
    {
        $serial = self::service()->analyze(self::patterns(), new LintRequest(['.'], [], 0, analysisWorkers: 1));
        $progress = new RecordingLintProgress();

        $parallel = self::service()->analyze(self::patterns(), new LintRequest(['.'], [], 0, analysisWorkers: 8), $progress);

        $this->assertEquals($serial, $parallel);
        $this->assertSame([4], $progress->workerCountsIn(LintProgress::STEP_ANALYSIS));
        $this->assertSame([4], $progress->workerCountsIn(LintProgress::STEP_OPTIMIZATIONS));
        $this->assertSame([[LintProgress::STEP_ANALYSIS, 4, 4], [LintProgress::STEP_OPTIMIZATIONS, 4, 4]], $progress->stepSums());
    }

    /**
     * More workers allowed than files: each collection step starts one
     * worker per file, and finds what the parent alone finds.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_collect_patterns_on_more_workers_than_files_starts_one_per_file(): void
    {
        $project = $this->makeProject(['lib/a.php' => self::CALLER, 'lib/b.php' => "<?php\n"]);
        $service = new LintService(
            new AnalysisService(RegexParser::create()),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy()))]),
        );
        $progress = new RecordingLintProgress();

        $patterns = $service->collectPatterns(new LintRequest([$project.'/lib'], [], 0, analysisWorkers: 8), $progress);

        $this->assertSame(['/(a+)+/', '/[0-9]/'], array_values(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, array_filter($patterns, static fn (PatternOccurrence $occurrence): bool => null === $occurrence->ignores))));
        $this->assertSame([2], $progress->workerCountsIn(LintProgress::STEP_DECLARATIONS));
        $this->assertSame([2], $progress->workerCountsIn(LintProgress::STEP_PATTERNS));
        $this->assertSame([], $progress->workerCountsIn(LintProgress::STEP_FILES), 'The walk runs in the parent.');
    }

    private static function service(): LintService
    {
        return new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([]));
    }

    /**
     * A pattern with issues, one with an optimization, an ignored one and
     * an invalid one: each advances both steps once.
     *
     * @return list<PatternOccurrence>
     */
    private static function patterns(): array
    {
        return [
            new PatternOccurrence('/(a+)+/', 'a.php', 1, 'preg_match'),
            new PatternOccurrence('/[0-9]/', 'b.php', 2, 'preg_match'),
            new PatternOccurrence('/c{1}/', 'c.php', 3, 'preg_match', null, null, true),
            new PatternOccurrence('/[d/', 'd.php', 4, 'preg_match'),
        ];
    }
}
