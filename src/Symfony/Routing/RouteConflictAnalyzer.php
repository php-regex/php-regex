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

namespace PhpRegex\Symfony\Routing;

use PhpRegex\Automata\Determinization\DeterminizationAlgorithm;
use PhpRegex\Automata\Exception\ComplexityException;
use PhpRegex\Automata\LanguageSolver;
use PhpRegex\Automata\Minimization\MinimizationAlgorithm;
use PhpRegex\Automata\Options\MatchMode;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Solver\InMemoryDfaCache;
use PhpRegex\Parser\DelimitedPattern;
use PhpRegex\Toolkit\Regex;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Detects Symfony route conflicts using RegexParser automata logic.
 *
 * @internal
 *
 * @phpstan-import-type RouteDescriptor from RouteConflictReport
 * @phpstan-import-type RouteConflict from RouteConflictReport
 * @phpstan-import-type RouteSkip from RouteConflictReport
 */
final readonly class RouteConflictAnalyzer
{
    private const SUPPORTED_FLAGS = ['i', 's', 'u'];

    private const IGNORED_FLAGS = ['D'];

    private LanguageSolver $solver;

    public function __construct(
        private Regex $regex,
        private string $minimizationAlgorithm = MinimizationAlgorithm::Hopcroft->value,
        ?LanguageSolver $solver = null,
        private string $determinizationAlgorithm = DeterminizationAlgorithm::SubsetIndexed->value,
    ) {
        $this->solver = $solver ?? new LanguageSolver($this->regex->parser(), new InMemoryDfaCache());
    }

    public function analyze(RouteCollection $collection, bool $includeOverlaps = true): RouteConflictReport
    {
        /** @var array<int, RouteDescriptor> $routes */
        $routes = [];
        $skippedRoutes = [];
        $routesWithConditions = [];
        $routesWithUnsupportedHosts = [];
        $index = 0;

        $options = new SolverOptions(
            matchMode: MatchMode::Full,
            minimizeDfa: false,
            minimizationAlgorithm: $this->resolveMinimizationAlgorithm(),
            determinizationAlgorithm: $this->resolveDeterminizationAlgorithm(),
        );

        foreach ($collection as $name => $route) {
            $index++;
            $descriptor = $this->buildDescriptor(
                (string) $name,
                $route,
                $index,
                $options,
                $skippedRoutes,
                $routesWithConditions,
                $routesWithUnsupportedHosts,
            );
            if (null !== $descriptor) {
                $routes[] = $descriptor;
            }
        }

        $routes = $this->sortBySpecificity($routes);

        /** @var array<int, RouteConflict> $conflicts */
        $conflicts = [];
        $shadowed = 0;
        $overlaps = 0;
        $equivalent = 0;
        $skippedPairs = 0;
        $pairsTotal = 0;
        $pairsFilteredMethods = 0;
        $pairsFilteredSchemes = 0;
        $pairsFilteredPrefix = 0;
        $hostChecks = 0;
        $hostSkipped = 0;
        $hostIntersections = 0;
        $pathIntersections = 0;
        $pathSubsets = 0;
        $count = \count($routes);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $routes[$i];
                $right = $routes[$j];
                $pairsTotal++;

                if (!$this->methodsOverlap($left['methods'], $right['methods'])) {
                    $pairsFilteredMethods++;

                    continue;
                }

                if (!$this->schemesOverlap($left['schemes'], $right['schemes'])) {
                    $pairsFilteredSchemes++;

                    continue;
                }

                if (!$this->prefixOverlaps($left['staticPrefix'], $right['staticPrefix'])) {
                    $pairsFilteredPrefix++;

                    continue;
                }

                if (!$this->hostsOverlap(
                    $left,
                    $right,
                    $options,
                    $skippedPairs,
                    $hostChecks,
                    $hostIntersections,
                    $hostSkipped,
                )) {
                    continue;
                }

                try {
                    $pathIntersections++;
                    $intersection = $this->solver->intersection($left['pathPattern'], $right['pathPattern'], $options);
                } catch (ComplexityException) {
                    $skippedPairs++;

                    continue;
                }

                if ($intersection->isEmpty) {
                    continue;
                }

                $example = $intersection->example;
                $pathSubsets++;
                $equivalence = $this->solver->equivalent($left['pathPattern'], $right['pathPattern'], $options);
                $isEquivalent = $equivalence->isEquivalent;
                $leftSubsetRight = null === $equivalence->leftOnlyExample;
                $rightSubsetLeft = null === $equivalence->rightOnlyExample;
                if ($isEquivalent) {
                    $equivalent++;
                }

                $notes = $this->buildNotes($left, $right);
                if ($isEquivalent) {
                    $notes[] = 'Equivalent route patterns.';
                }

                if ($leftSubsetRight && $left['index'] > $right['index']) {
                    $shadowed++;
                    $route = $right;
                    $other = $left;
                    $conflictType = 'shadowed';
                    $notes[] = 'Less specific route declared BEFORE more specific route.';
                } elseif ($rightSubsetLeft && $right['index'] > $left['index']) {
                    $shadowed++;
                    $route = $left;
                    $other = $right;
                    $conflictType = 'shadowed';
                    $notes[] = 'Less specific route declared BEFORE more specific route.';
                } else {
                    if ($right['index'] > $left['index']) {
                        continue;
                    }

                    $overlaps++;

                    if (!$includeOverlaps) {
                        continue;
                    }

                    $route = $right;
                    $other = $left;
                    $conflictType = 'overlap';
                    $notes[] = 'Less specific route declared BEFORE more specific route.';
                }

                $conflicts[] = [
                    'route' => $route,
                    'conflict' => $other,
                    'type' => $conflictType,
                    'example' => $example,
                    'equivalent' => $isEquivalent,
                    'methods' => $this->intersectMethods($route['methods'], $other['methods']),
                    'schemes' => $this->intersectSchemes($route['schemes'], $other['schemes']),
                    'notes' => $notes,
                ];
            }
        }

        $stats = [
            'routes' => $index,
            'conflicts' => \count($conflicts),
            'shadowed' => $shadowed,
            'overlaps' => $overlaps,
            'equivalent' => $equivalent,
            'skipped_routes' => \count($skippedRoutes),
            'skipped_pairs' => $skippedPairs,
            'pairs_total' => $pairsTotal,
            'pairs_filtered_methods' => $pairsFilteredMethods,
            'pairs_filtered_schemes' => $pairsFilteredSchemes,
            'pairs_filtered_prefix' => $pairsFilteredPrefix,
            'host_checks' => $hostChecks,
            'host_skipped' => $hostSkipped,
            'host_intersections' => $hostIntersections,
            'path_intersections' => $pathIntersections,
            'path_subsets' => $pathSubsets,
        ];

        return new RouteConflictReport(
            $conflicts,
            $skippedRoutes,
            $stats,
            array_values(array_unique($routesWithConditions)),
            array_values(array_unique($routesWithUnsupportedHosts)),
        );
    }

    /**
     * @param array<RouteSkip>   $skippedRoutes
     * @param array<int, string> $routesWithConditions
     * @param array<int, string> $routesWithUnsupportedHosts
     *
     * @phpstan-return RouteDescriptor|null
     */
    private function buildDescriptor(
        string $name,
        Route $route,
        int $index,
        SolverOptions $options,
        array &$skippedRoutes,
        array &$routesWithConditions,
        array &$routesWithUnsupportedHosts,
    ): ?array {
        try {
            $compiled = $route->compile();
        } catch (\Throwable $exception) {
            $skippedRoutes[] = [
                'route' => $name,
                'reason' => 'Route compile failed: '.$exception->getMessage(),
            ];

            return null;
        }

        $pathRegex = $compiled->getRegex();

        try {
            $pathNormalization = $this->normalizePattern($pathRegex);
        } catch (\Throwable $exception) {
            $skippedRoutes[] = [
                'route' => $name,
                'reason' => 'Route regex normalization failed: '.$exception->getMessage(),
            ];

            return null;
        }

        if ([] !== $pathNormalization['unsupportedFlags']) {
            $skippedRoutes[] = [
                'route' => $name,
                'reason' => 'Unsupported regex flags: '.implode(', ', $pathNormalization['unsupportedFlags']),
            ];

            return null;
        }

        $hostRegex = $this->resolveHostRegex($compiled);
        $hostPattern = null;
        $hostValue = $route->getHost();
        $hasHostRequirement = '' !== $hostValue;
        $hostUnsupported = false;

        if ($hasHostRequirement && null === $hostRegex) {
            $hostUnsupported = true;
            $routesWithUnsupportedHosts[] = $name;
        } elseif ($hasHostRequirement) {
            try {
                $hostNormalization = $this->normalizePattern($hostRegex);
            } catch (\Throwable) {
                $hostNormalization = null;
                $hostUnsupported = true;
                $routesWithUnsupportedHosts[] = $name;
            }

            if (null !== $hostNormalization && [] !== $hostNormalization['unsupportedFlags']) {
                $hostUnsupported = true;
                $routesWithUnsupportedHosts[] = $name;
            } elseif (null !== $hostNormalization) {
                $hostPattern = $hostNormalization['pattern'];
            }
        }

        $condition = $route->getCondition();
        $hasCondition = '' !== trim($condition);
        if ($hasCondition) {
            $routesWithConditions[] = $name;
        }

        return [
            'name' => $name,
            'path' => $route->getPath(),
            'pathPattern' => $pathNormalization['pattern'],
            'staticPrefix' => $this->resolveStaticPrefix($compiled, $route),
            'staticSegments' => $this->extractStaticSegments($route->getPath()),
            'methods' => $this->normalizeMethods($route->getMethods()),
            'schemes' => $this->normalizeSchemes($route->getSchemes()),
            'hostPattern' => $hostPattern,
            'hasHostRequirement' => $hasHostRequirement,
            'hostUnsupported' => $hostUnsupported,
            'hasCondition' => $hasCondition,
            'index' => $index,
        ];
    }

    private function resolveHostRegex(object $compiled): ?string
    {
        if (!method_exists($compiled, 'getHostRegex')) {
            return null;
        }

        $hostRegex = $compiled->getHostRegex();
        if (!\is_string($hostRegex) || '' === $hostRegex) {
            return null;
        }

        return $hostRegex;
    }

    private function resolveStaticPrefix(object $compiled, Route $route): string
    {
        if (method_exists($compiled, 'getStaticPrefix')) {
            $prefix = $compiled->getStaticPrefix();
            if (\is_string($prefix)) {
                return $prefix;
            }
        }

        return $route->getPath();
    }

    /**
     * @return array{pattern: string, ignoredFlags: array<int, string>, unsupportedFlags: array<int, string>}
     */
    private function normalizePattern(string $regex): array
    {
        $pattern = DelimitedPattern::fromDelimited($regex);
        $flags = $pattern->flags;
        $normalizedFlags = '';
        $ignored = [];
        $unsupported = [];

        foreach (\str_split($flags) as $flag) {
            if (\in_array($flag, self::SUPPORTED_FLAGS, true)) {
                $normalizedFlags .= $flag;

                continue;
            }

            if (\in_array($flag, self::IGNORED_FLAGS, true)) {
                $ignored[] = $flag;

                continue;
            }

            $unsupported[] = $flag;
        }

        $normalized = DelimitedPattern::fromRaw($pattern->pattern, $normalizedFlags, $pattern->delimiter);

        return [
            'pattern' => $normalized->toString(),
            'ignoredFlags' => $ignored,
            'unsupportedFlags' => array_values(array_unique($unsupported)),
        ];
    }

    /**
     * @phpstan-param RouteDescriptor $left
     * @phpstan-param RouteDescriptor $right
     */
    private function hostsOverlap(
        array $left,
        array $right,
        SolverOptions $options,
        int &$skippedPairs,
        int &$hostChecks,
        int &$hostIntersections,
        int &$hostSkipped,
    ): bool {
        if (!$left['hasHostRequirement'] || !$right['hasHostRequirement']) {
            return true;
        }

        $hostChecks++;

        if ($left['hostUnsupported'] || $right['hostUnsupported']) {
            $hostSkipped++;

            return true;
        }

        if (null === $left['hostPattern'] || null === $right['hostPattern']) {
            $skippedPairs++;
            $hostSkipped++;

            return true;
        }

        try {
            $hostIntersections++;
            $intersection = $this->solver->intersection($left['hostPattern'], $right['hostPattern'], $options);
        } catch (ComplexityException) {
            $skippedPairs++;
            $hostSkipped++;

            return true;
        }

        return !$intersection->isEmpty;
    }

    /**
     * @phpstan-param RouteDescriptor $left
     * @phpstan-param RouteDescriptor $right
     *
     * @return array<int, string>
     */
    private function buildNotes(array $left, array $right): array
    {
        $notes = [];

        if ($left['hasCondition'] || $right['hasCondition']) {
            $notes[] = 'Route conditions were not evaluated.';
        }

        if ($left['hostUnsupported'] || $right['hostUnsupported']) {
            $notes[] = 'Host requirements were not evaluated.';
        }

        return $notes;
    }

    /**
     * @param array<int|string, string> $methods
     *
     * @return array<int, string>
     */
    private function normalizeMethods(array $methods): array
    {
        if ([] === $methods) {
            return [];
        }

        $normalized = [];
        foreach ($methods as $method) {
            if (!\is_string($method) || '' === $method) {
                continue;
            }
            $normalized[] = strtoupper($method);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int|string, string> $schemes
     *
     * @return array<int, string>
     */
    private function normalizeSchemes(array $schemes): array
    {
        if ([] === $schemes) {
            return [];
        }

        $normalized = [];
        foreach ($schemes as $scheme) {
            if (!\is_string($scheme) || '' === $scheme) {
                continue;
            }
            $normalized[] = strtolower($scheme);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     *
     * @return array<int, string>
     */
    private function intersectMethods(array $left, array $right): array
    {
        if ([] === $left && [] === $right) {
            return [];
        }

        if ([] === $left) {
            return array_values($right);
        }

        if ([] === $right) {
            return array_values($left);
        }

        return array_values(array_intersect($left, $right));
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     *
     * @return array<int, string>
     */
    private function intersectSchemes(array $left, array $right): array
    {
        if ([] === $left && [] === $right) {
            return [];
        }

        if ([] === $left) {
            return array_values($right);
        }

        if ([] === $right) {
            return array_values($left);
        }

        return array_values(array_intersect($left, $right));
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     */
    private function methodsOverlap(array $left, array $right): bool
    {
        if ([] === $left || [] === $right) {
            return true;
        }

        return [] !== array_intersect($left, $right);
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     */
    private function schemesOverlap(array $left, array $right): bool
    {
        if ([] === $left || [] === $right) {
            return true;
        }

        return [] !== array_intersect($left, $right);
    }

    private function prefixOverlaps(string $left, string $right): bool
    {
        if ('' === $left || '' === $right) {
            return true;
        }

        return str_starts_with($left, $right) || str_starts_with($right, $left);
    }

    /**
     * @return array<int, string>
     */
    private function extractStaticSegments(string $path): array
    {
        $trimmed = trim($path, '/');
        if ('' === $trimmed) {
            return [];
        }

        $segments = explode('/', $trimmed);
        $staticSegments = [];

        foreach ($segments as $segment) {
            if ('' === $segment || str_contains($segment, '{')) {
                continue;
            }
            $staticSegments[] = $segment;
        }

        return $staticSegments;
    }

    /**
     * Sorts routes by specificity to match Symfony's best-match routing algorithm.
     *
     * Routes with more static characters come first, then more static segments.
     * Routes with variable segments are more specific than routes without.
     * Original index is used as tiebreaker to preserve declaration order.
     *
     * @phpstan-param array<int, RouteDescriptor> $routes
     *
     * @phpstan-return array<int, RouteDescriptor>
     */
    private function sortBySpecificity(array $routes): array
    {
        usort($routes, function (array $a, array $b): int {
            $staticPrefixA = $a['staticPrefix'];
            $staticPrefixB = $b['staticPrefix'];
            $prefixLenA = \strlen($staticPrefixA);
            $prefixLenB = \strlen($staticPrefixB);

            if ($prefixLenA !== $prefixLenB) {
                return $prefixLenB <=> $prefixLenA;
            }

            $segmentsA = \count($a['staticSegments']);
            $segmentsB = \count($b['staticSegments']);

            if ($segmentsA !== $segmentsB) {
                return $segmentsB <=> $segmentsA;
            }

            $variableSegmentsA = $this->countVariableSegments($a['path']);
            $variableSegmentsB = $this->countVariableSegments($b['path']);

            if ($variableSegmentsA !== $variableSegmentsB) {
                return $variableSegmentsB <=> $variableSegmentsA;
            }

            return $a['index'] <=> $b['index'];
        });

        return $routes;
    }

    private function countVariableSegments(string $path): int
    {
        preg_match_all('/\{[^}]+\}/', $path, $matches);

        return \count($matches[0]);
    }

    private function resolveMinimizationAlgorithm(): MinimizationAlgorithm
    {
        $normalized = \strtolower(\trim($this->minimizationAlgorithm));
        $algorithm = MinimizationAlgorithm::tryFrom($normalized);

        return $algorithm ?? MinimizationAlgorithm::Hopcroft;
    }

    private function resolveDeterminizationAlgorithm(): DeterminizationAlgorithm
    {
        $normalized = \strtolower(\trim($this->determinizationAlgorithm));
        $algorithm = DeterminizationAlgorithm::tryFrom($normalized);

        return $algorithm ?? DeterminizationAlgorithm::SubsetIndexed;
    }
}
