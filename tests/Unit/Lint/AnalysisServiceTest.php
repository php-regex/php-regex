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
use PHPRegex\Linter\LintException;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\ProofResult;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\RecordingLintProgress;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\TestCase;

final class AnalysisServiceTest extends TestCase
{
    use TemporaryProject;

    private AnalysisService $analysis;

    protected function setUp(): void
    {
        $this->analysis = new AnalysisService(
            RegexParser::create(),
            null,
            50,
            'low',
            [],
            [],
            'theoretical',
            null,
            true, // redosEnabled
        );
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    public function test_analyze_redos_returns_empty_array_for_no_patterns(): void
    {
        $result = $this->analysis->analyzeRedos([], RedosSeverity::Medium);

        $this->assertSame([], $result);
    }

    public function test_analyze_redos_returns_empty_array_for_invalid_pattern(): void
    {
        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Medium);

        $this->assertSame([], $result);
    }

    public function test_analyze_redos_detects_vulnerable_pattern(): void
    {
        $patterns = [
            // (a+)+ alone matches a…a! at once; before the end it fails at n=19 (PCRE2 10.49).
            new PatternOccurrence('/(a+)+$/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low);

        $this->assertCount(1, $result);
        $this->assertSame('test.php', $result[0]['file']);
        $this->assertSame(1, $result[0]['line']);
        $this->assertArrayHasKey('analysis', $result[0]);
    }

    public function test_analyze_redos_filters_by_threshold(): void
    {
        $patterns = [
            new PatternOccurrence('/\w+/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::High);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_returns_empty_array_for_no_patterns(): void
    {
        $result = $this->analysis->suggestOptimizations([], 0);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_returns_empty_array_for_invalid_pattern(): void
    {
        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_skips_a_pattern_that_parses_but_pcre_rejects(): void
    {
        // PCRE refuses a reference to a group the pattern does not have
        // (preg_match('/[0-9]+\2/', '') is false), though the tree builds
        // and the optimizer would rewrite it to /\d+\2/.
        $patterns = [
            new PatternOccurrence('/[0-9]+\2/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_filters_by_min_savings(): void
    {
        $patterns = [
            new PatternOccurrence('/test/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 100);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_continues_on_parse_error(): void
    {
        $patterns = [
            new PatternOccurrence('/[0-9]/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/[unclosed/', 'test.php', 2, 'preg_match'),
            new PatternOccurrence('/another-valid/', 'test.php', 3, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertGreaterThanOrEqual(1, \count($result));
    }

    public function test_analyze_redos_continues_on_parse_error(): void
    {
        $patterns = [
            new PatternOccurrence('/valid/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/[unclosed/', 'test.php', 2, 'preg_match'),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Medium);

        $this->assertGreaterThanOrEqual(0, \count($result));
    }

    public function test_extract_fragment_with_empty_pattern(): void
    {
        $patterns = [new PatternOccurrence('', 'test.php', 1, 'preg_match')];
        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Medium);

        $this->assertSame([], $result);
    }

    public function test_highlight_body_handles_empty_pattern(): void
    {
        $result = $this->analysis->highlightBody('', 'i', '/');

        $this->assertIsString($result);
    }

    public function test_suggest_optimizations_filters_by_savings_with_zero(): void
    {
        $patterns = [
            new PatternOccurrence('/test/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 1000);

        $this->assertSame([], $result);
    }

    public function test_lint_can_run_in_parallel(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $patterns = [
            new PatternOccurrence('/[a-z/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/(a+)+/', 'test.php', 2, 'preg_match'),
            new PatternOccurrence('/foo/', 'test.php', 3, 'preg_match'),
        ];

        $sequentialProgress = new RecordingLintProgress();
        $parallelProgress = new RecordingLintProgress();

        $sequential = $this->analysis->lint($patterns, $sequentialProgress);
        $parallel = $this->analysis->lint($patterns, $parallelProgress, 2);

        $this->assertEquals($sequential, $parallel);
        $this->assertSame(3, $sequentialProgress->advancedTotal());
        $this->assertSame(3, $parallelProgress->advancedTotal());
        $this->assertSame([2], $parallelProgress->workerCounts());
    }

    public function test_scan_delegates_to_extractor(): void
    {
        $paths = ['src'];
        $exclude = ['vendor'];

        $result = $this->analysis->scan($paths, $exclude);

        $this->assertIsArray($result);
        // Since extractor is default, it should return some patterns
    }

    public function test_highlight_returns_highlighted_string(): void
    {
        $pattern = '/foo/';
        $result = $this->analysis->highlight($pattern);

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function test_highlight_body_with_flags(): void
    {
        $result = $this->analysis->highlightBody('foo', 'i', '#');

        $this->assertIsString($result);
    }

    public function test_lint_with_sequential_processing(): void
    {
        $patterns = [
            new PatternOccurrence('/valid/', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->lint($patterns, null, 1);

        $this->assertIsArray($result);
    }

    public function test_lint_reports_progress_for_ignored_patterns(): void
    {
        $patterns = [
            new PatternOccurrence('/ignored/', 'test.php', 1, 'preg_match', null, null, true),
        ];

        $progress = new RecordingLintProgress();
        $this->analysis->lint($patterns, $progress);

        $this->assertSame(1, $progress->advancedTotal());
    }

    public function test_lint_progress_for_invalid_pattern(): void
    {
        $patterns = [
            new PatternOccurrence('/foo', 'test.php', 1, 'preg_match'),
        ];

        $progress = new RecordingLintProgress();
        $issues = $this->analysis->lint($patterns, $progress);

        $this->assertNotEmpty($issues);
        $this->assertSame(1, $progress->advancedTotal());
    }

    public function test_analyze_redos_skips_ignored_patterns(): void
    {
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match', null, null, true),
        ];

        $result = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_skips_ignored_patterns(): void
    {
        $patterns = [
            new PatternOccurrence('/a{2}/', 'test.php', 1, 'preg_match', null, null, true),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertSame([], $result);
    }

    public function test_suggest_optimizations_with_extended_mode(): void
    {
        $patterns = [
            new PatternOccurrence('/a{1}/x', 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertCount(1, $result);
        $this->assertSame('/a/x', $result[0]['optimization']->optimized);
    }

    public function test_suggest_optimizations_extended_mode_keeps_the_written_layout(): void
    {
        $pattern = "/\n\tfoo{1}\t# one\n\t(?:a|b)  # two\n/x";
        $patterns = [
            new PatternOccurrence($pattern, 'test.php', 1, 'preg_match'),
        ];

        $result = $this->analysis->suggestOptimizations($patterns, 0);

        $this->assertCount(1, $result);
        $this->assertSame($pattern, $result[0]['optimization']->original);
        $this->assertSame("/\n\tfoo\t# one\n\t[ab]  # two\n/x", $result[0]['optimization']->optimized);
    }

    public function test_suggest_optimizations_extended_mode_falls_back_to_the_pretty_layout(): void
    {
        // A tree without the text it was read from has no layout to keep:
        // both sides are pretty-printed. The cache here gives it back so.
        $pattern = "/a{1}  # comment\n/x";
        $ast = Regex::create()->parse($pattern);
        $cache = new class(new RegexNode($ast->pattern, $ast->flags, $ast->delimiter, $ast->startPosition, $ast->endPosition)) implements CacheInterface {
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

        $result = (new AnalysisService(RegexParser::create(['cache' => $cache])))->suggestOptimizations([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')], 0);

        $this->assertCount(1, $result);
        $baseline = $ast->accept(new PatternPrinter(str_contains($ast->flags, 'x')));
        $this->assertSame($baseline, $result[0]['optimization']->original);
    }

    public function test_suggest_optimizations_skips_when_optimizer_throws(): void
    {
        $cache = new class implements CacheInterface {
            private int $loadCalls = 0;

            public function generateKey(string $seed): string
            {
                return 'key';
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): ?RegexNode
            {
                $this->loadCalls++;
                if ($this->loadCalls > 1) {
                    throw new \RuntimeException('cache load failed');
                }

                return null;
            }
        };

        $analysis = new AnalysisService(RegexParser::create(['cache' => $cache]));
        $patterns = [
            new PatternOccurrence('/a/x', 'test.php', 1, 'preg_match'),
        ];

        $result = $analysis->suggestOptimizations($patterns, 0);

        $this->assertSame([], $result);
    }

    public function test_analyze_redos_can_run_in_parallel(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is not available.');
        }

        $patterns = [
            new PatternOccurrence('/(a+)+/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/foo/', 'test.php', 2, 'preg_match'),
        ];

        $sequential = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low, 1);
        $parallel = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low, 2);

        $this->assertEquals($sequential, $parallel);
    }

    /**
     * On workers, the optimizations are the serial ones, and every pattern
     * advances the progress once: the ignored and the invalid ones too.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_suggest_optimizations_can_run_in_parallel(): void
    {
        $patterns = [
            new PatternOccurrence('/a{1}/', 'a.php', 1, 'preg_match'),
            new PatternOccurrence('/[0-9]/', 'b.php', 2, 'preg_match'),
            new PatternOccurrence('/c{1}/', 'c.php', 3, 'preg_match', null, null, true),
            new PatternOccurrence('/[d/', 'd.php', 4, 'preg_match'),
        ];
        $sequentialProgress = new RecordingLintProgress();
        $parallelProgress = new RecordingLintProgress();

        $sequential = $this->analysis->suggestOptimizations($patterns, 0, null, 1, $sequentialProgress);
        $parallel = $this->analysis->suggestOptimizations($patterns, 0, null, 2, $parallelProgress);

        $this->assertEquals($sequential, $parallel);
        $this->assertSame(['a.php', 'b.php'], array_column($parallel, 'file'));
        $this->assertSame(4, $sequentialProgress->advancedTotal());
        $this->assertSame(4, $parallelProgress->advancedTotal());
        $this->assertSame([], $sequentialProgress->workerCounts());
        $this->assertSame([2], $parallelProgress->workerCounts());
    }

    /**
     * No pattern: nothing found, nothing advanced, no worker started.
     */
    public function test_lint_with_no_patterns_on_workers_gives_nothing_and_advances_nothing(): void
    {
        $lintProgress = new RecordingLintProgress();
        $optimizationProgress = new RecordingLintProgress();

        $this->assertSame([], $this->analysis->lint([], $lintProgress, 2));
        $this->assertSame([], $this->analysis->suggestOptimizations([], 0, null, 2, $optimizationProgress));
        $this->assertSame([], $this->analysis->analyzeRedos([], RedosSeverity::Low, 2));
        $this->assertSame(0, $lintProgress->advancedTotal());
        $this->assertSame(0, $optimizationProgress->advancedTotal());
        $this->assertSame([], $lintProgress->workerCounts());
        $this->assertSame([], $optimizationProgress->workerCounts());
    }

    /**
     * One pattern is not worth a worker: it is analysed in the parent,
     * whatever the number of jobs.
     */
    public function test_lint_with_one_pattern_on_workers_stays_in_the_parent(): void
    {
        $patterns = [new PatternOccurrence('/a{1}/', 'a.php', 1, 'preg_match')];
        $lintProgress = new RecordingLintProgress();
        $optimizationProgress = new RecordingLintProgress();

        $this->assertEquals($this->analysis->lint($patterns), $this->analysis->lint($patterns, $lintProgress, 4));
        $this->assertEquals($this->analysis->suggestOptimizations($patterns, 0), $this->analysis->suggestOptimizations($patterns, 0, null, 4, $optimizationProgress));
        $this->assertSame(1, $lintProgress->advancedTotal());
        $this->assertSame(1, $optimizationProgress->advancedTotal());
        $this->assertSame([], $lintProgress->workerCounts());
        $this->assertSame([], $optimizationProgress->workerCounts());
    }

    /**
     * When no worker can be forked, the lint runs in the parent: the same
     * issues, every pattern advanced.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_lint_falls_back_to_serial_with_full_progress_when_no_worker_can_be_forked(): void
    {
        $patterns = [
            new PatternOccurrence('/a+/', 'a.php', 1, 'preg_match'),
            new PatternOccurrence('/(b+)+/', 'b.php', 2, 'preg_match'),
        ];
        $serial = $this->analysis->lint($patterns);
        LintFunctionOverrides::queuePcntlForkResult(-1);
        $progress = new RecordingLintProgress();

        $fallback = $this->analysis->lint($patterns, $progress, 2);

        $this->assertSame([], LintFunctionOverrides::$pcntlForkSequence, 'The run tried to fork.');
        $this->assertEquals($serial, $fallback);
        $this->assertSame(2, $progress->advancedTotal());
    }

    /**
     * When no worker can be forked, the optimizations are looked for in the
     * parent: the same ones, every pattern advanced.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_suggest_optimizations_falls_back_to_serial_with_full_progress_when_no_worker_can_be_forked(): void
    {
        $patterns = [
            new PatternOccurrence('/a{1}/', 'a.php', 1, 'preg_match'),
            new PatternOccurrence('/[0-9]/', 'b.php', 2, 'preg_match'),
        ];
        $serial = $this->analysis->suggestOptimizations($patterns, 0);
        LintFunctionOverrides::queuePcntlForkResult(-1);
        $progress = new RecordingLintProgress();

        $fallback = $this->analysis->suggestOptimizations($patterns, 0, null, 2, $progress);

        $this->assertSame([], LintFunctionOverrides::$pcntlForkSequence, 'The run tried to fork.');
        $this->assertEquals($serial, $fallback);
        $this->assertSame(2, $progress->advancedTotal());
    }

    /**
     * A worker that throws fails the lint with the class and message it
     * threw, and the file of the pattern it was analysing; no payload file
     * is left. The throw comes from the source read for the
     * invisible-character rule, through a stream wrapper.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_lint_on_workers_reports_a_failing_worker_with_its_file(): void
    {
        $wrapper = new class {
            public mixed $context = null;

            public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
            {
                throw new \RuntimeException('Boom');
            }

            public function url_stat(string $path, int $flags): bool
            {
                return false;
            }
        };
        $this->assertTrue(stream_wrapper_register('regexparser-boom', $wrapper::class));
        $analysis = new AnalysisService(RegexParser::create());
        $patterns = [
            // A raw tab in the pattern: the rule reads the source to see how
            // the PHP literal spells it.
            new PatternOccurrence("/a\tb/", 'regexparser-boom://one.php', 3, 'preg_match', null, null, false, 1, 10),
            new PatternOccurrence('/c/', 'two.php', 1, 'preg_match'),
        ];
        $before = self::payloadFiles();
        $payloads = $this->makeProject();
        LintFunctionOverrides::$tempnamDirectory = $payloads;

        try {
            $analysis->lint($patterns, new RecordingLintProgress(), 2);
            $this->fail('The failure of the worker is reported.');
        } catch (LintException $e) {
            $this->assertSame('Parallel analysis failed: RuntimeException: Boom (in regexparser-boom://one.php)', $e->getMessage());
        } finally {
            stream_wrapper_unregister('regexparser-boom');
        }

        $this->assertSame([], array_values(array_diff(scandir($payloads) ?: [], ['.', '..'])));
        $this->assertSame([], array_values(array_diff(self::payloadFiles(), $before)));
    }

    /**
     * The issues the workers find come back in the serial order, every
     * pattern advanced once, the workers started announced once.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_lint_on_workers_gives_the_serial_issues_in_order_and_progress(): void
    {
        $patterns = [
            new PatternOccurrence('/(a+)+/', 'a.php', 1, 'preg_match'),
            new PatternOccurrence('/[b/', 'b.php', 2, 'preg_match'),
            new PatternOccurrence('/c/', 'c.php', 3, 'preg_match', null, null, true),
            new PatternOccurrence('/(d+)+$/', 'd.php', 4, 'preg_match'),
            new PatternOccurrence('/e{1}/', 'e.php', 5, 'preg_match'),
        ];
        $serialProgress = new RecordingLintProgress();
        $parallelProgress = new RecordingLintProgress();

        $serial = $this->analysis->lint($patterns, $serialProgress, 1);
        $parallel = $this->analysis->lint($patterns, $parallelProgress, 2);

        $this->assertNotEmpty($serial);
        $this->assertEquals($serial, $parallel);
        $this->assertSame(array_column($serial, 'file'), array_column($parallel, 'file'));
        $this->assertSame(5, $serialProgress->advancedTotal());
        $this->assertSame(5, $parallelProgress->advancedTotal());
        $this->assertSame([], $serialProgress->workerCounts());
        $this->assertSame([2], $parallelProgress->workerCounts());
    }

    /**
     * A ReDoS analysis found on a worker comes back whole: its culprit
     * node, its severity, its findings, no incomplete class.
     */
    #[RequiresFunction('pcntl_fork')]
    public function test_lint_on_workers_round_trips_a_redos_analysis_with_its_culprit_node(): void
    {
        $patterns = [
            new PatternOccurrence('/(a+)+$/', 'test.php', 1, 'preg_match'),
            new PatternOccurrence('/foo/', 'test.php', 2, 'preg_match'),
        ];

        $serial = self::redosAnalyses($this->analysis->lint($patterns));
        $parallel = self::redosAnalyses($this->analysis->lint($patterns, new RecordingLintProgress(), 2));
        $redos = $this->analysis->analyzeRedos($patterns, RedosSeverity::Low, 2);

        $this->assertCount(1, $serial);
        $this->assertCount(1, $parallel);
        $this->assertInstanceOf(NodeInterface::class, $serial[0]->culpritNode);
        $read = $parallel[0];
        $this->assertInstanceOf(NodeInterface::class, $read->culpritNode);
        $this->assertSame($serial[0]->severity, $read->severity);
        $this->assertCount(\count($serial[0]->findings), $read->findings);
        $this->assertStringNotContainsString('__PHP_Incomplete_Class', serialize($read));
        $this->assertCount(1, $redos);
        $this->assertInstanceOf(NodeInterface::class, $redos[0]['analysis']->culpritNode);
    }

    public function test_skip_risk_analysis_helpers(): void
    {
        $occurrence = new PatternOccurrence('/foo|bar/', 'test.php', 1, 'preg_match');

        $skip = $this->invokePrivate('shouldSkipRiskAnalysis', $occurrence);
        $this->assertTrue($skip);

        $this->assertSame('', $this->invokePrivate('extractFragment', ''));
        $this->assertSame('', $this->invokePrivate('trimPatternBody', ''));
        $this->assertFalse($this->invokePrivate('isIgnored', ''));
        $this->assertFalse($this->invokePrivate('isTriviallySafe', ''));
    }

    public function test_uses_extended_mode_handles_invalid_input(): void
    {
        $this->assertFalse($this->invokePrivate('usesExtendedMode', ''));
        $this->assertFalse($this->invokePrivate('usesExtendedMode', '/'));
    }

    public function test_worker_results_may_carry_a_class_from_a_subdirectory(): void
    {
        $allowed = $this->invokePrivate('allowedWorkerClasses');

        $this->assertIsArray($allowed);
        $this->assertContains(ProofResult::class, $allowed);
        $this->assertContains(RedosAnalysis::class, $allowed);
        $this->assertNotContains(NodeInterface::class, $allowed);
    }

    /**
     * The ReDoS analyses the issues carry, on the ReDoS issue.
     *
     * @param array<array<string, mixed>> $issues
     *
     * @return list<RedosAnalysis>
     */
    private static function redosAnalyses(array $issues): array
    {
        $analyses = [];
        foreach ($issues as $issue) {
            if ('regex.lint.redos' === ($issue['issueId'] ?? null) && ($issue['analysis'] ?? null) instanceof RedosAnalysis) {
                $analyses[] = $issue['analysis'];
            }
        }

        return $analyses;
    }

    /**
     * The payload files a run of workers could leave in the temporary
     * directory.
     *
     * @return list<string>
     */
    private static function payloadFiles(): array
    {
        return array_values(array_filter(glob(sys_get_temp_dir().'/regexparser_*') ?: [], is_file(...)));
    }

    private function invokePrivate(string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($this->analysis);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invoke($this->analysis, ...$args);
    }
}
