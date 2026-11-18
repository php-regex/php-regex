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
use PHPRegex\Parser\Engine\LibraryPcre;
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

    /**
     * The methods or the schemes of a route that lists some, every one of
     * them empty, as setMethods(['']) does: Symfony's matchers send no
     * request to it, where a route that lists none takes any.
     */
    private const NO_REQUEST = [''];

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

                // The matchers check the trailing slash of a route before
                // its scheme: an earlier route on other schemes still stops
                // the GET and HEAD requests it would redirect.
                $schemesOverlap = $this->schemesOverlap($earlier['schemes'], $later['schemes']);
                if (!$schemesOverlap && !$this->redirects($earlier, $later)) {
                    $pairsFilteredSchemes++;

                    continue;
                }

                // A fast reject: two literal prefixes neither of which
                // starts the other cannot begin the same path.
                if (!$this->prefixOverlaps($earlier['staticPrefix'], $later['staticPrefix'])) {
                    $pairsFilteredPrefix++;

                    continue;
                }

                $hostsOverlap = $this->hostsOverlap(
                    $earlier,
                    $later,
                    $options,
                    $skippedPairs,
                    $hostChecks,
                    $hostIntersections,
                    $hostSkipped,
                );
                if (false === $hostsOverlap) {
                    continue;
                }

                try {
                    $pathIntersections++;
                    $intersection = $this->solver->intersection($earlier['pathPattern'], $later['pathPattern'], $options);
                    // On disjoint schemes, each route keeps the paths both
                    // match for its own scheme: only the redirect is read,
                    // which takes the later route's other paths. The path
                    // the matcher redirects for an earlier route ending on
                    // a variable that may end on the slash is PCRE's
                    // ordered choice, which no language reads: the pair is
                    // left out.
                    if (!$schemesOverlap && self::endsOnAVariable($earlier['path']) && $this->lastVariableMayEndOnTheSlash($earlier, $options)) {
                        $skippedPairs++;

                        continue;
                    }

                    if ($intersection->isEmpty || !$schemesOverlap) {
                        $redirect = $intersection->isEmpty
                            ? $this->redirectConflict($earlier, $later, $options, null === $hostsOverlap)
                            : $this->redirectBesideSharedPaths($earlier, $later, $options, null === $hostsOverlap, $skippedPairs);
                        if (null !== $redirect && 'shadowed' === $redirect['type']) {
                            $shadowed++;
                            $conflicts[] = $redirect;
                        } elseif (null !== $redirect) {
                            $overlaps++;
                            if ($includeOverlaps) {
                                $conflicts[] = $redirect;
                            }
                        }

                        continue;
                    }

                    $pathSubsets++;
                    $equivalence = $this->solver->equivalent($earlier['pathPattern'], $later['pathPattern'], $options);
                    $redirectsTheRest = null !== $equivalence->rightOnlyExample && $this->redirectTakesTheRest($earlier, $later, $options);
                    $passesSomeOn = null === $equivalence->rightOnlyExample && $this->passesOnAPathEndingOnTheSlash($earlier, $later, $options);
                } catch (ComplexityException) {
                    $skippedPairs++;

                    continue;
                }

                // A later route the earlier one matches in part and
                // redirects for the rest is shadowed: a path of it the
                // earlier route does not match is one the redirect takes.
                if ($redirectsTheRest) {
                    $shadowed++;
                    $conflicts[] = $this->redirected($earlier, $later, 'shadowed', $equivalence->rightOnlyExample, null === $hostsOverlap);

                    continue;
                }

                $notes = $this->buildNotes($earlier, $later, null === $hostsOverlap);

                // The later route is shadowed when the earlier one takes
                // every request it takes: every path, and its methods, its
                // schemes and its host. An earlier route whose paths are a
                // strict part of the later one's is declared first on
                // purpose: both stay reachable. Any other pair sharing a
                // path overlaps: each route keeps some requests, or may, as
                // PCRE captures the variable the earlier route ends on.
                $laterPathInEarlier = null === $equivalence->rightOnlyExample;
                $earlierPathInLater = null === $equivalence->leftOnlyExample;
                $laterTakesEarlier = $earlierPathInLater && $this->takesEveryRequestOf($later, $earlier, $options);
                if ($laterPathInEarlier && !$passesSomeOn && $this->takesEveryRequestOf($earlier, $later, $options)) {
                    $shadowed++;
                    $conflictType = 'shadowed';
                    if ($equivalence->isEquivalent) {
                        $equivalent++;
                        $notes[] = 'Equivalent route patterns.';
                    }
                    $notes[] = 'Less specific route declared BEFORE more specific route.';
                } elseif ($earlierPathInLater && !$laterPathInEarlier) {
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
                    'laterTakesEarlier' => $laterTakesEarlier,
                    'redirected' => false,
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
            'slashPattern' => $this->normalizePattern(self::slashToggled($pathRegex))['pattern'],
            'lastRequirement' => self::lastRequirement($compiled),
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

    /**
     * Whether the path ends on a variable, its slash aside, as the
     * framework's matcher dumper reads it to match the path with the slash
     * or without it.
     */
    private static function endsOnAVariable(string $path): bool
    {
        return 1 === LibraryPcre::match('#\{[\w\x80-\xFF]+\}/?$#', $path);
    }

    /**
     * The requirement of the last variable of the path, as the compiler
     * wrote it into the route regex, the default "[^/]++" included. Null
     * when the compiled route names no variable.
     */
    private static function lastRequirement(CompiledRoute $compiled): ?string
    {
        // The compiled route lists its tokens last first.
        foreach ($compiled->getTokens() as $token) {
            if (\is_array($token) && 'variable' === ($token[0] ?? null) && \is_string($token[2] ?? null)) {
                return $token[2];
            }
        }

        return null;
    }

    /**
     * Whether a value of the variable the route ends on may end on the
     * slash, as the framework's matcher reads it, an empty value as ending
     * on it: the matcher redirects a path ending on the slash, or not, as
     * PCRE's ordered choice captured the value. When no value of the
     * requirement ends on the slash, "[^/]++" or "\d+", it redirects every
     * path that differs from one of the route by its trailing slash,
     * whatever that order. A requirement the compiled route does not name
     * may.
     *
     * @phpstan-param RouteDescriptor $route
     *
     * @throws ComplexityException When the solver gives up on the requirement
     */
    private function lastVariableMayEndOnTheSlash(array $route, SolverOptions $options): bool
    {
        if (null === $route['lastRequirement']) {
            return true;
        }

        $flags = DelimitedPattern::fromDelimited($route['pathPattern'])->flags;

        return $this->sharesAString((string) new DelimitedPattern('^(?:'.$route['lastRequirement'].')$', $flags, '{'), '^(?:.*/)?$', $options);
    }

    /**
     * Whether the earlier route may pass a request of the later one on to
     * the routes after it, as PCRE captures the variable it ends on: with
     * no slash after it, and a requirement that may end on the slash, it
     * takes a path ending on the slash as is when the value captured ends
     * on it, and redirects it otherwise, which it does for GET and HEAD
     * alone. The later route may then keep a request of another method on
     * a path of its own that ends on the slash, "/" aside, which the
     * matcher never reads for its slash.
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     *
     * @throws ComplexityException When the solver gives up on a path or the requirement
     */
    private function passesOnAPathEndingOnTheSlash(array $earlier, array $later, SolverOptions $options): bool
    {
        return !str_ends_with($earlier['path'], '/')
            && self::endsOnAVariable($earlier['path'])
            && !$this->listHolds(['GET', 'HEAD'], $later['methods'])
            && $this->sharesAString($later['pathPattern'], '^.+/$', $options)
            && $this->lastVariableMayEndOnTheSlash($earlier, $options);
    }

    /**
     * Whether the pattern matches a string the regex matches, the regex
     * read under the pattern's flags.
     *
     * @throws ComplexityException When the solver gives up on the pattern
     */
    private function sharesAString(string $pattern, string $regex, SolverOptions $options): bool
    {
        $flags = DelimitedPattern::fromDelimited($pattern)->flags;

        return !$this->solver->intersection($pattern, (string) new DelimitedPattern($regex, $flags, '{'), $options)->isEmpty;
    }

    /**
     * The paths that differ from those of the regex by their trailing slash:
     * the slash before the closing anchor is dropped, or one is added.
     */
    private static function slashSwapped(string $regex): string
    {
        $end = strrpos($regex, '$');
        $slash = false !== $end && $end > 0 && '/' === $regex[$end - 1] ? 1 : 0;

        return false === $end ? $regex : substr_replace($regex, 1 === $slash ? '$' : '/$', $end - $slash, 1 + $slash);
    }

    /**
     * The route regex as the matchers try it on a GET or HEAD request,
     * with its trailing slash or without it, as UrlMatcher::matchCollection()
     * rewrites it: "$" becomes "/?$", the slash before it dropped. A regex
     * of the application's own with no "$" is left as it is.
     */
    private static function slashToggled(string $regex): string
    {
        $end = strrpos($regex, '$');
        $slash = false !== $end && $end > 0 && '/' === $regex[$end - 1] ? 1 : 0;

        return false === $end ? $regex : substr_replace($regex, '/?$', $end - $slash, 1 + $slash);
    }

    /**
     * Whether the framework's matcher may send a request of the later route
     * to the earlier one for its trailing slash: it stops a GET or HEAD
     * request whose path differs from the path of an earlier route for GET
     * by the slash alone, before it checks the scheme, and tries no later
     * route. A route given only empty schemes takes no request.
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     */
    private function redirects(array $earlier, array $later): bool
    {
        return self::NO_REQUEST !== $earlier['schemes']
            && self::NO_REQUEST !== $later['schemes']
            && $this->listHolds($earlier['methods'], ['GET'])
            && $this->methodsOverlap(['GET', 'HEAD'], $later['methods']);
    }

    /**
     * The conflict of two routes whose paths share none: the framework's
     * matcher redirects a GET or HEAD request whose path differs from an
     * earlier route's by a trailing slash alone to that route, whatever
     * the scheme, and tries no later one. Null when the earlier route takes
     * no GET request, the later one no GET or HEAD request, or none of its
     * paths is redirected. The later route is shadowed when it takes only
     * GET and HEAD requests, on hosts the earlier one holds. An earlier
     * route ending on a variable that may end on the slash matches such a
     * path as is, or redirects it, as PCRE captures the value: matched as
     * is, the path keeps to the earlier route's schemes, which must then
     * hold the later route's for a shadow.
     *
     * @param bool $hostsUnread whether the solver gave up on the two hosts
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     *
     * @throws ComplexityException When the solver gives up on the paths
     *
     * @phpstan-return RouteConflict|null
     */
    private function redirectConflict(array $earlier, array $later, SolverOptions $options, bool $hostsUnread): ?array
    {
        if (!$this->redirects($earlier, $later)) {
            return null;
        }

        $redirected = $this->solver->intersection($earlier['slashPattern'], $later['pathPattern'], $options);
        if ($redirected->isEmpty) {
            return null;
        }

        $everyPath = $this->solver->subsetOf($later['pathPattern'], $earlier['slashPattern'], $options)->isSubset;
        $schemes = $everyPath && self::endsOnAVariable($earlier['path']) && $this->lastVariableMayEndOnTheSlash($earlier, $options) ? $earlier['schemes'] : [];
        $shadowed = $everyPath && $this->takesEveryRequestOf(['methods' => ['GET', 'HEAD'], 'schemes' => $schemes] + $earlier, $later, $options);

        return $this->redirected($earlier, $later, $shadowed ? 'shadowed' : 'overlap', $redirected->example, $hostsUnread);
    }

    /**
     * The conflict of two routes on disjoint schemes that share paths: the
     * later route keeps those on its own schemes, and loses to the redirect
     * a path that differs from one of the earlier route by its trailing
     * slash alone, unless it serves the path of the earlier route the
     * redirect leads to. An overlap where the shortest such path leads to
     * a path the later route does not match. Null when it has no such path,
     * or when every one leads to a path the later route matches: it matches
     * every path of the earlier one. The pair is counted among the skipped
     * ones when the shortest leads to a path the later route matches and
     * another may not: telling which takes a question on three languages.
     * The earlier route ends on no variable, or on one no value of which
     * ends on the slash: no path of its own differs from another of them by
     * the slash, and the matcher redirects every path that differs from
     * one of them by it. The pair reaches it only where redirects() holds.
     *
     * @param bool $hostsUnread whether the solver gave up on the two hosts
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     *
     * @throws ComplexityException When the solver gives up on the paths
     *
     * @phpstan-return RouteConflict|null
     */
    private function redirectBesideSharedPaths(array $earlier, array $later, SolverOptions $options, bool $hostsUnread, int &$skippedPairs): ?array
    {
        $redirected = $this->solver->intersection(self::slashSwapped($earlier['pathPattern']), $later['pathPattern'], $options);
        if ($redirected->isEmpty || null === $redirected->example) {
            return null;
        }

        if (!$this->sharesAString($later['pathPattern'], '^'.preg_quote(self::landing($redirected->example), '{').'$', $options)) {
            return $this->redirected($earlier, $later, 'overlap', $redirected->example, $hostsUnread);
        }

        if (!$this->solver->subsetOf($earlier['pathPattern'], $later['pathPattern'], $options)->isSubset) {
            $skippedPairs++;
        }

        return null;
    }

    /**
     * The path the framework's matcher redirects a request to for its
     * trailing slash: the slashes trimmed off its path, or one added.
     */
    private static function landing(string $path): string
    {
        $trimmed = rtrim($path, '/');

        return $trimmed === $path ? $path.'/' : $trimmed;
    }

    /**
     * Whether the earlier route takes every request of a later one whose
     * paths it matches only in part, redirecting the rest: the later route
     * takes only GET and HEAD requests, on schemes and hosts the earlier
     * route takes, and each of its paths is a path of the earlier route or
     * differs from one by a trailing slash alone. A redirect the solver
     * gives up on leaves the pair to the paths both match.
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     */
    private function redirectTakesTheRest(array $earlier, array $later, SolverOptions $options): bool
    {
        if (!$this->redirects($earlier, $later) || !$this->takesEveryRequestOf(['methods' => ['GET', 'HEAD']] + $earlier, $later, $options)) {
            return false;
        }

        try {
            return $this->solver->subsetOf($later['pathPattern'], $earlier['slashPattern'], $options)->isSubset;
        } catch (ComplexityException) {
            return false;
        }
    }

    /**
     * A conflict the trailing-slash redirect makes, scoped to the GET and
     * HEAD requests of the later route.
     *
     * @param bool $hostsUnread whether the solver gave up on the two hosts
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     *
     * @phpstan-return RouteConflict
     */
    private function redirected(array $earlier, array $later, string $type, ?string $example, bool $hostsUnread): array
    {
        return [
            'route' => $earlier,
            'conflict' => $later,
            'type' => $type,
            'example' => $example,
            'equivalent' => false,
            'methods' => $this->intersectMethods(['GET', 'HEAD'], $later['methods']),
            'schemes' => $later['schemes'],
            'notes' => [...$this->buildNotes($earlier, $later, $hostsUnread), 'GET and HEAD requests differing by a trailing slash are redirected to the earlier route.'],
            'laterTakesEarlier' => false,
            'redirected' => true,
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
     * Whether the hosts of the two routes can match the same name: null
     * when the solver gives up on them, which leaves the pair compared on
     * its paths with its hosts unread.
     *
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
    ): ?bool {
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

            return null;
        }

        return !$intersection->isEmpty;
    }

    /**
     * Whether every request the inner route takes, the outer route takes
     * too, but for the path: no method, scheme or host of its own, or one
     * that holds the inner route's. The matchers send a HEAD request to a
     * route for GET. A host the analysis cannot read, or one the solver
     * gives up on, leaves the paths to decide, as it does when the hosts
     * are intersected.
     *
     * @phpstan-param RouteDescriptor $outer
     * @phpstan-param RouteDescriptor $inner
     */
    private function takesEveryRequestOf(array $outer, array $inner, SolverOptions $options): bool
    {
        $methods = \in_array('GET', $outer['methods'], true) ? [...$outer['methods'], 'HEAD'] : $outer['methods'];
        if (!$this->listHolds($methods, $inner['methods']) || !$this->listHolds($outer['schemes'], $inner['schemes'])) {
            return false;
        }

        if (!$outer['hasHostRequirement']) {
            return true;
        }

        if (!$inner['hasHostRequirement']) {
            return false;
        }

        if ($outer['hostUnsupported'] || $inner['hostUnsupported']) {
            return true;
        }

        try {
            return null === $this->solver->equivalent($outer['hostPattern'], $inner['hostPattern'], $options)->rightOnlyExample;
        } catch (ComplexityException) {
            return true;
        }
    }

    /**
     * Whether a list of methods or schemes takes every value the inner one
     * takes: an empty list takes them all.
     *
     * @param array<int, string> $outer
     * @param array<int, string> $inner
     */
    private function listHolds(array $outer, array $inner): bool
    {
        if ([] === $outer) {
            return true;
        }

        return [] !== $inner && [] === array_diff($inner, $outer);
    }

    /**
     * @param bool $hostsUnread whether the solver gave up on the two hosts
     *
     * @phpstan-param RouteDescriptor $left
     * @phpstan-param RouteDescriptor $right
     *
     * @return array<int, string>
     */
    private function buildNotes(array $left, array $right, bool $hostsUnread): array
    {
        $notes = [];

        if ($left['hasCondition'] || $right['hasCondition']) {
            $notes[] = 'Route conditions were not evaluated.';
        }

        if ($hostsUnread || $left['hostUnsupported'] || $right['hostUnsupported']) {
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

        return [] === $normalized ? self::NO_REQUEST : array_values(array_unique($normalized));
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

        return [] === $normalized ? self::NO_REQUEST : array_values(array_unique($normalized));
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

        return $this->sharedMethods($left, $right);
    }

    /**
     * The methods two routes that list some both take. Symfony's matchers
     * send a HEAD request to a route for GET: a route for HEAD shares its
     * requests with a route for GET.
     *
     * @param array<int, string> $left
     * @param array<int, string> $right
     *
     * @return list<string>
     */
    private function sharedMethods(array $left, array $right): array
    {
        $methods = array_values(array_intersect($left, $right));
        if (!\in_array('HEAD', $methods, true) && ((\in_array('HEAD', $left, true) && \in_array('GET', $right, true)) || (\in_array('GET', $left, true) && \in_array('HEAD', $right, true)))) {
            $methods[] = 'HEAD';
        }

        return $methods;
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
        if (self::NO_REQUEST === $left || self::NO_REQUEST === $right) {
            return false;
        }

        if ([] === $left || [] === $right) {
            return true;
        }

        return [] !== $this->sharedMethods($left, $right);
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     */
    private function schemesOverlap(array $left, array $right): bool
    {
        if (self::NO_REQUEST === $left || self::NO_REQUEST === $right) {
            return false;
        }

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
