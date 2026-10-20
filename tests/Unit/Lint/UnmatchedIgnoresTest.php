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

use PhpParser\ParserFactory;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InlineIgnores;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "@regex-ignore" may name the issues it silences, and a marker that
 * silences nothing is reported as regex.ignore.unmatched, on its own line.
 *
 * "/\d+[11]/i" reports two issues: regex.lint.flag.useless.i (no letter for
 * "i" to fold) and regex.lint.charclass.redundant (the second "1"). "/x/"
 * reports none.
 */
final class UnmatchedIgnoresTest extends TestCase
{
    use TemporaryProject;

    protected function tearDown(): void
    {
        $this->removeTemporaryProjects();
    }

    /**
     * @return iterable<string, array{strategy: class-string<ExtractorInterface>}>
     */
    public static function provideStrategies(): iterable
    {
        yield 'tokenizer' => ['strategy' => TokenBasedExtractionStrategy::class];
        yield 'php-parser' => ['strategy' => PhpParserExtractionStrategy::class];
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_marker_naming_an_identifier_silences_only_that_issue(string $strategy): void
    {
        $report = $this->lint($strategy, "<?php\npreg_match('/\\d+[11]/i', \$s); // @regex-ignore regex.lint.flag.useless.i\n");

        $this->assertSame([[2, 'regex.lint.charclass.redundant']], self::issues($report));
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_bare_marker_silences_every_issue_of_its_line_and_is_not_reported(string $strategy): void
    {
        $report = $this->lint($strategy, "<?php\n// @regex-ignore-next-line\npreg_match('/\\d+[11]/i', \$s);\n");

        $this->assertSame([], self::issues($report));
        $this->assertSame(0, $report->stats['errors']);
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_a_marker_that_silences_nothing_is_reported_on_its_line(string $strategy): void
    {
        $report = $this->lint($strategy, <<<'PHP'
            <?php
            preg_match('/x/', $s); // @regex-ignore
            /*
             * Nothing below this comment.
             * @regex-ignore-next-line
             */

            $kept = 1; // @regex-ignore
            PHP);

        $this->assertSame([
            [2, 'regex.ignore.unmatched'],
            [5, 'regex.ignore.unmatched'],
            [8, 'regex.ignore.unmatched'],
        ], self::issues($report));
        $this->assertSame(3, $report->stats['errors']);
        $this->assertSame(
            'The @regex-ignore marker silences nothing: no issue is reported on line 2. Remove it.',
            $report->results[0]['issues'][0]->message,
        );
        $this->assertSame('The @regex-ignore-next-line marker silences nothing: no issue is reported on line 7. Remove it.', $report->results[1]['issues'][0]->message);
    }

    /**
     * An identifier that silences nothing is reported by name, whether the
     * issue is gone or the identifier names no issue at all; the others of
     * the marker still silence theirs.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_an_identifier_that_silences_nothing_is_reported_by_name(string $strategy): void
    {
        $report = $this->lint($strategy, "<?php\npreg_match('/\\d+[11]/i', \$s); // @regex-ignore regex.lint.charclass.redundant, regex.lint.no.such.rule, regex.lint.quantifier.nested\n");

        $this->assertSame([
            [2, 'regex.ignore.unmatched'],
            [2, 'regex.ignore.unmatched'],
            [2, 'regex.lint.flag.useless.i'],
        ], self::issues($report));
        $messages = array_column(array_merge([], ...array_column($report->results, 'issues')), 'message');
        $this->assertContains('The @regex-ignore marker names regex.lint.no.such.rule, which no issue reports on line 2. Remove the identifier.', $messages);
        $this->assertContains('The @regex-ignore marker names regex.lint.quantifier.nested, which no issue reports on line 2. Remove the identifier.', $messages);
    }

    /**
     * The report of a marker is no issue of the line it covers: a bare
     * marker on its own line never silences it.
     *
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_the_report_of_a_marker_is_never_silenced_by_a_marker(string $strategy): void
    {
        $report = $this->lint($strategy, "<?php\n// @regex-ignore-next-line\n\$unused = 1; // @regex-ignore\n");

        $this->assertSame([[2, 'regex.ignore.unmatched'], [3, 'regex.ignore.unmatched']], self::issues($report));
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    #[Test]
    #[DataProvider('provideStrategies')]
    public function test_the_report_can_be_turned_off(string $strategy): void
    {
        $report = $this->lint($strategy, "<?php\npreg_match('/x/', \$s); // @regex-ignore regex.lint.redos\n\$kept = 1; // @regex-ignore\n", false);

        $this->assertSame([], self::issues($report));
    }

    /**
     * An identifier naming a check this run leaves off is passed over: lint
     * and ReDoS often run apart on the same code. A marker whose every
     * identifier is passed over is not reported; one that names no check
     * at all still is.
     */
    #[Test]
    public function test_an_identifier_of_a_check_the_run_leaves_off_is_passed_over(): void
    {
        $code = <<<'PHP'
            <?php
            preg_match('/x/', $s); // @regex-ignore regex.lint.redos, regex.lint.redos.search
            preg_match('/x/', $s); // @regex-ignore regex.optimization, regex.lint.complexity
            preg_match('/x/', $s); // @regex-ignore regex.lint.charclass.single
            preg_match('/x/', $s); // @regex-ignore regex.lint.quantifier.nested
            preg_match('/x/', $s); // @regex-ignore regex.group.unclosed
            preg_match('/x/', $s); // @regex-ignore regex.lint.compat.meaningChanges
            preg_match('/x/', $s); // @regex-ignore regex.lint.no.such.rule, regex.lint.redos
            PHP;
        $project = $this->makeProject(['src/a.php' => $code]);
        $parser = RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44', 'runtime_pcre_validation' => false]);
        $sources = new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy()))]);

        // Lint with quantifier.nested turned off, without ReDoS, optimizations, complexity nor validation.
        $lint = new LintService(new AnalysisService($parser, lintRules: ['quantifier.nested' => false]), $sources);
        $request = new LintRequest([$project.'/src'], [], 1, checkValidation: false, checkOptimizations: false, analysisWorkers: 1);
        $report = $lint->analyze($lint->collectPatterns($request), $request);
        $this->assertSame([[8, 'regex.ignore.unmatched']], self::issues($report));
        $this->assertSame('The @regex-ignore marker names regex.lint.no.such.rule, which no issue reports on line 8. Remove the identifier.', $report->results[0]['issues'][0]->message);

        // Every check on: each identifier silencing nothing is reported.
        $lint = new LintService(new AnalysisService($parser, redosEnabled: true, lintRules: ['charclass.single' => true]), $sources);
        $request = new LintRequest([$project.'/src'], [], 1, checkRedos: true, checkOptimizations: true, analysisWorkers: 1, checkComplexity: true);
        $report = $lint->analyze($lint->collectPatterns($request), $request);
        $lines = array_count_values(array_column(array_filter(array_merge([], ...array_column($report->results, 'issues')), static fn (LintIssue $issue): bool => 'regex.ignore.unmatched' === $issue->issueId), 'line'));
        ksort($lines);
        $this->assertSame([2 => 2, 3 => 2, 4 => 1, 5 => 1, 6 => 1, 8 => 2], $lines);
    }

    /**
     * A run that leaves the ReDoS check off passes over its identifiers,
     * whatever the analysis would run.
     */
    #[Test]
    public function test_a_run_without_redos_passes_over_its_identifiers(): void
    {
        $project = $this->makeProject(['src/a.php' => "<?php\npreg_match('/x/', \$s); // @regex-ignore regex.lint.redos\npreg_match('/x/', \$s); // @regex-ignore regex.lint.redos.search\n"]);
        $parser = RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44', 'runtime_pcre_validation' => false]);
        $sources = new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy()))]);
        $lint = new LintService(new AnalysisService($parser, redosEnabled: true), $sources);
        $request = new LintRequest([$project.'/src'], [], 1, checkRedos: false, checkOptimizations: false, analysisWorkers: 1);

        $this->assertSame([], self::issues($lint->analyze($lint->collectPatterns($request), $request)));
    }

    /**
     * What the analysis leaves off itself: the ReDoS check when off, the
     * change of meaning when the range is one version or the check is
     * off, and no identifier that is not a lint rule's, lint off or not.
     */
    #[Test]
    public function test_what_the_analysis_leaves_off(): void
    {
        $parser = RegexParser::create(['php_version' => '8.2']);
        $range = [RegexParser::create(['php_version' => '8.4'])];

        $this->assertTrue((new AnalysisService($parser))->leavesOff('regex.lint.redos'));
        $this->assertTrue((new AnalysisService($parser))->leavesOff('regex.lint.compat.meaningChanges'));
        $this->assertFalse((new AnalysisService($parser, range: $range))->leavesOff('regex.lint.compat.meaningChanges'));
        $this->assertTrue((new AnalysisService($parser, lintRules: ['compat.meaningChanges' => false], range: $range))->leavesOff('regex.lint.compat.meaningChanges'));
        $this->assertFalse((new AnalysisService($parser, lintEnabled: false))->leavesOff('regex.optimization'));
        $this->assertFalse((new AnalysisService($parser, lintEnabled: false))->leavesOff('regex.lint.no.such.rule'));
    }

    /**
     * The search cost of the ReDoS check is left off with the check, or
     * by its rule, in full or short.
     */
    #[Test]
    public function test_the_search_cost_is_left_off_by_its_rule(): void
    {
        $parser = RegexParser::create();

        $this->assertTrue((new AnalysisService($parser))->leavesOff('regex.lint.redos.search'));
        $this->assertFalse((new AnalysisService($parser, redosEnabled: true))->leavesOff('regex.lint.redos.search'));
        $this->assertTrue((new AnalysisService($parser, redosEnabled: true, lintRules: ['redos.search' => false]))->leavesOff('regex.lint.redos.search'));
        $this->assertTrue((new AnalysisService($parser, redosEnabled: true, lintRules: ['regex.lint.redos.search' => false]))->leavesOff('regex.lint.redos.search'));
        $this->assertFalse((new AnalysisService($parser, redosEnabled: true))->leavesOff('regex.lint.redos'));
        $this->assertTrue((new AnalysisService($parser, lintEnabled: false))->leavesOff('regex.lint.quantifier.nested'));
        $this->assertFalse((new AnalysisService($parser))->leavesOff('regex.lint.no.such.rule'));
    }

    /**
     * The report of a marker is on the marker's own column.
     */
    #[Test]
    public function test_the_report_of_a_marker_is_on_its_column(): void
    {
        $report = $this->lint(TokenBasedExtractionStrategy::class, "<?php\n\$kept = 1; // @regex-ignore\n");

        $this->assertSame(15, $report->results[0]['issues'][0]->column);
    }

    /**
     * A run holds something to analyse when it holds a pattern, or a marker
     * to report; an occurrence silenced whole with no markers known is left
     * out, as before.
     */
    #[Test]
    public function test_what_a_run_has_to_analyse(): void
    {
        $marker = InlineIgnores::markers("<?php\n// @regex-ignore\n");
        $this->assertTrue(LintService::holdsNothing([], true));
        $this->assertTrue(LintService::holdsNothing([PatternOccurrence::scanned('a.php', [])], true));
        $this->assertFalse(LintService::holdsNothing([PatternOccurrence::scanned('a.php', $marker)], true));
        $this->assertTrue(LintService::holdsNothing([PatternOccurrence::scanned('a.php', $marker)], false));
        $this->assertFalse(LintService::holdsNothing([new PatternOccurrence('/a/', 'a.php', 1, 'preg_match')], false));

        $service = self::service(TokenBasedExtractionStrategy::class);
        $report = $service->analyze([new PatternOccurrence('/\d+[11]/i', 'a.php', 2, 'preg_match', isIgnored: true)], new LintRequest(['.'], [], 1, checkOptimizations: false));
        $this->assertSame([], $report->results);
    }

    /**
     * A marker in a file that holds no pattern at all is read too, and so is
     * one over a pattern that a marker silences in another file of the run.
     */
    #[Test]
    public function test_a_marker_in_a_file_without_any_pattern_is_reported(): void
    {
        $project = $this->makeProject([
            'src/a.php' => "<?php\n\$kept = 1; // @regex-ignore\n",
            'src/b.php' => "<?php\npreg_match('/\\d+[11]/i', \$s); // @regex-ignore\n",
        ]);

        $report = self::service(TokenBasedExtractionStrategy::class)->analyze(
            self::service(TokenBasedExtractionStrategy::class)->collectPatterns(self::request($project)),
            self::request($project),
        );

        $this->assertSame([[$project.'/src/a.php', 2, 'regex.ignore.unmatched']], array_map(
            static fn (LintIssue $issue): array => [$issue->file, $issue->line, $issue->issueId],
            array_merge([], ...array_column($report->results, 'issues')),
        ));
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    private function lint(string $strategy, string $code, bool $report = true): LintReport
    {
        if (PhpParserExtractionStrategy::class === $strategy && !class_exists(ParserFactory::class)) {
            $strategy = TokenBasedExtractionStrategy::class;
        }

        $project = $this->makeProject(['src/a.php' => $code]);
        $service = self::service($strategy);
        $request = self::request($project, $report);

        return $service->analyze($service->collectPatterns($request), $request);
    }

    /**
     * @param class-string<ExtractorInterface> $strategy
     */
    private static function service(string $strategy): LintService
    {
        return new LintService(
            new AnalysisService(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44', 'runtime_pcre_validation' => false])),
            new PatternSourceCollection([new PhpFilePatternSource(new PatternExtractor(new $strategy()))]),
        );
    }

    private static function request(string $project, bool $report = true): LintRequest
    {
        return new LintRequest([$project.'/src'], [], 1, checkOptimizations: false, analysisWorkers: 1, reportUnmatchedIgnores: $report);
    }

    /**
     * The line and identifier of each issue, sorted.
     *
     * @return list<array{int, string|null}>
     */
    private static function issues(LintReport $report): array
    {
        $issues = [];
        foreach ($report->results as $result) {
            foreach ($result['issues'] as $issue) {
                $issues[] = [$issue->line, $issue->issueId];
            }
        }
        sort($issues);

        return $issues;
    }
}
