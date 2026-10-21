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

namespace PHPRegex\PHPStan;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\UnsupportedTargetException;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPStan\Analyser\Scope;
use PHPStan\Php\PhpVersion;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;

/**
 * The checks of one constant pattern, as the "phpRegex" parameter sets
 * them: validity for the versions its TargetScope judges, then lint, ReDoS
 * risks and optimizations on demand, each error worded by ErrorFactory.
 * The rules read their patterns through it.
 *
 * @internal
 *
 * @phpstan-import-type ConfigArray from RegexPatternRule
 */
final class PatternChecker
{
    private const ISSUE_ID_REDOS = 'regex.lint.redos';
    private const ISSUE_ID_REDOS_SEARCH = 'regex.lint.redos.search';
    private const ISSUE_ID_COMPLEXITY = RuleCatalog::COMPLEXITY;

    /**
     * The versions a pattern is judged for, at each call.
     */
    private readonly TargetScope $scope;

    private readonly bool $lintEnabled;

    private readonly bool $redosEnabled;

    private readonly string $redosThreshold;

    private readonly bool $optimizationsEnabled;

    private readonly int $optimizationMinSavings;

    private readonly OptimizerOptions $optimizationOptions;

    private readonly bool $complexityEnabled;

    /**
     * The complexity score from which a pattern is reported.
     */
    private readonly int $complexityThreshold;

    private ?AnalysisService $analysis = null;

    /**
     * The analysis of each lowest version a guard or a marker leaves, by
     * PHP_VERSION_ID, PCRE2 and locale.
     *
     * @var array<string, AnalysisService>
     */
    private array $guardedFloors = [];

    /**
     * @param ConfigArray                        $config             the "phpRegex" parameter: "phpVersion", "pcreVersion", "locale"
     *                                                               and "checks" ("lint", "redos", "optimizations", "complexity"),
     *                                                               every key optional
     * @param PhpVersion|null                    $phpVersion         the PHP version PHPStan analyses the project for: patterns are
     *                                                               judged for it, with the PCRE2 it bundles, unless "phpVersion"
     *                                                               is "runtime" or names a version, and "pcreVersion" a release
     * @param int|array{min: int, max: int}|null $phpVersionRange    PHPStan's "phpVersion" parameter: with {min, max}, a pattern is
     *                                                               also validated at each later PHP up to max where a rule of the
     *                                                               library changes, unless "phpVersion" names one version; a guard
     *                                                               on PHP_VERSION_ID narrows the versions a call is judged for
     * @param ReflectionProvider|null            $reflectionProvider tells whether PHP_VERSION_ID names the global constant in a
     *                                                               namespace, for a guard written without a leading backslash
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion", "locale", "checks.redos.threshold" or
     *                                     "checks.complexity.threshold" cannot be read
     * @throws UnsupportedTargetException  when the PHP to judge for ships PCRE1, as regex lint refuses it
     */
    public function __construct(
        array $config = [],
        ?PhpVersion $phpVersion = null,
        int|array|null $phpVersionRange = null,
        ?ReflectionProvider $reflectionProvider = null
    ) {
        $this->scope = new TargetScope($config, $phpVersion, $phpVersionRange, $reflectionProvider);

        $checks = $config['checks'] ?? [];
        $lint = $checks['lint'] ?? [];
        $redos = $checks['redos'] ?? [];
        $optimizations = $checks['optimizations'] ?? [];
        $complexity = $checks['complexity'] ?? [];

        $this->lintEnabled = true === ($lint['enabled'] ?? false);
        $this->redosEnabled = true === ($redos['enabled'] ?? false);
        $this->redosThreshold = self::redosThreshold($redos['threshold'] ?? null);
        $this->optimizationsEnabled = true === ($optimizations['enabled'] ?? false);
        $minSavings = $optimizations['minSavings'] ?? null;
        $this->optimizationMinSavings = \is_int($minSavings) ? max(1, $minSavings) : 1;
        // The PHPStan parameters name the options in camelCase; every rewrite
        // is checked with the automata unless the parameters say otherwise.
        $this->optimizationOptions = OptimizerOptions::fromCamelCaseArray(($optimizations['options'] ?? []) + ['verifyWithAutomata' => true]);
        $this->complexityEnabled = true === ($complexity['enabled'] ?? false);
        $this->complexityThreshold = self::complexityThreshold($complexity['threshold'] ?? null);
    }

    /**
     * The parser that judges patterns for the target.
     */
    public function parser(): RegexParser
    {
        return $this->scope->parser();
    }

    public function optimizationsEnabled(): bool
    {
        return $this->optimizationsEnabled;
    }

    /**
     * The errors of a pattern the running engine compiles; the caller
     * reports one it refuses (PHPStan core does in a preg_*() call).
     *
     * @param string   $source     where the pattern was found, as "php:preg_match()"
     * @param int|null $fileOffset where the literal the pattern was written with starts in the file, when
     *                             the call holds it: a raw character is reported only where that literal
     *                             holds it as written
     *
     * @return array<IdentifierRuleError>
     */
    public function check(string $pattern, int $lineNumber, Scope $scope, string $source, bool $constantSubject, ?int $fileOffset = null): array
    {
        if (!$this->scope->judgesBeyondTheRunningEngine() && !$this->lintEnabled && !$this->redosEnabled && !$this->optimizationsEnabled && !$this->complexityEnabled) {
            return [];
        }

        // What the running engine refuses is the caller's to report.
        if (!$this->runningEngineCompiles($pattern)) {
            return [];
        }

        // The floor first, then the later PHPs of PHPStan's range: the first
        // that refuses the pattern is reported. Under a guard on
        // PHP_VERSION_ID, those of the versions the call runs on.
        $targets = $this->scope->targetsAt($scope, $lineNumber);
        if (null === $targets) {
            return [];
        }
        [$validated, $floor] = $targets;
        foreach ($validated as [$parser, $label]) {
            $validation = $parser->validate($pattern);
            // A limit of the library: the pattern is not analyzed, and
            // nothing is said about it.
            if (ValidationErrorCategory::Limit === $validation->category) {
                return [];
            }

            if (!$validation->isValid) {
                return [ErrorFactory::invalidForTarget($label, $validation->error ?? 'Invalid regex.', $validation->hint, $lineNumber)];
            }
        }

        if (!$this->lintEnabled && !$this->redosEnabled && !$this->optimizationsEnabled && !$this->complexityEnabled) {
            return [];
        }

        $errors = [];
        $occurrence = new PatternOccurrence(
            $pattern,
            $scope->getFile(),
            $lineNumber,
            $source,
            fileOffset: $fileOffset,
        );
        $analysisService = $this->analysisAt($floor);

        $redosIssues = [];
        $lintIssues = [];
        if ($this->lintEnabled || $this->redosEnabled || $this->complexityEnabled) {
            foreach ($analysisService->lint([$occurrence]) as $issue) {
                // A pattern the library cannot read comes back without an
                // issue id, alone: nothing below reports it.
                $issueId = $issue->issueId;
                if (self::ISSUE_ID_COMPLEXITY === $issueId) {
                    if ($this->complexityEnabled) {
                        $lintIssues[] = $issue;
                    }

                    continue;
                }

                // Turned on alone, the complexity check reports nothing else.
                if (!$this->lintEnabled && !$this->redosEnabled) {
                    continue;
                }

                if (self::ISSUE_ID_REDOS === $issueId || self::ISSUE_ID_REDOS_SEARCH === $issueId) {
                    $redosIssues[] = $issue;

                    continue;
                }

                $lintIssues[] = $issue;
            }
        }

        foreach ($constantSubject ? [] : $redosIssues as $issue) {
            $analysis = $issue->analysis;
            if (!$analysis instanceof RedosAnalysis) {
                continue; // never: a ReDoS issue carries its analysis; the check is for the type
            }

            if (self::ISSUE_ID_REDOS_SEARCH === $issue->issueId) {
                $errors[] = ErrorFactory::searchCost($pattern, $issue->message, $issue->hint, $lineNumber);

                continue;
            }

            $repair = (new RedosRepairer($this->scope->parser()))->repair($pattern)[0] ?? null;
            $errors[] = ErrorFactory::redos($pattern, $analysis, $repair, $lineNumber);
        }

        if ($this->optimizationsEnabled) {
            /** @var array<array{file: string, line: int, optimization: OptimizationResult, savings: int, source?: string}> $optimizations */
            $optimizations = $analysisService->suggestOptimizations(
                [$occurrence],
                $this->optimizationMinSavings,
                $this->optimizationOptions,
            );

            foreach ($optimizations as $optimizationEntry) {
                $optimization = $optimizationEntry['optimization'];
                if (!OptimizationFormat::isSafe($pattern, $optimization->optimized)) {
                    continue;
                }
                $errors[] = ErrorFactory::optimization($pattern, $optimization->optimized, $lineNumber);
            }
        }

        foreach ($lintIssues as $issue) {
            $issueId = $issue->issueId;
            if (null === $issueId || '' === $issueId) {
                continue; // a pattern the library cannot read, which the running engine compiles
            }

            $errors[] = ErrorFactory::lintIssue($issueId, $issue->message, $issue->hint, $lineNumber);
        }

        return $errors;
    }

    /**
     * Where the expression starts in its file, when it is a string literal
     * or literals joined by ".": the pattern is then written there.
     */
    public static function literalOffset(Expr $expr): ?int
    {
        $parts = [$expr];
        while ([] !== $parts) {
            $part = array_pop($parts);
            if ($part instanceof Concat) {
                $parts[] = $part->left;
                $parts[] = $part->right;
            } elseif (!$part instanceof String_) {
                return null;
            }
        }

        $offset = $expr->getStartFilePos();

        return $offset >= 0 ? $offset : null;
    }

    /**
     * Whether the engine running PHPStan compiles the pattern.
     */
    public function runningEngineCompiles(string $pattern): bool
    {
        return null === (new PcreEngine())->compile($pattern);
    }

    /**
     * The errors of the "@regex-target" markers of a file: one that cannot
     * be read, one that keeps no version of PHPStan's range, each on its
     * line.
     *
     * @return list<IdentifierRuleError>
     */
    public function markerErrors(string $file): array
    {
        $errors = [];
        foreach ($this->scope->markersOf($file) as $marker) {
            if (null === $marker->constraint) {
                $errors[] = ErrorFactory::unreadableMarker($marker);
            } elseif ([] === $this->scope->judged()->narrow($marker->constraint)) {
                $errors[] = ErrorFactory::emptyMarker($marker, $this->scope->judged()->describe());
            }
        }

        return $errors;
    }

    /**
     * "checks.redos.threshold", critical when unset. It is read even while
     * the section is off: a typo there would bite the day it is switched on.
     *
     * @throws InvalidRegexOptionException when the value names no threshold
     */
    private static function redosThreshold(mixed $threshold): string
    {
        if (null === $threshold) {
            return RedosSeverity::Critical->value;
        }

        if (!\is_string($threshold)) {
            throw new InvalidRegexOptionException(\sprintf('"checks.redos.threshold" must be low, medium, high or critical, not a %s.', get_debug_type($threshold)));
        }

        return RedosSeverity::fromConfig($threshold)->value;
    }

    /**
     * "checks.complexity.threshold", 50 when unset. It is read even while
     * the section is off, as the ReDoS threshold is.
     *
     * @throws InvalidRegexOptionException when the value is not an integer of at least 1
     */
    private static function complexityThreshold(mixed $threshold): int
    {
        if (null === $threshold) {
            return 50;
        }

        if (!\is_int($threshold) || $threshold < 1) {
            throw new InvalidRegexOptionException(\sprintf('"checks.complexity.threshold" must be an integer of at least 1, not %s.', json_encode($threshold)));
        }

        return $threshold;
    }

    /**
     * The analysis that reads a pattern for the lowest version a call is
     * judged for: the target's, or the one of a version a guard or a marker
     * leaves, built once.
     */
    private function analysisAt(RegexParser $floor): AnalysisService
    {
        if ($floor === $this->scope->parser()) {
            return $this->analysis ??= $this->analysisFor($floor);
        }

        return $this->guardedFloors[$floor->target()->key()] ??= $this->analysisFor($floor);
    }

    private function analysisFor(RegexParser $parser): AnalysisService
    {
        return new AnalysisService(
            $parser,
            null,
            warningThreshold: $this->complexityThreshold,
            redosThreshold: $this->redosThreshold,
            redosMode: RedosMode::Theoretical,
            redosEnabled: $this->redosEnabled,
            lintEnabled: $this->lintEnabled,
        );
    }
}
