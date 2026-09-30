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

namespace RegexParser\Bridge\Symfony\Command;

use RegexParser\Bridge\Symfony\Analyzer\AnalysisContext;
use RegexParser\Bridge\Symfony\Analyzer\AnalyzerRegistry;
use RegexParser\Bridge\Symfony\Analyzer\CheckOutcome;
use RegexParser\Bridge\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use RegexParser\Bridge\Symfony\Analyzer\Formatter\JsonReportFormatter;
use RegexParser\Bridge\Symfony\Analyzer\ReportSection;
use RegexParser\Bridge\Symfony\Analyzer\SecurityReport;
use RegexParser\Exception\InvalidRegexOptionException;
use RegexParser\ReDoS\ReDoSSeverity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'regex:analyze',
    description: 'Run RegexParser Symfony bridge analyzers (routes, security).',
)]
final class RegexAnalyzeCommand extends Command
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

        $onlyOption = $input->getOption('only');
        $only = $this->normalizeLowercaseList(\is_array($onlyOption) ? $onlyOption : []);
        $available = array_map(static fn ($analyzer): string => $analyzer->getId(), $this->registry->all());
        if ([] !== $only) {
            $unknown = array_diff($only, $available);
            if ([] !== $unknown) {
                $io->error('Unknown analyzer ids: '.implode(', ', $unknown).'.');

                return Command::INVALID;
            }
        }

        $analyzers = $this->registry->filter($only);
        if ([] === $analyzers) {
            $message = 'No matching analyzers found.';
            if ([] !== $available) {
                $message .= ' Available: '.implode(', ', $available).'.';
            }
            $io->error($message);

            return Command::INVALID;
        }

        $failOn = $this->resolveFailOn($input, $io);
        if (null === $failOn) {
            return Command::INVALID;
        }

        $threshold = $this->resolveThreshold($input, $io);
        if (null === $threshold) {
            return Command::INVALID;
        }

        $debug = (bool) $input->getOption('debug') || $output->isVerbose();

        $projectDir = $this->kernel?->getProjectDir() ?? (getcwd() ?: null);
        $environment = $this->kernel?->getEnvironment();

        $configOption = $input->getOption('config');
        $configPaths = $this->normalizeStringList(\is_array($configOption) ? $configOption : []);

        $context = new AnalysisContext(
            $projectDir,
            $environment,
            (bool) $input->getOption('show-overlaps'),
            $only,
            $configPaths,
            $threshold,
            (bool) $input->getOption('skip-firewalls'),
            $debug,
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
            $analyzed = $analyzer->analyze($context);
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

        if (self::FORMAT_JSON === $format) {
            $output->writeln($this->jsonFormatter->format($report, $debug));
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
        $format = is_string($formatValue) ? strtolower(trim($formatValue)) : '';

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
     * @return array<int, string>|null
     */
    private function resolveFailOn(InputInterface $input, SymfonyStyle $io): ?array
    {
        $failOnOption = $input->getOption('fail-on');
        $failOn = $this->normalizeLowercaseList(\is_array($failOnOption) ? $failOnOption : []);
        if ([] === $failOn) {
            $failOn = self::DEFAULT_FAIL_ON;
        }

        $allowed = ['shadowed', 'overlap', 'redos', 'critical', 'any', 'none'];
        $invalid = array_diff($failOn, $allowed);

        if ([] !== $invalid) {
            $io->error('The --fail-on values must be one of: shadowed, overlap, redos, critical, any, none.');

            return null;
        }

        if (\in_array('none', $failOn, true)) {
            return ['none'];
        }

        return array_values(array_unique($failOn));
    }

    private function resolveThreshold(InputInterface $input, SymfonyStyle $io): ?ReDoSSeverity
    {
        $thresholdValue = $input->getOption('redos-threshold');
        $threshold = \is_string($thresholdValue) ? trim($thresholdValue) : '';

        try {
            return ReDoSSeverity::fromConfig('' === $threshold ? $this->defaultRedosThreshold : $threshold);
        } catch (InvalidRegexOptionException $e) {
            $io->error('Invalid value for --redos-threshold: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return array<int, string>
     */
    private function normalizeStringList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ('' !== $trimmed) {
                $normalized[] = $trimmed;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return array<int, string>
     */
    private function normalizeLowercaseList(array $values): array
    {
        return array_map(
            strtolower(...),
            $this->normalizeStringList($values),
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

        if (\in_array('critical', $failOn, true) && $report->hasSeverity(CheckOutcome::CRITICAL)) {
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
