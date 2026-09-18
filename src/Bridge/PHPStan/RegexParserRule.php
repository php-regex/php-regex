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

namespace RegexParser\Bridge\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexPatternOccurrence;
use RegexParser\OptimizationResult;
use RegexParser\ReDoS\ReDoSAnalysis;
use RegexParser\ReDoS\ReDoSMode;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\Regex;

/**
 * Validates regex patterns in `preg_*` functions for syntax and ReDoS vulnerabilities.
 *
 * @implements Rule<FuncCall>
 */
final class RegexParserRule implements Rule
{
    public const IDENTIFIER_SYNTAX_INVALID = 'regex.syntax.invalid';
    public const IDENTIFIER_SYNTAX_DELIMITER = 'regex.syntax.delimiter';
    public const IDENTIFIER_SYNTAX_EMPTY = 'regex.syntax.empty';
    public const IDENTIFIER_REDOS_CRITICAL = 'regex.redos.critical';
    public const IDENTIFIER_REDOS_HIGH = 'regex.redos.high';
    public const IDENTIFIER_REDOS_MEDIUM = 'regex.redos.medium';
    public const IDENTIFIER_REDOS_LOW = 'regex.redos.low';
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

    private readonly bool $ignoreParseErrors;

    private readonly bool $reportRedos;

    private readonly string $redosThreshold;

    private readonly string $redosMode;

    private readonly bool $suggestOptimizations;

    /**
     * @var array<string, bool|int>
     */
    private readonly array $optimizationConfig;

    private readonly int $optimizationMinSavings;

    private ?RegexAnalysisService $analysis = null;

    /**
     * @param bool   $ignoreParseErrors Ignore parse errors for partial regex strings
     * @param bool   $reportRedos       Report ReDoS risk analysis
     * @param string $redosThreshold    Minimum ReDoS severity level to report
     * @param string $redosMode         ReDoS reporting mode (off|theoretical|confirmed)
     * @param array{
     *     digits: bool,
     *     word: bool,
     *     ranges: bool,
     *     canonicalizeCharClasses?: bool,
     *     autoPossessify?: bool,
     *     allowAlternationFactorization?: bool,
     *     minQuantifierCount?: int,
     *     verifyWithAutomata?: bool
     * } $optimizationConfig
     * @param array<string, mixed> $config
     */
    public function __construct(
        bool $ignoreParseErrors = true,
        bool $reportRedos = false,
        string $redosThreshold = 'high',
        string $redosMode = 'theoretical',
        bool $suggestOptimizations = false,
        array $optimizationConfig = [
            'digits' => true,
            'word' => true,
            'ranges' => true,
            'canonicalizeCharClasses' => true,
        ],
        array $config = [],
    ) {
        $overrides = $this->normalizeConfigOverrides($config);

        $ignoreParseErrorsOverride = $overrides['ignoreParseErrors'] ?? null;
        if (\is_bool($ignoreParseErrorsOverride)) {
            $ignoreParseErrors = $ignoreParseErrorsOverride;
        }
        $this->ignoreParseErrors = $ignoreParseErrors;

        $reportRedosOverride = $overrides['reportRedos'] ?? null;
        if (\is_bool($reportRedosOverride)) {
            $reportRedos = $reportRedosOverride;
        }
        $this->reportRedos = $reportRedos;

        $redosThresholdOverride = $overrides['redosThreshold'] ?? null;
        if (\is_string($redosThresholdOverride) && '' !== $redosThresholdOverride) {
            $redosThreshold = $redosThresholdOverride;
        }
        $this->redosThreshold = $redosThreshold;

        $redosModeOverride = $overrides['redosMode'] ?? null;
        if (\is_string($redosModeOverride) && '' !== $redosModeOverride) {
            $redosMode = $redosModeOverride;
        }
        $this->redosMode = $redosMode;

        $suggestOptimizationsOverride = $overrides['suggestOptimizations'] ?? null;
        if (\is_bool($suggestOptimizationsOverride)) {
            $suggestOptimizations = $suggestOptimizationsOverride;
        }
        $this->suggestOptimizations = $suggestOptimizations;

        $optimizationMinSavings = 1;
        $optimizationMinSavingsOverride = $overrides['optimizationMinSavings'] ?? null;
        if (\is_int($optimizationMinSavingsOverride)) {
            $optimizationMinSavings = $optimizationMinSavingsOverride;
        }
        $this->optimizationMinSavings = $optimizationMinSavings;

        /** @var array<string, bool|int> $optimizationOverrides */
        $optimizationOverrides = $overrides['optimizationConfig'] ?? null;
        if (\is_array($optimizationOverrides)) {
            $optimizationConfig = $this->mergeOptimizationConfig($optimizationConfig, $optimizationOverrides);
        }
        $this->optimizationConfig = $optimizationConfig;
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
        if ('' === $pattern) {
            return [
                RuleErrorBuilder::message('Regex pattern cannot be empty.')
                    ->line($lineNumber)
                    ->identifier(self::IDENTIFIER_SYNTAX_EMPTY)
                    ->build(),
            ];
        }

        $errors = [];
        $occurrence = new RegexPatternOccurrence(
            $pattern,
            $scope->getFile(),
            $lineNumber,
            $this->formatSource($functionName),
        );
        $issues = $this->getAnalysisService()->lint([$occurrence]);

        foreach ($issues as $issue) {
            if (null === ($issue['issueId'] ?? null)) {
                $shortPattern = $this->truncatePattern($pattern);
                $message = $this->firstLine((string) ($issue['message'] ?? 'Invalid regex.'));
                $errors[] = RuleErrorBuilder::message(\sprintf('Regex syntax error: %s (Pattern: "%s")', $message, $shortPattern))
                    ->line($lineNumber)
                    ->identifier($this->getIdentifierForSyntaxError($message))
                    ->build();

                return $errors;
            }
        }

        $redosIssues = [];
        $lintIssues = [];
        foreach ($issues as $issue) {
            $issueId = $issue['issueId'];
            if (self::ISSUE_ID_COMPLEXITY === $issueId) {
                continue;
            }

            if (self::ISSUE_ID_REDOS === $issueId) {
                $redosIssues[] = $issue;

                continue;
            }

            $lintIssues[] = $issue;
        }

        foreach ($redosIssues as $issue) {
            if (!$this->reportRedos) {
                continue;
            }

            $analysis = $issue['analysis'] ?? null;
            if (!$analysis instanceof ReDoSAnalysis) {
                continue;
            }

            $identifier = match ($analysis->severity) {
                ReDoSSeverity::CRITICAL => self::IDENTIFIER_REDOS_CRITICAL,
                ReDoSSeverity::HIGH => self::IDENTIFIER_REDOS_HIGH,
                ReDoSSeverity::MEDIUM => self::IDENTIFIER_REDOS_MEDIUM,
                default => self::IDENTIFIER_REDOS_LOW,
            };

            $status = $analysis->isConfirmed()
                ? 'Confirmed ReDoS risk'
                : 'Potential ReDoS risk (theoretical)';
            $confidence = strtoupper($analysis->confidenceLevel()->value);
            $errors[] = RuleErrorBuilder::message(\sprintf(
                '%s (severity: %s, confidence: %s): %s',
                $status,
                strtoupper($analysis->severity->value),
                $confidence,
                $this->truncatePattern($pattern),
            ))
                ->line($lineNumber)
                ->tip($this->getTipForReDoS($analysis->recommendations))
                ->identifier($identifier)
                ->build();
        }

        if ($this->suggestOptimizations) {
            /**
             * @var array{
             *     digits?: bool,
             *     word?: bool,
             *     ranges?: bool,
             *     canonicalizeCharClasses?: bool,
             *     autoPossessify?: bool,
             *     allowAlternationFactorization?: bool,
             *     minQuantifierCount?: int,
             *     verifyWithAutomata?: bool
             * } $optimizationConfig
             */
            $optimizationConfig = $this->optimizationConfig;

            /** @var array<array{file: string, line: int, optimization: OptimizationResult, savings: int, source?: string}> $optimizations */
            $optimizations = $this->getAnalysisService()->suggestOptimizations(
                [$occurrence],
                $this->optimizationMinSavings,
                $optimizationConfig,
            );

            foreach ($optimizations as $optimizationEntry) {
                /** @var OptimizationResult $optimization */
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
            $issueId = $issue['issueId'];
            if (!\is_string($issueId) || '' === $issueId) {
                continue;
            }

            $builder = RuleErrorBuilder::message((string) $issue['message'])
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

    private function getIdentifierForSyntaxError(string $errorMessage): string
    {
        if (str_contains($errorMessage, 'delimiter')) {
            return self::IDENTIFIER_SYNTAX_DELIMITER;
        }

        return self::IDENTIFIER_SYNTAX_INVALID;
    }

    private function truncatePattern(string $pattern, int $length = self::MAX_PATTERN_DISPLAY_LENGTH): string
    {
        return \strlen($pattern) > $length ? substr($pattern, 0, $length).'...' : $pattern;
    }

    private function firstLine(string $message): string
    {
        $lines = explode("\n", $message);

        return $lines[0] ?? $message;
    }

    private function formatSource(string $functionName): string
    {
        return 'php:'.$functionName.'()';
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function normalizeConfigOverrides(array $config): array
    {
        /** @var array<string, mixed> $overrides */
        $overrides = [];

        if (\array_key_exists('ignoreParseErrors', $config) && \is_bool($config['ignoreParseErrors'])) {
            $overrides['ignoreParseErrors'] = $config['ignoreParseErrors'];
        }

        if (\array_key_exists('reportRedos', $config) && \is_bool($config['reportRedos'])) {
            $overrides['reportRedos'] = $config['reportRedos'];
        }

        if (
            \array_key_exists('redosMode', $config)
            && \is_string($config['redosMode'])
            && '' !== $config['redosMode']
        ) {
            $mode = ReDoSMode::tryFrom(strtolower($config['redosMode']));
            if (null !== $mode) {
                $overrides['redosMode'] = $mode->value;
            }
        }

        if (
            \array_key_exists('redosThreshold', $config)
            && \is_string($config['redosThreshold'])
            && '' !== $config['redosThreshold']
        ) {
            $threshold = ReDoSSeverity::tryFrom(strtolower($config['redosThreshold']));
            if (null !== $threshold) {
                $overrides['redosThreshold'] = $threshold->value;
            }
        }

        if (\array_key_exists('suggestOptimizations', $config) && \is_bool($config['suggestOptimizations'])) {
            $overrides['suggestOptimizations'] = $config['suggestOptimizations'];
        }

        if (\array_key_exists('optimizationConfig', $config) && \is_array($config['optimizationConfig'])) {
            /** @var array<string, mixed> $optionsConfig */
            $optionsConfig = $config['optimizationConfig'];
            $options = $this->normalizeOptimizationOptions($optionsConfig);
            if ([] !== $options) {
                $overrides['optimizationConfig'] = $options;
            }
        }

        if (\array_key_exists('minSavings', $config) && \is_int($config['minSavings'])) {
            $overrides['optimizationMinSavings'] = max(1, $config['minSavings']);
        }

        if (\array_key_exists('rules', $config) && \is_array($config['rules'])) {
            $rules = $config['rules'];
            if (\array_key_exists('redos', $rules) && \is_bool($rules['redos'])) {
                $overrides['reportRedos'] = $rules['redos'];
            }
            if (\array_key_exists('optimization', $rules) && \is_bool($rules['optimization'])) {
                $overrides['suggestOptimizations'] = $rules['optimization'];
            }
        }

        if (\array_key_exists('checks', $config) && \is_array($config['checks'])) {
            $checks = $config['checks'];
            if (\array_key_exists('redos', $checks)) {
                $overrides = $this->normalizeRedosOverrides($checks['redos'], $overrides);
            }
            if (\array_key_exists('optimizations', $checks)) {
                $overrides = $this->normalizeOptimizationOverrides($checks['optimizations'], $overrides);
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function normalizeRedosOverrides(mixed $redos, array $overrides): array
    {
        if (\is_bool($redos)) {
            $overrides['reportRedos'] = $redos;

            return $overrides;
        }

        if (!\is_array($redos)) {
            return $overrides;
        }

        $enabled = null;

        if (\array_key_exists('enabled', $redos) && \is_bool($redos['enabled'])) {
            $enabled = $redos['enabled'];
        }

        if (\array_key_exists('mode', $redos) && \is_string($redos['mode']) && '' !== $redos['mode']) {
            $mode = ReDoSMode::tryFrom(strtolower($redos['mode']));
            if (null !== $mode) {
                $overrides['redosMode'] = $mode->value;
                if (ReDoSMode::OFF === $mode) {
                    $enabled = false;
                }
            }
        }

        if (\array_key_exists('threshold', $redos) && \is_string($redos['threshold']) && '' !== $redos['threshold']) {
            $threshold = ReDoSSeverity::tryFrom(strtolower($redos['threshold']));
            if (null !== $threshold) {
                $overrides['redosThreshold'] = $threshold->value;
            }
        }

        if (null !== $enabled) {
            $overrides['reportRedos'] = $enabled;
        }

        return $overrides;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function normalizeOptimizationOverrides(mixed $optimizations, array $overrides): array
    {
        if (\is_bool($optimizations)) {
            $overrides['suggestOptimizations'] = $optimizations;

            return $overrides;
        }

        if (!\is_array($optimizations)) {
            return $overrides;
        }

        if (\array_key_exists('enabled', $optimizations) && \is_bool($optimizations['enabled'])) {
            $overrides['suggestOptimizations'] = $optimizations['enabled'];
        }

        if (\array_key_exists('minSavings', $optimizations) && \is_int($optimizations['minSavings'])) {
            $overrides['optimizationMinSavings'] = max(1, $optimizations['minSavings']);
        }

        if (\array_key_exists('options', $optimizations) && \is_array($optimizations['options'])) {
            /** @var array<string, mixed> $optionsConfig */
            $optionsConfig = $optimizations['options'];
            $options = $this->normalizeOptimizationOptions($optionsConfig);
            if ([] !== $options) {
                $overrides['optimizationConfig'] = $options;
            }
        }

        return $overrides;
    }

    /**
     * @param array<string, bool|int> $base
     * @param array<string, bool|int> $overrides
     *
     * @return array<string, bool|int>
     */
    private function mergeOptimizationConfig(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ([
            'digits',
            'word',
            'ranges',
            'canonicalizeCharClasses',
            'autoPossessify',
            'allowAlternationFactorization',
            'verifyWithAutomata',
        ] as $key) {
            if (\array_key_exists($key, $overrides) && \is_bool($overrides[$key])) {
                $merged[$key] = $overrides[$key];
            }
        }

        if (\array_key_exists('minQuantifierCount', $overrides) && \is_int($overrides['minQuantifierCount'])) {
            $merged['minQuantifierCount'] = $overrides['minQuantifierCount'];
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, bool|int>
     */
    private function normalizeOptimizationOptions(array $options): array
    {
        $normalized = [];
        $booleanMapping = [
            'digits' => 'digits',
            'word' => 'word',
            'ranges' => 'ranges',
            'canonicalizeCharClasses' => 'canonicalizeCharClasses',
            'possessive' => 'autoPossessify',
            'autoPossessify' => 'autoPossessify',
            'factorize' => 'allowAlternationFactorization',
            'allowAlternationFactorization' => 'allowAlternationFactorization',
            'verifyWithAutomata' => 'verifyWithAutomata',
        ];

        foreach ($booleanMapping as $inputKey => $targetKey) {
            if (\array_key_exists($inputKey, $options) && \is_bool($options[$inputKey])) {
                $normalized[$targetKey] = $options[$inputKey];
            }
        }

        if (\array_key_exists('minQuantifierCount', $options) && \is_int($options['minQuantifierCount'])) {
            $normalized['minQuantifierCount'] = $options['minQuantifierCount'];
        }

        return $normalized;
    }

    private function getAnalysisService(): RegexAnalysisService
    {
        return $this->analysis ??= new RegexAnalysisService(
            Regex::create(),
            null,
            redosThreshold: $this->redosThreshold,
            redosMode: $this->redosMode,
            ignoreParseErrors: $this->ignoreParseErrors,
            redosEnabled: $this->reportRedos,
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
