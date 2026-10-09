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

namespace PHPRegex\Laravel\Command;

use Illuminate\Console\Command;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Laravel\Output\LaravelConsoleFormatter;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\Internal\LintStatsCounter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lint regex patterns in PHP source code.
 *
 * @phpstan-import-type LintResult from LintReport
 *
 * @internal
 */
final class LintCommand extends Command
{
    private const PROGRESS_BAR_WIDTH = 28;
    private const MESSAGE_PAD_LENGTH = 15;
    private const FORMAT_CONSOLE = 'console';

    /**
     * The identifier of a source file an extractor could not read.
     */
    private const ISSUE_ID_SOURCE_UNREADABLE = 'regex.lint.source.unreadable';

    /**
     * Keys of config/php-regex.php that 2.0 no longer reads, with what
     * to write instead. A config published by 1.x may still hold them.
     */
    private const STALE_KEYS = [
        'exclude_paths' => 'renamed "exclude"',
        'analysis.ignore_patterns' => 'merged into "redos.ignored_patterns"',
        'analysis.redos_threshold' => 'removed, it was never read; the ReDoS threshold is "redos.threshold"',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:lint
        {paths?* : The paths to analyze}
        {--exclude=* : Paths to exclude}
        {--min-savings=1 : Minimum optimization savings in characters}
        {--jobs=-1 : Parallel workers for analysis (auto-detected if not specified)}
        {--no-routes : Skip route validation}
        {--no-validators : Skip validator validation}
        {--format=console : Output format (console, json, github, checkstyle, junit)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lints, validates, and optimizes regex patterns in your PHP code';

    /**
     * The lint and analysis services are resolved when the command runs,
     * not when artisan lists its commands: a setting they cannot use then
     * stops this command only.
     */
    public function __construct(private readonly FormatterRegistry $formatterRegistry)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $workingDir = base_path();
        $pathHelper = new RelativePathHelper($workingDir);
        $editorUrl = config('php-regex.ide');
        $linkFormatter = new LinkFormatter(\is_string($editorUrl) ? $editorUrl : null, $pathHelper);

        /** @var array<string>|null $pathsArg */
        $pathsArg = $this->argument('paths');
        /** @var array<string> $defaultPaths */
        $defaultPaths = config('php-regex.paths', ['app']);
        $paths = !empty($pathsArg) ? $pathsArg : $defaultPaths;

        /** @var array<string>|null $excludeOption */
        $excludeOption = $this->option('exclude');
        /** @var array<string> $defaultExclude */
        $defaultExclude = config('php-regex.exclude', ['vendor', 'node_modules', 'storage']);
        $exclude = !empty($excludeOption) ? $excludeOption : $defaultExclude;

        $minSavings = (int) $this->option('min-savings');
        $skipRoutes = (bool) $this->option('no-routes');
        $skipValidators = (bool) $this->option('no-validators');
        $format = strtolower((string) $this->option('format'));

        if (!$this->formatterRegistry->has($format)) {
            $this->error(\sprintf(
                "Invalid format '%s'. Supported formats: %s",
                $format,
                implode(', ', $this->formatterRegistry->getNames()),
            ));

            return self::INVALID;
        }

        $jobs = (int) $this->option('jobs');
        if ($jobs < 1) {
            $jobs = $this->detectCpuCount();
        }

        // The Regex service judges for the running PHP; the lint judges for
        // the project's target, and never compiles with the running PHP.
        try {
            $target = ProjectTarget::fromSources(
                ['config php-regex.php_version' => $this->configVersion('php_version')],
                ['config php-regex.pcre_version' => $this->configRelease('pcre_version')],
                base_path(),
                getenv(),
            );
            $parser = Regex::create(PHPRegexServiceProvider::regexOptions($this->laravel) + $target->regexOptions())->parser();
            $range = $target->rangeParsers($parser, PHPRegexServiceProvider::regexOptions($this->laravel));
            /** @var \PHPRegex\Linter\AnalysisService $appAnalysis */
            $appAnalysis = $this->laravel->make('php-regex.analysis');
            /** @var \PHPRegex\Linter\LintService $appLint */
            $appLint = $this->laravel->make('php-regex.lint');
        } catch (InvalidRegexOptionException $e) {
            return $this->renderFailure($format, 'Invalid config/php-regex.php: '.$e->getMessage(), JsonDocument::STAGE_CONFIG, self::INVALID);
        }
        $analysis = $appAnalysis->withParser($parser, $range);
        $lint = $appLint->withAnalysis($analysis);

        $this->formatterRegistry->override(
            self::FORMAT_CONSOLE,
            new LaravelConsoleFormatter($analysis, $linkFormatter, $this->output->isDecorated()),
        );
        $this->formatterRegistry->override('json', new JsonFormatter(target: $target->toArray()));

        if (self::FORMAT_CONSOLE === $format) {
            $this->showBanner($jobs, $target);
        } else {
            $this->reportOnStderr($target);
        }

        $startTime = (float) microtime(true);
        $showProgress = self::FORMAT_CONSOLE === $format && !$this->option('quiet');
        $collectionBar = null;
        $collectionFinished = false;
        $lastCount = 0;
        $fileCount = 0;

        if ($showProgress) {
            $this->line('  <fg=gray>[1/2] Scanning files</>');
            $collectionProgress = function (int $current, int $total) use (&$collectionBar, &$collectionFinished, &$lastCount, &$fileCount): void {
                if ($collectionFinished || $total <= 0) {
                    return;
                }

                $fileCount = $total;

                if (null === $collectionBar) {
                    $collectionBar = $this->createProgressBar($total);
                }

                $status = str_pad($current.'/'.$total, self::MESSAGE_PAD_LENGTH, ' ', \STR_PAD_LEFT);
                $collectionBar->setMessage($status);
                $advance = $current - $lastCount;
                if ($advance > 0) {
                    $collectionBar->advance($advance);
                    $lastCount = $current;
                }

                if ($current >= $total) {
                    $collectionBar->setMessage(str_pad($total.'/'.$total, self::MESSAGE_PAD_LENGTH, ' ', \STR_PAD_LEFT));
                    $collectionBar->finish();
                    $collectionFinished = true;
                }
            };
        } else {
            $collectionProgress = null;
        }

        $defaultOptimizations = $this->normalizeOptimizations(config('php-regex.optimizations', []));

        try {
            $request = new LintRequest(
                paths: $paths,
                excludePaths: $exclude,
                minSavings: $minSavings,
                disabledSources: array_values(array_filter([
                    $skipRoutes ? 'routes' : null,
                    $skipValidators ? 'validators' : null,
                ])),
                analysisWorkers: $jobs,
                optimizations: $defaultOptimizations,
                checkRedos: true === config('php-regex.redos.enabled'),
            );
            $patterns = $lint->collectPatterns($request, $collectionProgress);
        } catch (\Throwable $e) {
            return $this->renderFailure($format, 'Failed to collect patterns: '.$e->getMessage(), JsonDocument::STAGE_COLLECT, self::FAILURE);
        }

        $unreadFiles = $skipValidators ? [] : $this->unreadFileResults();

        // A file read with the tokenizer holds no pattern of its own: it is
        // counted in the stats only.
        $patternCount = \count(array_filter($patterns, static fn (PatternOccurrence $pattern): bool => null === $pattern->parserFallback));
        if ($showProgress) {
            $this->newLine();
            $this->line('  <fg=gray>Scanned '.$fileCount.' files, found '.$patternCount.' patterns.</>');
            $this->newLine();
        }

        if (empty($patterns) && [] === $unreadFiles) {
            return $this->renderEmptyResults($format);
        }

        $analysisBar = null;
        $currentAnalysis = 0;
        if ($showProgress) {
            $this->newLine();
            $this->line('  <fg=gray>[2/2] Analyzing patterns</>');
            $totalPatterns = \count($patterns);
            $analysisBar = $this->createProgressBar($totalPatterns);
            $progressCallback = static function () use ($analysisBar, &$currentAnalysis, $totalPatterns): void {
                $currentAnalysis++;
                $analysisBar->setMessage(str_pad($currentAnalysis.'/'.$totalPatterns, self::MESSAGE_PAD_LENGTH, ' ', \STR_PAD_LEFT));
                $analysisBar->advance();
            };
        } else {
            $progressCallback = null;
        }

        $report = $lint->analyze($patterns, $request, $progressCallback);

        if (null !== $analysisBar) {
            $analysisBar->setMessage(str_pad(\count($patterns).'/'.\count($patterns), 15, ' ', \STR_PAD_LEFT));
            $analysisBar->finish();
            $this->newLine(2);
        }

        $results = [...$report->results, ...$unreadFiles];
        $report = new LintReport(
            $this->sortResultsByFileAndLine($results),
            LintStatsCounter::count($results, $patterns),
        );

        $stats = $report->stats;

        $this->writeReport($format, $this->formatterRegistry->get($format)->format($report));

        if (self::FORMAT_CONSOLE === $format) {
            $elapsed = (float) microtime(true) - $startTime;
            $peakMemory = memory_get_peak_usage(true);
            $cacheStats = $parser->getCacheStats();
            $this->line('  <options=bold>Time:</> <fg=yellow>'.round($elapsed, 2).'s</> | <options=bold>Memory:</> <fg=yellow>'.round($peakMemory / 1024 / 1024, 2).' MB</> | <options=bold>Cache:</> <fg=yellow>'.$cacheStats['hits'].' hits, '.$cacheStats['misses'].' misses</> | <options=bold>Processes:</> <fg=yellow>'.$jobs.'</>');
            $this->newLine();
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A file the validation rule extractor could not read fails the run:
     * one error on its first line, named after the file and identified as
     * regex.lint.source.unreadable, instead of the rules it holds.
     *
     * @phpstan-return list<LintResult>
     */
    private function unreadFileResults(): array
    {
        /** @var ValidationRulePatternSource $validators */
        $validators = $this->laravel->make(ValidationRulePatternSource::class);

        $results = [];
        foreach ($validators->failures() as $failure) {
            $results[] = [
                'file' => $failure['file'],
                'line' => 1,
                'column' => 1,
                'fileOffset' => null,
                'source' => 'validation',
                'pattern' => null,
                'location' => 'Laravel validation rule',
                'issues' => [['type' => 'error', 'message' => $failure['message'], 'file' => $failure['file'], 'line' => 1, 'issueId' => self::ISSUE_ID_SOURCE_UNREADABLE, 'source' => 'validation']],
                'optimizations' => [],
                'problems' => [new Diagnostic(DiagnosticType::Lint, LintSeverity::Error, $failure['message'], self::ISSUE_ID_SOURCE_UNREADABLE)],
            ];
        }

        return $results;
    }

    private function showBanner(int $jobs, ProjectTarget $target): void
    {
        $version = Regex::VERSION;

        $this->line('<fg=cyan;options=bold>PHPRegex</> <fg=yellow>'.$version.'</> by Younes ENNAJI');
        $this->newLine();

        $maxLabelLength = max(array_map(strlen(...), ['Runtime', 'Target', 'Processes']));
        $this->line('<fg=white;options=bold>'.str_pad('Runtime', $maxLabelLength).'</> : PHP <fg=yellow>'.\PHP_VERSION.'</>');
        $this->line('<fg=white;options=bold>'.str_pad('Target', $maxLabelLength).'</> : '.\sprintf(
            'PHP <fg=yellow>%s</>, PCRE2 <fg=yellow>%s</> (%s)',
            $target->php(),
            $target->target()->pcreVersion,
            $target->source(),
        ));
        $this->line('<fg=white;options=bold>'.str_pad('Processes', $maxLabelLength).'</> : <fg=yellow>'.$jobs.'</>');
        foreach ($target->notices() as $notice) {
            $this->line('<fg=gray>Note: '.$notice.'</>');
        }
        foreach ($this->staleKeyWarnings() as $warning) {
            $this->warn($warning);
        }

        $this->newLine();
    }

    /**
     * Outside the console format, stdout holds the report alone: the
     * target, the notices and the stale keys go to stderr, when there is one.
     */
    private function reportOnStderr(ProjectTarget $target): void
    {
        $output = $this->output->getOutput();
        if (!$output instanceof ConsoleOutputInterface) {
            return;
        }

        $stderr = $output->getErrorOutput();
        foreach ([...$target->notices(), ...$this->staleKeyWarnings()] as $line) {
            $stderr->writeln($line, OutputInterface::OUTPUT_RAW);
        }
        $stderr->writeln(\sprintf('Target: PHP %s, PCRE2 %s (%s)', $target->php(), $target->target()->pcreVersion, $target->source()), OutputInterface::OUTPUT_RAW);
    }

    /**
     * The keys of config/php-regex.php 2.0 ignores, each with what
     * replaces it.
     *
     * @return list<string>
     */
    private function staleKeyWarnings(): array
    {
        $warnings = [];
        foreach (self::STALE_KEYS as $key => $replacement) {
            if (config()->has('php-regex.'.$key)) {
                $warnings[] = \sprintf(
                    'config/php-regex.php: "%s" is ignored since 2.0: %s. Re-publish the config (php artisan vendor:publish --tag=php-regex-config --force) or edit the key.',
                    $key,
                    $replacement,
                );
            }
        }

        return $warnings;
    }

    /**
     * A version of config/php-regex.php, as written: an int or a string.
     */
    private function configVersion(string $key): string|int|null
    {
        $value = config('php-regex.'.$key);
        if (null === $value || \is_string($value) || \is_int($value)) {
            return $value;
        }

        throw new InvalidRegexOptionException(\sprintf('"%s" must be a version string like "8.2" or a PHP_VERSION_ID like 80200, not a %s.', $key, get_debug_type($value)));
    }

    private function configRelease(string $key): ?string
    {
        $value = config('php-regex.'.$key);
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw new InvalidRegexOptionException(\sprintf('"%s" must be a PCRE2 release string like "10.42", not a %s.', $key, get_debug_type($value)));
    }

    /**
     * A run that stops: an error line on the console, the format's own
     * error document otherwise (the JSON envelope names the stage).
     */
    private function renderFailure(string $format, string $message, string $stage, int $exitCode): int
    {
        if (self::FORMAT_CONSOLE === $format) {
            $this->error($message);

            return $exitCode;
        }

        $formatter = $this->formatterRegistry->get($format);
        $error = $formatter instanceof JsonFormatter ? $formatter->formatError($message, $stage) : $formatter->formatError($message);
        $this->writeReport($format, str_ends_with($error, "\n") ? $error : $error."\n");

        return $exitCode;
    }

    /**
     * The console report goes through the console styles; a machine format
     * is written as it is, never read for tags, and even under --quiet: it
     * is the output asked for, not a status line.
     */
    private function writeReport(string $format, string $report): void
    {
        if (self::FORMAT_CONSOLE === $format) {
            $this->output->write($report);

            return;
        }

        $this->output->write($report, false, OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET);
    }

    private function renderEmptyResults(string $format): int
    {
        if (self::FORMAT_CONSOLE === $format) {
            $this->newLine();
            $this->line('  <bg=green;fg=white;options=bold> PASS </> <fg=gray>No regex patterns found.</>');
            $this->showFooter();

            return self::SUCCESS;
        }

        $emptyReport = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $this->writeReport($format, $this->formatterRegistry->get($format)->format($emptyReport));

        return self::SUCCESS;
    }

    private function showFooter(): void
    {
        $this->newLine();
        $message = 'If PHPRegex helps, a GitHub star is appreciated: ';
        $this->line('  <fg=gray>'.$message.'https://github.com/php-regex/php-regex</>');
        $this->newLine();
    }

    private function createProgressBar(int $total): ProgressBar
    {
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %message% [%bar%] %percent:3s%% %elapsed:6s%');
        $bar->setBarWidth(self::PROGRESS_BAR_WIDTH);
        $bar->setProgressCharacter('▓');
        $bar->setEmptyBarCharacter('░');
        $bar->setMessage(str_pad('0/'.$total, self::MESSAGE_PAD_LENGTH, ' ', \STR_PAD_LEFT));
        $bar->start();

        return $bar;
    }

    /**
     * Detect the number of available CPU cores.
     */
    private function detectCpuCount(): int
    {
        // Try Swoole extension first (fastest)
        if (\function_exists('swoole_cpu_num')) {
            return swoole_cpu_num();
        }

        // Unix-like systems
        if (\DIRECTORY_SEPARATOR === '/') {
            // Linux
            // Linux only: no test on another system reads /proc/cpuinfo.
            if (\is_readable('/proc/cpuinfo')) {
                $cpuinfo = \file_get_contents('/proc/cpuinfo');
                if (false !== $cpuinfo) {
                    $matches = [];
                    LibraryPcre::matchAll('/^processor\s*:/m', $cpuinfo, $matches);
                    if (!empty($matches[0])) {
                        return \count($matches[0]);
                    }
                }
            }

            // macOS/BSD - use nproc command or fallback
            $nproc = false !== @\file_get_contents('/usr/bin/nproc') ? \trim((string) @\file_get_contents('/proc/self/status')) : null;
            if (null === $nproc) {
                // Try reading sysctl output via proc
                $sysctlPath = '/usr/sbin/sysctl';
                if (\is_executable($sysctlPath)) {
                    $output = [];
                    $returnCode = 0;
                    @\exec($sysctlPath.' -n hw.ncpu 2>/dev/null', $output, $returnCode);
                    if (0 === $returnCode && !empty($output[0])) {
                        $cpu = (int) \trim($output[0]);
                        if ($cpu > 0) {
                            return $cpu;
                        }
                    }
                }
            }
        }

        // Fallback
        return 1;
    }

    /**
     * The optimizations of config/php-regex.php, keyed in snake_case as
     * the optimizer reads them. Lint checks every rewrite with the automata
     * unless the config says otherwise.
     */
    private function normalizeOptimizations(mixed $optimizations): OptimizerOptions
    {
        return OptimizerOptions::fromArray((\is_array($optimizations) ? $optimizations : []) + ['verify_with_automata' => true]);
    }

    /**
     * @phpstan-param array<LintResult> $results
     *
     * @phpstan-return array<LintResult>
     */
    private function sortResultsByFileAndLine(array $results): array
    {
        usort($results, static function (array $a, array $b): int {
            $fileCompare = strcmp((string) $a['file'], (string) $b['file']);
            if (0 !== $fileCompare) {
                return $fileCompare;
            }

            return $a['line'] <=> $b['line'];
        });

        return $results;
    }
}
