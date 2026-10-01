<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\PatternOccurrence;
use PhpRegex\Optimizer\OptimizationResult;
use PhpRegex\Optimizer\OptimizerOptions;
use PhpRegex\Parser\Engine\PcreEngine;
use PhpRegex\Parser\Exception\InvalidRegexOptionException;
use PhpRegex\Parser\RegexParser;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosMode;
use PhpRegex\Redos\RedosSeverity;
use PHPStan\Analyser\Scope;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports, in `preg_*` calls, the patterns the targeted PHP and PCRE2 refuse
 * while the engine running PHPStan compiles them (PHPStan core reports what
 * the running engine refuses); lint, ReDoS risks and optimizations on demand.
 *
 * @implements Rule<FuncCall>
 */
final class RegexPatternRule implements Rule
{
    public const IDENTIFIER_INVALID_FOR_TARGET = 'regex.invalidForTarget';
    public const IDENTIFIER_REDOS = 'regex.redos';
    public const IDENTIFIER_OPTIMIZATION = 'regex.optimization';

    private const ISSUE_ID_REDOS = 'regex.lint.redos';
    private const ISSUE_ID_COMPLEXITY = 'regex.lint.complexity';
    private const MAX_PATTERN_DISPLAY_LENGTH = 50;

    private const PREG_FUNCTION_MAP = [
        'preg_match' => 0,
        'preg_match_all' => 0,
        'preg_replace' => 0,
        'preg_replace_callback' => 0,
        'preg_split' => 0,
        'preg_grep' => 0,
        'preg_filter' => 0,
        'preg_replace_callback_array' => 0,
    ];

    private const DOC_BASE_URL = 'https://github.com/php-regex/regex-parser/blob/main/docs/reference.md';

    private const DOC_LINKS = [
        // Flags - Use PHP.net where possible, or precise concept pages
        'Flag \'s\' is useless' => self::DOC_BASE_URL.'#useless-flag-s-dotall',
        'Flag \'m\' is useless' => self::DOC_BASE_URL.'#useless-flag-m-multiline',
        'Flag \'i\' is useless' => self::DOC_BASE_URL.'#useless-flag-i-caseless',

        // Security & Concepts - The authority on explaining regex mechanics
        'catastrophic backtracking' => self::DOC_BASE_URL.'#catastrophic-backtracking',

        // Advanced Syntax - Internal documentation
        'possessive quantifiers' => self::DOC_BASE_URL.'#possessive-quantifiers',
        'atomic groups' => self::DOC_BASE_URL.'#atomic-groups',

        // Assertions
        'lookahead' => self::DOC_BASE_URL.'#assertions',
        'lookbehind' => self::DOC_BASE_URL.'#assertions',
    ];

    private const LINT_DOC_LINKS = [
        'regex.lint.flag.useless.s' => self::DOC_BASE_URL.'#useless-flag-s-dotall',
        'regex.lint.flag.useless.m' => self::DOC_BASE_URL.'#useless-flag-m-multiline',
        'regex.lint.flag.useless.i' => self::DOC_BASE_URL.'#useless-flag-i-caseless',
        'regex.lint.anchor.impossible.start' => self::DOC_BASE_URL.'#anchor-conflicts',
        'regex.lint.anchor.impossible.end' => self::DOC_BASE_URL.'#anchor-conflicts',
        'regex.lint.quantifier.nested' => self::DOC_BASE_URL.'#nested-quantifiers',
        'regex.lint.dotstar.nested' => self::DOC_BASE_URL.'#dot-star-in-quantifier',
        'regex.lint.quantifier.useless' => self::DOC_BASE_URL.'#useless-quantifier',
        'regex.lint.quantifier.zero' => self::DOC_BASE_URL.'#zero-quantifier',
        'regex.lint.group.redundant' => self::DOC_BASE_URL.'#redundant-non-capturing-group',
        'regex.lint.alternation.duplicateDisjunction' => self::DOC_BASE_URL.'#duplicate-alternation-branches',
        'regex.lint.alternation.empty' => self::DOC_BASE_URL.'#empty-alternatives',
        'regex.lint.alternation.overlap' => self::DOC_BASE_URL.'#overlapping-alternation-branches',
        'regex.lint.overlap.charset' => self::DOC_BASE_URL.'#overlapping-alternation-branches',
        'regex.lint.backref.useless' => self::DOC_BASE_URL.'#useless-backreferences',
        'regex.lint.charclass.redundant' => self::DOC_BASE_URL.'#redundant-character-class-elements',
        'regex.lint.charclass.duplicateChars' => self::DOC_BASE_URL.'#duplicate-character-class-elements',
        'regex.lint.range.useless' => self::DOC_BASE_URL.'#useless-character-range',
        'regex.lint.charclass.suspiciousRange' => self::DOC_BASE_URL.'#suspicious-ascii-ranges',
        'regex.lint.charclass.suspiciousPipe' => self::DOC_BASE_URL.'#alternation-like-character-classes',
        'regex.lint.escape.suspicious' => self::DOC_BASE_URL.'#suspicious-escapes',
        'regex.lint.flag.redundant' => self::DOC_BASE_URL.'#inline-flag-redundant',
        'regex.lint.flag.override' => self::DOC_BASE_URL.'#inline-flag-override',
        'regex.lint.quantifier.concatenation' => self::DOC_BASE_URL.'#optimal-quantifier-concatenation',
    ];

    /**
     * Judges patterns for the target; its cache stays in memory.
     */
    private readonly RegexParser $regex;

    /**
     * "PHP 8.2 with PCRE2 10.40", or null when the target is the running
     * engine: PHPStan core reports all it refuses.
     */
    private readonly ?string $targetLabel;

    private readonly bool $lintEnabled;

    private readonly bool $redosEnabled;

    private readonly string $redosThreshold;

    private readonly bool $optimizationsEnabled;

    private readonly int $optimizationMinSavings;

    private readonly OptimizerOptions $optimizationOptions;

    private ?AnalysisService $analysis = null;

    /**
     * @param array<string, mixed> $config     the "phpRegex" parameter: "phpVersion", "pcreVersion" and
     *                                         "checks" ("lint", "redos", "optimizations"), every key optional
     * @param PhpVersion|null      $phpVersion the PHP version PHPStan analyses the project for: patterns are
     *                                         judged for it, with the PCRE2 it bundles, unless "phpVersion"
     *                                         is "runtime" or names a version, and "pcreVersion" a release
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion" or "checks.redos.threshold" cannot be read
     */
    public function __construct(array $config = [], ?PhpVersion $phpVersion = null)
    {
        // Built now, so that an unreadable version stops PHPStan before any file is read.
        $this->regex = RegexParser::create(self::targetOptions($config, $phpVersion));
        $target = $this->regex->target();
        $this->targetLabel = $target->isRunningEngine() ? null : \sprintf(
            'PHP %d.%d with PCRE2 %s',
            intdiv($target->phpVersionId, 10000),
            intdiv($target->phpVersionId, 100) % 100,
            $target->pcreVersion,
        );

        $checks = self::section($config, 'checks');
        $lint = self::section($checks, 'lint');
        $redos = self::section($checks, 'redos');
        $optimizations = self::section($checks, 'optimizations');

        $this->lintEnabled = true === ($lint['enabled'] ?? false);
        $this->redosEnabled = true === ($redos['enabled'] ?? false);
        $this->redosThreshold = self::redosThreshold($redos['threshold'] ?? null);
        $this->optimizationsEnabled = true === ($optimizations['enabled'] ?? false);
        $minSavings = $optimizations['minSavings'] ?? null;
        $this->optimizationMinSavings = \is_int($minSavings) ? max(1, $minSavings) : 1;
        // The PHPStan parameters name the options in camelCase; every rewrite
        // is checked with the automata unless the parameters say otherwise.
        $this->optimizationOptions = OptimizerOptions::fromCamelCaseArray(self::section($optimizations, 'options') + ['verifyWithAutomata' => true]);
    }

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * @return array<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof FuncCall) {
            return [];
        }

        if (!$node->name instanceof Name) {
            return [];
        }

        $functionName = $node->name->toLowerString();
        if (!isset(self::PREG_FUNCTION_MAP[$functionName])) {
            return [];
        }

        $patternArgPosition = self::PREG_FUNCTION_MAP[$functionName];
        $args = $node->getArgs();

        if (!isset($args[$patternArgPosition])) {
            return [];
        }

        $patternArg = $args[$patternArgPosition]->value;

        if ('preg_replace_callback_array' === $functionName) {
            return $this->processPregReplaceCallbackArray($patternArg, $scope, $node->getLine(), $functionName);
        }

        $errors = [];
        foreach ($scope->getType($patternArg)->getConstantStrings() as $constantString) {
            $errors = array_merge(
                $errors,
                $this->validatePattern($constantString->getValue(), $node->getLine(), $scope, $functionName),
            );
        }

        return $errors;
    }

    public function isOptimizationFormatSafe(string $original, string $optimized): bool
    {
        $delimiter = $optimized[0] ?? '';
        if ('' === $delimiter) {
            return false;
        }

        $lastDelimiterPos = strrpos($optimized, $delimiter);
        if (false === $lastDelimiterPos || 0 === $lastDelimiterPos) {
            return false;
        }

        $patternPart = substr($optimized, 1, $lastDelimiterPos - 1);

        $originalDelimiter = $original[0] ?? '';
        $originalPatternPart = '';
        if ('' !== $originalDelimiter) {
            $originalLastPos = strrpos($original, $originalDelimiter);
            if (false !== $originalLastPos) {
                $originalPatternPart = substr($original, 1, $originalLastPos - 1);
            }
        }

        if ('' === $patternPart) {
            return false;
        }

        if (\strlen($patternPart) < 2) {
            return false;
        }

        if (str_contains($originalPatternPart, '\n') && !str_contains($patternPart, '\n')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<IdentifierRuleError>
     */
    private function processPregReplaceCallbackArray(Node $arrayNode, Scope $scope, int $lineNumber, string $functionName): array
    {
        if (!$arrayNode instanceof Array_) {
            return [];
        }

        $errors = [];
        foreach ($arrayNode->items as $item) {
            if (!$item instanceof ArrayItem || !$item->key instanceof String_) {
                continue;
            }

            $pattern = $item->key->value;
            $errors = array_merge(
                $errors,
                $this->validatePattern($pattern, $lineNumber, $scope, $functionName),
            );
        }

        return $errors;
    }

    /**
     * @return array<IdentifierRuleError>
     */
    private function validatePattern(string $pattern, int $lineNumber, Scope $scope, string $functionName): array
    {
        if (null === $this->targetLabel && !$this->lintEnabled && !$this->redosEnabled && !$this->optimizationsEnabled) {
            return [];
        }

        // What the running engine refuses, PHPStan core reports ("regexp.pattern").
        if (!$this->runningEngineCompiles($pattern)) {
            return [];
        }

        if (null !== $this->targetLabel) {
            $validation = $this->regex->validate($pattern);
            if (!$validation->isValid) {
                $reason = $this->firstLine($validation->error ?? 'Invalid regex.');
                $builder = RuleErrorBuilder::message(\sprintf(
                    'Regex pattern is invalid for %s: %s%s',
                    $this->targetLabel,
                    $reason,
                    str_ends_with($reason, '.') ? '' : '.',
                ))
                    ->line($lineNumber)
                    ->identifier(self::IDENTIFIER_INVALID_FOR_TARGET);
                if (null !== $validation->hint && '' !== $validation->hint) {
                    $builder = $builder->tip($validation->hint);
                }

                return [$builder->build()];
            }
        }

        if (!$this->lintEnabled && !$this->redosEnabled && !$this->optimizationsEnabled) {
            return [];
        }

        $errors = [];
        $occurrence = new PatternOccurrence(
            $pattern,
            $scope->getFile(),
            $lineNumber,
            $this->formatSource($functionName),
        );

        $redosIssues = [];
        $lintIssues = [];
        if ($this->lintEnabled || $this->redosEnabled) {
            foreach ($this->getAnalysisService()->lint([$occurrence]) as $issue) {
                // A pattern the library cannot read comes back without an
                // issue id, alone: nothing below reports it.
                $issueId = $issue['issueId'] ?? null;
                if (self::ISSUE_ID_COMPLEXITY === $issueId) {
                    continue;
                }

                if (self::ISSUE_ID_REDOS === $issueId) {
                    $redosIssues[] = $issue;

                    continue;
                }

                $lintIssues[] = $issue;
            }
        }

        foreach ($redosIssues as $issue) {
            $analysis = $issue['analysis'] ?? null;
            if (!$analysis instanceof RedosAnalysis) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Potential ReDoS risk (theoretical) (severity: %s, confidence: %s): %s',
                strtoupper($analysis->severity->value),
                strtoupper($analysis->confidenceLevel()->value),
                $this->truncatePattern($pattern),
            ))
                ->line($lineNumber)
                ->tip($this->getTipForReDoS($analysis->recommendations))
                ->identifier(self::IDENTIFIER_REDOS)
                ->build();
        }

        if ($this->optimizationsEnabled) {
            /** @var array<array{file: string, line: int, optimization: OptimizationResult, savings: int, source?: string}> $optimizations */
            $optimizations = $this->getAnalysisService()->suggestOptimizations(
                [$occurrence],
                $this->optimizationMinSavings,
                $this->optimizationOptions,
            );

            foreach ($optimizations as $optimizationEntry) {
                $optimization = $optimizationEntry['optimization'];
                if (!$this->isOptimizationFormatSafe($pattern, $optimization->optimized)) {
                    continue;
                }
                $shortPattern = $this->truncatePattern($pattern);
                $errors[] = RuleErrorBuilder::message(\sprintf('Regex pattern can be optimized: "%s"', $shortPattern))
                    ->line($lineNumber)
                    ->identifier(self::IDENTIFIER_OPTIMIZATION)
                    ->tip(\sprintf('Consider using: %s', $optimization->optimized))
                    ->build();
            }
        }

        foreach ($lintIssues as $issue) {
            $issueId = $issue['issueId'] ?? null;
            if (!\is_string($issueId) || '' === $issueId) {
                continue;
            }

            $builder = RuleErrorBuilder::message($issue['message'])
                ->line($lineNumber)
                ->identifier($issueId);
            $tipParts = [];
            $hint = $issue['hint'] ?? null;
            if (null !== $hint && '' !== $hint) {
                $tipParts[] = $hint;
            }
            if (isset(self::LINT_DOC_LINKS[$issueId])) {
                $tipParts[] = 'Read more: '.self::LINT_DOC_LINKS[$issueId];
            }
            if ([] !== $tipParts) {
                $builder = $builder->tip(implode("\n", $tipParts));
            }
            $errors[] = $builder->build();
        }

        return $errors;
    }

    /**
     * Whether the engine running PHPStan compiles the pattern.
     */
    private function runningEngineCompiles(string $pattern): bool
    {
        return null === (new PcreEngine())->compile($pattern);
    }

    private function truncatePattern(string $pattern, int $length = self::MAX_PATTERN_DISPLAY_LENGTH): string
    {
        return \strlen($pattern) > $length ? substr($pattern, 0, $length).'...' : $pattern;
    }

    private function firstLine(string $message): string
    {
        $lines = explode("\n", $message);

        return $lines[0];
    }

    private function formatSource(string $functionName): string
    {
        return 'php:'.$functionName.'()';
    }

    /**
     * The "php_version" and "pcre_version" options of the target: PHPStan's
     * PHP version with the PCRE2 it bundles by default, the running PHP and
     * the PCRE2 it links when that version is the running one or the setting
     * is "runtime", else the version the setting names.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function targetOptions(array $config, ?PhpVersion $phpVersion): array
    {
        $options = [];
        $setting = $config['phpVersion'] ?? null;
        if (null === $setting) {
            if (null !== $phpVersion && \PHP_VERSION_ID !== $phpVersion->getVersionId()) {
                $options['php_version'] = $phpVersion->getVersionId();
            }
        } elseif ('runtime' !== $setting) {
            $options['php_version'] = $setting;
        }

        $pcreVersion = $config['pcreVersion'] ?? null;
        if (null !== $pcreVersion) {
            $options['pcre_version'] = $pcreVersion;
        }

        return $options;
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
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function section(array $config, string $key): array
    {
        $section = $config[$key] ?? null;

        return \is_array($section) ? $section : [];
    }

    private function getAnalysisService(): AnalysisService
    {
        return $this->analysis ??= new AnalysisService(
            $this->regex,
            null,
            redosThreshold: $this->redosThreshold,
            redosMode: RedosMode::Theoretical,
            redosEnabled: $this->redosEnabled,
            lintEnabled: $this->lintEnabled,
        );
    }

    /**
     * @param array<string> $recommendations
     */
    private function getTipForReDoS(array $recommendations): string
    {
        $tip = implode("\n", $recommendations);

        // Append links for relevant recommendations
        $additionalLinks = [];
        if (str_contains($tip, 'possessive quantifiers') || str_contains($tip, 'possessive')) {
            $additionalLinks[] = 'Read more about possessive quantifiers: '.self::DOC_LINKS['possessive quantifiers'];
        }
        if (str_contains($tip, 'atomic groups')) {
            $additionalLinks[] = 'Read more about atomic groups: '.self::DOC_LINKS['atomic groups'];
        }

        // Always append catastrophic backtracking link for ReDoS
        $additionalLinks[] = 'Read more about catastrophic backtracking: '.self::DOC_LINKS['catastrophic backtracking'];

        $tip .= "\n\n".implode("\n", $additionalLinks);

        return $tip;
    }
}
