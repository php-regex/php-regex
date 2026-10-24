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

namespace PHPRegex\Symfony\Routing;

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Minimization\MinimizationAlgorithm;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Routing\CompiledRoute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Detects Symfony route conflicts with the PHPRegex automata.
 *
 * @internal
 *
 * @phpstan-import-type RouteDescriptor from RouteConflictReport
 * @phpstan-import-type RouteConflict from RouteConflictReport
 * @phpstan-import-type RouteSkip from RouteConflictReport
 */
final class RouteConflictAnalyzer
{
    private const SUPPORTED_FLAGS = ['i', 's', 'u'];

    private const IGNORED_FLAGS = ['D'];

    private readonly LanguageSolver $solver;

    public function __construct(
        private readonly Regex $regex,
        private readonly string $minimizationAlgorithm = 'hopcroft',
        ?LanguageSolver $solver = null,
        private readonly string $determinizationAlgorithm = 'subset-indexed',
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
                $skippedRoutes,
                $routesWithConditions,
                $routesWithUnsupportedHosts,
            );
            if (null !== $descriptor) {
                $routes[] = $descriptor;
            }
        }

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

        // The router tries the routes in the order they were declared and
        // stops at the first match: of two routes sharing a path, the
        // earlier one always wins it. Each pair is therefore read earlier
        // route first.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $earlier = $routes[$i];
                $later = $routes[$j];
                $pairsTotal++;

                if (!$this->methodsOverlap($earlier['methods'], $later['methods'])) {
                    $pairsFilteredMethods++;

                    continue;
                }

                if (!$this->schemesOverlap($earlier['schemes'], $later['schemes'])) {
                    $pairsFilteredSchemes++;

                    continue;
                }

                // A fast reject: two literal prefixes neither of which
                // starts the other cannot begin the same path.
                if (!$this->prefixOverlaps($earlier['staticPrefix'], $later['staticPrefix'])) {
                    $pairsFilteredPrefix++;

                    continue;
                }

                if (!$this->hostsOverlap(
                    $earlier,
                    $later,
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
                    $intersection = $this->solver->intersection($earlier['pathPattern'], $later['pathPattern'], $options);
                    if ($intersection->isEmpty) {
                        continue;
                    }

                    $pathSubsets++;
                    $equivalence = $this->solver->equivalent($earlier['pathPattern'], $later['pathPattern'], $options);
                } catch (ComplexityException) {
                    $skippedPairs++;

                    continue;
                }

                $notes = $this->buildNotes($earlier, $later);

                if (null === $equivalence->rightOnlyExample) {
                    // Every path of the later route goes to the earlier one.
                    $shadowed++;
                    $conflictType = 'shadowed';
                    if ($equivalence->isEquivalent) {
                        $equivalent++;
                        $notes[] = 'Equivalent route patterns.';
                    }
                    $notes[] = 'Less specific route declared BEFORE more specific route.';
                } elseif (null === $equivalence->leftOnlyExample) {
                    // The earlier route is a part of the later one, declared
                    // first on purpose: both stay reachable.
                    continue;
                } else {
                    $overlaps++;

                    if (!$includeOverlaps) {
                        continue;
                    }

                    $conflictType = 'overlap';
                    $notes[] = 'The earlier route wins every path both routes match.';
                }

                $conflicts[] = [
                    'route' => $earlier,
                    'conflict' => $later,
                    'type' => $conflictType,
                    'example' => $intersection->example,
                    'equivalent' => $equivalence->isEquivalent,
                    'methods' => $this->intersectMethods($earlier['methods'], $later['methods']),
                    'schemes' => $this->intersectSchemes($earlier['schemes'], $later['schemes']),
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
        array &$skippedRoutes,
        array &$routesWithConditions,
        array &$routesWithUnsupportedHosts,
    ): ?array {
        try {
            $compiled = $route->compile();
        } catch (\Throwable $exception) {
            // A boundary: the route and its compiler belong to the
            // application, and whatever they throw only skips that route.
            $skippedRoutes[] = [
                'route' => $name,
                'reason' => 'Route compile failed: '.$exception->getMessage(),
            ];

            return null;
        }

        $pathRegex = $compiled->getRegex();

        try {
            $pathNormalization = $this->normalizePattern($pathRegex);
        } catch (ExceptionInterface $exception) {
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
        $hostPattern = '';
        $hostValue = $route->getHost();
        $hasHostRequirement = '' !== $hostValue;
        $hostUnsupported = false;

        if ($hasHostRequirement && null === $hostRegex) {
            $hostUnsupported = true;
            $routesWithUnsupportedHosts[] = $name;
        } elseif ($hasHostRequirement) {
            try {
                $hostNormalization = $this->normalizePattern($hostRegex);
            } catch (ExceptionInterface) {
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
        $hasCondition = '' !== trim($condition, " \t\n\r\0\x0B");
        if ($hasCondition) {
            $routesWithConditions[] = $name;
        }

        return [
            'name' => $name,
            'path' => $route->getPath(),
            'pathPattern' => $pathNormalization['pattern'],
            'staticPrefix' => $this->resolveStaticPrefix($compiled),
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

    private function resolveHostRegex(CompiledRoute $compiled): ?string
    {
        $hostRegex = $compiled->getHostRegex();
        if (null === $hostRegex || '' === $hostRegex) {
            return null;
        }

        return $hostRegex;
    }

    private function resolveStaticPrefix(CompiledRoute $compiled): string
    {
        return $compiled->getStaticPrefix();
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

        $normalized = new DelimitedPattern($pattern->pattern, $normalizedFlags, $pattern->delimiter);

        return [
            'pattern' => (string) $normalized,
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

    private function resolveMinimizationAlgorithm(): MinimizationAlgorithm
    {
        $normalized = \strtolower(\trim($this->minimizationAlgorithm, " \t\n\r\0\x0B"));
        $algorithm = MinimizationAlgorithm::tryFrom($normalized);

        return $algorithm ?? MinimizationAlgorithm::Hopcroft;
    }

    private function resolveDeterminizationAlgorithm(): DeterminizationAlgorithm
    {
        $normalized = \strtolower(\trim($this->determinizationAlgorithm, " \t\n\r\0\x0B"));
        $algorithm = DeterminizationAlgorithm::tryFrom($normalized);

        return $algorithm ?? DeterminizationAlgorithm::SubsetIndexed;
    }
}
