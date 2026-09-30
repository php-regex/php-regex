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

namespace RegexParser\Bridge\Symfony\Security;

use RegexParser\Automata\Api\RegexLanguageSolver;
use RegexParser\Automata\Builder\DfaBuilder;
use RegexParser\Automata\Determinization\DeterminizationAlgorithm;
use RegexParser\Automata\Minimization\MinimizationAlgorithm;
use RegexParser\Automata\Options\MatchMode;
use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Automata\Solver\InMemoryDfaCache;
use RegexParser\Automata\Transform\RegularSubsetValidator;
use RegexParser\Exception\ComplexityException;
use RegexParser\Regex;
use RegexParser\RegexPattern;

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
final readonly class SecurityAccessControlAnalyzer
{
    private const LEVEL_PUBLIC = 'public';
    private const LEVEL_RESTRICTED = 'restricted';
    private const LEVEL_CONDITIONAL = 'conditional';
    private const LEVEL_UNKNOWN = 'unknown';

    private const SUPPORTED_FLAGS = ['i', 's'];

    private const IGNORED_FLAGS = ['D'];

    private RegexLanguageSolver $solver;

    public function __construct(
        private Regex $regex,
        private SecurityPatternNormalizer $patternNormalizer = new SecurityPatternNormalizer(),
        private ?RegularSubsetValidator $validator = null,
        private ?DfaBuilder $dfaBuilder = null,
        private string $minimizationAlgorithm = MinimizationAlgorithm::HOPCROFT->value,
        ?RegexLanguageSolver $solver = null,
        private string $determinizationAlgorithm = DeterminizationAlgorithm::SUBSET_INDEXED->value,
    ) {
        $this->solver = $solver ?? RegexLanguageSolver::forRegex(
            $this->regex->parser(),
            $this->validator,
            $this->dfaBuilder,
            new InMemoryDfaCache(),
        );
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
            matchMode: MatchMode::FULL,
            minimizeDfa: false,
            minimizationAlgorithm: $this->resolveMinimizationAlgorithm(),
            determinizationAlgorithm: $this->resolveDeterminizationAlgorithm(),
        );

        foreach ($rules as $rule) {
            $index++;
            $descriptor = $this->buildDescriptor(
                $rule,
                $index,
                $options,
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
        $critical = 0;
        $equivalent = 0;
        $redundant = 0;
        $pairsTotal = 0;
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

                if (!$this->methodsOverlap($left['methods'], $right['methods'])) {
                    $pairsFilteredMethods++;

                    continue;
                }

                if (!$this->hostsOverlap($left, $right, $options, $hostChecks, $hostIntersections)) {
                    continue;
                }

                $pathIntersections++;
                $intersection = $this->solver->intersectionEmpty($left['pattern'], $right['pattern'], $options);
                if ($intersection->isEmpty) {
                    continue;
                }

                $example = $intersection->example;
                $pathSubsets++;
                $equivalence = $this->solver->equivalent($left['pattern'], $right['pattern'], $options);
                $isEquivalent = $equivalence->isEquivalent;
                $isSubset = null === $equivalence->rightOnlyExample;
                $leftSubset = null === $equivalence->leftOnlyExample;
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
            'critical' => $critical,
            'equivalent' => $equivalent,
            'redundant' => $redundant,
            'skipped_rules' => \count($skippedRules),
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
        SolverOptions $options,
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
        if (null === $path || '' === trim((string) $path)) {
            $path = '';
            $notes[] = 'Rule has no path; it matches all requests.';
            $rulesWithNoPath[] = $index;
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

        try {
            $pathPattern = $this->normalizePattern($path);
        } catch (\Throwable $exception) {
            $reason = $exception instanceof ComplexityException
                ? $exception->getMessage()
                : 'Invalid path regex.';

            $skippedRules[] = [
                'index' => $index,
                'file' => $rule['file'],
                'line' => $rule['line'],
                'reason' => $reason,
            ];

            return null;
        }

        $hostPattern = null;
        $hostUnsupported = false;
        $host = $rule['host'];
        if (null !== $host && '' !== trim($host)) {
            try {
                $hostPattern = $this->normalizePattern($host);
            } catch (\Throwable) {
                $hostPattern = null;
                $hostUnsupported = true;
                $rulesWithUnsupportedHosts[] = $index;
                $notes[] = 'Host restrictions are not evaluated.';
            }
        }

        $methods = $this->normalizeList($rule['methods']);
        $roles = $this->normalizeList($rule['roles']);
        $ips = $this->normalizeList($rule['ips']);

        return [
            'index' => $index,
            'file' => $rule['file'],
            'line' => $rule['line'],
            'path' => '' === $path ? null : $path,
            'pattern' => $pathPattern,
            'roles' => $roles,
            'methods' => $methods,
            'host' => $host,
            'hostPattern' => $hostPattern,
            'hostUnsupported' => $hostUnsupported,
            'ips' => $ips,
            'allowIf' => $rule['allowIf'],
            'requiresChannel' => $rule['requiresChannel'],
            'accessLevel' => $this->resolveAccessLevel($roles, $rule['allowIf']),
            'notes' => $notes,
        ];
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

        $roleLookup = [];
        foreach ($roles as $role) {
            $roleLookup[strtoupper($role)] = true;
        }
        if (isset($roleLookup['PUBLIC_ACCESS']) || isset($roleLookup['IS_AUTHENTICATED_ANONYMOUSLY'])) {
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
        return $this->sameList($left['roles'], $right['roles'], true)
            && $this->sameList($left['methods'], $right['methods'], true)
            && $left['hostPattern'] === $right['hostPattern']
            && $this->sameList($left['ips'], $right['ips'], true)
            && $left['requiresChannel'] === $right['requiresChannel']
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
     * @param array<int, mixed> $values
     *
     * @return array<int, string>
     */
    private function normalizeList(array $values): array
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

    private function normalizePattern(string $pattern): string
    {
        $trimmed = trim($pattern);
        if ('' === $trimmed) {
            return '#.*#';
        }

        $first = $trimmed[0] ?? '';
        if (\in_array($first, ['/', '#', '~', '%'], true)) {
            $regexPattern = RegexPattern::fromDelimited($trimmed);
            $flags = $regexPattern->flags;
            $normalizedFlags = '';
            $unsupportedFlags = [];

            foreach (\str_split($flags) as $flag) {
                if (\in_array($flag, self::SUPPORTED_FLAGS, true)) {
                    $normalizedFlags .= $flag;

                    continue;
                }

                if (\in_array($flag, self::IGNORED_FLAGS, true)) {
                    continue;
                }

                $unsupportedFlags[] = $flag;
            }

            if ([] !== $unsupportedFlags) {
                throw new ComplexityException('Unsupported regex flags: '.implode(', ', $unsupportedFlags).'.');
            }

            $normalizedBody = $this->normalizeSearchPattern($regexPattern->pattern);
            $normalized = RegexPattern::fromRaw($normalizedBody, $normalizedFlags, $regexPattern->delimiter);

            return $normalized->toString();
        }

        $normalized = $this->patternNormalizer->normalize($trimmed);
        $regexPattern = RegexPattern::fromDelimited($normalized);
        $normalizedBody = $this->normalizeSearchPattern($regexPattern->pattern);

        return RegexPattern::fromRaw($normalizedBody, $regexPattern->flags, $regexPattern->delimiter)->toString();
    }

    private function normalizeSearchPattern(string $pattern): string
    {
        $hasStartAnchor = $this->startsWithAnchor($pattern);
        $hasEndAnchor = $this->endsWithAnchor($pattern);

        if ($hasStartAnchor && $hasEndAnchor) {
            return $pattern;
        }

        if ($hasStartAnchor) {
            return $pattern.'.*';
        }

        if ($hasEndAnchor) {
            return '.*'.$pattern;
        }

        return '.*'.$pattern.'.*';
    }

    private function startsWithAnchor(string $pattern): bool
    {
        return '' !== $pattern && '^' === $pattern[0];
    }

    private function endsWithAnchor(string $pattern): bool
    {
        $length = \strlen($pattern);
        if (0 === $length) {
            return false;
        }

        if ('$' !== $pattern[$length - 1]) {
            return false;
        }

        $backslashes = 0;
        for ($i = $length - 2; $i >= 0 && '\\' === $pattern[$i]; $i--) {
            $backslashes++;
        }

        return 0 === $backslashes % 2;
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
        $intersection = $this->solver->intersectionEmpty($left['hostPattern'], $right['hostPattern'], $options);

        return !$intersection->isEmpty;
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

        $upperLeft = [];
        foreach ($left as $value) {
            $upperLeft[] = strtoupper($value);
        }
        $upperRight = [];
        foreach ($right as $value) {
            $upperRight[] = strtoupper($value);
        }

        return [] !== array_intersect($upperLeft, $upperRight);
    }

    private function resolveMinimizationAlgorithm(): MinimizationAlgorithm
    {
        $normalized = \strtolower(\trim($this->minimizationAlgorithm));
        $algorithm = MinimizationAlgorithm::tryFrom($normalized);

        return $algorithm ?? MinimizationAlgorithm::HOPCROFT;
    }

    private function resolveDeterminizationAlgorithm(): DeterminizationAlgorithm
    {
        $normalized = \strtolower(\trim($this->determinizationAlgorithm));
        $algorithm = DeterminizationAlgorithm::tryFrom($normalized);

        return $algorithm ?? DeterminizationAlgorithm::SUBSET_INDEXED;
    }
}
