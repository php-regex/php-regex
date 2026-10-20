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
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintRunFailure;
use PHPRegex\Linter\LintRunner;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSetup;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\RecordingLintProgress;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The steps of a lint run every front end takes alike, and the failure each
 * one ends with.
 *
 * @phpstan-import-type LintResult from LintReport
 */
final class LintRunnerTest extends TestCase
{
    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_paths_that_exist_pass_the_check(): void
    {
        $this->expectNotToPerformAssertions();

        LintRunner::checkPaths([__DIR__, __FILE__, '']);
        LintRunner::checkPaths([], 'the paths');
    }

    #[Test]
    public function test_a_missing_path_given_on_the_command_line_is_a_usage_error(): void
    {
        $failure = $this->failureOf(static fn () => LintRunner::checkPaths([__DIR__, __DIR__.'/missing', __DIR__.'/other']));

        $this->assertSame('Path not found: '.__DIR__.'/missing', $failure->getMessage());
        $this->assertSame('usage', $failure->stage);
        $this->assertSame(2, $failure->exitCode);
    }

    #[Test]
    public function test_a_missing_configured_path_is_a_configuration_error_naming_the_setting(): void
    {
        $failure = $this->failureOf(static fn () => LintRunner::checkPaths(['missing'], 'php_regex.paths'));

        $this->assertSame('Path not found: missing (from php_regex.paths)', $failure->getMessage());
        $this->assertSame('config', $failure->stage);
        $this->assertSame(2, $failure->exitCode);
    }

    #[Test]
    public function test_resolve_gives_what_the_settings_resolve_to(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $setup = new LintSetup(ProjectTarget::fromSources([], [], null, []), $analysis, new LintService($analysis, new PatternSourceCollection([])));

        $this->assertSame($setup, LintRunner::resolve(static fn (): LintSetup => $setup));
    }

    #[Test]
    public function test_a_target_the_run_cannot_judge_for_stops_it_at_the_target_stage(): void
    {
        $cause = new UnsupportedTargetException('PHP 5.6 is no target.');

        $failure = $this->failureOf(static fn () => LintRunner::resolve(static fn () => throw $cause, 'Invalid option: '));

        $this->assertSame('PHP 5.6 is no target.', $failure->getMessage());
        $this->assertSame('target', $failure->stage);
        $this->assertSame(2, $failure->exitCode);
        $this->assertSame($cause, $failure->getPrevious());
    }

    #[Test]
    public function test_a_setting_the_run_cannot_read_stops_it_at_the_config_stage(): void
    {
        $cause = new InvalidRegexOptionException('"php_version" is no version.');

        $failure = $this->failureOf(static fn () => LintRunner::resolve(static fn () => throw $cause, 'Invalid option: '));

        $this->assertSame('Invalid option: "php_version" is no version.', $failure->getMessage());
        $this->assertSame('config', $failure->stage);
        $this->assertSame(2, $failure->exitCode);
        $this->assertSame($cause, $failure->getPrevious());
    }

    #[Test]
    public function test_resolve_lets_any_other_failure_through(): void
    {
        $cause = new \LogicException('a bug');

        try {
            LintRunner::resolve(static fn () => throw $cause);
            $this->fail('The failure did not go through.');
        } catch (\Throwable $e) {
            $this->assertSame($cause, $e);
        }
    }

    /**
     * The setup judges for the target: its analysis reads with the parser
     * the options and the target build, and its lint analyses with it.
     */
    #[Test]
    public function test_setup_judges_for_the_target(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $target = ProjectTarget::fromSources(['setting' => '8.2'], ['setting' => '10.40'], null, []);

        $setup = LintSetup::forTarget($target, ['max_pattern_length' => 12], $analysis, new LintService($analysis, new PatternSourceCollection([])));

        $this->assertSame($target, $setup->target);
        $this->assertNotSame($analysis, $setup->analysis);
        $this->assertSame('10.40', $setup->target->target()->pcreVersion);
        $issues = $setup->lint->analyze([new PatternOccurrence('/'.str_repeat('a', 20).'/', 'a.php', 1, 'php')], new LintRequest(['.'], [], 1))->results;
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('12', $issues[0]['issues'][0]->message);
    }

    #[Test]
    public function test_collect_gives_the_patterns_of_the_sources(): void
    {
        $patterns = [new PatternOccurrence('/a/', 'a.php', 1, 'php')];
        $runner = new LintRunner(self::lint(self::source(static fn (): array => $patterns)));

        $this->assertSame($patterns, $runner->collect(new LintRequest(['.'], [], 1)));
    }

    /**
     * A source runs the application's code (its router, its validator
     * metadata): whatever it throws stops the run at the collect stage.
     */
    #[Test]
    public function test_a_source_that_fails_stops_the_run_at_the_collect_stage(): void
    {
        $cause = new \TypeError('boom');
        $runner = new LintRunner(self::lint(self::source(static fn (): array => throw $cause)));

        $failure = $this->failureOf(static fn () => $runner->collect(new LintRequest(['.'], [], 1), new RecordingLintProgress()));

        $this->assertSame('Failed to collect patterns: boom', $failure->getMessage());
        $this->assertSame('collect', $failure->stage);
        $this->assertSame(1, $failure->exitCode);
        $this->assertSame($cause, $failure->getPrevious());
    }

    #[Test]
    public function test_pattern_count_leaves_out_the_files_read(): void
    {
        $this->assertSame(2, LintRunner::patternCount([
            new PatternOccurrence('/a/', 'a.php', 1, 'php'),
            PatternOccurrence::scanned('a.php', []),
            PatternOccurrence::parserFallback('b.php', 'syntax error'),
            new PatternOccurrence('/b/', 'b.php', 2, 'php'),
        ]));
        $this->assertSame(0, LintRunner::patternCount([]));
    }

    /**
     * The results a front end adds (a file it could not read) take their
     * place by file and line among the analysis's, and count in the stats.
     */
    #[Test]
    public function test_analyze_sorts_the_added_results_among_the_report_and_counts_them(): void
    {
        $patterns = [new PatternOccurrence('/(/', 'b.php', 3, 'php'), new PatternOccurrence('/[/', 'd.php', 1, 'php')];
        $runner = new LintRunner(self::lint(self::source(static fn (): array => $patterns)));

        $report = $runner->analyze($patterns, new LintRequest(['.'], [], 1), extra: [
            self::unread('c.php', 1),
            self::unread('b.php', 1),
            self::unread('a.php', 9),
        ]);

        $this->assertSame([['a.php', 9], ['b.php', 1], ['b.php', 3], ['c.php', 1], ['d.php', 1]], array_map(static fn (array $result): array => [$result['file'], $result['line']], $report->results));
        $this->assertSame(5, $report->stats['errors']);
        $this->assertSame(3, $report->stats['lintErrors'] ?? 0);
    }

    #[Test]
    public function test_analyze_gives_the_report_of_the_analysis_when_nothing_is_added(): void
    {
        $patterns = [new PatternOccurrence('/(/', 'b.php', 3, 'php'), new PatternOccurrence('/[0-9]/', 'a.php', 1, 'php')];
        $lint = self::lint(self::source(static fn (): array => $patterns));
        $request = new LintRequest(['.'], [], 1);

        $report = (new LintRunner($lint))->analyze($patterns, $request);

        $expected = $lint->analyze($patterns, $request);
        $this->assertSame(array_map(static fn (array $result): array => [$result['file'], $result['line']], $expected->results), array_map(static fn (array $result): array => [$result['file'], $result['line']], $report->results));
        $this->assertSame($expected->stats, $report->stats);
    }

    /**
     * A worker that ends without its results stops the run at the analyze
     * stage. The pids handed back are ones no system gives out, so no
     * worker runs and its channel is closed from the start.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_a_lost_worker_stops_the_run_at_the_analyze_stage(): void
    {
        LintFunctionOverrides::queuePcntlForkResult(2_000_000_001);
        LintFunctionOverrides::queuePcntlForkResult(2_000_000_002);
        $patterns = [new PatternOccurrence('/foo/', 'test.php', 12, 'php'), new PatternOccurrence('/bar/', 'test.php', 13, 'php')];
        $runner = new LintRunner(self::lint(self::source(static fn (): array => $patterns)));

        $failure = $this->failureOf(static fn () => $runner->analyze($patterns, new LintRequest(['.'], [], 1, analysisWorkers: 2)));

        $this->assertStringContainsString('a worker exited unexpectedly while reading test.php', $failure->getMessage());
        $this->assertSame('analyze', $failure->stage);
        $this->assertSame(1, $failure->exitCode);
    }

    /**
     * @phpstan-return LintResult
     */
    private static function unread(string $file, int $line): array
    {
        return [
            'file' => $file,
            'line' => $line,
            'column' => 1,
            'fileOffset' => null,
            'source' => 'validation',
            'pattern' => null,
            'location' => null,
            'issues' => [new LintIssue(LintSeverity::Error, 'Unreadable.', $file, $line, issueId: 'regex.lint.source.unreadable')],
            'optimizations' => [],
        ];
    }

    private static function lint(PatternSourceInterface $source): LintService
    {
        return new LintService(new AnalysisService(RegexParser::create()), new PatternSourceCollection([$source]));
    }

    /**
     * @param \Closure(): list<PatternOccurrence> $extract
     */
    private static function source(\Closure $extract): PatternSourceInterface
    {
        return new class($extract) implements PatternSourceInterface {
            /**
             * @param \Closure(): list<PatternOccurrence> $extract
             */
            public function __construct(private readonly \Closure $extract) {}

            public function getName(): string
            {
                return 'custom';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                return ($this->extract)();
            }
        };
    }

    private function failureOf(\Closure $step): LintRunFailure
    {
        try {
            $step();
        } catch (LintRunFailure $failure) {
            return $failure;
        }

        $this->fail('The step did not stop the run.');
    }
}
