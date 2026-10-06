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
use PHPRegex\Cli\Output;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
final class LintCommand extends AbstractCommand implements CommandInterface
{
    private const USAGE = "Usage: regex lint [paths...] [--exclude <path>] [--min-savings <n>] [--jobs <n>] [--format console|json|github|checkstyle|junit] [--output <file>] [--baseline <file>] [--generate-baseline <file>] [--redos] [--no-redos] [--redos-mode=theoretical|confirmed] [--redos-threshold=low|medium|high|critical] [--no-validate] [--no-optimize] [--interop <presets>] [--no-interop] [--pattern-function <spec>] [--verbose|--debug|--quiet]\n";

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
        $json = self::asksForJson($input->args);

        $lintConfigResult = $this->configLoader->load();
        if (null !== $lintConfigResult->error) {
            return $this->fail($output, $json, $lintConfigResult->error, self::INVALID);
        }

        $lintDefaults = $this->defaultsBuilder->build($lintConfigResult->config);
        $lintConfigFiles = $lintConfigResult->files;

        $parsed = $this->argumentParser->parse($input->args, $lintDefaults);
        if ($parsed->help) {
            return $this->helpCommand->run(new Input('help', [], $input->globalOptions, []), $output);
        }
        $arguments = $parsed->arguments;
        if (null !== $parsed->error || null === $arguments) {
            $code = $this->fail($output, $json, $parsed->error ?? 'Invalid lint arguments', self::INVALID);
            if (!$json) {
                $output->writeError(self::USAGE);
            }

            return $code;
        }

        $format = $arguments->format;
        $json = 'json' === $format;
        $formatterRegistry = new FormatterRegistry();
        if (!$formatterRegistry->has($format)) {
            return $this->fail($output, $json, \sprintf('Unknown format: %s. Available formats: %s', $format, implode(', ', $formatterRegistry->getNames())), self::INVALID);
        }

        // Read before the run: a baseline that cannot be used stops it.
        $baseline = null;
        if (null !== $arguments->baseline) {
            try {
                $baseline = LintBaseline::load($arguments->baseline);
            } catch (CliException $e) {
                return $this->fail($output, $json, $e->getMessage(), self::INVALID);
            }
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
        } catch (InvalidRegexOptionException $e) {
            return $this->fail($output, $json, 'Invalid option: '.$e->getMessage(), self::INVALID);
        }

        $paths = $arguments->paths;
        $exclude = $arguments->exclude;
        $minSavings = (int) $arguments->minSavings;
        $verbosity = $arguments->verbosity;
        $quiet = $arguments->quiet;
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
        $outputFile = $arguments->output;

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
        $collectionStartTime = $startTime;
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
            return $this->fail($output, $json, 'Failed to collect patterns: '.$e->getMessage(), self::FAILURE);
        }

        $collectionTime = (float) microtime(true) - $collectionStartTime;

        if ([] === $patterns) {
            if ('console' === $format) {
                $this->outputRenderer->renderSummary($output, ['errors' => 0, 'warnings' => 0, 'optimizations' => 0], true);
            } else {
                $emptyReport = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);
                $output->write($formatter->format($emptyReport));
            }

            return self::SUCCESS;
        }

        if ('console' === $format) {
            $patternCount = \count($patterns);
            $output->write('  '.$output->dim("Scanned {$fileCount} files, found {$patternCount} patterns.\n\n"));
        }

        $progressCallback = null;
        $analysisStartTime = (float) microtime(true);
        if ('console' === $format && $config->shouldShowProgress()) {
            $output->write('  '.$output->dim('[2/2] Analyzing patterns')."\n");
            $output->progressStart(\count($patterns));
            $progressCallback = static fn () => $output->progressAdvance();
        }

        $report = $lint->analyze($patterns, $request, $progressCallback);

        if (null !== $progressCallback) {
            $output->progressFinish();
        }

        $analysisTime = (float) microtime(true) - $analysisStartTime;

        // A generated baseline must cover the FULL report, before any
        // existing baseline filters issues out — otherwise previously
        // baselined issues silently vanish from the new baseline.
        if (null !== $arguments->generateBaseline) {
            if (false === @file_put_contents($arguments->generateBaseline, LintBaseline::generate($report)."\n")) {
                return $this->fail($output, $json, 'Could not write the baseline to '.$arguments->generateBaseline, self::INVALID);
            }
        }

        if (null !== $baseline) {
            $report = $baseline->filter($report);
        }

        $output->write($formatter->format($report));

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

        // Only the console report shares stdout with status lines.
        $status = 'console' === $format ? $output->write(...) : $output->writeError(...);

        if (null !== $arguments->generateBaseline) {
            $status("Baseline generated at {$arguments->generateBaseline}\n");
        }

        if (null !== $baseline && $baseline->legacy) {
            $status("Note: the baseline {$arguments->baseline} is in the 1.x format, matched on file, line and message; regenerate it with --generate-baseline to match issues whose line or message changed.\n");
        }

        if (null !== $outputFile) {
            $content = $formatter->format($report);
            if ('console' === $format) {
                // Strip ANSI codes for file output
                $content = LibraryPcre::replace('/\e\[[0-9;]*m/', '', $content);
            }
            $dir = dirname($outputFile);
            if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                $output->writeError("Could not create directory: $dir\n");
            } elseif (false === @file_put_contents($outputFile, $content)) {
                $output->writeError("Could not write to file: $outputFile\n");
            } else {
                $status("Output also written to: {$outputFile}\n");
            }
        }

        return $report->stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Report an error the run cannot go past: as JSON on stdout when a JSON
     * report was asked for, so that stdout stays one JSON document, else on
     * stderr.
     */
    private function fail(Output $output, bool $json, string $message, int $exitCode): int
    {
        if ($json) {
            $output->write((new JsonFormatter())->formatError($message)."\n");
        } else {
            $output->writeError('Error: '.$message."\n");
        }

        return $exitCode;
    }

    /**
     * Whether the command line asks for a JSON report, read before the
     * configuration is: an unreadable regex.json is still reported as JSON.
     *
     * @param array<int, string> $args
     */
    private static function asksForJson(array $args): bool
    {
        foreach ($args as $index => $arg) {
            if ('--format=json' === $arg || ('--format' === $arg && 'json' === ($args[$index + 1] ?? null))) {
                return true;
            }
        }

        return false;
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
