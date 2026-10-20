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
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintRunFailure;
use PHPRegex\Linter\LintRunner;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintStatsCounter;
use PHPRegex\Linter\Parallel\WorkerCount;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

/**
 * @phpstan-import-type LintDefaults from LintDefaultsBuilder
 *
 * @internal
 */
final class LintCommand extends AbstractCommand implements JsonCommandInterface
{
    private const USAGE = "Usage: regex lint [paths...] [--exclude <path>] [--min-savings <n>] [--jobs <n>] [--format console|json|github|checkstyle|junit] [--json] [--output <file>] [--baseline <file>] [--generate-baseline <file>] [--no-report-unmatched-ignores] [--redos] [--no-redos] [--redos-mode=theoretical|confirmed] [--redos-threshold=low|medium|high|critical] [--no-validate] [--no-optimize] [--lint|--no-lint] [--enable-rule=<id>] [--disable-rule=<id>] [--interop <presets>] [--no-interop] [--pattern-function <spec>] [--verbose|--debug|--quiet]\n";

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
        $json = 'json' === ($requestedFormat ?? (\is_string($configuredFormat) ? Ascii::lower($configuredFormat) : null));

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

        // What regex.json and the command line hold that the run ignores:
        // one warning each on stderr, as the notes, and the run goes on. A
        // name both of them hold is said once.
        if (OutputConfiguration::VERBOSITY_QUIET !== $arguments->verbosity && !$output->isQuiet()) {
            foreach (array_unique([...$lintConfigResult->warnings, ...$parsed->warnings]) as $warning) {
                $output->writeError('Warning: '.$warning."\n");
            }
        }

        // A format is named in any case, as JsonRequest reads it.
        $format = Ascii::lower($arguments->format);
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
                $input->globalOptions->locale,
            );
            $regex = Regex::create($target->regexOptions());
            $range = $target->rangeParsers($regex->parser());
            $judged = $target->judgedRange($regex->parser());
        } catch (UnsupportedTargetException $e) {
            // Neither a usage error nor a finding: the project is not judged.
            return $this->fail($output, $json, $e->getMessage(), JsonDocument::STAGE_TARGET, self::INVALID);
        } catch (InvalidRegexOptionException $e) {
            // A version given on the command line is a usage error; one
            // read from regex.json or the environment, a configuration one.
            $fromCommandLine = null !== $input->globalOptions->phpVersion || null !== $input->globalOptions->pcreVersion || null !== $input->globalOptions->locale;

            return $this->fail($output, $json, $this->invalidOptionMessage($e, $input->regexOptions), $fromCommandLine ? JsonDocument::STAGE_USAGE : JsonDocument::STAGE_CONFIG, self::INVALID);
        }

        $paths = $arguments->paths;
        $exclude = $arguments->exclude;
        $minSavings = (int) $arguments->minSavings;
        $verbosity = $arguments->verbosity;
        // The author and the request for a star are for -v, in every front
        // end alike.
        $verbose = \in_array($verbosity, [OutputConfiguration::VERBOSITY_VERBOSE, OutputConfiguration::VERBOSITY_DEBUG], true);
        $checkRedos = $arguments->checkRedos;
        $checkValidation = $arguments->checkValidation;
        $checkOptimizations = $arguments->checkOptimizations;
        $jobs = (int) $arguments->jobs;
        if (-1 === $jobs) {
            // One process per core the run may use, when it can fork at all.
            $jobs = (new WorkerCount())->count();
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
                $output->writeError(\sprintf("Target: %s (%s)\n", $target->label(), $target->source()));
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
            warningThreshold: $arguments->complexityThreshold,
            redosThreshold: $arguments->redosThreshold ?? RedosSeverity::High->value,
            redosMode: RedosMode::tryFrom($arguments->redosMode) ?? RedosMode::Theoretical,
            redosConfirmOptions: new ConfirmationOptions(),
            redosEnabled: $checkRedos,
            lintEnabled: $arguments->checkLint,
            lintRules: $arguments->lintRules,
            range: $range,
            judged: $judged,
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
        $runner = new LintRunner(new LintService($analysis, $sources));

        if ('console' === $format && OutputConfiguration::VERBOSITY_QUIET !== $verbosity && $input->globalOptions->visuals) {
            $output->write($this->outputRenderer->renderBanner($output, $target, $jobs, $lintConfigFiles, $verbose));
        }

        $startTime = (float) microtime(true);

        // The steps and their progress go to stderr: stdout keeps the report.
        $showSteps = 'console' === $format && $config->shouldShowProgress() && !$output->isQuiet();
        $progress = 'console' === $format ? new LintConsoleProgress($output, $showSteps, $checkOptimizations) : null;

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
                declarationPaths: self::declarationPaths($lintDefaults, $paths),
                vendorPaths: [(getcwd() ?: '.').'/vendor'],
                checkComplexity: $arguments->checkComplexity,
                reportUnmatchedIgnores: $arguments->reportUnmatchedIgnores,
            );
            $patterns = $runner->collect($request, $progress);
        } catch (LintRunFailure $e) {
            $progress?->abandon();

            return $this->fail($output, $json, $e->getMessage(), $e->stage, $e->exitCode);
        } catch (InvalidRegexOptionException $e) {
            // An optimizer option of regex.json the request cannot take.
            $progress?->abandon();

            return $this->fail($output, $json, 'Failed to collect patterns: '.$e->getMessage(), JsonDocument::STAGE_COLLECT, self::FAILURE);
        }

        $progress?->endScan();

        // A baseline whose entries may match nothing any more is read even
        // when no pattern is left.
        if (LintService::holdsNothing($patterns, $arguments->reportUnmatchedIgnores) && !(null !== $baseline && $arguments->reportUnmatchedIgnores)) {
            $emptyReport = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);
            if (!$this->writeBaselineFile($arguments, $emptyReport)) {
                return $this->failToWriteBaseline($output, $json, (string) $arguments->generateBaseline);
            }
            if ('console' === $format) {
                $this->outputRenderer->renderSummary($output, $emptyReport->stats, true, $verbose);
            } else {
                $this->writeReport($output, $format, $formatter->format($emptyReport));
            }

            return $this->writeRequestedFiles($output, $arguments, $format, $formatter, $emptyReport) ? self::SUCCESS : self::INVALID;
        }

        // A file read with the tokenizer holds no pattern of its own: it is
        // counted in the stats, and named under --verbose.
        // A file read twice is named once, as results name it.
        $parserFallbacks = LintStatsCounter::parserFallbacks($patterns);
        if ([] !== $parserFallbacks && $verbose && !$output->isQuiet()) {
            $writeLine = 'console' === $format ? $output->write(...) : $output->writeError(...);
            $workingDirectory = getcwd();
            foreach ($parserFallbacks as $fallback) {
                $file = RelativePathHelper::relativize($fallback->file, false === $workingDirectory ? null : $workingDirectory);
                $writeLine(\sprintf("  Parsed with the tokenizer: %s (%s)\n", $file, $fallback->parserFallback));
            }
        }

        if (null !== $progress && $showSteps) {
            $patternCount = LintRunner::patternCount($patterns);
            $output->writeError('  '.$output->dim("Scanned {$progress->files()} files, found {$patternCount} patterns.")."\n\n");
        }

        try {
            $report = $runner->analyze($patterns, $request, $progress);
        } catch (LintRunFailure $e) {
            $progress?->abandon();

            return $this->fail($output, $json, $e->getMessage(), $e->stage, $e->exitCode);
        }

        // A generated baseline must cover the FULL report, before any
        // existing baseline filters issues out — otherwise previously
        // baselined issues silently vanish from the new baseline.
        if (!$this->writeBaselineFile($arguments, $report)) {
            return $this->failToWriteBaseline($output, $json, (string) $arguments->generateBaseline);
        }

        // An entry no issue takes any more is reported, in the files the
        // run read and the files gone from the paths it lints.
        if (null !== $baseline) {
            $report = $baseline->filter($report, $arguments->reportUnmatchedIgnores ? self::scannedFiles($patterns) : null, array_values($paths), array_values($exclude));
        }

        $this->writeReport($output, $format, $formatter->format($report));

        if ($formatter instanceof ConsoleFormatter) {
            $output->write($formatter->getSummary($report->stats));
            $elapsed = (float) microtime(true) - $startTime;
            $peakMemory = memory_get_peak_usage(true);
            $timeStr = round($elapsed, 2).'s';
            $memoryStr = round($peakMemory / 1024 / 1024, 2).' MB';
            $processesStr = (string) ($progress?->processes() ?? 1);
            // No cache line: the binary keeps no cache between runs.
            $output->write('  '.$output->dim('Time: ').$output->warning($timeStr).'  '.$output->dim('Memory: ').$output->warning($memoryStr).'  '.$output->dim('Processes: ').$output->warning($processesStr)."\n");
            if ($verbose) {
                $output->write($formatter->formatFooter());
            }
        }

        if (!$this->writeRequestedFiles($output, $arguments, $format, $formatter, $report)) {
            return self::INVALID;
        }

        return $report->stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The PHP files the run read.
     *
     * @param array<PatternOccurrence> $patterns
     *
     * @return list<string>
     */
    private static function scannedFiles(array $patterns): array
    {
        $files = [];
        foreach ($patterns as $pattern) {
            if (null !== $pattern->ignores) {
                $files[] = $pattern->file;
            }
        }

        return $files;
    }

    /**
     * What makes a file the run is given unusable, checked before the run
     * starts: a path that does not exist, a baseline that is missing, cannot
     * be read or holds no baseline, a baseline to generate or a report file
     * that cannot be written.
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
        try {
            LintRunner::checkPaths($arguments->paths, $pathsGiven ? null : 'the "paths" of the configuration');
        } catch (LintRunFailure $e) {
            return [$e->getMessage(), $e->stage];
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

        $report = (string) $arguments->output;
        if ('' !== $report && !self::canWriteMakingDirectories($report)) {
            return ['Output file not writable: '.$report, JsonDocument::STAGE_USAGE];
        }

        return null;
    }

    /**
     * Whether a file can be written: an existing file that is writable, a
     * device or a pipe as much as a regular one, or a new one in a writable
     * directory.
     */
    private static function canWrite(string $file): bool
    {
        if (file_exists($file)) {
            return !is_dir($file) && is_writable($file);
        }

        $directory = \dirname($file);

        return is_dir($directory) && is_writable($directory);
    }

    /**
     * Whether a file can be written once the directories it lacks are made:
     * the nearest one that exists is a writable directory.
     */
    private static function canWriteMakingDirectories(string $file): bool
    {
        $directory = \dirname($file);
        while (!file_exists($directory) && \dirname($directory) !== $directory) {
            $directory = \dirname($directory);
        }

        return $directory === \dirname($file) ? self::canWrite($file) : is_dir($directory) && is_writable($directory);
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
     * Name the baseline written, and write the report to --output as well.
     *
     * @return bool whether the report reached its file, when one is asked for
     */
    private function writeRequestedFiles(Output $output, LintArguments $arguments, string $format, OutputFormatterInterface $formatter, LintReport $report): bool
    {
        // Only the console report shares stdout with status lines.
        $status = 'console' === $format ? $output->write(...) : $output->writeError(...);

        if (null !== $arguments->generateBaseline) {
            $status("Baseline generated at {$arguments->generateBaseline}\n");
        }

        $outputFile = $arguments->output;
        if (null === $outputFile) {
            return true;
        }

        $content = $formatter->format($report);
        if ('console' === $format) {
            // Strip ANSI codes for file output
            $content = self::withoutMargin(LibraryPcre::replace('/\e\[[0-9;]*m/', '', $content) ?? $content);
        }
        // Checked before the run: what fails here changed during it, or ran
        // out of room.
        $dir = \dirname($outputFile);
        if ((!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) || false === @file_put_contents($outputFile, $content)) {
            $output->writeError('Error: Could not write the output file: '.$outputFile."\n");

            return false;
        }

        $status("Output also written to: {$outputFile}\n");

        return true;
    }

    /**
     * The console report without the margin it keeps on screen: the
     * indentation every non-blank line shares goes, so a file starts at the
     * first column and each line keeps its depth relative to the others.
     */
    private static function withoutMargin(string $content): string
    {
        LibraryPcre::matchAll('/^ *(?=\S)/m', $content, $indents);
        $margin = [] === $indents[0] ? 0 : min(array_map(strlen(...), $indents[0]));

        return 0 === $margin ? $content : LibraryPcre::replace('/^ {'.$margin.'}/m', '', $content) ?? $content;
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
     * Where the functions marked #[RegexPattern] are read, whatever paths
     * the run lints: the configured paths, the linted ones when none are,
     * never the whole working directory. vendor/ of the working directory,
     * the project root as for regex.json, is read apart.
     *
     * @param LintDefaults  $lintDefaults
     * @param array<string> $linted
     *
     * @return list<string>
     */
    private static function declarationPaths(array $lintDefaults, array $linted): array
    {
        $paths = array_values(array_filter($lintDefaults['paths'] ?? [], static fn (string $path): bool => '' !== $path));

        return [] === $paths ? array_values($linted) : $paths;
    }
}
