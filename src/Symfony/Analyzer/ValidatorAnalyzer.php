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

use PHPRegex\Symfony\Validator\RegexConstraintAnalyzer;
use PHPRegex\Symfony\Validator\RegexConstraintReport;
use PHPRegex\Symfony\Validator\ValidatedClassLocator;

/**
 * The validator section of regex:analyze, and regex:validator on its own:
 * the Regex constraints of the validated classes, against the HTML pattern
 * a form gives the browser and against the other constraints of their
 * property.
 *
 * An error or a warning of the audit is an issue of kind "validator"; a
 * question the solver could not answer is a warning of the section.
 *
 * @internal
 *
 * @phpstan-import-type ValidatorFinding from RegexConstraintAnalyzer
 */
final class ValidatorAnalyzer implements AnalyzerInterface
{
    public const ID = 'validator';

    private const TITLE = 'Validator constraints';

    private readonly RegexConstraintAnalyzer $analyzer;

    /**
     * @param list<string> $classes the classes to read; none reads the mapping files and the project's src/
     */
    public function __construct(
        private readonly ValidatedClassLocator $locator,
        ?RegexConstraintAnalyzer $analyzer = null,
        private readonly array $classes = [],
    ) {
        $this->analyzer = $analyzer ?? new RegexConstraintAnalyzer();
    }

    public function getId(): string
    {
        return self::ID;
    }

    public function getLabel(): string
    {
        return 'Validator';
    }

    public function analyze(AnalysisContext $context): array
    {
        if (!$this->locator->isSupported()) {
            return [new ReportSection(self::ID, self::TITLE, summary: [
                new AnalysisNotice(CheckOutcome::Warn, 'Validator service is not available. Install Symfony Validator or enable the validator service.'),
            ])];
        }

        $directories = null === $context->projectDir ? [] : [$context->projectDir.\DIRECTORY_SEPARATOR.'src'];
        [$metadata, $unread] = $this->locator->locate($this->classes, $directories);
        $report = $this->analyzer->analyze($metadata);

        return [$this->section($report, $unread, $context->debug)];
    }

    /**
     * @param list<string> $unread
     */
    public function section(RegexConstraintReport $report, array $unread = [], bool $debug = false): ReportSection
    {
        $issues = [];
        $warnings = [];
        foreach ($report->findings as $finding) {
            if ('notice' === $finding['severity']) {
                $warnings[] = new AnalysisNotice(CheckOutcome::Warn, \sprintf('%s::%s %s: %s', $finding['class'], $finding['property'], $finding['pattern'], $finding['message']));

                continue;
            }

            $issues[] = self::issue($finding);
        }

        $suggestions = [];
        foreach ($report->findings as $finding) {
            if (isset($finding['suggestion']) && 'notice' !== $finding['severity']) {
                $suggestions[] = \sprintf('%s::%s: set htmlPattern: %s', $finding['class'], $finding['property'], (string) json_encode($finding['suggestion'], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
        }

        $errors = \count($report->ofSeverity('error'));
        $summary = [];
        if (0 === $report->stats['regex']) {
            $summary[] = new AnalysisNotice(CheckOutcome::Pass, 'No Regex constraint found.');
        } elseif ([] === $issues) {
            $summary[] = new AnalysisNotice(CheckOutcome::Pass, 'Every Regex constraint agrees with its HTML pattern and its property.');
        } else {
            $summary[] = new AnalysisNotice($errors > 0 ? CheckOutcome::Fail : CheckOutcome::Warn, \sprintf('%d finding%s on %d Regex constraint%s.', \count($issues), 1 === \count($issues) ? '' : 's', $report->stats['regex'], 1 === $report->stats['regex'] ? '' : 's'));
        }

        $debugRows = [];
        if ($debug) {
            $debugRows = [
                'Classes read' => $report->stats['classes'],
                'HTML patterns compared' => $report->stats['html_compared'],
            ];
            foreach ($unread as $index => $reason) {
                $debugRows['Unread class '.($index + 1)] = $reason;
            }
        }

        return new ReportSection(
            self::ID,
            self::TITLE,
            ['Classes' => $report->stats['classes'], 'Properties' => $report->stats['properties'], 'Regex constraints' => $report->stats['regex'], 'Errors' => $errors],
            $summary,
            $warnings,
            $issues,
            $suggestions,
            $debugRows,
        );
    }

    /**
     * @param ValidatorFinding $finding
     */
    private static function issue(array $finding): AnalysisIssue
    {
        $details = [
            new IssueDetail('Property', $finding['class'].'::'.$finding['property']),
            new IssueDetail('Pattern', $finding['pattern'], 'pattern'),
        ];
        if (isset($finding['htmlPattern'])) {
            $details[] = new IssueDetail('HTML pattern', $finding['htmlPattern'], 'pattern');
        }

        if (isset($finding['example'])) {
            $details[] = new IssueDetail('Example', $finding['example'], 'example');
        }

        if (isset($finding['suggestion'])) {
            $details[] = new IssueDetail('Suggested htmlPattern', $finding['suggestion'], 'pattern');
        }

        // The title names the class alone; the Property row names it whole.
        $class = substr($finding['class'], (int) strrpos('\\'.$finding['class'], '\\'));

        return new AnalysisIssue(
            self::ID,
            'error' === $finding['severity'] ? CheckOutcome::Fail : CheckOutcome::Warn,
            \sprintf('%s::%s %s', $class, $finding['property'], $finding['check']),
            $details,
            [$finding['message']],
        );
    }
}
