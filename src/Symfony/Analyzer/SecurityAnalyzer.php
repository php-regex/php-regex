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

namespace PHPRegex\Symfony\Analyzer;

use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Symfony\Security\AccessRuleSummary;
use PHPRegex\Symfony\Security\SecurityAccessControlAnalyzer;
use PHPRegex\Symfony\Security\SecurityAccessControlReport;
use PHPRegex\Symfony\Security\SecurityAccessSuggestionBuilder;
use PHPRegex\Symfony\Security\SecurityConfigException;
use PHPRegex\Symfony\Security\SecurityConfigExtractor;
use PHPRegex\Symfony\Security\SecurityConfigLocator;
use PHPRegex\Symfony\Security\SecurityFirewallAnalyzer;
use PHPRegex\Symfony\Security\SecurityFirewallReport;

/**
 * @internal
 *
 * @phpstan-import-type AccessConflict from SecurityAccessControlReport
 * @phpstan-import-type AccessRule from SecurityAccessControlReport
 * @phpstan-import-type FirewallFinding from SecurityFirewallReport
 * @phpstan-import-type AccessControlRule from SecurityConfigExtractor
 * @phpstan-import-type FirewallRule from SecurityConfigExtractor
 *
 * @phpstan-type SkippedFile array{file: string, reason: string}
 */
final class SecurityAnalyzer implements AnalyzerInterface
{
    private const ID = 'security';
    private const ARROW_LABEL = "\u{21B3}";

    public function __construct(
        private readonly SecurityConfigExtractor $extractor,
        private readonly SecurityConfigLocator $locator,
        private readonly SecurityAccessControlAnalyzer $accessAnalyzer,
        private readonly SecurityFirewallAnalyzer $firewallAnalyzer,
        private readonly SecurityAccessSuggestionBuilder $suggestionBuilder = new SecurityAccessSuggestionBuilder(),
    ) {}

    public function getId(): string
    {
        return self::ID;
    }

    public function getLabel(): string
    {
        return 'Security';
    }

    /**
     * @throws SecurityConfigException when the security configuration cannot be read
     */
    public function analyze(AnalysisContext $context): array
    {
        $config = $this->read($context);

        if (null === $config) {
            return [
                new ReportSection(
                    self::ID.'_access',
                    'Security Access Control',
                    summary: [
                        new AnalysisNotice(CheckOutcome::Warn, 'No security configuration found.'),
                    ],
                ),
            ];
        }

        return $this->sections($config, $context);
    }

    /**
     * The security configuration the context names, null when there is none.
     *
     * @throws SecurityConfigException when the security configuration cannot be read
     *
     * @return array{accessControl: list<AccessControlRule>, firewalls: list<FirewallRule>, skippedFiles: list<SkippedFile>}|null
     */
    public function read(AnalysisContext $context): ?array
    {
        return $this->extractor->read(
            $context->securityConfigPaths,
            $context->kernel,
            $this->locator,
            $context->projectDir,
            $context->environment,
        );
    }

    /**
     * The sections of a security configuration read: its access_control
     * rules, then its firewalls unless the context skips them.
     *
     * @param array{accessControl: list<AccessControlRule>, firewalls: list<FirewallRule>, skippedFiles: list<SkippedFile>} $config
     *
     * @return list<ReportSection>
     */
    public function sections(array $config, AnalysisContext $context): array
    {
        $pathHelper = new RelativePathHelper($context->projectDir);
        $skippedFiles = $config['skippedFiles'];

        if ([] === $config['accessControl'] && [] === $config['firewalls']) {
            return [
                new ReportSection(
                    self::ID.'_access',
                    'Security Access Control',
                    summary: [
                        new AnalysisNotice(CheckOutcome::Pass, 'No security configuration entries found.'),
                    ],
                    warnings: $this->buildFileWarnings($skippedFiles),
                    debug: $context->debug ? $this->skippedFileRows($skippedFiles, $pathHelper) : [],
                ),
            ];
        }

        $accessReport = $this->accessAnalyzer->analyze($config['accessControl'], $context->includeOverlaps);

        $sections = [
            $this->buildAccessSection($accessReport, $context, $pathHelper, $skippedFiles),
        ];

        if (!$context->skipFirewalls) {
            $firewallReport = $this->firewallAnalyzer->analyze($config['firewalls'], $context->redosThreshold);
            $sections[] = $this->buildFirewallSection($firewallReport, $context, $pathHelper);
        }

        return $sections;
    }

    /**
     * @param array<int, SkippedFile> $skippedFiles
     *
     * @return list<AnalysisNotice>
     */
    private function buildFileWarnings(array $skippedFiles): array
    {
        if ([] === $skippedFiles) {
            return [];
        }

        return [new AnalysisNotice(
            CheckOutcome::Warn,
            \sprintf('%d security config files were skipped.', \count($skippedFiles)),
        )];
    }

    /**
     * Why each file was left out, as debug rows.
     *
     * @param array<int, SkippedFile> $skippedFiles
     *
     * @return array<string, string>
     */
    private function skippedFileRows(array $skippedFiles, RelativePathHelper $pathHelper): array
    {
        $rows = [];
        foreach ($skippedFiles as $skip) {
            $rows['Skipped file '.$pathHelper->getRelativePath($skip['file'])] = $skip['reason'];
        }

        return $rows;
    }

    /**
     * @param array<int, SkippedFile> $skippedFiles
     */
    private function buildAccessSection(
        SecurityAccessControlReport $report,
        AnalysisContext $context,
        RelativePathHelper $pathHelper,
        array $skippedFiles,
    ): ReportSection {
        $meta = [
            'Rules' => $report->stats['rules'],
            'Mode' => $context->includeOverlaps ? 'Shadowed + overlaps' : 'Shadowed only',
            'Shadowed' => $report->stats['shadowed'],
            'Overlaps' => $report->stats['overlaps'],
            'Critical' => $report->stats['critical'],
        ];

        $warnings = $this->buildAccessWarnings($report, $skippedFiles);
        $summary = $this->buildAccessSummary($report, $context->includeOverlaps);

        $issues = [];
        foreach ($report->conflicts as $conflict) {
            $issues[] = $this->buildAccessIssue($conflict, $pathHelper);
        }

        $suggestions = [];
        if ([] !== $report->conflicts) {
            $suggestions = $this->suggestionBuilder->collect(
                $report->conflicts,
                static fn (string $file, int $line): string => AccessRuleSummary::location($pathHelper, $file, $line),
            );
        }

        $debug = [];
        if ($context->debug) {
            $debug = [
                'Pairs total' => $report->stats['pairs_total'] ?? 0,
                'Pairs filtered (methods)' => $report->stats['pairs_filtered_methods'] ?? 0,
                'Host checks' => $report->stats['host_checks'] ?? 0,
                'Host intersections' => $report->stats['host_intersections'] ?? 0,
                'Path intersections' => $report->stats['path_intersections'] ?? 0,
                'Path subset checks' => $report->stats['path_subsets'] ?? 0,
                'Pairs skipped (complexity)' => $report->stats['skipped_pairs'],
            ];
            foreach ($report->skippedRules as $skip) {
                $location = $this->formatLocation($pathHelper, $skip['file'], $skip['line']);
                $debug[\sprintf('Skipped rule #%d (%s)', $skip['index'], $location)] = $skip['reason'];
            }
            $debug += $this->skippedFileRows($skippedFiles, $pathHelper);
        }

        return new ReportSection(
            self::ID.'_access',
            'Security Access Control',
            $meta,
            $summary,
            $warnings,
            $issues,
            $suggestions,
            $debug,
        );
    }

    /**
     * @param array<int, SkippedFile> $skippedFiles
     *
     * @return array<int, AnalysisNotice>
     */
    private function buildAccessWarnings(SecurityAccessControlReport $report, array $skippedFiles): array
    {
        $warnings = $this->buildFileWarnings($skippedFiles);

        if ([] !== $report->skippedRules) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf(
                    '%d access_control rules skipped due to unsupported regex features.',
                    \count($report->skippedRules),
                ),
            );
        }

        if ([] !== $report->rulesWithAllowIf) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf(
                    '%d rules use allow_if; conditions are not evaluated during analysis.',
                    \count(array_unique($report->rulesWithAllowIf)),
                ),
            );
        }

        if ([] !== $report->rulesWithIps) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf(
                    '%d rules use IP restrictions; IPs are not evaluated during analysis.',
                    \count(array_unique($report->rulesWithIps)),
                ),
            );
        }

        if ([] !== $report->rulesWithNoPath) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf(
                    '%d rules have no path and match all requests.',
                    \count(array_unique($report->rulesWithNoPath)),
                ),
            );
        }

        if ([] !== $report->rulesWithUnsupportedHosts) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf(
                    '%d rules use host restrictions that could not be analyzed.',
                    \count(array_unique($report->rulesWithUnsupportedHosts)),
                ),
            );
        }

        return $warnings;
    }

    /**
     * @return array<int, AnalysisNotice>
     */
    private function buildAccessSummary(SecurityAccessControlReport $report, bool $includeOverlaps): array
    {
        $summary = [];
        $shadowed = $report->stats['shadowed'];
        $overlaps = $report->stats['overlaps'];
        $critical = $report->stats['critical'];

        if (0 === $shadowed && 0 === $overlaps) {
            $summary[] = new AnalysisNotice(CheckOutcome::Pass, 'No access_control conflicts detected.');

            return $summary;
        }

        if ($critical > 0) {
            $summary[] = new AnalysisNotice(
                CheckOutcome::Critical,
                \sprintf('%d critical shadowing conflicts detected.', $critical),
            );
        }

        if ($shadowed > 0) {
            $summary[] = new AnalysisNotice(
                CheckOutcome::Fail,
                \sprintf('%d shadowed rules detected.', $shadowed),
            );
        }

        if ($overlaps > 0) {
            $suffix = $includeOverlaps ? 'Listed below.' : 'Use --show-overlaps to include them.';
            $summary[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf('%d overlapping rules detected. %s', $overlaps, $suffix),
            );
        }

        return $summary;
    }

    /**
     * @phpstan-param AccessConflict $conflict
     */
    private function buildAccessIssue(array $conflict, RelativePathHelper $pathHelper): AnalysisIssue
    {
        $rule = $conflict['rule'];
        $other = $conflict['conflict'];
        $type = $conflict['type'];

        $severity = match (true) {
            'critical' === $conflict['severity'] => CheckOutcome::Critical,
            'shadowed' === $type => CheckOutcome::Fail,
            default => CheckOutcome::Warn,
        };

        $title = \sprintf(
            '#%d (%s) %s #%d (%s)',
            $rule['index'],
            $this->formatLocation($pathHelper, $rule['file'], $rule['line']),
            self::ARROW_LABEL,
            $other['index'],
            $this->formatLocation($pathHelper, $other['file'], $other['line']),
        );

        $details = [
            new IssueDetail('Rule', $this->formatRuleSummary($rule, $pathHelper)),
            new IssueDetail('Conflict', $this->formatRuleSummary($other, $pathHelper)),
        ];

        if (null !== $conflict['example']) {
            $details[] = new IssueDetail('Example', $conflict['example'], 'example');
        }

        return new AnalysisIssue(
            $type,
            $severity,
            $title,
            $details,
            $conflict['notes'],
        );
    }

    private function buildFirewallSection(
        SecurityFirewallReport $report,
        AnalysisContext $context,
        RelativePathHelper $pathHelper,
    ): ReportSection {
        $meta = [
            'Firewalls' => $report->stats['firewalls'],
            'ReDoS >=' => $context->redosThreshold->value,
            'Flagged' => $report->stats['flagged'],
        ];

        $warnings = [];
        if ([] !== $report->skippedFirewalls) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf('%d firewalls skipped during ReDoS analysis.', \count($report->skippedFirewalls)),
            );
        }

        $summary = [];
        if ([] === $report->findings) {
            $summary[] = new AnalysisNotice(CheckOutcome::Pass, 'No risky firewall regex detected.');
        } else {
            $summary[] = new AnalysisNotice(
                CheckOutcome::Fail,
                \sprintf('%d firewall regex patterns exceed the ReDoS threshold.', $report->stats['flagged']),
            );
        }

        $issues = [];
        foreach ($report->findings as $finding) {
            $issues[] = $this->buildFirewallIssue($finding, $pathHelper);
        }

        $debug = [];
        if ($context->debug) {
            foreach ($report->skippedFirewalls as $skip) {
                $location = $this->formatLocation($pathHelper, $skip['file'], $skip['line']);
                $debug[\sprintf('Skipped firewall %s (%s)', $skip['name'], $location)] = $skip['reason'];
            }
        }

        return new ReportSection(
            self::ID.'_firewall',
            'Security Firewall Regex',
            $meta,
            $summary,
            $warnings,
            $issues,
            debug: $debug,
        );
    }

    /**
     * @phpstan-param FirewallFinding $finding
     */
    private function buildFirewallIssue(array $finding, RelativePathHelper $pathHelper): AnalysisIssue
    {
        $severity = match ($finding['severity']) {
            'critical' => CheckOutcome::Critical,
            'high' => CheckOutcome::Fail,
            default => CheckOutcome::Warn,
        };

        $location = $this->formatLocation($pathHelper, $finding['file'], $finding['line']);
        $title = \sprintf('%s (%s)', $finding['name'], $location);

        $details = [
            new IssueDetail('Severity', strtoupper($finding['severity'])),
            new IssueDetail('Verdict', $finding['verdict']),
            new IssueDetail('Score', (string) $finding['score']),
            new IssueDetail('Pattern', $finding['pattern'], 'pattern'),
        ];

        if (null !== $finding['vulnerable'] && '' !== $finding['vulnerable']) {
            $details[] = new IssueDetail('Vulnerable', $finding['vulnerable'], 'pattern');
        }

        if (null !== $finding['trigger'] && '' !== $finding['trigger']) {
            $details[] = new IssueDetail('Trigger', $finding['trigger'], 'example');
        }

        // The attack is already a quoted PHP expression: a text row, not an example quoted again.
        if (null !== $finding['attack']) {
            $details[] = new IssueDetail('Attack', $finding['attack']);
        }

        return new AnalysisIssue('redos', $severity, $title, $details);
    }

    /**
     * @phpstan-param AccessRule $rule
     */
    private function formatRuleSummary(array $rule, RelativePathHelper $pathHelper): string
    {
        return AccessRuleSummary::of($rule, $this->formatLocation($pathHelper, $rule['file'], $rule['line']));
    }

    private function formatLocation(RelativePathHelper $pathHelper, string $file, int $line): string
    {
        return AccessRuleSummary::location($pathHelper, $file, $line);
    }
}
