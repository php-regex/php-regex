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

namespace PHPRegex\Linter\Console;

use PHPRegex\Linter\Formatter\ConsoleTagFormatter;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\LinkFormatter;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintRunFailure;
use PHPRegex\Linter\LintRunner;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSetup;
use PHPRegex\Linter\Parallel\WorkerCount;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Text\Ascii;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * regex:lint as the Symfony and Laravel bridges run it, on a Symfony
 * console: each bridge maps its command line and its configuration to a
 * ConsoleLintRequest, and this run does the rest the same way for both.
 *
 * The standard output holds the banner and the report; the steps, the
 * notes of a machine report and the errors go to the error output, as
 * `regex lint` writes them. A machine format (json, github, checkstyle,
 * junit) is written as its document, its error document when the run
 * stops, even under --quiet.
 *
 * @internal
 */
final class ConsoleLintRunner
{
    private const FORMAT_CONSOLE = 'console';

    /**
     * The steps of a run: the scan, the analysis and the optimizations.
     */
    private const STEPS = 3;

    public function __construct(private readonly FormatterRegistry $formatters = new FormatterRegistry()) {}

    public function run(ConsoleLintRequest $request, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $format = Ascii::lower($request->format);
        if (!$this->formatters->has($format)) {
            $errors->writeln(\sprintf("<error>Error: Invalid format '%s'. Supported formats: %s</error>", OutputFormatter::escape($format), implode(', ', $this->formatters->getNames())));

            return LintRunFailure::EXIT_INVALID;
        }

        try {
            return $this->lint($request, $format, $output, $errors);
        } catch (LintRunFailure $failure) {
            $this->renderFailure($format, $output, $errors, $failure);

            return $failure->exitCode;
        }
    }

    /**
     * @throws LintRunFailure
     */
    private function lint(ConsoleLintRequest $request, string $format, OutputInterface $output, OutputInterface $errors): int
    {
        $console = self::FORMAT_CONSOLE === $format;
        // --jobs is read before the paths, and the paths before the target,
        // as `regex lint` reads them.
        $jobs = self::jobs($request->jobs);
        LintRunner::checkPaths($request->paths, $request->pathsSetting);
        $setup = LintRunner::resolve($request->setup, $request->invalidOptionPrefix);

        $pathHelper = new RelativePathHelper($request->displayBase);
        $this->formatters->register(self::FORMAT_CONSOLE, new ConsoleTagFormatter($setup->analysis, new LinkFormatter($request->editorUrl, $pathHelper), $output->isDecorated()));
        $this->formatters->register('json', new JsonFormatter(target: $setup->target->toArray()));

        if ($console) {
            $this->writeBanner($output, $request, $setup, $jobs);
        } elseif ($output instanceof ConsoleOutputInterface) {
            // Without an error output the notes would land in the document.
            self::writeNotes($errors, $setup);
        }

        $lintRequest = new LintRequest(
            paths: $request->paths,
            excludePaths: $request->exclude,
            minSavings: $request->minSavings,
            disabledSources: $request->disabledSources,
            analysisWorkers: $jobs,
            optimizations: $request->optimizations,
            declarationPaths: $request->declarationPaths,
            vendorPaths: $request->vendorPaths,
            checkRedos: $request->checkRedos,
            checkComplexity: $request->checkComplexity,
            reportUnmatchedIgnores: $request->reportUnmatchedIgnores,
            excludeBase: $request->excludeBase,
        );

        $startTime = microtime(true);
        $progress = null;
        if ($console && !$output->isQuiet()) {
            // The bridges always suggest optimizations, a step of their own.
            $progress = new LintProgressBar($output, self::STEPS);
            $progress->writeln('  <fg=gray>[1/'.self::STEPS.'] Scanning files</>');
        }

        $runner = new LintRunner($setup->lint);

        try {
            $patterns = $runner->collect($lintRequest, $progress);
        } finally {
            $progress?->close();
        }

        $unread = null === $request->unread ? [] : ($request->unread)();
        if (null !== $progress) {
            $progress->writeln('  <fg=gray>Scanned '.$progress->filesFound().' files, found '.LintRunner::patternCount($patterns).' patterns.</>');
        }

        if ([] === $unread && LintService::holdsNothing($patterns, $request->reportUnmatchedIgnores)) {
            if ($console) {
                $output->writeln('');
                $output->writeln('  <bg=green;fg=white;options=bold> PASS </> <fg=gray>No regex patterns found.</>');
                $output->writeln('');
                ConsoleWriter::footer($output);
            } else {
                $this->writeReport($format, $output, $this->formatters->get($format)->format(new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0])));
            }

            return 0;
        }

        try {
            $report = $runner->analyze($patterns, $lintRequest, $progress, $unread);
        } finally {
            $progress?->close();
        }

        $this->writeReport($format, $output, $this->formatters->get($format)->format($report));

        // The console shows the run's figures, unless --quiet silences it.
        // No cache count: the workers parse the patterns, not this process.
        if (null !== $progress) {
            $elapsed = microtime(true) - $startTime;
            $peakMemory = memory_get_peak_usage(true);
            $output->writeln('  <options=bold>Time:</> <fg=yellow>'.round($elapsed, 2).'s</> | <options=bold>Memory:</> <fg=yellow>'.round($peakMemory / 1024 / 1024, 2).' MB</> | <options=bold>Processes:</> <fg=yellow>'.$progress->workersStarted().'</>');
            $output->writeln('');
            ConsoleWriter::footer($output);
        }

        return $report->stats['errors'] > 0 ? LintRunFailure::EXIT_FAILURE : 0;
    }

    /**
     * The workers --jobs asks for, a whole number of 1 or more; one per CPU
     * the run may use when it is not given, and none can start without
     * pcntl.
     *
     * @throws LintRunFailure
     */
    private static function jobs(?string $jobs): int
    {
        if (null === $jobs) {
            return (new WorkerCount())->count();
        }

        $count = filter_var($jobs, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (false === $count) {
            throw new LintRunFailure('The --jobs value must be a positive integer.', JsonDocument::STAGE_USAGE, LintRunFailure::EXIT_INVALID);
        }

        return $count;
    }

    private function writeBanner(OutputInterface $output, ConsoleLintRequest $request, LintSetup $setup, int $jobs): void
    {
        $target = $setup->target;
        ConsoleWriter::title($output, $request->version);
        $output->writeln('');

        $locale = $target->target()->locale;
        $lines = [
            'Runtime' => 'PHP <fg=yellow>'.\PHP_VERSION.'</>',
            'Target' => \sprintf('PHP <fg=yellow>%s</>, PCRE2 <fg=yellow>%s</>%s (%s)', $target->php(), $target->target()->pcreVersion, 'C' === $locale ? '' : ', locale <fg=yellow>'.$locale.'</>', $target->source()),
            'Processes' => '<fg=yellow>'.$jobs.'</>',
        ];
        $width = max(array_map(strlen(...), array_keys($lines)));
        foreach ($lines as $label => $value) {
            $output->writeln('<fg=white;options=bold>'.str_pad($label, $width).'</> : '.$value);
        }
        foreach ($target->notices() as $notice) {
            $output->writeln('<fg=gray>Note: '.OutputFormatter::escape($notice).'</>');
        }
        foreach ($setup->warnings as $warning) {
            $output->writeln('<fg=yellow>Warning: '.OutputFormatter::escape($warning).'</>');
        }

        $output->writeln('');
    }

    /**
     * Outside the console format, stdout holds the report alone: what
     * resolving the target noticed, what the configuration holds that the
     * run ignores, and the target go to the error output.
     */
    private static function writeNotes(OutputInterface $errors, LintSetup $setup): void
    {
        $target = $setup->target;
        foreach ($target->notices() as $notice) {
            $errors->writeln('Note: '.$notice, OutputInterface::OUTPUT_RAW);
        }
        foreach ($setup->warnings as $warning) {
            $errors->writeln('Warning: '.$warning, OutputInterface::OUTPUT_RAW);
        }
        $errors->writeln(\sprintf('Target: %s (%s)', $target->label(), $target->source()), OutputInterface::OUTPUT_RAW);
    }

    /**
     * A run that stops: an error line on the error output on the console,
     * the format's own error document otherwise (the JSON envelope names
     * the stage).
     */
    private function renderFailure(string $format, OutputInterface $output, OutputInterface $errors, LintRunFailure $failure): void
    {
        if (self::FORMAT_CONSOLE === $format) {
            $errors->writeln('<error>Error: '.OutputFormatter::escape($failure->getMessage()).'</error>');

            return;
        }

        $formatter = $this->formatters->get($format);
        $error = $formatter instanceof JsonFormatter ? $formatter->formatError($failure->getMessage(), $failure->stage) : $formatter->formatError($failure->getMessage());
        $this->writeReport($format, $output, str_ends_with($error, "\n") ? $error : $error."\n");
    }

    /**
     * The console report goes through the console styles; a machine format
     * is written as it is, never read for tags, and even under --quiet: it
     * is the output asked for, not a status line.
     */
    private function writeReport(string $format, OutputInterface $output, string $report): void
    {
        if (self::FORMAT_CONSOLE === $format) {
            $output->write($report);

            return;
        }

        ConsoleWriter::document($output, $report);
    }
}
