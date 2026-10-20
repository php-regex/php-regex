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

namespace PHPRegex\Symfony\Command;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\AnalyzerRegistry;
use PHPRegex\Symfony\Analyzer\CheckOutcome;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\Formatter\JsonReportFormatter;
use PHPRegex\Symfony\Analyzer\ReportSection;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Symfony\Output\ConsoleWriter;
use PHPRegex\Symfony\Security\SecurityConfigException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal
 */
#[AsCommand(
    name: 'regex:analyze',
    description: 'Run PHPRegex Symfony bridge analyzers (routes, security).',
)]
final class AnalyzeCommand extends Command
{
    private const FORMAT_CONSOLE = 'console';
    private const FORMAT_JSON = 'json';

    private const DEFAULT_FAIL_ON = ['shadowed', 'redos', 'critical'];

    public function __construct(
        private readonly AnalyzerRegistry $registry,
        private readonly ConsoleReportFormatter $consoleFormatter,
        private readonly JsonReportFormatter $jsonFormatter,
        private readonly ?KernelInterface $kernel = null,
        private readonly string $defaultRedosThreshold = 'high',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'only',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Run only these analyzers (routes, security).',
            )
            ->addOption('show-overlaps', null, InputOption::VALUE_NONE, 'Include partial overlaps in reports.')
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED,
                'Security config files to analyze.',
            )
            ->addOption('skip-firewalls', null, InputOption::VALUE_NONE, 'Skip firewall regex ReDoS analysis.')
            ->addOption(
                'redos-threshold',
                null,
                InputOption::VALUE_OPTIONAL,
                'Minimum ReDoS severity to report (low|medium|high|critical).',
                $this->defaultRedosThreshold,
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_OPTIONAL,
                'Output format (console, json).',
                self::FORMAT_CONSOLE,
            )
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Include diagnostic timing and solver statistics.')
            ->addOption(
                'fail-on',
                null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Kinds that should fail the command (shadowed, overlap, redos, critical, any, none).',
                self::DEFAULT_FAIL_ON,
            )
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command runs Symfony bridge analyzers (routes, security).

                <info>php %command.full_name%</info>
                <info>php %command.full_name% --only=routes</info>
                <info>php %command.full_name% --show-overlaps</info>
                <info>php %command.full_name% --format=json</info>
                <info>php %command.full_name% --debug</info>
                <info>php %command.full_name% -vvv</info>

                Control CI failures:
                <info>php %command.full_name% --fail-on=shadowed --fail-on=redos</info>
                <info>php %command.full_name% --fail-on=any</info>
                <info>php %command.full_name% --fail-on=none</info>
                EOF);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = $this->resolveFormat($input, $io);
        if (null === $format) {
            return Command::INVALID;
        }
        $json = self::FORMAT_JSON === $format;

        $only = $this->normalizeLowercaseList($input, 'only');
        $available = array_map(static fn ($analyzer): string => $analyzer->getId(), $this->registry->all());
        if ([] !== $only) {
            $unknown = array_diff($only, $available);
            if ([] !== $unknown) {
                return $this->refuse($output, $io, $json, 'Unknown analyzer ids: '.implode(', ', $unknown).'.');
            }
        }

        $analyzers = $this->registry->filter($only);
        if ([] === $analyzers) {
            $message = 'No matching analyzers found.';
            if ([] !== $available) {
                $message .= ' Available: '.implode(', ', $available).'.';
            }

            return $this->refuse($output, $io, $json, $message);
        }

        $failOn = $this->resolveFailOn($input);
        if (\is_string($failOn)) {
            return $this->refuse($output, $io, $json, $failOn);
        }

        $threshold = $this->resolveThreshold($input);
        if (\is_string($threshold)) {
            return $this->refuse($output, $io, $json, $threshold);
        }

        $debug = (bool) $input->getOption('debug') || $output->isVerbose();

        $projectDir = $this->kernel?->getProjectDir() ?? (getcwd() ?: null);
        $environment = $this->kernel?->getEnvironment();

        $configPaths = $this->normalizeStringList($input, 'config');

        $context = new AnalysisContext(
            $projectDir,
            $environment,
            (bool) $input->getOption('show-overlaps'),
            $configPaths,
            $threshold,
            (bool) $input->getOption('skip-firewalls'),
            $debug,
            $this->kernel,
        );

        $renderBanner = self::FORMAT_CONSOLE === $format && !$output->isQuiet();
        $progressBar = null;
        if ($renderBanner) {
            $this->consoleFormatter->renderBanner($io);
            $analyzerCount = \count($analyzers);
            $message = \sprintf('Analyzing %d analyzer%s...', $analyzerCount, 1 === $analyzerCount ? '' : 's');

            if ($output->isDecorated()) {
                $io->writeln('<fg=gray>'.$message.'</>');
                $progressBar = $io->createProgressBar($analyzerCount);
                $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
                $progressBar->setMessage('starting');
                $progressBar->start();
            } else {
                $io->writeln('<fg=gray>'.$message.'</>');
            }
        }

        $sections = [];
        foreach ($analyzers as $analyzer) {
            if (null !== $progressBar) {
                $progressBar->setMessage($analyzer->getLabel());
            }

            $start = microtime(true);

            try {
                $analyzed = $analyzer->analyze($context);
            } catch (SecurityConfigException $e) {
                return $this->refuse($output, $io, $json, $e->getMessage(), JsonDocument::STAGE_CONFIG);
            }

            $durationMs = (int) round((microtime(true) - $start) * 1000);

            foreach ($analyzed as $section) {
                $sections[] = $debug ? $this->withDebug($section, $durationMs) : $section;
            }

            if (null !== $progressBar) {
                $progressBar->advance();
            }
        }

        $report = new SecurityReport($sections);

        if (null !== $progressBar) {
            $progressBar->finish();
            $io->newLine(2);
        } elseif ($renderBanner) {
            $io->newLine();
        }

        if ($json) {
            ConsoleWriter::document($output, $this->jsonFormatter->format($report, $debug));
        } else {
            $this->consoleFormatter->render($report, $io, !$renderBanner, $debug);
            if ($renderBanner) {
                $this->consoleFormatter->renderFooter($io);
            }
        }

        return $this->shouldFail($report, $failOn) ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveFormat(InputInterface $input, SymfonyStyle $io): ?string
    {
        $formatValue = $input->getOption('format');
        $format = is_string($formatValue) ? strtolower(trim($formatValue, " \t\n\r\0\x0B")) : '';

        if ('' === $format) {
            $format = self::FORMAT_CONSOLE;
        }

        if (!\in_array($format, [self::FORMAT_CONSOLE, self::FORMAT_JSON], true)) {
            $io->error('The --format value must be one of: console, json.');

            return null;
        }

        return $format;
    }

    /**
     * A command line, or a configuration, it cannot use: the error envelope
     * in JSON mode, an error block otherwise.
     */
    private function refuse(OutputInterface $output, SymfonyStyle $io, bool $json, string $message, string $stage = JsonDocument::STAGE_USAGE): int
    {
        if ($json) {
            ConsoleWriter::document($output, JsonDocument::error($message, $stage));
        } else {
            $io->error($message);
        }

        return Command::INVALID;
    }

    /**
     * @return array<int, string>|string the kinds, or why they cannot be used
     */
    private function resolveFailOn(InputInterface $input): array|string
    {
        $failOn = $this->normalizeLowercaseList($input, 'fail-on');
        if ([] === $failOn) {
            $failOn = self::DEFAULT_FAIL_ON;
        }

        $allowed = ['shadowed', 'overlap', 'redos', 'critical', 'any', 'none'];
        $invalid = array_diff($failOn, $allowed);

        if ([] !== $invalid) {
            return 'The --fail-on values must be one of: shadowed, overlap, redos, critical, any, none.';
        }

        if (\in_array('none', $failOn, true)) {
            return ['none'];
        }

        return array_values(array_unique($failOn));
    }

    /**
     * @return RedosSeverity|string the threshold, or why it cannot be used
     */
    private function resolveThreshold(InputInterface $input): RedosSeverity|string
    {
        $thresholdValue = $input->getOption('redos-threshold');
        $threshold = \is_string($thresholdValue) ? trim($thresholdValue, " \t\n\r\0\x0B") : '';

        try {
            return RedosSeverity::fromConfig('' === $threshold ? $this->defaultRedosThreshold : $threshold);
        } catch (InvalidRegexOptionException $e) {
            return 'Invalid value for --redos-threshold: '.$e->getMessage();
        }
    }

    /**
     * The trimmed, non-empty strings an array option was given, each once.
     *
     * @return list<string>
     */
    private function normalizeStringList(InputInterface $input, string $option): array
    {
        $values = $input->getOption($option);
        $normalized = [];
        foreach (\is_array($values) ? $values : [] as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $trimmed = trim($value, " \t\n\r\0\x0B");
            if ('' !== $trimmed) {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array<int, string>
     */
    private function normalizeLowercaseList(InputInterface $input, string $option): array
    {
        return array_map(
            strtolower(...),
            $this->normalizeStringList($input, $option),
        );
    }

    /**
     * @param array<int, string> $failOn
     */
    private function shouldFail(SecurityReport $report, array $failOn): bool
    {
        if (\in_array('none', $failOn, true)) {
            return false;
        }

        if (\in_array('any', $failOn, true) && $report->hasAnyIssues()) {
            return true;
        }

        if (\in_array('critical', $failOn, true) && $report->hasSeverity(CheckOutcome::Critical)) {
            return true;
        }

        $kinds = array_values(array_diff($failOn, ['critical', 'any']));
        if ([] === $kinds) {
            return false;
        }

        return $report->hasIssuesOfKind($kinds);
    }

    private function withDebug(ReportSection $section, int $durationMs): ReportSection
    {
        $debug = [
            'Duration' => $durationMs.' ms',
            'Issues' => \count($section->issues),
            'Warnings' => \count($section->warnings),
            'Suggestions' => \count($section->suggestions),
        ];

        if ([] !== $section->debug) {
            $debug = array_merge($debug, $section->debug);
        }

        return new ReportSection(
            $section->id,
            $section->title,
            $section->meta,
            $section->summary,
            $section->warnings,
            $section->issues,
            $section->suggestions,
            $debug,
        );
    }
}
