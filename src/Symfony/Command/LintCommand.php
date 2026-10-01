<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Symfony\Command;

use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\Config\ProjectTarget;
use PhpRegex\Linter\Formatter\FormatterRegistry;
use PhpRegex\Linter\Formatter\JsonFormatter;
use PhpRegex\Linter\Formatter\LinkFormatter;
use PhpRegex\Linter\Formatter\RelativePathHelper;
use PhpRegex\Linter\LintReport;
use PhpRegex\Linter\LintRequest;
use PhpRegex\Linter\LintService;
use PhpRegex\Optimizer\OptimizerOptions;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Symfony\Output\SymfonyConsoleFormatter;
use PhpRegex\Toolkit\Regex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lint regex patterns in PHP source code.
 *
 * @phpstan-import-type LintResult from LintReport
 * @phpstan-import-type LintStats from LintReport
 */
#[AsCommand(
    name: 'regex:lint',
    description: 'Lints, validates, and optimizes regex patterns in your PHP code.',
)]
final class LintCommand extends Command
{
    private const PROGRESS_BAR_WIDTH = 28;
    private const MESSAGE_PAD_LENGTH = 15;
    private const FORMAT_CONSOLE = 'console';

    private RelativePathHelper $pathHelper;

    private LinkFormatter $linkFormatter;

    /**
     * @var array<string>
     */
    private array $defaultPaths;

    /**
     * @var array<string>
     */
    private array $defaultExcludePaths;

    private readonly OptimizerOptions $defaultOptimizations;

    /**
     * @param array<string>           $defaultPaths
     * @param array<string>           $defaultExcludePaths
     * @param array<string, bool|int> $defaultOptimizations the bundle's optimizations, in snake_case
     * @param array<string, mixed>    $regexOptions         what Regex::create() takes, but the target and the
     *                                                      runtime validation: the settings patterns are read with
     * @param string|int|null         $phpVersion           the bundle's php_version
     * @param string|null             $pcreVersion          the bundle's pcre_version
     * @param string|null             $projectDir           where composer.json is read; null reads none
     */
    public function __construct(
        private readonly LintService $lint,
        private readonly AnalysisService $analysis,
        private readonly FormatterRegistry $formatterRegistry = new FormatterRegistry(),
        array $defaultPaths = ['src'],
        array $defaultExcludePaths = ['vendor'],
        array $defaultOptimizations = [],
        private readonly ?string $editorUrl = null,
        private readonly array $regexOptions = [],
        private readonly string|int|null $phpVersion = null,
        private readonly ?string $pcreVersion = null,
        private readonly ?string $projectDir = null,
    ) {
        $this->defaultPaths = $this->normalizeStringList($defaultPaths);
        $this->defaultExcludePaths = $this->normalizeStringList($defaultExcludePaths);
        $this->defaultOptimizations = $this->normalizeOptimizations($defaultOptimizations);

        if ([] === $this->defaultPaths) {
            $this->defaultPaths = ['src'];
        }

        if ([] === $this->defaultExcludePaths) {
            $this->defaultExcludePaths = ['vendor'];
        }

        // Initialize with temporary path helper, will be updated in execute()
        $this->pathHelper = new RelativePathHelper(getcwd() ?: null);
        $this->linkFormatter = new LinkFormatter($this->editorUrl, $this->pathHelper);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'The paths to analyze', array_values($this->defaultPaths))
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Paths to exclude', $this->defaultExcludePaths)
            ->addOption('min-savings', null, InputOption::VALUE_OPTIONAL, 'Minimum optimization savings in characters', 1)
             ->addOption('jobs', 'j', InputOption::VALUE_OPTIONAL, 'Parallel workers for analysis (auto-detected if not specified)', -1)
            ->addOption('no-routes', null, InputOption::VALUE_NONE, 'Skip route validation')
            ->addOption('no-validators', null, InputOption::VALUE_NONE, 'Skip validator validation')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (console, json, github, checkstyle, junit)', self::FORMAT_CONSOLE)
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command scans your PHP code for regex patterns and provides:

                * Validation of regex syntax
                * Performance and security warnings
                * Optimization suggestions
                * Integration with Symfony routes and validators

                <info>php %command.full_name%</info>

                Analyze specific directories:
                <info>php %command.full_name% src/ lib/</info>

                Exclude directories:
                <info>php %command.full_name% --exclude=tests --exclude=vendor</info>

                Show only significant optimizations:
                <info>php %command.full_name% --min-savings=10</info>

                 Run analysis in parallel (auto-detected by default):
                 <info>php %command.full_name% --jobs=4</info>

                Skip specific validations:
                <info>php %command.full_name% --no-routes --no-validators</info>

                Output format for CI/CD:
                <info>php %command.full_name% --format=json</info>
                <info>php %command.full_name% --format=github</info>
                <info>php %command.full_name% --format=checkstyle</info>
                <info>php %command.full_name% --format=junit</info>
                EOF
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workingDir = getcwd() ?: null;
        $this->pathHelper = new RelativePathHelper($workingDir);
        $this->linkFormatter = new LinkFormatter($this->editorUrl, $this->pathHelper);

        $paths = $this->normalizeStringList($input->getArgument('paths'));
        $exclude = $this->normalizeStringList($input->getOption('exclude'));
        $minSavingsValue = $input->getOption('min-savings');
        $minSavings = is_numeric($minSavingsValue) ? (int) $minSavingsValue : 1;
        $skipRoutes = (bool) $input->getOption('no-routes');
        $skipValidators = (bool) $input->getOption('no-validators');

        try {
            $format = $this->validateAndNormalizeFormat($input, $io);
        } catch (InvalidRegexOptionException) {
            return Command::INVALID;
        }

        // The runtime service judges for the running PHP; the lint judges
        // for the project's target, and never compiles with the running PHP.
        try {
            $target = ProjectTarget::fromSources(
                ['regex_parser.php_version' => $this->phpVersion],
                ['regex_parser.pcre_version' => $this->pcreVersion],
                $this->projectDir,
                getenv(),
            );
            $parser = Regex::create($this->regexOptions + $target->regexOptions())->parser();
        } catch (InvalidRegexOptionException $e) {
            $io->error('Invalid option: '.$e->getMessage());

            return Command::INVALID;
        }
        $analysis = $this->analysis->withParser($parser);
        $lint = $this->lint->withAnalysis($analysis);

        $this->formatterRegistry->override(
            self::FORMAT_CONSOLE,
            new SymfonyConsoleFormatter($analysis, $this->linkFormatter, $output->isDecorated()),
        );
        $this->formatterRegistry->override('json', new JsonFormatter(target: $target->toArray()));

        $jobsExplicitlySet = $input->hasParameterOption(['--jobs', '-j']);
        $jobsValue = $input->getOption('jobs');
        $jobs = is_numeric($jobsValue) ? (int) $jobsValue : -1;

        if ($jobsExplicitlySet) {
            if ($jobs < 1) {
                $io->error('The --jobs value must be a positive integer.');

                return Command::INVALID;
            }
        } else {
            // Auto-detect optimal number of jobs
            $jobs = self::detectCpuCount();
        }

        if (self::FORMAT_CONSOLE === $format) {
            $this->showBanner($io, $jobs, $target);
        } else {
            $this->reportTargetOnStderr($output, $target);
        }

        $startTime = (float) microtime(true);
        $collectionStartTime = $startTime;
        $collectionProgress = null;
        $showProgress = self::FORMAT_CONSOLE === $format && OutputInterface::VERBOSITY_QUIET !== $output->getVerbosity();
        $collectionBar = null;
        $collectionFinished = false;
        $lastCount = 0;
        $fileCount = 0;
        if ($showProgress) {
            $io->writeln('  <fg=gray>[1/2] Scanning files</>');
            $collectionProgress = function (int $current, int $total) use ($io, &$collectionBar, &$collectionFinished, &$lastCount, &$fileCount): void {
                if ($collectionFinished || $total <= 0) {
                    return;
                }

                $fileCount = $total;

                $collectionBar ??= $this->createProgressBar($io, $total);

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
        }

        try {
            $request = new LintRequest(
                paths: $paths,
                excludePaths: $exclude,
                minSavings: $minSavings,
                disabledSources: array_values(array_filter([
                    $skipRoutes ? 'routes' : null,
                    $skipValidators ? 'validators' : null,
                ], static fn (?string $source): bool => null !== $source)),
                analysisWorkers: $jobs,
                optimizations: $this->defaultOptimizations,
            );
            $patterns = $lint->collectPatterns($request, $collectionProgress);
        } catch (\Throwable $e) {
            return $this->renderCollectionFailure($format, $output, $io, $e->getMessage());
        }

        $patternCount = \count($patterns);
        if ($showProgress) {
            $io->newLine();
            $io->writeln('  <fg=gray>Scanned '.$fileCount.' files, found '.$patternCount.' patterns.</>');
            $io->newLine();
        }

        if (empty($patterns)) {
            return $this->renderEmptyResults($format, $output, $io);
        }

        $analysisBar = null;
        $currentAnalysis = 0;
        if ($showProgress) {
            $io->newLine();
            $io->writeln('  <fg=gray>[2/2] Analyzing patterns</>');
            $totalPatterns = \count($patterns);
            $analysisBar = $this->createProgressBar($io, $totalPatterns);
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
            $io->newLine(2);
        }

        $report = new LintReport(
            $this->sortResultsByFileAndLine($report->results),
            $report->stats,
        );

        $stats = $report->stats;

        $formatter = $this->formatterRegistry->get($format);
        $output->write($formatter->format($report));

        if (self::FORMAT_CONSOLE === $format) {
            $elapsed = (float) microtime(true) - $startTime;
            $peakMemory = memory_get_peak_usage(true);
            $cacheStats = $parser->getCacheStats();
            $io->writeln('  <options=bold>Time:</> <fg=yellow>'.round($elapsed, 2).'s</> | <options=bold>Memory:</> <fg=yellow>'.round($peakMemory / 1024 / 1024, 2).' MB</> | <options=bold>Cache:</> <fg=yellow>'.$cacheStats['hits'].' hits, '.$cacheStats['misses'].' misses</> | <options=bold>Processes:</> <fg=yellow>'.$jobs.'</>');
            $io->newLine();
        }

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function showBanner(SymfonyStyle $io, int $jobs, ProjectTarget $target): void
    {
        $version = Regex::VERSION;

        $io->writeln('<fg=cyan;options=bold>RegexParser</> <fg=yellow>'.$version.'</> by Younes ENNAJI');
        $io->newLine();

        $maxLabelLength = max(array_map(strlen(...), ['Runtime', 'Target', 'Processes']));
        $io->writeln('<fg=white;options=bold>'.str_pad('Runtime', $maxLabelLength).'</> : PHP <fg=yellow>'.\PHP_VERSION.'</>');
        $io->writeln('<fg=white;options=bold>'.str_pad('Target', $maxLabelLength).'</> : '.$this->describeTarget($target));
        $io->writeln('<fg=white;options=bold>'.str_pad('Processes', $maxLabelLength).'</> : <fg=yellow>'.$jobs.'</>');
        foreach ($target->notices() as $notice) {
            $io->writeln('<fg=gray>Note: '.$notice.'</>');
        }

        $io->newLine();
    }

    /**
     * Outside the console format, stdout holds the report alone: the target
     * and what resolving it noticed go to stderr, when there is one.
     */
    private function reportTargetOnStderr(OutputInterface $output, ProjectTarget $target): void
    {
        if (!$output instanceof ConsoleOutputInterface) {
            return;
        }

        $stderr = $output->getErrorOutput();
        foreach ($target->notices() as $notice) {
            $stderr->writeln('Note: '.$notice, OutputInterface::OUTPUT_RAW);
        }
        $stderr->writeln(\sprintf('Target: PHP %s, PCRE2 %s (%s)', $target->php(), $target->target()->pcreVersion, $target->source()), OutputInterface::OUTPUT_RAW);
    }

    private function describeTarget(ProjectTarget $target): string
    {
        return \sprintf('PHP <fg=yellow>%s</>, PCRE2 <fg=yellow>%s</> (%s)', $target->php(), $target->target()->pcreVersion, $target->source());
    }

    private function renderCollectionFailure(
        string $format,
        OutputInterface $output,
        SymfonyStyle $io,
        string $errorMessage,
    ): int {
        $message = "Failed to collect patterns: {$errorMessage}";

        if (self::FORMAT_CONSOLE === $format) {
            $io->error($message);
        } else {
            $formatter = $this->formatterRegistry->get($format);
            $output->writeln($formatter->formatError($message));
        }

        return Command::FAILURE;
    }

    private function renderEmptyResults(string $format, OutputInterface $output, SymfonyStyle $io): int
    {
        if (self::FORMAT_CONSOLE === $format) {
            $this->renderEmptySummary($io);

            return Command::SUCCESS;
        }

        $emptyReport = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $formatter = $this->formatterRegistry->get($format);
        $output->write($formatter->format($emptyReport));

        return Command::SUCCESS;
    }

    private function renderEmptySummary(SymfonyStyle $io): void
    {
        $io->newLine();
        $io->writeln('  <bg=green;fg=white;options=bold> PASS </> <fg=gray>No regex patterns found.</>');
        $this->showFooter($io);
    }

    private function showFooter(SymfonyStyle $io): void
    {
        $io->newLine();
        $message = 'If RegexParser helps, a GitHub star is appreciated: ';
        $io->writeln('  <fg=gray>'.$message.'https://github.com/php-regex/regex-parser</>');
        $io->newLine();
    }

    private function createProgressBar(SymfonyStyle $io, int $total): ProgressBar
    {
        $bar = $io->createProgressBar($total);
        $bar->setFormat(' %message% [%bar%] %percent:3s%% %elapsed:6s%');
        $bar->setBarWidth(self::PROGRESS_BAR_WIDTH);
        $bar->setProgressCharacter('▓');
        $bar->setEmptyBarCharacter('░');
        $bar->setMessage(str_pad('0/'.$total, self::MESSAGE_PAD_LENGTH, ' ', \STR_PAD_LEFT));
        $bar->start();

        return $bar;
    }

    private function validateAndNormalizeFormat(InputInterface $input, SymfonyStyle $io): string
    {
        $formatOption = $input->getOption('format');
        $format = \is_string($formatOption) ? strtolower($formatOption) : self::FORMAT_CONSOLE;

        if (!$this->formatterRegistry->has($format)) {
            $io->error(\sprintf(
                "Invalid format '%s'. Supported formats: %s",
                $format,
                implode(', ', $this->formatterRegistry->getNames()),
            ));

            throw new InvalidRegexOptionException('Invalid format');
        }

        return $format;
    }

    /**
     * @return array<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => \is_string($item) && '' !== $item));
    }

    /**
     * @param array<string, bool|int> $optimizations
     */
    private function normalizeOptimizations(array $optimizations): OptimizerOptions
    {
        // Lint checks every rewrite with the automata unless told otherwise.
        return OptimizerOptions::fromArray($optimizations + ['verify_with_automata' => true]);
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
            if (\is_readable('/proc/cpuinfo')) {
                $cpuinfo = \file_get_contents('/proc/cpuinfo');
                if (false !== $cpuinfo) {
                    $matches = [];
                    \preg_match_all('/^processor\s*:/m', $cpuinfo, $matches);
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
