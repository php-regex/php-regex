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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Command;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\LintProgress;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Symfony\Extractor\RoutePatternSource;
use PHPRegex\Symfony\Routing\RouteRequirementNormalizer;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * @phpstan-import-type RegexOptions from LintCommand
 */
final class LintCommandTest extends TestCase
{
    /**
     * Process ids no system hands out (above the largest pid_max, 2^22 on
     * Linux, 99998 on macOS): a fork that "gives" one started no worker, so
     * the worker's channel is closed from the start, as when a worker is
     * killed. Waiting for such a pid fails at once, signalling it does
     * nothing.
     */
    private const GONE_WORKER_PIDS = [2_000_000_001, 2_000_000_002];

    protected function setUp(): void
    {
        LintFunctionOverrides::reset();
    }

    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    #[Test]
    public function test_command_succeeds_by_default_with_no_patterns(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No regex patterns found', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_command_has_correct_name(): void
    {
        $command = $this->createCommand();

        $this->assertSame('regex:lint', $command->getName());
    }

    #[Test]
    public function test_command_has_all_expected_options(): void
    {
        $command = $this->createCommand();

        $definition = $command->getDefinition();

        $this->assertTrue($definition->hasArgument('paths'));
        $this->assertTrue($definition->hasOption('exclude'));
        $this->assertTrue($definition->hasOption('min-savings'));
        $this->assertTrue($definition->hasOption('jobs'));
        $this->assertTrue($definition->hasOption('no-routes'));
        $this->assertTrue($definition->hasOption('no-validators'));
        $this->assertTrue($definition->hasOption('format'));

        $this->assertFalse($definition->hasOption('analyze-redos'));
        $this->assertFalse($definition->hasOption('optimize'));
    }

    #[Test]
    public function test_json_format_outputs_raw_json(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent'], '--format' => 'json']);

        $this->assertSame(0, $status);

        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('No regex patterns found', (string) $output);
        $this->assertStringNotContainsString('Regex Parser', (string) $output);

        // Should be valid JSON
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('stats', $data);
        $this->assertArrayHasKey('results', $data);
        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'redos_errors' => 0, 'infos' => 0, 'lint_errors' => 0, 'parser_fallbacks' => 0, 'not_analyzed' => 0], $data['stats']);
        $this->assertSame([], $data['results']);
    }

    #[Test]
    public function test_invalid_format_returns_error(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent'], '--format' => 'invalid']);

        $this->assertSame(2, $status);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        $this->assertStringContainsString(
            'Invalid format \'invalid\'. Supported formats: console, json, github, checkstyle, junit',
            (string) $display,
        );
    }

    #[Test]
    public function test_normalize_string_list_filters_invalid_values(): void
    {
        $command = $this->createCommand();

        // Test the private method through reflection
        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('normalizeStringList');

        $this->assertSame([], $method->invoke($command, null));
        $this->assertSame([], $method->invoke($command, 'string'));
        $this->assertSame(['a', 'b'], $method->invoke($command, ['a', '', 'b', null, 123]));
    }

    #[Test]
    public function test_sort_results_by_file_and_line(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('sortResultsByFileAndLine');

        $results = [
            ['file' => 'b.php', 'line' => 10],
            ['file' => 'a.php', 'line' => 5],
            ['file' => 'a.php', 'line' => 1],
        ];

        /** @var array<array{file: string, line: int}> $sorted */
        $sorted = $method->invoke($command, $results);

        $this->assertSame('a.php', $sorted[0]['file']);
        $this->assertSame(1, $sorted[0]['line']);
        $this->assertSame('a.php', $sorted[1]['file']);
        $this->assertSame(5, $sorted[1]['line']);
        $this->assertSame('b.php', $sorted[2]['file']);
        $this->assertSame(10, $sorted[2]['line']);
    }

    #[Test]
    public function test_show_banner_outputs_correct_format(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('showBanner');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->exactly(2))->method('newLine');
        // Title, runtime, target and processes.
        $io->expects($this->exactly(4))->method('writeln');

        $method->invoke($command, $io, 1, ProjectTarget::fromSources([], [], null, []));
    }

    #[Test]
    public function test_the_empty_summary_ends_with_the_footer_after_a_blank_line(): void
    {
        $command = $this->createCommand();

        $reflection = new \ReflectionClass($command);
        $method = $reflection->getMethod('renderEmptySummary');

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->exactly(3))->method('newLine');
        $io->expects($this->exactly(2))->method('writeln')
            ->with($this->logicalOr(
                '  <bg=green;fg=white;options=bold> PASS </> <fg=gray>No regex patterns found.</>',
                '  <fg=gray>If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex</>',
            ));

        $method->invoke($command, $io);
    }

    #[Test]
    public function test_constructor_uses_defaults_when_paths_are_empty(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService($analysis, new PatternSourceCollection([]));

        $command = new LintCommand(
            lint: $lint,
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            defaultPaths: [],
            defaultExcludePaths: [],
            editorUrl: null,
        );

        $this->assertSame('regex:lint', $command->getName());
    }

    /**
     * The functions marked #[RegexPattern] are read in the configured paths
     * and in the project's vendor/, whatever paths the run lints; with no
     * project directory, the working directory is the project.
     */
    #[Test]
    public function test_execute_reads_declarations_in_the_configured_paths_and_vendor(): void
    {
        $source = new class implements PatternSourceInterface {
            /**
             * @var list<array<string>>
             */
            public array $declarationPaths = [];

            /**
             * @var list<array<string>>
             */
            public array $vendorPaths = [];

            public function getName(): string
            {
                return 'recording';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                $this->declarationPaths[] = $context->declarationPaths;
                $this->vendorPaths[] = $context->vendorPaths;

                return [];
            }
        };
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService($analysis, new PatternSourceCollection([$source]));

        foreach (['/app', null] as $projectDir) {
            $command = new LintCommand(lint: $lint, analysis: $analysis, defaultPaths: ['src', 'lib'], projectDir: $projectDir);
            (new CommandTester($command))->execute(['paths' => ['src/Controller/One.php'], '--format' => 'json']);
        }

        $this->assertSame([['src', 'lib'], ['src', 'lib']], $source->declarationPaths);
        $this->assertSame([['/app/vendor'], [getcwd().'/vendor']], $source->vendorPaths);
    }

    #[Test]
    public function test_execute_rejects_invalid_jobs_value(): void
    {
        $command = $this->createCommand();

        $tester = new CommandTester($command);
        $status = $tester->execute(['paths' => ['nonexistent'], '--jobs' => '0']);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('positive integer', (string) $tester->getDisplay());
    }

    /**
     * A source reporting through the run's progress, a step with nothing to
     * do included, leaves the run to end as usual.
     */
    #[Test]
    public function test_execute_progress_callback_handles_empty_totals(): void
    {
        $progressSource = new class implements PatternSourceInterface {
            public mixed $progress = null;

            public function getName(): string
            {
                return 'progress';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                $this->progress = $context->progress;
                if ($context->progress instanceof LintProgress) {
                    $context->progress->step(LintProgress::STEP_PATTERNS, 0);
                    $context->progress->finish();
                    $context->progress->step(LintProgress::STEP_PATTERNS, 2);
                    $context->progress->advance();
                    $context->progress->tick();
                    $context->progress->advance();
                    $context->progress->finish();
                }

                return [];
            }
        };

        $command = $this->createCommandWithSources([$progressSource]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['nonexistent']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('No regex patterns found', (string) $tester->getDisplay());
        $this->assertInstanceOf(LintProgress::class, $progressSource->progress, 'The console run hands the sources its progress.');
    }

    #[Test]
    public function test_execute_renders_collection_failure(): void
    {
        $failingSource = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'fail';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $command = $this->createCommandWithSources([$failingSource]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['nonexistent']]);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('boom', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_execute_renders_collection_failure_for_json(): void
    {
        $failingSource = new class implements PatternSourceInterface {
            public function getName(): string
            {
                return 'fail';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                throw new \RuntimeException('boom');
            }
        };

        $command = $this->createCommandWithSources([$failingSource]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['nonexistent'], '--format' => 'json']);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('Failed to collect patterns', (string) $tester->getDisplay());
    }

    #[Test]
    public function test_execute_analyzes_patterns_with_progress(): void
    {
        $source = new class implements PatternSourceInterface {
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
                return [
                    new PatternOccurrence('/foo/', 'test.php', 12, 'php:preg_match()'),
                ];
            }
        };

        $command = $this->createCommandWithSources([$source]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['.']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Analyzing patterns', (string) $tester->getDisplay());
    }

    /**
     * A file read with the tokenizer because the PHP parser could not is no
     * pattern of its own: counted in the stats, never in the patterns found.
     */
    #[Test]
    public function test_execute_leaves_a_parser_fallback_out_of_the_patterns_found(): void
    {
        $source = new class implements PatternSourceInterface {
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
                return [
                    new PatternOccurrence('/foo/', 'test.php', 2, 'php:preg_match()'),
                    PatternOccurrence::parserFallback('test.php', 'Syntax error, unexpected EOF on line 3'),
                ];
            }
        };

        $tester = new CommandTester($this->createCommandWithSources([$source]));
        $status = $tester->execute(['paths' => ['.']]);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('found 1 patterns.', (string) $tester->getDisplay());

        $tester = new CommandTester($this->createCommandWithSources([$source]));
        $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $data = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($data);
        $this->assertIsArray($data['stats']);
        $this->assertSame(1, $data['stats']['parser_fallbacks'] ?? null);
    }

    #[Test]
    public function test_execute_analyzes_patterns_without_progress_in_json(): void
    {
        $source = new class implements PatternSourceInterface {
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
                return [
                    new PatternOccurrence('/foo/', 'test.php', 12, 'php:preg_match()'),
                ];
            }
        };

        $command = $this->createCommandWithSources([$source]);
        $tester = new CommandTester($command);

        $status = $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $this->assertSame(0, $status);
        $this->assertStringNotContainsString('Analyzing patterns', (string) $tester->getDisplay());
        $this->assertIsArray(json_decode($tester->getDisplay(), true));
    }

    /**
     * A lint rule at Error fails the command: without /u, [é] matches the
     * byte \xC3 on its own.
     */
    #[Test]
    public function test_execute_fails_on_a_lint_rule_at_error(): void
    {
        $this->assertSame(1, preg_match('/^[é]$/', "\xC3"));

        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/[é]/')]));
        $status = $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $this->assertSame(1, $status);
        $issue = self::firstIssue($tester->getDisplay());
        $this->assertSame('error', $issue['severity'] ?? null);
        $this->assertSame('regex.lint.unicode.multibyteInClassWithoutU', $issue['issue_id'] ?? null);
    }

    #[Test]
    public function test_execute_passes_on_a_lint_rule_at_info(): void
    {
        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/(a)+/')]));
        $status = $tester->execute(['paths' => ['.'], '--format' => 'json']);

        $this->assertSame(0, $status);
        $this->assertSame('info', self::firstIssue($tester->getDisplay())['severity'] ?? null);
        $data = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($data);
        $this->assertIsArray($data['stats'] ?? null);
        $this->assertSame(1, $data['stats']['infos'] ?? null);
    }

    /**
     * The router compiles every requirement as "{^...$}sD" and turns its
     * unnamed groups into non-capturing ones: none of that is the user's
     * to change, so none of it is reported.
     */
    #[Test]
    public function test_execute_reports_nothing_the_router_adds_to_a_route_requirement(): void
    {
        $collection = new RouteCollection();
        $collection->add('product', new Route('/product/{id}', [], ['id' => '\d+']));
        $collection->add('home', new Route('/{locale}', [], ['locale' => '(en|fr)+']));
        $router = $this->createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $tester = new CommandTester($this->createCommandWithSources([new RoutePatternSource(new RouteRequirementNormalizer(), $router)]));
        $status = $tester->execute(['paths' => ['nonexistent']]);
        $display = (string) $tester->getDisplay();

        $this->assertSame(0, $status, $display);
        $this->assertStringContainsString('found 2 patterns', $display);
        $this->assertStringNotContainsString("Flag 's' is useless", $display);
        $this->assertStringNotContainsString('Quantified capturing group', $display);
        $this->assertStringNotContainsString('TIP', $display);
    }

    /**
     * The run names its three steps, the optimizations a step of their own.
     */
    #[Test]
    public function test_execute_names_the_three_steps(): void
    {
        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/foo/')]));

        $status = $tester->execute(['paths' => ['.'], '--jobs' => '1']);
        $display = (string) $tester->getDisplay();

        $this->assertSame(0, $status, $display);
        $scanning = strpos($display, '[1/3] Scanning files');
        $analyzing = strpos($display, '[2/3] Analyzing patterns');
        $suggesting = strpos($display, '[3/3] Suggesting optimizations');
        $this->assertNotFalse($scanning, $display);
        $this->assertNotFalse($analyzing, $display);
        $this->assertNotFalse($suggesting, $display);
        $this->assertLessThan($analyzing, $scanning);
        $this->assertLessThan($suggesting, $analyzing);
        $this->assertStringNotContainsString('[1/2]', $display);
        $this->assertStringNotContainsString('[2/2]', $display);
    }

    /**
     * A worker that ends without its results (a fatal error, out of memory,
     * killed) stops the run with exit code 1 and says so, naming the file
     * it was reading.
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_execute_fails_when_a_worker_is_lost_during_the_analysis(): void
    {
        foreach (self::GONE_WORKER_PIDS as $pid) {
            LintFunctionOverrides::queuePcntlForkResult($pid);
        }
        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/foo/', '/bar/')]));

        $status = self::withinTimeLimit(static fn (): int => $tester->execute(['paths' => ['.'], '--jobs' => '2']));
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';

        $this->assertSame(1, $status, $display);
        $this->assertStringContainsString('Parallel analysis failed: a worker exited unexpectedly while reading test.php', $display);
        $this->assertStringNotContainsString('Time:', $display, 'No footer: the run did not end.');
    }

    /**
     * Without pcntl no worker can start: the default the banner and the
     * footer show is one process, whatever the machine counts.
     */
    #[Test]
    public function test_execute_defaults_to_one_process_without_pcntl(): void
    {
        LintFunctionOverrides::$missingFunctions = ['pcntl_fork'];
        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/foo/', '/bar/')]));

        $status = $tester->execute(['paths' => ['.']]);
        $display = (string) $tester->getDisplay();

        $this->assertSame(0, $status, $display);
        $this->assertMatchesRegularExpression('/^Processes : 1$/m', $display);
        $this->assertMatchesRegularExpression('/\bProcesses: 1$/m', $display);
        // The workers parse the patterns: a cache count of this process
        // alone would read almost nothing on a parallel run.
        $this->assertMatchesRegularExpression('/^  Time: \S+ \| Memory: \S+ MB \| Processes: 1$/m', $display);
    }

    /**
     * The footer counts the workers the run started, at most --jobs: one
     * pattern is analysed in this process alone, whatever --jobs allows.
     */
    #[Test]
    public function test_execute_footer_counts_the_workers_started(): void
    {
        $tester = new CommandTester($this->createCommandWithSources([self::sourceOf('/foo/')]));

        $status = $tester->execute(['paths' => ['.'], '--jobs' => '2']);
        $display = (string) $tester->getDisplay();

        $this->assertSame(0, $status, $display);
        $this->assertMatchesRegularExpression('/^Processes : 2$/m', $display, 'The banner shows what the run planned.');
        $this->assertMatchesRegularExpression('/\bProcesses: 1$/m', $display);
    }

    /**
     * Undecorated output (a CI log) gets no new line while the run waits:
     * the clock is redrawn only on a terminal. Each worker here waits 1.2 s
     * before its first pattern, so a bar redrawn while it waits would print
     * its "0/2" line again once the elapsed time reads one second
     * ("1 s" on recent Symfony releases, "1 sec" on older ones).
     */
    #[Test]
    #[RequiresFunction('pcntl_fork')]
    public function test_execute_adds_no_line_while_waiting_on_an_undecorated_output(): void
    {
        $marker = sys_get_temp_dir().'/php-regex-slow-worker-'.bin2hex(random_bytes(6));
        $command = $this->createCommandWithSources(
            [self::sourceOf('/foo/', '/bar/')],
            ['cache' => self::cacheSlowInWorkers($marker, 1_200_000)],
        );
        $tester = new CommandTester($command);

        try {
            $startedAt = microtime(true);
            $status = $tester->execute(['paths' => ['.'], '--jobs' => '2'], ['decorated' => false]);
            $duration = microtime(true) - $startedAt;
        } finally {
            @unlink($marker);
        }
        $display = (string) $tester->getDisplay();

        $this->assertSame(0, $status, $display);
        $this->assertGreaterThanOrEqual(1.2, $duration, 'The workers did not wait: nothing was tested.');
        $this->assertStringContainsString('[2/3] Analyzing patterns', $display);
        // Symfony 6.4 writes "< 1 sec" for no time at all, 7.x "< 1 ms".
        $this->assertDoesNotMatchRegularExpression('/\b0\/2\b.*(?:(?<!< )\b[1-9]\d* (?:s|secs?|min)\b|\b\d\d:0[1-9]\b)/', $display, $display);
    }

    /**
     * A parse cache that, in a forked worker only, waits before its first
     * answer, then always misses. The marker file keeps the wait to the
     * first step that forks.
     */
    private static function cacheSlowInWorkers(string $marker, int $microseconds): CacheInterface
    {
        return new class(getmypid(), $marker, $microseconds) implements CacheInterface {
            public function __construct(
                private readonly int|false $parent,
                private readonly string $marker,
                private readonly int $microseconds,
            ) {}

            public function generateKey(string $seed): string
            {
                return hash('xxh128', $seed);
            }

            public function write(string $key, RegexNode $ast): void {}

            public function load(string $key): ?RegexNode
            {
                if (getmypid() !== $this->parent && !file_exists($this->marker)) {
                    usleep($this->microseconds);
                    @touch($this->marker);
                }

                return null;
            }
        };
    }

    /**
     * Runs the command, failing instead of hanging when it waits for a
     * worker that never answers.
     *
     * @template T
     *
     * @param \Closure(): T $run
     *
     * @return T
     */
    private static function withinTimeLimit(\Closure $run): mixed
    {
        $async = pcntl_async_signals(true);
        pcntl_signal(\SIGALRM, static function (): never {
            throw new \RuntimeException('The lint did not end within 30 seconds: it waits for a worker that is gone.');
        });
        pcntl_alarm(30);

        try {
            return $run();
        } finally {
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, \SIG_DFL);
            pcntl_async_signals($async);
        }
    }

    private function createCommand(): LintCommand
    {
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService(
            $analysis,
            new PatternSourceCollection([]),
        );

        return new LintCommand(
            lint: $lint,
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            editorUrl: null,
        );
    }

    /**
     * @return array<mixed>
     */
    private static function firstIssue(string $json): array
    {
        $data = json_decode($json, true);
        self::assertIsArray($data, $json);
        self::assertIsArray($data['results'] ?? null);
        self::assertIsArray($data['results'][0] ?? null);
        self::assertIsArray($data['results'][0]['issues'] ?? null);
        self::assertIsArray($data['results'][0]['issues'][0] ?? null);

        return $data['results'][0]['issues'][0];
    }

    private static function sourceOf(string ...$patterns): PatternSourceInterface
    {
        return new class(array_values($patterns)) implements PatternSourceInterface {
            /**
             * @param list<string> $patterns
             */
            public function __construct(private readonly array $patterns) {}

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
                return array_map(
                    static fn (string $pattern, int $index): PatternOccurrence => new PatternOccurrence($pattern, 'test.php', 12 + $index, 'php:preg_match()'),
                    $this->patterns,
                    array_keys($this->patterns),
                );
            }
        };
    }

    /**
     * @param array<int, PatternSourceInterface> $sources
     * @param RegexOptions                       $regexOptions
     */
    private function createCommandWithSources(array $sources, array $regexOptions = []): LintCommand
    {
        $analysis = new AnalysisService(RegexParser::create());
        $lint = new LintService(
            $analysis,
            new PatternSourceCollection($sources),
        );

        return new LintCommand(
            lint: $lint,
            analysis: $analysis,
            formatterRegistry: new FormatterRegistry(),
            editorUrl: null,
            regexOptions: $regexOptions,
        );
    }
}
