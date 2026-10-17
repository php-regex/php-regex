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
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
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
            $report->results[0]['issues'][0]['message'],
        );
        $this->assertSame('The @regex-ignore-next-line marker silences nothing: no issue is reported on line 7. Remove it.', $report->results[1]['issues'][0]['message']);
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
            static fn (array $issue): array => [$issue['file'], $issue['line'], $issue['issueId'] ?? null],
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
                $issues[] = [$issue['line'], $issue['issueId'] ?? null];
            }
        }
        sort($issues);

        return $issues;
    }
}
