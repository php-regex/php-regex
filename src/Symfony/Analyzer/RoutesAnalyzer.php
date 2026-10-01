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

use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Routing\RouteConflictReport;
use PHPRegex\Symfony\Routing\RouteConflictSuggestionBuilder;
use Symfony\Component\Routing\RouterInterface;

/**
 * @internal
 *
 * @phpstan-import-type RouteConflict from RouteConflictReport
 * @phpstan-import-type RouteDescriptor from RouteConflictReport
 */
final readonly class RoutesAnalyzer implements AnalyzerInterface
{
    private const ID = 'routes';
    private const PRIORITY = 10;
    private const ARROW_LABEL = "\u{21B3}";

    public function __construct(
        private RouteConflictAnalyzer $analyzer,
        private RouteConflictSuggestionBuilder $suggestionBuilder = new RouteConflictSuggestionBuilder(),
        private ?RouterInterface $router = null,
    ) {}

    public function getId(): string
    {
        return self::ID;
    }

    public function getLabel(): string
    {
        return 'Routes';
    }

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    public function analyze(AnalysisContext $context): array
    {
        if (null === $this->router) {
            return [
                new ReportSection(
                    self::ID,
                    'Routes',
                    summary: [
                        new AnalysisNotice(CheckOutcome::Warn, 'Router service is not available.'),
                    ],
                ),
            ];
        }

        $collection = $this->router->getRouteCollection();
        $routes = $collection->all();
        $routeCount = \count($routes);

        if (0 === $routeCount) {
            return [
                new ReportSection(
                    self::ID,
                    'Routes',
                    meta: ['Routes' => 0],
                    summary: [
                        new AnalysisNotice(CheckOutcome::Pass, 'No routes found.'),
                    ],
                ),
            ];
        }

        $report = $this->analyzer->analyze($collection, $context->includeOverlaps);

        $meta = [
            'Routes' => $report->stats['routes'],
            'Mode' => $context->includeOverlaps ? 'Shadowed + overlaps' : 'Shadowed only',
            'Shadowed' => $report->stats['shadowed'],
            'Overlaps' => $report->stats['overlaps'],
        ];

        $warnings = $this->buildWarnings($report);
        $summary = $this->buildSummary($report, $context->includeOverlaps);

        $issues = [];
        foreach ($report->conflicts as $conflict) {
            $issues[] = $this->buildIssue($conflict);
        }

        $suggestions = [];
        if ([] !== $report->conflicts) {
            $suggestions = $this->suggestionBuilder->collect($report->conflicts);
        }

        $debug = [];
        if ($context->debug) {
            $debug = [
                'Pairs total' => $report->stats['pairs_total'] ?? 0,
                'Pairs filtered (methods)' => $report->stats['pairs_filtered_methods'] ?? 0,
                'Pairs filtered (schemes)' => $report->stats['pairs_filtered_schemes'] ?? 0,
                'Pairs filtered (prefix)' => $report->stats['pairs_filtered_prefix'] ?? 0,
                'Host checks' => $report->stats['host_checks'] ?? 0,
                'Host checks skipped' => $report->stats['host_skipped'] ?? 0,
                'Host intersections' => $report->stats['host_intersections'] ?? 0,
                'Path intersections' => $report->stats['path_intersections'] ?? 0,
                'Path subset checks' => $report->stats['path_subsets'] ?? 0,
                'Pairs skipped (complexity)' => $report->stats['skipped_pairs'] ?? 0,
            ];
        }

        return [
            new ReportSection(
                self::ID,
                'Routes',
                $meta,
                $summary,
                $warnings,
                $issues,
                $suggestions,
                $debug,
            ),
        ];
    }

    /**
     * @return array<int, AnalysisNotice>
     */
    private function buildWarnings(RouteConflictReport $report): array
    {
        $warnings = [];

        if ([] !== $report->skippedRoutes) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf('%d routes skipped due to unsupported regex features.', \count($report->skippedRoutes)),
            );
        }

        if ([] !== $report->routesWithConditions) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf(
                    '%d routes use conditions; conditions are not evaluated during analysis.',
                    \count(array_unique($report->routesWithConditions)),
                ),
            );
        }

        if ([] !== $report->routesWithUnsupportedHosts) {
            $warnings[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf(
                    '%d routes use host requirements that could not be analyzed.',
                    \count(array_unique($report->routesWithUnsupportedHosts)),
                ),
            );
        }

        return $warnings;
    }

    /**
     * @return array<int, AnalysisNotice>
     */
    private function buildSummary(RouteConflictReport $report, bool $includeOverlaps): array
    {
        $summary = [];
        $shadowed = $report->stats['shadowed'];
        $overlaps = $report->stats['overlaps'];

        if (0 === $shadowed && 0 === $overlaps) {
            $summary[] = new AnalysisNotice(CheckOutcome::Pass, 'No route conflicts detected.');

            return $summary;
        }

        if ($shadowed > 0) {
            $summary[] = new AnalysisNotice(
                CheckOutcome::Fail,
                \sprintf('%d shadowed routes detected.', $shadowed),
            );
        }

        if ($overlaps > 0) {
            $suffix = $includeOverlaps ? 'Listed below.' : 'Use --show-overlaps to include them.';
            $summary[] = new AnalysisNotice(
                CheckOutcome::Warn,
                \sprintf('%d overlapping routes detected. %s', $overlaps, $suffix),
            );
        }

        return $summary;
    }

    /**
     * @phpstan-param RouteConflict $conflict
     */
    private function buildIssue(array $conflict): AnalysisIssue
    {
        $route = $conflict['route'];
        $other = $conflict['conflict'];
        $type = $conflict['type'];

        $severity = 'shadowed' === $type ? CheckOutcome::Fail : CheckOutcome::Warn;
        $title = \sprintf(
            '%s (#%d) %s %s (#%d)',
            $route['name'],
            $route['index'],
            self::ARROW_LABEL,
            $other['name'],
            $other['index'],
        );

        $details = [
            new IssueDetail('Route', $route['path']),
            new IssueDetail('Conflict', $other['path']),
            new IssueDetail('Scope', $this->formatScope($conflict['methods'], $conflict['schemes'])),
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

    /**
     * @param array<int, string> $methods
     * @param array<int, string> $schemes
     */
    private function formatScope(array $methods, array $schemes): string
    {
        if ([] === $methods && [] === $schemes) {
            return 'any';
        }

        $parts = [];
        if ([] !== $methods) {
            $parts[] = implode('|', $methods);
        }
        if ([] !== $schemes) {
            $parts[] = implode('|', $schemes);
        }

        return implode(' • ', $parts);
    }
}
