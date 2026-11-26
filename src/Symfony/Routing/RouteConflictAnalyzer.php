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
use PHPRegex\Automata\Ordered\FirstMatchCandidate;
use PHPRegex\Automata\Ordered\FirstMatchPartition;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Routing\CompiledRoute;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
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
 * @phpstan-import-type RouteReach from RouteReachabilityReport
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

    /**
     * The requirements of a host variable symfony/routing reads as matching
     * no dot since 6.4.51, 7.4.24 and 8.1.12: word characters, "\d", "\w",
     * classes of them, groups, alternatives and quantifiers.
     */
    private const MATCHES_NO_DOT = '#^(?:[\w|?*+{},:)-]|\((?!\?)|\(\?[:|]|\\\\[dw]|\[(?:\w|\\\\[dw]|(?<=\w)-(?=\w)|(?<=\[)-|-(?=\]))++\])++$#';

    private static ?bool $trimsEveryTrailingSlash = null;

    private static ?bool $groupsHostsOnAnyVariable = null;

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
        $lookedUpByPath = self::routesLookedUpByPath($collection);
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
                $lookedUpByPath,
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
        // route first, but for a route it looks up by its path, which it
        // reads before any route it tries with a regex, whatever their
        // order: such a pair is read in the matcher's order.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                [$earlier, $later] = !$routes[$i]['static'] && $routes[$j]['static'] ? [$routes[$j], $routes[$i]] : [$routes[$i], $routes[$j]];
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

                if (self::mayBeTriedFirst($earlier, $later)) {
                    $skippedPairs++;

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
                    $keepsAPath = null === $equivalence->rightOnlyExample && $this->keepsAPathWithOneMoreSlash($earlier, $later, $options);
                    $laterFirstRedirectsEarlier = null !== $equivalence->leftOnlyExample && $this->redirectsEveryPathOf($later, $earlier, $options);
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
                // strict part of the later one's, or whose requests are all
                // among the later one's, is declared first on purpose, a
                // carve-out: both stay reachable. Any other pair sharing a
                // path overlaps: each route keeps some requests, or may, as
                // PCRE captures the variable the earlier route ends on. So
                // does a later route that keeps a path its regex does not
                // match, the one the "/?" of the dumper ends.
                $laterPathInEarlier = null === $equivalence->rightOnlyExample;
                $earlierPathInLater = null === $equivalence->leftOnlyExample;
                $laterTakesEarlier = $earlierPathInLater && $this->takesEveryRequestOf($later, $earlier, $options);
                $takesEveryRequest = $laterPathInEarlier && !$passesSomeOn && $this->takesEveryRequestOf($earlier, $later, $options);
                if ($takesEveryRequest && !$keepsAPath) {
                    $shadowed++;
                    $conflictType = 'shadowed';
                    if ($equivalence->isEquivalent) {
                        $equivalent++;
                        $notes[] = 'Equivalent route patterns.';
                    }
                    $notes[] = 'Less specific route declared BEFORE more specific route.';
                } elseif (!$takesEveryRequest && (($earlierPathInLater && !$laterPathInEarlier) || $laterTakesEarlier)) {
                    // A carve-out, but the earlier route redirects for the
                    // trailing slash a GET or HEAD request of the later one
                    // it does not match: "/admin" before a catch-all takes
                    // "/admin/".
                    try {
                        $lost = $this->redirects($earlier, $later) && !(self::endsOnAVariable($earlier['path']) && $this->lastVariableMayEndOnTheSlash($earlier, $options)) ? $this->lostToTheRedirect($earlier, $later, $options) : null;
                    } catch (ComplexityException) {
                        $skippedPairs++;
                        $lost = null;
                    }

                    if (null !== $lost) {
                        $overlaps++;
                        if ($includeOverlaps) {
                            $conflicts[] = $this->redirected($earlier, $later, 'overlap', $lost, null === $hostsOverlap);
                        }
                    }

                    continue;
                } elseif (!$takesEveryRequest && $this->passesOnTheExample($earlier, $later, $intersection->example)) {
                    $skippedPairs++;

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
                    'redirected' => false,
                    'laterFirstTakesEarlier' => $laterTakesEarlier || $laterFirstRedirectsEarlier,
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
     * The requests each route keeps once the routes the matcher tries
     * before it have taken theirs. A request is a method, a scheme, a host
     * and a path, joined by a separator past Unicode, never through the
     * text of their patterns; a route keeps the requests its methods
     * (HEAD with GET), schemes, host and path match, and, when it takes
     * GET, takes from the later routes the GET and HEAD requests on any
     * scheme whose path differs from one of its own by the trailing slash,
     * which the matcher redirects to whatever route matches the other. A
     * route several earlier ones shadow together, none of them alone, is
     * one the pairwise analysis cannot see.
     *
     * The routes the matcher looks up by their path come first. A route
     * with a condition, or with a host the analysis cannot read, may take
     * nothing: it shadows no other. An earlier route on a host the matcher
     * may sort after the later one's shadows nothing either.
     */
    public function analyzeReachability(RouteCollection $collection): RouteReachabilityReport
    {
        $options = new SolverOptions(matchMode: MatchMode::Full, minimizeDfa: false);
        $lookedUpByPath = self::routesLookedUpByPath($collection);
        $skipped = $conditions = $hosts = [];
        /** @var list<RouteDescriptor> $routes */
        $routes = [];
        $index = 0;
        foreach ($collection as $name => $route) {
            $descriptor = $this->buildDescriptor((string) $name, $route, ++$index, $skipped, $conditions, $hosts, $lookedUpByPath);
            if (null !== $descriptor) {
                $routes[] = $descriptor;
            }
        }

        usort($routes, static fn (array $left, array $right): int => [!$left['static'], $left['index']] <=> [!$right['static'], $right['index']]);

        $requests = [];
        $candidates = [];
        $pairs = 0;
        foreach ($routes as $i => $route) {
            $requests[$i] = $this->requestsOf($route);
            if (null === $requests[$i]) {
                $candidates[] = null;

                continue;
            }

            $rivals = [];
            for ($j = 0; $j < $i; $j++) {
                $earlier = $routes[$j];
                if (null === $requests[$j] || $earlier['hasCondition'] || !$this->prefixOverlaps($earlier['staticPrefix'], $route['staticPrefix']) || self::mayBeTriedFirst($earlier, $route)) {
                    continue;
                }

                $rivals[] = $j;
                $pairs++;
            }

            // A path it redirects for the slash is one no later route gets,
            // and one it does not serve either; one the "/?" of the dumper
            // ends after a value ending on the slash is one it may take.
            $candidates[] = new FirstMatchCandidate($requests[$i], [[...\array_slice($requests[$i][0], 0, 3), $this->pathsItMayTake($route, $options)]], $rivals);
        }

        $cells = (new FirstMatchPartition($this->solver, $options))->partition($candidates);

        $reach = [];
        $unreachable = $cumulative = $refused = 0;
        foreach ($routes as $i => $route) {
            $cell = $cells[$i] ?? null;
            if (null === $cell) {
                continue;
            }

            $takenBy = [];
            foreach ($cell->takenBy as $j => $example) {
                $takenBy[] = ['name' => $routes[$j]['name'], 'index' => $routes[$j]['index'], 'path' => $routes[$j]['path'], 'example' => self::request($example)];
            }

            $reach[] = [
                'name' => $route['name'],
                'index' => $route['index'],
                'path' => $route['path'],
                'reachable' => $cell->reachable,
                'example' => null === $cell->example ? null : self::request($cell->example),
                'takenBy' => $takenBy,
                'cumulative' => $cell->isShadowedCumulatively(),
                'refused' => $cell->refused,
            ];
            $unreachable += (int) (false === $cell->reachable);
            $cumulative += (int) $cell->isShadowedCumulatively();
            $refused += (int) (null !== $cell->refused);
        }

        return new RouteReachabilityReport($reach, [
            'routes' => $index,
            'analyzed' => \count($reach),
            'unreachable' => $unreachable,
            'cumulative' => $cumulative,
            'refused' => $refused,
            'skipped_routes' => \count($skipped),
            'pairs_compared' => $pairs,
        ]);
    }

    /**
     * @param array<RouteSkip>    $skippedRoutes
     * @param array<int, string>  $routesWithConditions
     * @param array<int, string>  $routesWithUnsupportedHosts
     * @param array<string, true> $lookedUpByPath             the names of the routes the matcher looks up by their path
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
        array $lookedUpByPath,
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

        $path = $route->getPath();
        $static = isset($lookedUpByPath[$name]);
        [$takenAsIs, $taken, $redirected] = self::slashForms($pathRegex, $path, $static);
        $slashPattern = $this->normalizePattern($taken)['pattern'];

        return [
            'name' => $name,
            'path' => $path,
            'static' => $static,
            'pathPattern' => $this->normalizePattern($takenAsIs)['pattern'],
            'slashPattern' => $slashPattern,
            'redirectPattern' => $this->normalizePattern($redirected)['pattern'],
            'lastRequirement' => self::lastRequirement($compiled),
            'staticPrefix' => $this->resolveStaticPrefix($compiled),
            'staticSegments' => $this->extractStaticSegments($route->getPath()),
            'methods' => $this->normalizeMethods($route->getMethods()),
            'schemes' => $this->normalizeSchemes($route->getSchemes()),
            'host' => $hostValue,
            'hostPattern' => $hostPattern,
            'hasHostRequirement' => $hasHostRequirement,
            'hostUnsupported' => $hostUnsupported,
            'looseHostVariables' => self::looseHostVariables($route),
            'hasCondition' => $hasCondition,
            'index' => $index,
        ];
    }

    /**
     * Whether the matcher may try the later of two routes on overlapping
     * hosts first. When a route names a host, the dumper sorts the routes
     * by the literal end of their host, letter case included, as
     * StaticPrefixCollection::addRoute() groups them, and may move a route
     * before an earlier one whose literal end parts from its own: no host
     * matches both, unless they part on letter case alone, which the
     * matcher ignores in a host.
     *
     * Before symfony/routing 6.4.51, 7.4.24 and 8.1.12, and on 8.0, the
     * dumper also groups the routes on every variable of their host, as if
     * it were the text of one label: once a route before both names
     * "app-{sub}.example.com", it tries a later route on that host before
     * an earlier one on "{sub}.example.com", which takes the same hosts.
     * It moves a route past another only on the end both hosts share,
     * variables included, the rest of each read as text that tells them
     * apart: there, two routes on distinct hosts whose shared end holds a
     * variable that takes less or more than a label, in either host, may
     * be tried in either order. Two routes on the same host keep theirs,
     * as do two routes that share no variable.
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     */
    private static function mayBeTriedFirst(array $earlier, array $later): bool
    {
        if (self::partOnLetterCaseAlone($earlier['host'], $later['host'])) {
            return true;
        }

        $loose = [...$earlier['looseHostVariables'], ...$later['looseHostVariables']];
        if ([] === $loose || $earlier['host'] === $later['host']) {
            return false;
        }

        $shared = 0;
        $longest = min(\strlen($earlier['host']), \strlen($later['host']));
        while ($shared < $longest && $earlier['host'][-1 - $shared] === $later['host'][-1 - $shared]) {
            $shared++;
        }

        return min($loose) <= $shared && self::groupsHostsOnAnyVariable();
    }

    /**
     * Whether the literal ends of the two hosts part on letter case alone.
     */
    private static function partOnLetterCaseAlone(string $earlierHost, string $laterHost): bool
    {
        $earlierEnd = self::literalEnd($earlierHost);
        $laterEnd = self::literalEnd($laterHost);
        if ('' === $earlierEnd || '' === $laterEnd || str_ends_with($earlierEnd, $laterEnd) || str_ends_with($laterEnd, $earlierEnd)) {
            return false;
        }

        return str_ends_with(strtolower($earlierEnd), strtolower($laterEnd)) || str_ends_with(strtolower($laterEnd), strtolower($earlierEnd));
    }

    /**
     * The variables of the host that take less or more than one label, as
     * symfony/routing reads them since 6.4.51, 7.4.24 and 8.1.12, where
     * the dumper no longer groups the routes on them: a variable next to
     * other characters in its label, "app-{sub}", and one whose
     * requirement may match a dot. Each is given as the number of
     * characters from where it starts to the end of the host.
     *
     * @return list<int>
     */
    private static function looseHostVariables(Route $route): array
    {
        $host = $route->getHost();
        LibraryPcre::matchAll('#\{!?([\w\x80-\xFF]++)\}#', $host, $variables, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);

        $loose = [];
        foreach ($variables as $variable) {
            [$token, $start] = $variable[0] ?? ['', 0];
            $before = 0 === $start ? '.' : $host[$start - 1];
            $after = $host[$start + \strlen($token)] ?? '.';
            $requirement = $route->getRequirement($variable[1][0] ?? '');
            if ('.' !== $before || '.' !== $after || (null !== $requirement && 1 !== LibraryPcre::match(self::MATCHES_NO_DOT, $requirement))) {
                $loose[] = \strlen($host) - $start;
            }
        }

        return $loose;
    }

    /**
     * Whether the dumper groups the routes on any variable of their host,
     * as symfony/routing does before 6.4.51, 7.4.24 and 8.1.12, and on 8.0.
     * Read on the dumper installed: whether a route on "a-{x}.b" declared
     * after one on "{x}.b" answers first, a route before both naming its
     * host.
     */
    private static function groupsHostsOnAnyVariable(): bool
    {
        if (null === self::$groupsHostsOnAnyVariable) {
            $routes = new RouteCollection();
            $routes->add('first', (new Route('/first'))->setHost('a-{x}.b'));
            $routes->add('earlier', (new Route('/p'))->setHost('{x}.b'));
            $routes->add('later', (new Route('/p'))->setHost('a-{x}.b'));
            self::$groupsHostsOnAnyVariable = 'later' === (new CompiledUrlMatcher((new CompiledUrlMatcherDumper($routes))->getCompiledRoutes(), new RequestContext('', 'GET', 'a-c.b')))->match('/p')['_route'];
        }

        return self::$groupsHostsOnAnyVariable;
    }

    /**
     * The host after its last variable.
     */
    private static function literalEnd(string $host): string
    {
        $variableEnd = strrpos($host, '}');

        return false === $variableEnd ? $host : substr($host, $variableEnd + 1);
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
     * may, as may one holding a lookaround, a boundary or an anchor, which
     * reads the route around the variable: "(?<=e)a/" is a value of it
     * after "pre", none of it alone.
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
        $readsAround = NodeFinder::findFirst(
            $this->regex->parser()->parse((string) new DelimitedPattern('(?:'.$route['lastRequirement'].')', $flags, '{'))->pattern,
            static fn (NodeInterface $node): bool => $node instanceof AnchorNode || $node instanceof AssertionNode
                || ($node instanceof GroupNode && \in_array($node->type, [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative], true)),
        );

        return null !== $readsAround
            || $this->sharesAString((string) new DelimitedPattern('^(?:'.$route['lastRequirement'].')$', $flags, '{'), '^(?:.*/)?$', $options);
    }

    /**
     * Whether the earlier route may pass a request of the later one on to
     * the routes after it, as PCRE captures the variable it ends on: with
     * no slash after it, and a requirement that may end on the slash, it
     * takes a path ending on the slash as is when the value captured ends
     * on it, and redirects it otherwise, which it does for GET and HEAD
     * alone. The later route may then keep a request of another method on
     * a path of its own that ends on the slash, "/" aside, which the
     * matcher never reads for its slash: a path of its regex, or one the
     * "/?" of the dumper ends.
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
            && $this->sharesAString($this->pathsTakenAsIs($later, $options), '^.+/$', $options)
            && $this->readsOwnPathsForTheSlash($earlier, $options);
    }

    /**
     * The paths the matcher may take as is for the route once it comes to
     * it, as a pattern: those of its regex, and, where it ends on a
     * variable a value of which may end on the slash or be empty, those
     * the "/?" the dumper writes after every route ends. The matcher takes
     * such a path as is when the value PCRE captured ends on the slash or
     * is empty: "/{p<admin/>}" takes "/admin//", "/a{y<b?>}" takes "/a/",
     * and "/{p<admin/>}/", whose slash the dumper trims off before that
     * "/?", takes "/admin/". A route with a slash after its variable
     * redirects a path that ends on none: of the paths that "/?" ends, it
     * takes those ending on a slash alone. Which of them it takes is
     * PCRE's ordered choice: the pattern holds them all, and tells what a
     * route may keep, never what it takes from another. A regex of the
     * application's own with no "$" is taken as it is.
     *
     * @phpstan-param RouteDescriptor $route
     *
     * @throws ComplexityException When the solver gives up on the requirement
     */
    private function pathsTakenAsIs(array $route, SolverOptions $options): string
    {
        if (!self::endsOnAVariable($route['path']) || !$this->lastVariableMayEndOnTheSlash($route, $options)) {
            return $route['pathPattern'];
        }

        $end = str_ends_with($route['path'], '/') ? strrpos($route['slashPattern'], '$') : false;

        return false === $end ? $route['slashPattern'] : substr_replace($route['slashPattern'], '(?<=/)', $end, 0);
    }

    /**
     * The same paths, those the "/?" of the dumper ends counted in when
     * the solver gives up on the requirement: a route is said unreachable
     * only when it keeps none of them.
     *
     * @phpstan-param RouteDescriptor $route
     */
    private function pathsItMayTake(array $route, SolverOptions $options): string
    {
        try {
            return $this->pathsTakenAsIs($route, $options);
        } catch (ComplexityException) {
            return $route['slashPattern'];
        }
    }

    /**
     * Whether the later route, every path of whose regex the earlier one
     * matches, keeps a request on a path the "/?" of the dumper ends: the
     * earlier route takes or redirects the GET and HEAD requests of the
     * paths its own "/?" ends, when it takes GET. For another method it
     * takes the paths of its regex, with those its "/?" ends on a slash
     * when it has one after its variable, which it takes as is whatever
     * PCRE captured. "/{x<a>}/" before "/{y<a/>}" leaves it "/a//", and
     * "/{x<a|ab>}" before "/a{y<b?>}" the POST requests of "/a/", which it
     * reads for its slash and passes on.
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     *
     * @throws ComplexityException When the solver gives up on a path or the requirement
     */
    private function keepsAPathWithOneMoreSlash(array $earlier, array $later, SolverOptions $options): bool
    {
        $taken = $this->pathsTakenAsIs($later, $options);
        if ($taken === $later['pathPattern']) {
            return false;
        }

        if ($this->redirects($earlier, $later) && $this->listHolds(['GET', 'HEAD'], $later['methods'])) {
            $stopped = $earlier['slashPattern'];
        } else {
            $stopped = str_ends_with($earlier['path'], '/') ? $this->pathsTakenAsIs($earlier, $options) : $earlier['pathPattern'];
        }

        return !$this->solver->subsetOf($taken, $stopped, $options)->isSubset;
    }

    /**
     * Whether one of the two routes passes on to the routes after it every
     * request of the shared path, for the methods both routes take: none
     * is GET or HEAD, and it reads the path for the slash. The earlier
     * route then takes none of them, or the later route would not have:
     * the path tells no request the later route loses. Another shared path
     * may, which the pair is left out rather than judged on.
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     */
    private function passesOnTheExample(array $earlier, array $later, ?string $path): bool
    {
        $methods = $this->intersectMethods($earlier['methods'], $later['methods']);

        return null !== $path
            && [] !== $methods
            && [] === array_intersect($methods, ['GET', 'HEAD'])
            && (self::capturesWithoutTheSlash($earlier, $path) || self::capturesWithoutTheSlash($later, $path));
    }

    /**
     * Whether the route may read a path of its own ending on the slash for
     * the slash, as PCRE captures the variable it ends on: it ends on one
     * with no slash after it, whose requirement may end on the slash.
     *
     * @phpstan-param RouteDescriptor $route
     *
     * @throws ComplexityException When the solver gives up on the requirement
     */
    private function readsOwnPathsForTheSlash(array $route, SolverOptions $options): bool
    {
        return !str_ends_with($route['path'], '/')
            && self::endsOnAVariable($route['path'])
            && $this->lastVariableMayEndOnTheSlash($route, $options);
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
     * The paths the compiled matcher takes for the route as is, takes on a
     * GET or HEAD request, as is or redirected, and redirects, as forms of
     * its regex. A route tried with a regex takes its path with one
     * trailing slash at most, the "/?" the dumper writes before "$", and
     * redirects the path with the slash it lacks, or without the one it
     * has. Where the matcher trims every trailing slash of the request off
     * before it looks a route up by its path, a route it looks up so takes
     * its path with any number of them when it ends on a slash, and
     * redirects it with none; else it redirects it with any number, "/"
     * answering those with a 404, as the root route tried with a regex
     * answers "//". Where it looks a route up for a request with one
     * trailing slash at most, the route reads its slash as a route tried
     * with a regex does, and "/" takes no path but its own. The slash of
     * "/" is no trailing one. A regex of the application's own with no "$"
     * is taken as it is.
     *
     * @return array{string, string, string}
     */
    private static function slashForms(string $regex, string $path, bool $static): array
    {
        $end = strrpos($regex, '$');
        if (false === $end) {
            return [$regex, $regex, $regex];
        }

        $slash = '/' !== $path && $end > 0 && '/' === $regex[$end - 1];
        $head = substr($regex, 0, $end - (int) $slash);
        $tail = substr($regex, $end + 1);
        $trims = self::trimsEveryTrailingSlash();
        if ('/' === $path && !$trims) {
            return [$regex, $regex, $head.'(?!)$'.$tail];
        }

        $any = $static && $trims ? '/*$' : '/?$';

        if ($slash) {
            return [$static && $trims ? $head.'/+$'.$tail : $regex, $head.$any.$tail, $head.'$'.$tail];
        }

        return [$regex, $head.$any.$tail, $head.($static && $trims ? '/+$' : '/$').$tail];
    }

    /**
     * Whether the compiled matcher trims every trailing slash of a request
     * off before it looks a route up by its path, as symfony/routing does
     * before 6.4.51, 7.4.24 and 8.1.12, and on 8.0, and reads "//" for the
     * slash of "/". Since those releases, it looks a route up for a request
     * with one trailing slash at most, tries the routes with a regex on any
     * other, and reads no trailing slash on "/". Read on the matcher
     * installed: whether "/a/" takes "/a//" before a catch-all declared
     * after it.
     */
    private static function trimsEveryTrailingSlash(): bool
    {
        if (null === self::$trimsEveryTrailingSlash) {
            $routes = new RouteCollection();
            $routes->add('a', new Route('/a/'));
            $routes->add('any', new Route('/{p<.*>}'));
            self::$trimsEveryTrailingSlash = 'a' === (new CompiledUrlMatcher((new CompiledUrlMatcherDumper($routes))->getCompiledRoutes(), new RequestContext()))->match('/a//')['_route'];
        }

        return self::$trimsEveryTrailingSlash;
    }

    /**
     * The names of the routes the compiled matcher looks up by their path,
     * before any route it tries with a regex, as the framework's dumper
     * sorts them: a route with no path variable that no route sorted
     * before it and tried with a regex matches, the routes being sorted by
     * host first when one names a host. Conditions play no part in the
     * sort, and the dumper compiles them only with ExpressionLanguage: it
     * reads a copy of the routes without them. None when the dumper refuses
     * the routes, as for a regex the engine refuses, which stops the
     * router too: every route is then read as tried with a regex.
     *
     * @return array<string, true>
     */
    private static function routesLookedUpByPath(RouteCollection $collection): array
    {
        $routes = new RouteCollection();
        foreach ($collection->all() as $name => $route) {
            $routes->add($name, (clone $route)->setCondition(''));
        }

        set_error_handler(static fn (): bool => true);

        try {
            $compiled = (new CompiledUrlMatcherDumper($routes))->getCompiledRoutes();
        } catch (\Throwable) {
            // A boundary: the routes belong to the application, and
            // whatever the dumper throws on them only drops this sort.
            return [];
        } finally {
            restore_error_handler();
        }

        $names = [];
        foreach (\is_array($compiled[1] ?? null) ? $compiled[1] : [] as $routesOfAPath) {
            foreach (\is_array($routesOfAPath) ? $routesOfAPath : [] as $compiledRoute) {
                $name = \is_array($compiledRoute) && \is_array($compiledRoute[0] ?? null) ? ($compiledRoute[0]['_route'] ?? null) : null;
                if (\is_string($name)) {
                    $names[$name] = true;
                }
            }
        }

        return $names;
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
     * GET and HEAD requests, on hosts the earlier one holds, and none on a
     * path the earlier one neither takes nor redirects: after "/{x<admin>}",
     * "/{p<admin/>}" keeps "/admin//", which the "/?" of the dumper ends
     * after a value ending on the slash. An earlier
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

        $everyPath = $this->solver->subsetOf($this->pathsTakenAsIs($later, $options), $earlier['slashPattern'], $options)->isSubset;
        $schemes = $everyPath && self::endsOnAVariable($earlier['path']) && $this->lastVariableMayEndOnTheSlash($earlier, $options) ? $earlier['schemes'] : [];
        $shadowed = $everyPath && $this->takesEveryRequestOf(['methods' => ['GET', 'HEAD'], 'schemes' => $schemes] + $earlier, $later, $options);

        return $this->redirected($earlier, $later, $shadowed ? 'shadowed' : 'overlap', $redirected->example, $hostsUnread);
    }

    /**
     * The conflict of two routes on disjoint schemes that share paths: the
     * later route keeps those on its own schemes, and loses to the redirect
     * a path that differs from one of the earlier route by its trailing
     * slashes alone, unless it serves the path of the earlier route the
     * redirect leads to: it matches it, and takes it as is rather than for
     * its own slash. An overlap where the shortest such path leads to a
     * path the later route does not serve, or to none, as "//" after "/".
     * Null when it has no such path, or when it serves every path one leads
     * to: it matches every path of the earlier one and reads none of them
     * for its slash, or serves the one path every redirect of a route
     * looked up by its path leads to. The pair is counted among the skipped
     * ones when the later route serves the path the shortest leads to and
     * may not serve another: telling which takes a question on three
     * languages, or on PCRE's ordered choice. The earlier route ends on no
     * variable, or on one no value of which ends on the slash: no path of
     * its own differs from another of them by the slash, and the matcher
     * redirects every path that differs from one of them by it. The pair
     * reaches it only where redirects() holds.
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
        $redirected = $this->solver->intersection($earlier['redirectPattern'], $later['pathPattern'], $options);
        if ($redirected->isEmpty || null === $redirected->example) {
            return null;
        }

        // The later route serves the path the redirect leads to when it
        // matches it, and takes it as is rather than for its own slash.
        $landing = self::landing($redirected->example);
        if (null === $landing || !$this->sharesAString($later['pathPattern'], '^'.preg_quote($landing, '{').'$', $options) || self::capturesWithoutTheSlash($later, $landing)) {
            return $this->redirected($earlier, $later, 'overlap', $redirected->example, $hostsUnread);
        }

        // The shortest path redirected is one the later route serves: the
        // least path it loses, if any, is one the earlier route redirects,
        // does not match as is, and whose landing the later route does not
        // serve: (L ∩ swapped(E)) \ E \ swapped(L).
        $lost = $this->lostToTheRedirect($earlier, $later, $options, true);
        if (null === $lost) {
            return null;
        }

        $landing = self::landing($lost);
        if (null === $landing || !$this->sharesAString($later['pathPattern'], '^'.preg_quote($landing, '{').'$', $options) || self::capturesWithoutTheSlash($later, $landing)) {
            return $this->redirected($earlier, $later, 'overlap', $lost, $hostsUnread);
        }

        if (!$earlier['static'] && (!$this->solver->subsetOf($earlier['pathPattern'], $later['pathPattern'], $options)->isSubset || (str_ends_with($landing, '/') && $this->readsOwnPathsForTheSlash($later, $options)))) {
            $skippedPairs++;
        }

        return null;
    }

    /**
     * The least path of the later route that the earlier one redirects
     * for its trailing slash and does not match as is: (L ∩ swapped(E)) \ E,
     * read on the languages of the two routes, null when there is none or
     * when one path is read as UTF-8 and the other as bytes. Unless served,
     * a path whose landing the later route matches is left out too, as
     * the later route keeps it on schemes of its own: \ swapped(L).
     *
     * @phpstan-param RouteDescriptor $earlier
     * @phpstan-param RouteDescriptor $later
     *
     * @throws ComplexityException When the solver gives up on the paths
     */
    private function lostToTheRedirect(array $earlier, array $later, SolverOptions $options, bool $unlessServed = false): ?string
    {
        try {
            $lost = $this->solver->language($later['pathPattern'], $options)
                ->intersect($this->solver->language($earlier['redirectPattern'], $options))
                ->minus($this->solver->language($earlier['pathPattern'], $options));

            return ($unlessServed ? $lost->minus($this->solver->language($later['redirectPattern'], $options)) : $lost)->shortestExample();
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The path the framework's matcher redirects a request to for its
     * trailing slash: every slash trimmed off its path, or one added. Null
     * where trimming leaves nothing: it answers "//" with a 404.
     */
    private static function landing(string $path): ?string
    {
        $trimmed = rtrim($path, '/');
        if ('' === $trimmed) {
            return null;
        }

        return $trimmed === $path ? $path.'/' : $trimmed;
    }

    /**
     * Whether the earlier route takes every request of a later one whose
     * paths it matches only in part, redirecting the rest: the later route
     * takes only GET and HEAD requests, on schemes and hosts the earlier
     * route takes, and each path it may take, those the "/?" of the dumper
     * ends included, is a path of the earlier route or differs from one by
     * a trailing slash alone. A redirect the solver gives up on leaves the
     * pair to the paths both match.
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
            return $this->solver->subsetOf($this->pathsTakenAsIs($later, $options), $earlier['slashPattern'], $options)->isSubset;
        } catch (ComplexityException) {
            return false;
        }
    }

    /**
     * A conflict the trailing slash makes, scoped to the GET and HEAD
     * requests of the later route, with a note on what the matcher does
     * with a request of the example.
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
            'notes' => [...$this->buildNotes($earlier, $later, $hostsUnread), self::slashNote($earlier, $example)],
            'redirected' => true,
            'laterFirstTakesEarlier' => false,
        ];
    }

    /**
     * Whether the later route, declared first, would take every request of
     * the earlier one through its trailing slash: it takes the earlier
     * route's methods, schemes and host, these are GET and HEAD alone, and
     * each path of the earlier route is one it matches or redirects to one
     * of its own.
     *
     * @phpstan-param RouteDescriptor $later
     * @phpstan-param RouteDescriptor $earlier
     *
     * @throws ComplexityException When the solver gives up on the paths
     */
    private function redirectsEveryPathOf(array $later, array $earlier, SolverOptions $options): bool
    {
        return $this->listHolds(['GET', 'HEAD'], $earlier['methods'])
            && $this->takesEveryRequestOf($later, $earlier, $options)
            && $this->solver->subsetOf($earlier['pathPattern'], $later['slashPattern'], $options)->isSubset;
    }

    /**
     * What the matcher does with a GET or HEAD request of the path, which
     * differs from a path of the earlier route by its trailing slashes: it
     * takes as is a path ending on the slash under a variable whose value
     * PCRE captures with the slash, or with a slash after it, and reads
     * any other for its slash, answering "/" with more slashes with a 404
     * and redirecting the rest to the earlier route.
     *
     * @phpstan-param RouteDescriptor $earlier
     */
    private static function slashNote(array $earlier, ?string $path): string
    {
        if (null !== $path && str_ends_with($path, '/') && self::endsOnAVariable($earlier['path']) && !self::capturesWithoutTheSlash($earlier, $path)) {
            return 'GET and HEAD requests differing by a trailing slash are matched as is by the earlier route, as PCRE captures its last variable.';
        }

        if (null !== $path && null === self::landing($path)) {
            return 'The earlier route answers GET and HEAD requests for "/" followed by more slashes with a 404.';
        }

        return 'GET and HEAD requests differing by a trailing slash are redirected to the earlier route.';
    }

    /**
     * Whether the matcher reads a path ending on the slash, which the
     * route's regex matches with the slash or without it, for its slash:
     * the route ends on a variable with no slash after it, and the value
     * PCRE captures for it is not set, or set and ending on another
     * character. It then redirects a GET or HEAD request of the path, and
     * passes any other on to the routes after it. A route with a slash
     * after its last variable takes such a path as is.
     *
     * @phpstan-param RouteDescriptor $route
     */
    private static function capturesWithoutTheSlash(array $route, string $path): bool
    {
        if ('/' === $path || !str_ends_with($path, '/') || str_ends_with($route['path'], '/') || !self::endsOnAVariable($route['path'])) {
            return false;
        }

        LibraryPcre::match($route['slashPattern'], $path, $matches, \PREG_UNMATCHED_AS_NULL);
        $values = \is_array($matches) ? array_filter($matches, static fn (int|string $key): bool => \is_int($key) && 0 < $key, \ARRAY_FILTER_USE_KEY) : [];
        $value = [] === $values ? null : end($values);

        return !\is_string($value) || ('' !== $value && !str_ends_with($value, '/'));
    }

    /**
     * The requests a route takes, as tuples of patterns: a method, a
     * scheme, a host and a path. Null when it takes none for certain (no
     * method or no scheme it accepts) or when its host cannot be read.
     *
     * @phpstan-param RouteDescriptor $route
     *
     * @return list<list<string>>|null
     */
    private function requestsOf(array $route): ?array
    {
        if (self::NO_REQUEST === $route['methods'] || self::NO_REQUEST === $route['schemes'] || $route['hostUnsupported']) {
            return null;
        }

        $unicode = str_contains(DelimitedPattern::fromDelimited($route['pathPattern'])->flags, 'u');
        $host = $route['hasHostRequirement'] ? $route['hostPattern'] : '{^.*$}s'.($unicode ? 'u' : '');
        $methods = \in_array('GET', $route['methods'], true) ? [...$route['methods'], 'HEAD'] : $route['methods'];
        $requests = [[self::listOf($methods, '[A-Z]+'), self::listOf($route['schemes'], '[a-z][a-z0-9+.\-]*'), $host, $route['pathPattern']]];
        if ($this->listHolds($route['methods'], ['GET'])) {
            $requests[] = [self::listOf(['GET', 'HEAD'], ''), self::listOf([], '[a-z][a-z0-9+.\-]*'), $host, $route['redirectPattern']];
        }

        return $requests;
    }

    /**
     * The pattern of one of the words, or of any word when none is listed.
     *
     * @param array<int, string> $words
     */
    private static function listOf(array $words, string $any): string
    {
        if ([] === $words) {
            return '{^'.$any.'$}';
        }

        return '{^(?:'.implode('|', array_map(static fn (string $word): string => preg_quote($word, '{'), array_values(array_unique($words)))).')$}';
    }

    /**
     * @param list<string> $parts
     *
     * @return array{method: string, scheme: string, host: string, path: string}
     */
    private static function request(array $parts): array
    {
        return ['method' => $parts[0] ?? '', 'scheme' => $parts[1] ?? '', 'host' => $parts[2] ?? '', 'path' => $parts[3] ?? ''];
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

        if ($left['index'] > $right['index']) {
            $notes[] = 'The router looks up the route with no variable by its path before it tries the other with a regex, whatever their order.';
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
