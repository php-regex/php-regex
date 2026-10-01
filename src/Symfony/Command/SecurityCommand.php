<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Symfony\Command;

use PhpRegex\Linter\Formatter\RelativePathHelper;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Parser\Internal\DisplayEscaper;
use PhpRegex\Redos\RedosSeverity;
use PhpRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PhpRegex\Symfony\Security\SecurityAccessControlReport;
use PhpRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PhpRegex\Symfony\Security\SecurityConfigExtractor;
use PhpRegex\Symfony\Security\SecurityConfigLocator;
use PhpRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PhpRegex\Symfony\Security\SecurityFirewallReport;
use PhpRegex\Toolkit\Regex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @phpstan-import-type AccessConflict from SecurityAccessControlReport
 * @phpstan-import-type AccessRule from SecurityAccessControlReport
 * @phpstan-import-type AccessSkip from SecurityAccessControlReport
 * @phpstan-import-type FirewallFinding from SecurityFirewallReport
 * @phpstan-import-type FirewallSkip from SecurityFirewallReport
 */
#[AsCommand(
    name: 'regex:security',
    description: 'Analyze Symfony security access_control rules and firewall regexes.',
)]
final class SecurityCommand extends Command
{
    private const ARROW_LABEL = "\u{21B3}";
    private const TYPE_SHADOWED = 'shadowed';
    private const BADGE_CRIT = '<bg=red;fg=white;options=bold> CRIT </>';
    private const BADGE_FAIL = '<bg=red;fg=white;options=bold> FAIL </>';
    private const BADGE_WARN = '<bg=yellow;fg=black;options=bold> WARN </>';
    private const BADGE_PASS = '<bg=green;fg=white;options=bold> PASS </>';

    private RelativePathHelper $pathHelper;

    public function __construct(
        private readonly SecurityConfigExtractor $extractor,
        private readonly SecurityAccessControlAnalyzer $accessAnalyzer,
        private readonly SecurityFirewallAnalyzer $firewallAnalyzer,
        private readonly SecurityConfigLocator $configLocator = new SecurityConfigLocator(),
        private readonly SecurityAccessSuggestionBuilder $suggestionBuilder = new SecurityAccessSuggestionBuilder(),
        private readonly ?KernelInterface $kernel = null,
        private readonly string $defaultRedosThreshold = 'high',
    ) {
        $this->pathHelper = new RelativePathHelper(getcwd() ?: null);
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHidden(true);

        $this
            ->addOption('show-overlaps', null, InputOption::VALUE_NONE, 'Include partial overlaps in the report.')
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
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command inspects security access control rules and firewall regexes.

                It detects access_control shadowing (ordering issues) and can flag risky firewall regex patterns.
                Use --show-overlaps to include partial overlaps in access control.

                <info>php %command.full_name%</info>
                <info>php %command.full_name% --show-overlaps</info>
                <info>php %command.full_name% --redos-threshold=medium</info>

                Analyze specific config files:
                <info>php %command.full_name% --config=config/packages/security.yaml</info>
                EOF);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $projectDir = $this->kernel?->getProjectDir() ?? (getcwd() ?: null);
        $environment = $this->kernel?->getEnvironment();
        $this->pathHelper = new RelativePathHelper($projectDir);

        $includeOverlaps = (bool) $input->getOption('show-overlaps');
        $skipFirewalls = (bool) $input->getOption('skip-firewalls');

        $threshold = $this->resolveThreshold($input, $io);
        if (null === $threshold) {
            return Command::INVALID;
        }

        $configOption = $input->getOption('config');
        $explicitConfigs = $this->normalizeStringList(\is_array($configOption) ? $configOption : []);
        $configPaths = [] !== $explicitConfigs
            ? $explicitConfigs
            : $this->configLocator->locate($projectDir, $environment);

        if ([] !== $explicitConfigs) {
            $existing = array_values(array_filter($configPaths, is_file(...)));
            if ([] === $existing) {
                $io->error('None of the provided --config files could be found.');

                return Command::INVALID;
            }
        }
        if ([] === $configPaths) {
            $io->error('No security config files found. Use --config to specify one.');

            return Command::INVALID;
        }

        $rules = [];
        $firewalls = [];
        $skippedFiles = [];

        foreach ($configPaths as $path) {
            if (!is_file($path)) {
                $skippedFiles[] = ['file' => $path, 'reason' => 'File not found.'];

                continue;
            }

            if (!$this->isYamlFile($path)) {
                $skippedFiles[] = ['file' => $path, 'reason' => 'Only YAML security config files are supported.'];

                continue;
            }

            $data = $this->extractor->extract($path, $environment);
            $rules = array_merge($rules, $data['accessControl']);
            $firewalls = array_merge($firewalls, $data['firewalls']);
        }

        if ([] === $rules && [] === $firewalls) {
            $this->showBanner($io);
            $io->writeln('  '.self::BADGE_PASS.' <fg=white>No security configuration entries found.</>');
            $this->showFooter($io);

            return Command::SUCCESS;
        }

        $accessReport = $this->accessAnalyzer->analyze($rules, $includeOverlaps);
        $firewallReport = $skipFirewalls ? null : $this->firewallAnalyzer->analyze($firewalls, $threshold);

        $this->showBanner($io);
        $this->renderFileWarnings($io, $output, $skippedFiles);
        $this->renderAccessWarnings($io, $output, $accessReport);
        $this->renderAccessMeta($io, $accessReport, $includeOverlaps);
        $this->renderAccessStatus($io, $accessReport, $includeOverlaps);

        if (0 !== $accessReport->stats['conflicts']) {
            $this->renderAccessConflicts($io, $accessReport);

            $suggestions = $this->suggestionBuilder->collect(
                $accessReport->conflicts,
                fn (string $file, int $line): string => $this->formatLocation($file, $line),
            );
            if ([] !== $suggestions) {
                $io->section('Suggestions');
                foreach ($suggestions as $suggestion) {
                    $io->writeln('  <fg=gray>'.self::ARROW_LABEL.'</> '.$suggestion);
                }
                $io->newLine();
            }
        }

        if (null !== $firewallReport) {
            $this->renderFirewallWarnings($io, $output, $firewallReport);
            $this->renderFirewallMeta($io, $firewallReport, $threshold);
            $this->renderFirewallStatus($io, $firewallReport);

            if ([] !== $firewallReport->findings) {
                $this->renderFirewallFindings($io, $firewallReport);
            }
        }

        $this->showFooter($io);

        $accessFailure = $accessReport->stats['shadowed'] > 0
            || ($includeOverlaps && $accessReport->stats['overlaps'] > 0);
        $firewallFailure = null !== $firewallReport && $firewallReport->stats['flagged'] > 0;

        return ($accessFailure || $firewallFailure) ? Command::FAILURE : Command::SUCCESS;
    }

    private function resolveThreshold(InputInterface $input, SymfonyStyle $io): ?RedosSeverity
    {
        $thresholdValue = $input->getOption('redos-threshold');
        $threshold = \is_string($thresholdValue) ? trim($thresholdValue) : '';

        try {
            return RedosSeverity::fromConfig('' === $threshold ? $this->defaultRedosThreshold : $threshold);
        } catch (InvalidRegexOptionException $e) {
            $io->error('Invalid value for --redos-threshold: '.$e->getMessage());

            return null;
        }
    }

    private function isYamlFile(string $path): bool
    {
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return 'yaml' === $extension || 'yml' === $extension;
    }

    private function showBanner(SymfonyStyle $io): void
    {
        $io->writeln('<fg=cyan;options=bold>PhpRegex</> <fg=yellow>'.Regex::VERSION.'</> by Younes ENNAJI');
        $io->newLine();
    }

    /**
     * @param array<int, array{file: string, reason: string}> $skippedFiles
     */
    private function renderFileWarnings(SymfonyStyle $io, OutputInterface $output, array $skippedFiles): void
    {
        if ([] === $skippedFiles) {
            return;
        }

        $message = \sprintf('%d security config files were skipped.', \count($skippedFiles));
        $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            foreach ($skippedFiles as $skip) {
                $location = $this->pathHelper->getRelativePath($skip['file']);
                $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$location.': '.$skip['reason'].'</>');
            }
        }

        $io->newLine();
    }

    private function renderAccessWarnings(
        SymfonyStyle $io,
        OutputInterface $output,
        SecurityAccessControlReport $report,
    ): void {
        if ([] !== $report->skippedRules) {
            $message = \sprintf(
                '%d access_control rules skipped due to unsupported regex features.',
                \count($report->skippedRules),
            );
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                foreach ($report->skippedRules as $skip) {
                    $location = $this->formatLocation($skip['file'], $skip['line']);
                    $detail = \sprintf('#%d %s: %s', $skip['index'], $location, $skip['reason']);
                    $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$detail.'</>');
                }
            }

            $io->newLine();
        }

        if ([] !== $report->rulesWithAllowIf) {
            $message = \sprintf(
                '%d rules use allow_if; conditions are not evaluated during analysis.',
                \count(array_unique($report->rulesWithAllowIf)),
            );
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');
            $io->newLine();
        }

        if ([] !== $report->rulesWithIps) {
            $message = \sprintf(
                '%d rules use IP restrictions; IPs are not evaluated during analysis.',
                \count(array_unique($report->rulesWithIps)),
            );
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');
            $io->newLine();
        }

        if ([] !== $report->rulesWithNoPath) {
            $message = \sprintf(
                '%d rules have no path and match all requests.',
                \count(array_unique($report->rulesWithNoPath)),
            );
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');
            $io->newLine();
        }

        if ([] !== $report->rulesWithUnsupportedHosts) {
            $message = \sprintf(
                '%d rules use host restrictions that could not be analyzed.',
                \count(array_unique($report->rulesWithUnsupportedHosts)),
            );
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');
            $io->newLine();
        }
    }

    private function renderAccessMeta(
        SymfonyStyle $io,
        SecurityAccessControlReport $report,
        bool $includeOverlaps,
    ): void {
        $mode = $includeOverlaps ? 'Shadowed + overlaps' : 'Shadowed only';

        $labels = ['Rules', 'Mode', 'Shadowed', 'Overlaps', 'Critical'];
        $maxLabelLength = max(array_map(strlen(...), $labels));
        $io->writeln($this->formatMetaLine('Rules', (string) $report->stats['rules'], $maxLabelLength));
        $io->writeln($this->formatMetaLine('Mode', $mode, $maxLabelLength));
        $io->writeln($this->formatMetaLine('Shadowed', (string) $report->stats['shadowed'], $maxLabelLength));
        $io->writeln($this->formatMetaLine('Overlaps', (string) $report->stats['overlaps'], $maxLabelLength));
        $io->writeln($this->formatMetaLine('Critical', (string) $report->stats['critical'], $maxLabelLength));
        $io->newLine();
    }

    private function renderAccessStatus(
        SymfonyStyle $io,
        SecurityAccessControlReport $report,
        bool $includeOverlaps,
    ): void {
        $shadowed = $report->stats['shadowed'];
        $overlaps = $report->stats['overlaps'];
        $critical = $report->stats['critical'];

        if (0 === $shadowed && 0 === $overlaps) {
            $io->writeln('  '.self::BADGE_PASS.' <fg=white>No access_control conflicts detected.</>');
            $io->newLine();

            return;
        }

        if ($critical > 0) {
            $io->writeln(\sprintf(
                '  %s <fg=white>%d critical shadowing conflicts detected.</>',
                self::BADGE_CRIT,
                $critical,
            ));
        }

        if ($shadowed > 0) {
            $io->writeln(\sprintf(
                '  %s <fg=white>%d shadowed rules detected.</>',
                self::BADGE_FAIL,
                $shadowed,
            ));
        }

        if ($overlaps > 0) {
            $suffix = $includeOverlaps ? 'Listed below.' : 'Use --show-overlaps to include them.';
            $io->writeln(\sprintf(
                '  %s <fg=white>%d overlapping rules detected.</> <fg=gray>%s</>',
                self::BADGE_WARN,
                $overlaps,
                $suffix,
            ));
        }

        $io->newLine();
    }

    private function renderAccessConflicts(SymfonyStyle $io, SecurityAccessControlReport $report): void
    {
        $io->section('Access Control Conflicts');
        foreach ($report->conflicts as $conflict) {
            $rule = $conflict['rule'];
            $other = $conflict['conflict'];
            $typeLabel = $this->formatAccessType($conflict);
            $example = $this->formatExample($conflict['example']);

            $header = \sprintf(
                '  %s <fg=white>#%d</> <fg=gray>(%s)</> <fg=gray>%s</> <fg=white>#%d</> <fg=gray>(%s)</>',
                $typeLabel,
                $rule['index'],
                $this->formatLocation($rule['file'], $rule['line']),
                self::ARROW_LABEL,
                $other['index'],
                $this->formatLocation($other['file'], $other['line']),
            );
            $io->writeln($header);
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$this->formatRuleSummary($rule).'</>');
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$this->formatRuleSummary($other).'</>');
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' Example:</> '.$example);

            if ([] !== $conflict['notes']) {
                foreach ($conflict['notes'] as $note) {
                    $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' Note:</> '.$note);
                }
            }

            $io->newLine();
        }
    }

    private function renderFirewallWarnings(
        SymfonyStyle $io,
        OutputInterface $output,
        SecurityFirewallReport $report,
    ): void {
        if ([] === $report->skippedFirewalls) {
            return;
        }

        $message = \sprintf('%d firewalls skipped during ReDoS analysis.', \count($report->skippedFirewalls));
        $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            foreach ($report->skippedFirewalls as $skip) {
                $location = $this->formatLocation($skip['file'], $skip['line']);
                $detail = \sprintf('%s %s: %s', $skip['name'], $location, $skip['reason']);
                $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$detail.'</>');
            }
        }

        $io->newLine();
    }

    private function renderFirewallMeta(
        SymfonyStyle $io,
        SecurityFirewallReport $report,
        RedosSeverity $threshold,
    ): void {
        $labels = ['Firewalls', 'ReDoS >=', 'Flagged'];
        $maxLabelLength = max(array_map(strlen(...), $labels));
        $io->writeln($this->formatMetaLine('Firewalls', (string) $report->stats['firewalls'], $maxLabelLength));
        $io->writeln($this->formatMetaLine('ReDoS >=', $threshold->value, $maxLabelLength));
        $io->writeln($this->formatMetaLine('Flagged', (string) $report->stats['flagged'], $maxLabelLength));
        $io->newLine();
    }

    private function renderFirewallStatus(SymfonyStyle $io, SecurityFirewallReport $report): void
    {
        if ([] === $report->findings) {
            $io->writeln('  '.self::BADGE_PASS.' <fg=white>No risky firewall regex detected.</>');
            $io->newLine();

            return;
        }

        $io->writeln(\sprintf(
            '  %s <fg=white>%d firewall regex patterns exceed the ReDoS threshold.</>',
            self::BADGE_FAIL,
            $report->stats['flagged'],
        ));
        $io->newLine();
    }

    private function renderFirewallFindings(SymfonyStyle $io, SecurityFirewallReport $report): void
    {
        $io->section('Firewall Regex ReDoS');
        foreach ($report->findings as $finding) {
            $location = $this->formatLocation($finding['file'], $finding['line']);
            $severity = strtoupper($finding['severity']);
            $badge = $this->formatSeverityBadge($finding['severity']);
            $header = \sprintf(
                '  %s <fg=white>%s</> <fg=gray>(%s)</> <fg=gray>%s score %d</>',
                $badge,
                $finding['name'],
                $location,
                $severity,
                $finding['score'],
            );
            $io->writeln($header);
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' Pattern:</> '.$this->formatPattern($finding['pattern']));

            if (null !== $finding['vulnerable'] && '' !== $finding['vulnerable']) {
                $io->writeln(
                    '      <fg=gray>'.self::ARROW_LABEL.' Vulnerable:</> '.$this->formatPattern($finding['vulnerable']),
                );
            }

            if (null !== $finding['trigger'] && '' !== $finding['trigger']) {
                $io->writeln(
                    '      <fg=gray>'.self::ARROW_LABEL.' Trigger:</> '.$this->formatExample($finding['trigger']),
                );
            }

            $io->newLine();
        }
    }

    /**
     * @phpstan-param AccessConflict $conflict
     */
    private function formatAccessType(array $conflict): string
    {
        $isShadowed = self::TYPE_SHADOWED === $conflict['type'];
        $isCritical = $isShadowed && 'critical' === $conflict['severity'];

        $badge = $isCritical ? self::BADGE_CRIT : ($isShadowed ? self::BADGE_FAIL : self::BADGE_WARN);
        $label = $isShadowed ? 'Shadowed' : 'Overlap';

        $type = $badge.' <fg=white>'.$label.'</>';
        if ($isCritical) {
            $type .= ' <fg=red>(critical)</>';
        }
        if ([] !== $conflict['notes']) {
            $type .= ' <fg=gray>(approx)</>';
        }

        return $type;
    }

    /**
     * @phpstan-param AccessRule $rule
     */
    private function formatRuleSummary(array $rule): string
    {
        $location = $this->formatLocation($rule['file'], $rule['line']);
        $parts = [
            \sprintf('#%d %s', $rule['index'], $location),
            'path='.$this->formatPathValue($rule['path']),
            'roles='.$this->formatListValue($rule['roles'], 'any'),
        ];

        $scope = $this->formatScope($rule['methods'], $rule['host'], $rule['ips'], $rule['requiresChannel']);
        if ('any' !== $scope) {
            $parts[] = 'scope='.$scope;
        }

        return implode('  ', $parts);
    }

    /**
     * @param array<int, string> $methods
     * @param array<int, string> $ips
     */
    private function formatScope(array $methods, ?string $host, array $ips, ?string $requiresChannel): string
    {
        if ([] === $methods && [] === $ips && null === $host && null === $requiresChannel) {
            return 'any';
        }

        $parts = [];
        if ([] !== $methods) {
            $parts[] = 'methods='.implode('|', $methods);
        }
        if (null !== $host && '' !== trim($host)) {
            $parts[] = 'host='.$host;
        }
        if ([] !== $ips) {
            $parts[] = 'ips='.implode('|', $ips);
        }
        if (null !== $requiresChannel && '' !== trim($requiresChannel)) {
            $parts[] = 'channel='.$requiresChannel;
        }

        return implode(' • ', $parts);
    }

    private function formatPathValue(?string $path): string
    {
        if (null === $path || '' === $path) {
            return '(any)';
        }

        return $path;
    }

    /**
     * @param array<int, string> $values
     */
    private function formatListValue(array $values, string $emptyLabel): string
    {
        if ([] === $values) {
            return $emptyLabel;
        }

        return implode('|', $values);
    }

    private function formatLocation(string $file, int $line): string
    {
        return $this->pathHelper->getRelativePath($file).':'.$line;
    }

    private function formatExample(?string $example): string
    {
        if (null === $example) {
            return '-';
        }

        return DisplayEscaper::quote($example, '<fg=cyan>', '</>');
    }

    private function formatPattern(string $pattern): string
    {
        if ('' === $pattern) {
            return '<fg=cyan>""</>';
        }

        return '<fg=cyan>'.$pattern.'</>';
    }

    private function formatSeverityBadge(string $severity): string
    {
        return match ($severity) {
            'critical' => self::BADGE_CRIT,
            'high' => self::BADGE_FAIL,
            default => self::BADGE_WARN,
        };
    }

    private function showFooter(SymfonyStyle $io): void
    {
        $message = 'If PhpRegex helps, a GitHub star is appreciated: ';
        $io->writeln('  <fg=gray>'.$message.'https://github.com/php-regex/regex-parser</>');
        $io->newLine();
    }

    private function formatMetaLine(string $label, string $value, int $maxLabelLength): string
    {
        return \sprintf(
            '<fg=white;options=bold>%s</> : <fg=yellow>%s</>',
            str_pad($label, $maxLabelLength),
            $value,
        );
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
}
