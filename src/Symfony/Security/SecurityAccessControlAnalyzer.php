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

namespace PHPRegex\Symfony\Security;

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Minimization\MinimizationAlgorithm;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\InMemoryDfaCache;
use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\MethodRequestMatcher;

/**
 * Analyzes Symfony security access_control ordering with regex automata.
 *
 * @internal
 *
 * @phpstan-import-type AccessRule from SecurityAccessControlReport
 * @phpstan-import-type AccessConflict from SecurityAccessControlReport
 * @phpstan-import-type AccessSkip from SecurityAccessControlReport
 * @phpstan-import-type AccessControlRule from SecurityConfigExtractor
 */
final class SecurityAccessControlAnalyzer
{
    private const LEVEL_PUBLIC = 'public';
    private const LEVEL_RESTRICTED = 'restricted';
    private const LEVEL_CONDITIONAL = 'conditional';
    private const LEVEL_UNKNOWN = 'unknown';

    private readonly LanguageSolver $solver;

    public function __construct(
        private readonly Regex $regex,
        private readonly SecurityPatternNormalizer $patternNormalizer = new SecurityPatternNormalizer(),
        private readonly string $minimizationAlgorithm = 'hopcroft',
        ?LanguageSolver $solver = null,
        private readonly string $determinizationAlgorithm = 'subset-indexed',
    ) {
        $this->solver = $solver ?? new LanguageSolver($this->regex->parser(), new InMemoryDfaCache());
    }

    /**
     * @param array<AccessControlRule> $rules
     */
    public function analyze(array $rules, bool $includeOverlaps = false): SecurityAccessControlReport
    {
        $descriptors = [];
        $skippedRules = [];
        $rulesWithAllowIf = [];
        $rulesWithIps = [];
        $rulesWithNoPath = [];
        $rulesWithUnsupportedHosts = [];
        $index = 0;

        $options = new SolverOptions(
            matchMode: MatchMode::Full,
            minimizeDfa: false,
            minimizationAlgorithm: $this->resolveMinimizationAlgorithm(),
            determinizationAlgorithm: $this->resolveDeterminizationAlgorithm(),
        );

        foreach ($rules as $rule) {
            $index++;
            $descriptor = $this->buildDescriptor(
                $rule,
                $index,
                $skippedRules,
                $rulesWithAllowIf,
                $rulesWithIps,
                $rulesWithNoPath,
                $rulesWithUnsupportedHosts,
            );

            if (null !== $descriptor) {
                $descriptors[] = $descriptor;
            }
        }

        $conflicts = [];
        $shadowed = 0;
        $overlaps = 0;
        $carveOuts = 0;
        $critical = 0;
        $equivalent = 0;
        $redundant = 0;
        $pairsTotal = 0;
        $skippedPairs = 0;
        $pairsFilteredMethods = 0;
        $hostChecks = 0;
        $hostIntersections = 0;
        $pathIntersections = 0;
        $pathSubsets = 0;
        $count = \count($descriptors);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $left = $descriptors[$i];
                $right = $descriptors[$j];
                $pairsTotal++;

                if (!self::methodsOverlap($left, $right)) {
                    $pairsFilteredMethods++;

                    continue;
                }

                // Two ports take no request in common.
                if (null !== $left['port'] && null !== $right['port'] && $left['port'] !== $right['port']) {
                    continue;
                }

                if (!$this->hostsOverlap($left, $right, $options, $hostChecks, $hostIntersections)) {
                    continue;
                }

                try {
                    $pathIntersections++;
                    $intersection = $this->solver->intersection($left['pattern'], $right['pattern'], $options);
                    if ($intersection->isEmpty) {
                        continue;
                    }

                    $pathSubsets++;
                    $equivalence = $this->solver->equivalent($left['pattern'], $right['pattern'], $options);
                } catch (ComplexityException) {
                    $skippedPairs++;

                    continue;
                }

                // access_control is first match. The later rule is shadowed
                // when the earlier one takes every request it takes. The
                // earlier rule is a carve-out of the later one, the order
                // access_control expects, when it takes only requests the
                // later one takes too, or when its path is strictly
                // narrower. Any other pair sharing a request overlaps: the
                // order decides which rule applies to what both take.
                //
                // A path read from the environment is read as every path,
                // which it may be, but holds no other path, nor is held by
                // any other than every path, unless the same expression
                // gives both: the same value.
                $samePathFromEnvironment = self::sameFromEnvironment($left, $right, 'path');
                $laterPathInEarlier = null === $equivalence->rightOnlyExample && (!\in_array('path', $left['fromEnvironment'], true) || $samePathFromEnvironment);
                $earlierPathInLater = null === $equivalence->leftOnlyExample && (!\in_array('path', $right['fromEnvironment'], true) || $samePathFromEnvironment);
                $isSubset = $laterPathInEarlier && $this->takesEveryRequestOf($left, $right, $options);
                if (!$isSubset && $earlierPathInLater && (!$laterPathInEarlier || $this->takesEveryRequestOf($right, $left, $options))) {
                    $carveOuts++;

                    continue;
                }

                $example = $intersection->example;
                $isEquivalent = $equivalence->isEquivalent && $laterPathInEarlier && $earlierPathInLater;
                if ($isEquivalent) {
                    $equivalent++;
                }

                if ($isSubset) {
                    $shadowed++;
                } else {
                    $overlaps++;
                }

                if (!$includeOverlaps && !$isSubset) {
                    continue;
                }

                $severity = $this->resolveSeverity($left, $right, $isSubset);
                if ('critical' === $severity) {
                    $critical++;
                }

                $redundantRule = $isSubset && $this->accessRulesEquivalent($left, $right);
                if ($redundantRule) {
                    $redundant++;
                }

                $notes = $this->mergeNotes($left['notes'], $right['notes']);
                if ($isEquivalent) {
                    $notes[] = 'Equivalent path patterns.';
                }
                if ($redundantRule) {
                    $notes[] = 'Redundant rule (same access constraints).';
                }

                $conflicts[] = [
                    'rule' => $left,
                    'conflict' => $right,
                    'type' => $isSubset ? 'shadowed' : 'overlap',
                    'severity' => $severity,
                    'example' => $example,
                    'equivalent' => $isEquivalent,
                    'redundant' => $redundantRule,
                    'notes' => $notes,
                ];
            }
        }

        $stats = [
            'rules' => $index,
            'conflicts' => \count($conflicts),
            'shadowed' => $shadowed,
            'overlaps' => $overlaps,
            'carve_outs' => $carveOuts,
            'critical' => $critical,
            'equivalent' => $equivalent,
            'redundant' => $redundant,
            'skipped_rules' => \count($skippedRules),
            'skipped_pairs' => $skippedPairs,
            'pairs_total' => $pairsTotal,
            'pairs_filtered_methods' => $pairsFilteredMethods,
            'host_checks' => $hostChecks,
            'host_intersections' => $hostIntersections,
            'path_intersections' => $pathIntersections,
            'path_subsets' => $pathSubsets,
        ];

        return new SecurityAccessControlReport(
            $conflicts,
            $skippedRules,
            $stats,
            array_values(array_unique($rulesWithAllowIf)),
            array_values(array_unique($rulesWithIps)),
            array_values(array_unique($rulesWithNoPath)),
            array_values(array_unique($rulesWithUnsupportedHosts)),
        );
    }

    /**
     * @param AccessControlRule $rule
     * @param array<AccessSkip> $skippedRules
     * @param array<int, int>   $rulesWithAllowIf
     * @param array<int, int>   $rulesWithIps
     * @param array<int, int>   $rulesWithNoPath
     * @param array<int, int>   $rulesWithUnsupportedHosts
     *
     * @phpstan-return AccessRule|null
     */
    private function buildDescriptor(
        array $rule,
        int $index,
        array &$skippedRules,
        array &$rulesWithAllowIf,
        array &$rulesWithIps,
        array &$rulesWithNoPath,
        array &$rulesWithUnsupportedHosts,
    ): ?array {
        if (null !== $rule['requestMatcher']) {
            $skippedRules[] = [
                'index' => $index,
                'file' => $rule['file'],
                'line' => $rule['line'],
                'reason' => 'request_matcher rules cannot be analyzed.',
            ];

            return null;
        }

        $notes = [];
        $path = $rule['path'];
        $route = $rule['route'] ?? null;
        $attributes = $rule['attributes'] ?? [];
        if (!self::chainsAMatcher($path)) {
            $path = '';
            // A route or request attributes keep the rule to the paths the
            // router gives them; methods, a host, a port or addresses keep
            // it to some requests, on every path.
            if (null === $route && [] === $attributes) {
                $notes[] = 'Rule has no path; it matches every path.';
                $rulesWithNoPath[] = $index;
            }
        }

        if (null !== $rule['allowIf']) {
            $notes[] = 'allow_if conditions are not evaluated.';
            $rulesWithAllowIf[] = $index;
        }

        if ([] !== $rule['ips']) {
            $notes[] = 'IP restrictions are not evaluated.';
            $rulesWithIps[] = $index;
        }

        if (null !== $rule['requiresChannel']) {
            $notes[] = 'requires_channel is not evaluated.';
        }

        if (null !== $route || [] !== $attributes) {
            $notes[] = 'Route and attribute restrictions are not evaluated.';
        }

        // A value read from the environment is known at run time only.
        $fromEnvironment = $rule['fromEnvironment'] ?? [];
        if ([] !== $fromEnvironment) {
            $notes[] = 'Values read from the environment are not evaluated: '.implode(', ', $fromEnvironment).'.';
        }

        $pathPattern = $this->normalizePattern(\in_array('path', $fromEnvironment, true) ? '' : $path);
        if (null === $pathPattern) {
            $skippedRules[] = [
                'index' => $index,
                'file' => $rule['file'],
                'line' => $rule['line'],
                'reason' => 'Invalid path regex.',
            ];

            return null;
        }

        $hostPattern = null;
        $hostUnsupported = false;
        $host = self::chainsAMatcher($rule['host']) ? $rule['host'] : null;
        if (null !== $host && !\in_array('host', $fromEnvironment, true)) {
            $hostPattern = $this->normalizePattern($host, true);
            if (null === $hostPattern) {
                $hostUnsupported = true;
                $rulesWithUnsupportedHosts[] = $index;
                $notes[] = 'Host restrictions are not evaluated.';
            }
        }

        $methods = self::distinct($rule['methods']);
        $roles = self::distinct($rule['roles']);
        // IpsRequestMatcher splits each address it is given on its commas.
        $ips = [];
        foreach ($rule['ips'] as $item) {
            $ips = [...$ips, ...(LibraryPcre::split('/\s*,\s*/', $item) ?: [])];
        }
        $ips = self::distinct($ips);

        return [
            'index' => $index,
            'file' => $rule['file'],
            'line' => $rule['line'],
            'path' => '' === $path ? null : $path,
            'pattern' => $pathPattern,
            'roles' => $roles,
            'methods' => $methods,
            'methodsTaken' => self::methodsTaken($methods),
            'host' => $host,
            'hostPattern' => $hostPattern,
            'hostUnsupported' => $hostUnsupported,
            'ips' => $ips,
            'allowIf' => $rule['allowIf'],
            'requiresChannel' => $rule['requiresChannel'],
            'port' => $rule['port'] ?? null,
            'attributes' => $attributes,
            'route' => $route,
            'fromEnvironment' => $fromEnvironment,
            'accessLevel' => $this->resolveAccessLevel(\in_array('roles', $fromEnvironment, true) ? [] : array_values($rule['roles']), $rule['allowIf']),
            'notes' => $notes,
        ];
    }

    /**
     * Whether SecurityExtension chains a request matcher for a path or a
     * host: it tests the value as PHP does, so "" and "0" are none, and a
     * value holding only spaces is one, compiled as written.
     *
     * @phpstan-assert-if-true string $value
     */
    private static function chainsAMatcher(?string $value): bool
    {
        return null !== $value && '' !== $value && '0' !== $value;
    }

    /**
     * @param array<int, string> $roles
     */
    private function resolveAccessLevel(array $roles, ?string $allowIf): string
    {
        if (null !== $allowIf) {
            return self::LEVEL_CONDITIONAL;
        }

        if ([] === $roles) {
            return self::LEVEL_UNKNOWN;
        }

        // AuthenticatedVoter grants a visitor "PUBLIC_ACCESS" as written and
        // alone: it compares the whole list of roles with it, as
        // AccessListener does.
        if (['PUBLIC_ACCESS'] === $roles) {
            return self::LEVEL_PUBLIC;
        }

        return self::LEVEL_RESTRICTED;
    }

    /**
     * @phpstan-param AccessRule $left
     * @phpstan-param AccessRule $right
     */
    private function resolveSeverity(array $left, array $right, bool $isSubset): string
    {
        if (!$isSubset) {
            return 'warning';
        }

        if (self::LEVEL_PUBLIC === $left['accessLevel'] && self::LEVEL_RESTRICTED === $right['accessLevel']) {
            return 'critical';
        }

        return 'warning';
    }

    /**
     * @phpstan-param AccessRule $left
     * @phpstan-param AccessRule $right
     */
    private function accessRulesEquivalent(array $left, array $right): bool
    {
        return $left['fromEnvironment'] === $right['fromEnvironment']
            && $this->sameList($left['roles'], $right['roles'], false)
            && $left['methodsTaken'] === $right['methodsTaken']
            && $left['host'] === $right['host']
            && $left['hostPattern'] === $right['hostPattern']
            && $this->sameList($left['ips'], $right['ips'], true)
            && $left['requiresChannel'] === $right['requiresChannel']
            && $left['port'] === $right['port']
            && self::requestAttributes($left) === self::requestAttributes($right)
            && $left['allowIf'] === $right['allowIf'];
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     *
     * @return list<string>
     */
    private function mergeNotes(array $left, array $right): array
    {
        $notes = array_merge($left, $right);

        return array_values(array_unique($notes));
    }

    /**
     * The values of a list, each once and as written: the security bundle
     * trims none.
     *
     * @param array<int, string> $values
     *
     * @return list<string>
     */
    private static function distinct(array $values): array
    {
        return array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $left
     * @param array<int, string> $right
     */
    private function sameList(array $left, array $right, bool $caseInsensitive): bool
    {
        if ($caseInsensitive) {
            $upperLeft = [];
            foreach ($left as $value) {
                $upperLeft[] = strtoupper($value);
            }
            $upperRight = [];
            foreach ($right as $value) {
                $upperRight[] = strtoupper($value);
            }
            $left = $upperLeft;
            $right = $upperRight;
        }

        sort($left);
        sort($right);

        return $left === $right;
    }

    /**
     * The pattern Symfony matches the path, or the host, of a rule with,
     * read as a whole-subject match: a side left unanchored takes any text
     * there, as a search does. Null when PCRE refuses the pattern Symfony
     * compiles, or the search built from it.
     */
    private function normalizePattern(string $pattern, bool $host = false): ?string
    {
        $delimited = $this->patternNormalizer->normalize($pattern, $host);
        if (!$this->regex->validate($delimited)->isValid) {
            return null;
        }

        $regexPattern = DelimitedPattern::fromDelimited($delimited);
        $search = (string) new DelimitedPattern($this->normalizeSearchPattern($regexPattern->pattern), $regexPattern->flags, $regexPattern->delimiter);

        return $this->regex->validate($search)->isValid ? $search : null;
    }

    /**
     * A search for the pattern, as a whole-subject match: any text before,
     * the pattern grouped, any text after, line feeds included whether or
     * not Symfony compiles the pattern with "s". Grouped, every branch of a
     * top-level alternation is searched for, and an anchor binds only the
     * branch it is written in, as in preg_match(). The options the pattern
     * opens with, "(*UTF)" or "(*LIMIT_MATCH=1000)", stay first: PCRE reads
     * them there only.
     *
     * The pattern was written to end where Symfony's delimiter does, so the
     * group is closed whatever state its end leaves PCRE in: "\E" ends a
     * "\Q" left open, and the newline ends a comment the x flag opened with
     * "#". Out of such a comment, the newline lies in the comment "(?#" opens
     * and "(?#)" closes; in one, it ends it, and "(?#)" is a comment of its
     * own. Either way the group closes on the last ")", and the pattern
     * matches what it matched alone.
     */
    private function normalizeSearchPattern(string $pattern): string
    {
        $options = StartOptions::of($pattern);

        return $options.'(?s:.*)(?:'.substr($pattern, \strlen($options))."\\E(?#\n(?#))(?s:.*)";
    }

    /**
     * @phpstan-param AccessRule $left
     * @phpstan-param AccessRule $right
     */
    private function hostsOverlap(
        array $left,
        array $right,
        SolverOptions $options,
        int &$hostChecks,
        int &$hostIntersections,
    ): bool {
        if (null === $left['hostPattern'] || null === $right['hostPattern']) {
            return true;
        }

        if ($left['hostUnsupported'] || $right['hostUnsupported']) {
            return true;
        }

        $hostChecks++;
        $hostIntersections++;

        try {
            $intersection = $this->solver->intersection($left['hostPattern'], $right['hostPattern'], $options);
        } catch (ComplexityException) {
            // Hosts the solver cannot compare may share a request: the
            // paths decide, as for a host that could not be read.
            return true;
        }

        return !$intersection->isEmpty;
    }

    /**
     * Whether every request the inner rule takes, the outer rule takes too,
     * but for the path: no method, host, address, port or request attribute
     * of its own, or one that holds the inner rule's. Addresses and
     * attributes are compared as written: an attribute of the outer rule
     * the inner one does not require the same way may refuse some of its
     * requests. A host the parser or the solver cannot read leaves the
     * paths to decide, as it does when the hosts are intersected.
     *
     * @phpstan-param AccessRule $outer
     * @phpstan-param AccessRule $inner
     */
    private function takesEveryRequestOf(array $outer, array $inner, SolverOptions $options): bool
    {
        if (null !== $outer['methodsTaken'] && (null === $inner['methodsTaken'] || [] !== array_diff($inner['methodsTaken'], $outer['methodsTaken']))) {
            return false;
        }

        // Addresses read from the environment compare as written, as do a
        // route and attributes: the same expression gives the same value,
        // any other may give any.
        $fromEnvironment = \in_array('ips', $outer['fromEnvironment'], true) || \in_array('ips', $inner['fromEnvironment'], true);
        if ([] !== $outer['ips'] && !$this->listHolds($outer['ips'], $inner['ips'], !$fromEnvironment)) {
            return false;
        }

        if (null !== $outer['port'] && $outer['port'] !== $inner['port']) {
            return false;
        }

        $required = self::requestAttributes($inner);
        foreach (self::requestAttributes($outer) as $name => $pattern) {
            if (($required[$name] ?? null) !== $pattern) {
                return false;
            }
        }

        // A host read from the environment holds the one the same
        // expression gives alone.
        if (\in_array('host', $outer['fromEnvironment'], true)) {
            return self::sameFromEnvironment($outer, $inner, 'host');
        }

        if ($outer['hostUnsupported'] || $inner['hostUnsupported'] || null === $outer['hostPattern']) {
            return true;
        }

        if (null === $inner['hostPattern']) {
            return false;
        }

        try {
            return null === $this->solver->equivalent($outer['hostPattern'], $inner['hostPattern'], $options)->rightOnlyExample;
        } catch (ComplexityException) {
            return true;
        }
    }

    /**
     * The attributes a rule's request matcher requires, its route as
     * "_route", as the security bundle builds the matcher, by name.
     *
     * @phpstan-param AccessRule $rule
     *
     * @return array<string, string>
     */
    private static function requestAttributes(array $rule): array
    {
        $attributes = null === $rule['route'] ? $rule['attributes'] : ['_route' => $rule['route']] + $rule['attributes'];
        ksort($attributes);

        return $attributes;
    }

    /**
     * The methods a rule takes, asked of the application's
     * MethodRequestMatcher: it reads those the rule lists in upper case,
     * split on their commas, and takes HEAD beside GET on the releases
     * that add it (6.4.50, 7.4.23 and 8.1.11 on, never 8.0). A request
     * method is a token, so each method the matcher takes is one the list
     * writes, or HEAD. Null when the rule lists none: the security bundle
     * then chains no method matcher, and every method is taken.
     *
     * @param list<string> $methods
     *
     * @return list<string>|null
     */
    private static function methodsTaken(array $methods): ?array
    {
        if ([] === $methods) {
            return null;
        }

        $matcher = new MethodRequestMatcher($methods);
        $taken = [];
        // Every token of the list, cut at its commas and its spaces.
        $tokens = explode(',', strtr(strtoupper(implode(',', $methods)), " \t\n\v\f\r", ',,,,,,'));
        foreach (array_unique([...array_filter($tokens, static fn (string $token): bool => '' !== $token), 'HEAD']) as $method) {
            // Built as is: Request::create() would call the factory the
            // application may give Request::setFactory().
            if ($matcher->matches(new Request(server: ['REQUEST_METHOD' => $method]))) {
                $taken[] = $method;
            }
        }
        sort($taken);

        return $taken;
    }

    /**
     * Whether a list of addresses takes every value the inner one takes:
     * an empty list takes them all. An address is read in any case, an
     * expression read from the environment as written.
     *
     * @param array<int, string> $outer
     * @param array<int, string> $inner
     */
    private function listHolds(array $outer, array $inner, bool $caseInsensitive): bool
    {
        if ([] === $outer) {
            return true;
        }

        if ([] === $inner) {
            return false;
        }

        return $caseInsensitive
            ? [] === array_diff(array_map(strtoupper(...), $inner), array_map(strtoupper(...), $outer))
            : [] === array_diff($inner, $outer);
    }

    /**
     * Whether two rules read a field from the environment through the same
     * expression, the same processors over the same variable: the value
     * is the same at run time.
     *
     * @param 'path'|'host' $field
     *
     * @phpstan-param AccessRule $left
     * @phpstan-param AccessRule $right
     */
    private static function sameFromEnvironment(array $left, array $right, string $field): bool
    {
        return \in_array($field, $left['fromEnvironment'], true)
            && \in_array($field, $right['fromEnvironment'], true)
            && $left[$field] === $right[$field];
    }

    /**
     * Whether two rules take a method in common: a rule that lists none
     * takes them all, one whose list the matcher reads as no request method
     * takes none.
     *
     * @phpstan-param AccessRule $left
     * @phpstan-param AccessRule $right
     */
    private static function methodsOverlap(array $left, array $right): bool
    {
        if ([] === $left['methodsTaken'] || [] === $right['methodsTaken']) {
            return false;
        }

        return null === $left['methodsTaken'] || null === $right['methodsTaken'] || [] !== array_intersect($left['methodsTaken'], $right['methodsTaken']);
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
