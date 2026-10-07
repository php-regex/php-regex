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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\CliException;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\JsonRequest;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintArguments;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
final class LintCommand extends AbstractCommand implements JsonCommandInterface
{
    private const USAGE = "Usage: regex lint [paths...] [--exclude <path>] [--min-savings <n>] [--jobs <n>] [--format console|json|github|checkstyle|junit] [--json] [--output <file>] [--baseline <file>] [--generate-baseline <file>] [--redos] [--no-redos] [--redos-mode=theoretical|confirmed] [--redos-threshold=low|medium|high|critical] [--no-validate] [--no-optimize] [--interop <presets>] [--no-interop] [--pattern-function <spec>] [--verbose|--debug|--quiet]\n";

    public function __construct(
        private readonly HelpCommand $helpCommand,
        private readonly LintConfigLoader $configLoader,
        private readonly LintDefaultsBuilder $defaultsBuilder,
        private readonly LintArgumentParser $argumentParser,
        private readonly LintExtractorFactory $extractorFactory,
        private readonly LintOutputRenderer $outputRenderer,
    ) {}

    public function getName(): string
    {
        return 'lint';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Lint regex patterns in PHP source code';
    }

    public function run(Input $input, Output $output): int
    {
        // Read before the configuration is: an unreadable regex.json is
        // still reported as JSON.
        $requestedFormat = JsonRequest::format($input->args);
        $json = 'json' === $requestedFormat;

        $lintConfigResult = $this->configLoader->load();
        if (null !== $lintConfigResult->error) {
            return $this->fail($output, $json, $lintConfigResult->error, JsonDocument::STAGE_CONFIG, self::INVALID);
        }

        $lintDefaults = $this->defaultsBuilder->build($lintConfigResult->config);
        $lintConfigFiles = $lintConfigResult->files;

        // The configured format holds until the command line names one.
        $configuredFormat = $lintDefaults['format'] ?? null;
        $json = 'json' === ($requestedFormat ?? (\is_string($configuredFormat) ? strtolower($configuredFormat) : null));

        $parsed = $this->argumentParser->parse($input->args, $lintDefaults);
        if ($parsed->help) {
            return $this->helpCommand->run(new Input('help', [], $input->globalOptions, []), $output);
        }
        $arguments = $parsed->arguments;
        if (null !== $parsed->error || null === $arguments) {
            $code = $this->fail($output, $json, $parsed->error ?? 'Invalid lint arguments', JsonDocument::STAGE_USAGE, self::INVALID);
            if (!$json) {
                $output->writeError(self::USAGE);
            }

            return $code;
        }

        // A format is named in any case, as JsonRequest reads it.
        $format = strtolower($arguments->format);
        $json = 'json' === $format;
        $formatterRegistry = new FormatterRegistry();
        if (!$formatterRegistry->has($format)) {
            return $this->fail($output, $json, \sprintf('Unknown format: %s. Available formats: %s', $arguments->format, implode(', ', $formatterRegistry->getNames())), JsonDocument::STAGE_USAGE, self::INVALID);
        }

        $baseline = null;
        $unusable = $this->unusableFile($arguments, $parsed->pathsGiven, $baseline);
        if (null !== $unusable) {
            return $this->fail($output, $json, $unusable[0], $unusable[1], self::INVALID);
        }

        try {
            $target = ProjectTarget::resolve(
                $input->globalOptions->phpVersion,
                $input->globalOptions->pcreVersion,
                $lintConfigResult->config,
                getcwd() ?: '.',
                getenv(),
            );
            $regex = Regex::create($target->regexOptions());
            $range = $target->rangeParsers($regex->parser());
        } catch (InvalidRegexOptionException $e) {
            // A version given on the command line is a usage error; one
            // read from regex.json or the environment, a configuration one.
            $fromCommandLine = null !== $input->globalOptions->phpVersion || null !== $input->globalOptions->pcreVersion;

            return $this->fail($output, $json, 'Invalid option: '.$e->getMessage(), $fromCommandLine ? JsonDocument::STAGE_USAGE : JsonDocument::STAGE_CONFIG, self::INVALID);
        }

        $paths = $arguments->paths;
        $exclude = $arguments->exclude;
        $minSavings = (int) $arguments->minSavings;
        $verbosity = $arguments->verbosity;
        $checkRedos = $arguments->checkRedos;
        $checkValidation = $arguments->checkValidation;
        $checkOptimizations = $arguments->checkOptimizations;
        $jobs = (int) $arguments->jobs;
        if (-1 === $jobs) {
            // Auto-detect optimal number of jobs
            $jobs = self::detectCpuCount();
        } elseif ($jobs < 1) {
            $jobs = 1; // Minimum 1 job
        }

        if ([] === $paths) {
            $paths = ['.'];
        }
        if ([] === $exclude && !\array_key_exists('exclude', $lintDefaults)) {
            $exclude = ['vendor'];
        }

        // Outside the console format, stdout holds the report alone: the
        // target and what resolving it noticed go to stderr, when there is
        // one. The console banner carries them instead.
        if (OutputConfiguration::VERBOSITY_QUIET !== $verbosity && !$output->isQuiet() && 'console' !== $format) {
            foreach ($target->notices() as $notice) {
                $output->writeError('Note: '.$notice."\n");
            }
            if (!$json) {
                $output->writeError(\sprintf("Target: PHP %s, PCRE2 %s (%s)\n", $target->php(), $target->target()->pcreVersion, $target->source()));
            }
        }

        // Always respect configuration values regardless of verbosity
        $config = new OutputConfiguration(
            verbosity: $verbosity,
            ansi: $output->isAnsi(),
            showProgress: OutputConfiguration::VERBOSITY_QUIET !== $verbosity,
            showHints: OutputConfiguration::VERBOSITY_QUIET !== $verbosity,
            showOptimizations: $checkOptimizations,
        );

        // The confirmation always runs the interpreter, whose backtrack limit
        // is the one it measures against; there is no setting to turn JIT on.
        $analysis = new AnalysisService(
            $regex->parser(),
            redosThreshold: $arguments->redosThreshold ?? RedosSeverity::High->value,
            redosMode: $arguments->redosMode,
            redosConfirmOptions: new ConfirmationOptions(),
            redosEnabled: $checkRedos,
            lintEnabled: $arguments->checkLint,
            lintRules: $arguments->lintRules,
            range: $range,
        );

        $formatter = $json ? new JsonFormatter(target: $target->toArray()) : $formatterRegistry->get($format);

        if ('console' === $format) {
            $linkFormatter = '' !== $arguments->ide
                ? new LinkFormatter($arguments->ide, new RelativePathHelper())
                : null;
            $formatter = new ConsoleFormatter($analysis, $config, $arguments->ide, $linkFormatter);
        }

        $extractor = $this->extractorFactory->create($arguments);
        $sources = new PatternSourceCollection([
            new PhpFilePatternSource($extractor),
        ]);
        $lint = new LintService($analysis, $sources);

        if ('console' === $format && OutputConfiguration::VERBOSITY_QUIET !== $verbosity) {
            $output->write($this->outputRenderer->renderBanner($output, $target, $jobs, $lintConfigFiles));
        }

        $collectionProgress = null;
        $startTime = (float) microtime(true);
        $fileCount = 0;

        if ('console' === $format && $config->shouldShowProgress()) {
            $output->write('  '.$output->dim('[1/2] Scanning files')."\n");
            $collectionStarted = false;
            $lastCount = 0;
            $collectionProgress = static function (int $current, int $total) use (&$collectionStarted, &$lastCount, $output, &$fileCount): void {
                if ($total <= 0) {
                    return;
                }

                $fileCount = $total;

                if (!$collectionStarted) {
                    $output->progressStart($total);
                    $collectionStarted = true;
                }

                $advance = $current - $lastCount;
                if ($advance > 0) {
                    $output->progressAdvance($advance);
                    $lastCount = $current;
                }

                if ($current >= $total) {
                    $output->progressFinish();
                }
            };
        }

        try {
            $request = new LintRequest(
                paths: $paths,
                excludePaths: $exclude,
                minSavings: $minSavings,
                checkValidation: $checkValidation,
                checkRedos: $checkRedos,
                checkOptimizations: $checkOptimizations,
                analysisWorkers: $jobs,
                optimizations: OptimizerOptions::fromCamelCaseArray($arguments->optimizations + ['verifyWithAutomata' => true]),
            );
            $patterns = $lint->collectPatterns($request, $collectionProgress);
        } catch (\Throwable $e) {
            return $this->fail($output, $json, 'Failed to collect patterns: '.$e->getMessage(), JsonDocument::STAGE_COLLECT, self::FAILURE);
        }

        if ([] === $patterns) {
            $emptyReport = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);
            if (!$this->writeBaselineFile($arguments, $emptyReport)) {
                return $this->failToWriteBaseline($output, $json, (string) $arguments->generateBaseline);
            }
            if ('console' === $format) {
                $this->outputRenderer->renderSummary($output, $emptyReport->stats, true);
            } else {
                $this->writeReport($output, $format, $formatter->format($emptyReport));
            }
            $this->writeRequestedFiles($output, $arguments, $baseline, $format, $formatter, $emptyReport);

            return self::SUCCESS;
        }

        if ('console' === $format) {
            $patternCount = \count($patterns);
            $output->write('  '.$output->dim("Scanned {$fileCount} files, found {$patternCount} patterns.\n\n"));
        }

        $progressCallback = null;
        if ('console' === $format && $config->shouldShowProgress()) {
            $output->write('  '.$output->dim('[2/2] Analyzing patterns')."\n");
            $output->progressStart(\count($patterns));
            $progressCallback = static fn () => $output->progressAdvance();
        }

        $report = $lint->analyze($patterns, $request, $progressCallback);

        if (null !== $progressCallback) {
            $output->progressFinish();
        }

        // A generated baseline must cover the FULL report, before any
        // existing baseline filters issues out — otherwise previously
        // baselined issues silently vanish from the new baseline.
        if (!$this->writeBaselineFile($arguments, $report)) {
            return $this->failToWriteBaseline($output, $json, (string) $arguments->generateBaseline);
        }

        if (null !== $baseline) {
            $report = $baseline->filter($report);
        }

        $this->writeReport($output, $format, $formatter->format($report));

        if ($formatter instanceof ConsoleFormatter) {
            $output->write($formatter->getSummary($report->stats));
            $elapsed = (float) microtime(true) - $startTime;
            $peakMemory = memory_get_peak_usage(true);
            $cacheStats = $regex->getCacheStats();
            $timeStr = round($elapsed, 2).'s';
            $memoryStr = round($peakMemory / 1024 / 1024, 2).' MB';
            $cacheStr = $cacheStats['hits'].' hits, '.$cacheStats['misses'].' misses';
            $processesStr = (string) $jobs;
            $output->write('  '.$output->dim('Time: ').$output->warning($timeStr).'  '.$output->dim('Memory: ').$output->warning($memoryStr).'  '.$output->dim('Cache: ').$output->warning($cacheStr).'  '.$output->dim('Processes: ').$output->warning($processesStr)."\n");
            $output->write($formatter->formatFooter());
        }

        $this->writeRequestedFiles($output, $arguments, $baseline, $format, $formatter, $report);

        return $report->stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * What makes a file the run is given unusable, checked before the run
     * starts: a path that does not exist, a baseline that is missing, cannot
     * be read or holds no baseline, a baseline to generate that cannot be
     * written.
     *
     * @param bool              $pathsGiven whether the paths come from the command line, not from the configuration
     * @param LintBaseline|null $baseline   the baseline, read here once
     *
     * @param-out LintBaseline|null $baseline
     *
     * @return array{string, string}|null the message and the stage of the error
     */
    private function unusableFile(LintArguments $arguments, bool $pathsGiven, ?LintBaseline &$baseline): ?array
    {
        foreach ($arguments->paths as $path) {
            if ('' !== $path && !file_exists($path)) {
                return $pathsGiven
                    ? ['Path not found: '.$path, JsonDocument::STAGE_USAGE]
                    : ['Path not found: '.$path.' (from the "paths" of the configuration)', JsonDocument::STAGE_CONFIG];
            }
        }

        $baseline = null;
        if (null !== $arguments->baseline) {
            try {
                $baseline = LintBaseline::load($arguments->baseline);
            } catch (CliException $e) {
                return [$e->getMessage(), JsonDocument::STAGE_USAGE];
            }
        }

        $generated = (string) $arguments->generateBaseline;
        if ('' !== $generated && !self::canWrite($generated)) {
            return ['Baseline file not writable: '.$generated, JsonDocument::STAGE_USAGE];
        }

        return null;
    }

    /**
     * Whether a file can be written: an existing file that is writable, or a
     * new one in a writable directory.
     */
    private static function canWrite(string $file): bool
    {
        if (file_exists($file)) {
            return is_file($file) && is_writable($file);
        }

        $directory = \dirname($file);

        return is_dir($directory) && is_writable($directory);
    }

    /**
     * Writes the baseline --generate-baseline asks for, if any.
     *
     * @return bool false when it was asked for and could not be written
     */
    private function writeBaselineFile(LintArguments $arguments, LintReport $report): bool
    {
        if (!$arguments->generateBaseline) {
            return true;
        }

        return false !== @file_put_contents($arguments->generateBaseline, LintBaseline::generate($report));
    }

    /**
     * A baseline the check before the run found writable, then could not be
     * written: on stderr, and as the envelope while stdout holds no document
     * yet. Never "Baseline generated".
     */
    private function failToWriteBaseline(Output $output, bool $json, string $file): int
    {
        $message = 'Could not write the baseline file: '.$file;
        $output->writeError('Error: '.$message."\n");
        if ($json && !$output->hasWrittenDocument()) {
            $output->writeDocument((new JsonFormatter())->formatError($message, JsonDocument::STAGE_USAGE));
        }

        return self::INVALID;
    }

    /**
     * Name the baseline written, note a 1.x baseline, and write the report
     * to --output as well.
     */
    private function writeRequestedFiles(Output $output, LintArguments $arguments, ?LintBaseline $baseline, string $format, OutputFormatterInterface $formatter, LintReport $report): void
    {
        // Only the console report shares stdout with status lines.
        $status = 'console' === $format ? $output->write(...) : $output->writeError(...);

        if (null !== $arguments->generateBaseline) {
            $status("Baseline generated at {$arguments->generateBaseline}\n");
        }

        if (null !== $baseline && $baseline->legacy) {
            $status("Note: the baseline {$arguments->baseline} is in the 1.x format, matched on file, line and message; regenerate it with --generate-baseline to match issues whose line or message changed.\n");
        }

        $outputFile = $arguments->output;
        if (null === $outputFile) {
            return;
        }

        $content = $formatter->format($report);
        if ('console' === $format) {
            // Strip ANSI codes for file output
            $content = LibraryPcre::replace('/\e\[[0-9;]*m/', '', $content);
        }
        $dir = \dirname($outputFile);
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            $output->writeError("Could not create directory: $dir\n");
        } elseif (false === @file_put_contents($outputFile, $content)) {
            $output->writeError("Could not write to file: $outputFile\n");
        } else {
            $status("Output also written to: {$outputFile}\n");
        }
    }

    /**
     * Report an error the run cannot go past: as JSON on stdout when a JSON
     * report was asked for, so that stdout stays one JSON document, else on
     * stderr.
     */
    private function fail(Output $output, bool $json, string $message, string $stage, int $exitCode): int
    {
        if ($json) {
            $output->writeDocument((new JsonFormatter())->formatError($message, $stage));
        } else {
            $output->writeError('Error: '.$message."\n");
        }

        return $exitCode;
    }

    /**
     * A machine report (json, github, checkstyle, junit) is the output asked
     * for, a document: quiet mode silences the status lines around it, never
     * the report itself. Only the console report is a human one.
     */
    private function writeReport(Output $output, string $format, string $report): void
    {
        if ('console' === $format) {
            $output->write($report);
        } else {
            $output->writeDocument($report);
        }
    }

    /**
     * Detect the number of available CPU cores for optimal parallel processing.
     */
    private static function detectCpuCount(): int
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

            // macOS/BSD
            $result = \shell_exec('sysctl -n hw.ncpu 2>/dev/null');
            if (null !== $result) {
                $cpu = (int) \trim((string) $result);
                if ($cpu > 0) {
                    return $cpu;
                }
            }
        } else {
            // Windows
            $result = \shell_exec('wmic cpu get NumberOfCores 2>nul | findstr /r /v "^$" | findstr /v "NumberOfCores"');
            if (null !== $result) {
                $cpu = (int) \trim((string) $result);
                if ($cpu > 0) {
                    return $cpu;
                }
            }
        }

        // Fallback
        return 1;
    }
}
