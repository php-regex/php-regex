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

namespace PHPRegex\Linter;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\Internal\ForkedWorkerPool;
use PHPRegex\Linter\Internal\RedosVerdict;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\PatternParser;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;

/**
 * Handles regex-related analysis and transformations.
 *
 * @internal
 */
final readonly class AnalysisService
{
    private const PATTERN_DELIMITERS = ['/', '#', '~', '%'];
    private const ISSUE_ID_COMPLEXITY = 'regex.lint.complexity';
    private const ISSUE_ID_REDOS = 'regex.lint.redos';
    private const RISK_LINT_ISSUE_IDS = [
        'regex.lint.quantifier.nested' => true,
        'regex.lint.dotstar.nested' => true,
        'regex.lint.overlap.charset' => true,
    ];

    private RedosSeverity $redosSeverityThreshold;

    /**
     * @var array<string>
     */
    private array $ignoredPatterns;

    private RedosMode $redosMode;

    /**
     * @param string              $redosThreshold       the lowest severity reported: low, medium, high or
     *                                                  critical, in any case
     * @param array<string>       $ignoredPatterns
     * @param array<string>       $redosIgnoredPatterns
     * @param array<string, bool> $lintRules
     *
     * @throws InvalidRegexOptionException when the threshold names no severity
     */
    public function __construct(
        private RegexParser $regex,
        private ?PatternExtractor $extractor = null,
        private int $warningThreshold = 50,
        string $redosThreshold = RedosSeverity::High->value,
        array $ignoredPatterns = [],
        array $redosIgnoredPatterns = [],
        private bool $ignoreParseErrors = false,
        RedosMode|string $redosMode = RedosMode::Theoretical,
        private ?ConfirmationOptions $redosConfirmOptions = null,
        bool $redosEnabled = false,
        private bool $lintEnabled = true,
        private array $lintRules = [],
    ) {
        $this->redosSeverityThreshold = RedosSeverity::fromConfig($redosThreshold);
        $this->ignoredPatterns = $this->buildIgnoredPatterns($ignoredPatterns, $redosIgnoredPatterns);

        // When redosEnabled is false, force mode to OFF
        if (!$redosEnabled) {
            $this->redosMode = RedosMode::Off;
        } else {
            $this->redosMode = $redosMode instanceof RedosMode
                ? $redosMode
                : (RedosMode::tryFrom(strtolower((string) $redosMode)) ?? RedosMode::Theoretical);
        }
    }

    /**
     * The parser patterns are read and judged with.
     */
    public function getParser(): RegexParser
    {
        return $this->regex;
    }

    /**
     * The same analysis, judging patterns with another parser: how a lint
     * command judges for the project's target with the settings of the
     * application's service.
     */
    public function withParser(RegexParser $parser): self
    {
        return new self(
            $parser,
            $this->extractor,
            $this->warningThreshold,
            $this->redosSeverityThreshold->value,
            $this->ignoredPatterns,
            [],
            $this->ignoreParseErrors,
            $this->redosMode,
            $this->redosConfirmOptions,
            RedosMode::Off !== $this->redosMode,
            $this->lintEnabled,
            $this->lintRules,
        );
    }

    /**
     * @param array<string> $paths
     * @param array<string> $excludePaths
     *
     * @return array<PatternOccurrence>
     */
    public function scan(array $paths, array $excludePaths): array
    {
        $extractor = $this->extractor ?? new PatternExtractor(
            new TokenBasedExtractionStrategy(),
        );

        return $extractor->extract($paths, $excludePaths);
    }

    /**
     * @param array<PatternOccurrence> $patterns
     *
     * @return array<array{type: string, file: string, line: int, column: int, fileOffset?: int|null, position?: int|null, message: string, issueId?: string, hint?: string|null, tip?: string|null, source?: string, analysis?: RedosAnalysis, validation?: ValidationResult}>
     */
    public function lint(array $patterns, ?callable $progress = null, int $workers = 1): array
    {
        if ($workers <= 1 || \count($patterns) <= 1 || !$this->canRunInParallel()) {
            return $this->lintChunk($patterns, $progress);
        }

        return $this->runInParallel(
            $patterns,
            $workers,
            fn (array $chunk): array => $this->lintChunk($chunk),
            $progress,
        );
    }

    /**
     * @param array<PatternOccurrence> $patterns
     *
     * @return array<array{file: string, line: int, column?: int, fileOffset?: int|null, analysis: RedosAnalysis}>
     */
    public function analyzeRedos(array $patterns, RedosSeverity $threshold, int $workers = 1): array
    {
        if ($workers <= 1 || \count($patterns) <= 1 || !$this->canRunInParallel()) {
            return $this->analyzeRedosChunk($patterns, $threshold);
        }

        return $this->runInParallel(
            $patterns,
            $workers,
            fn (array $chunk): array => $this->analyzeRedosChunk($chunk, $threshold),
        );
    }

    /**
     * @param array<PatternOccurrence> $patterns
     * @param OptimizerOptions|null    $options  what an optimization may rewrite; by default, what lint
     *                                           allows, every rewrite checked with the automata
     *
     * @return array<array{file: string, line: int, column?: int, fileOffset?: int|null, optimization: OptimizationResult, savings: int, source?: string}>
     */
    public function suggestOptimizations(array $patterns, int $minSavings, ?OptimizerOptions $options = null, int $workers = 1): array
    {
        $options ??= new OptimizerOptions(verifyWithAutomata: true);

        if ($workers <= 1 || \count($patterns) <= 1 || !$this->canRunInParallel()) {
            return $this->suggestOptimizationsChunk($patterns, $minSavings, $options);
        }

        return $this->runInParallel(
            $patterns,
            $workers,
            fn (array $chunk): array => $this->suggestOptimizationsChunk($chunk, $minSavings, $options),
        );
    }

    public function highlight(string $pattern): string
    {
        $ast = $this->regex->parse($pattern);

        return $ast->accept(new ConsoleHighlighter());
    }

    public function highlightBody(string $body, string $flags = '', string $delimiter = '/'): string
    {
        $ast = $this->regex->parsePattern($body, $flags, $delimiter);

        return $ast->accept(new ConsoleHighlighter());
    }

    /**
     * @param array<PatternOccurrence> $patterns
     *
     * @return array<array{type: string, file: string, line: int, column: int, fileOffset?: int|null, position?: int|null, message: string, issueId?: string, hint?: string|null, tip?: string|null, source?: string, analysis?: RedosAnalysis, validation?: ValidationResult}>
     */
    private function lintChunk(array $patterns, ?callable $progress = null): array
    {
        $issues = [];

        foreach ($patterns as $occurrence) {
            if ($occurrence->isIgnored) {
                if ($progress) {
                    $progress();
                }

                continue;
            }

            $validation = $this->regex->validate($occurrence->pattern);
            $source = $occurrence->source;
            if (!$validation->isValid) {
                $message = $validation->error ?? 'Invalid regex.';
                if ($this->ignoreParseErrors && $this->isLikelyPartialRegexError($message)) {
                    if ($progress) {
                        $progress();
                    }

                    continue;
                }

                $issues[] = [
                    'type' => 'error',
                    'file' => $occurrence->file,
                    'line' => $occurrence->line,
                    'column' => $this->resolveColumn($occurrence),
                    'fileOffset' => $occurrence->fileOffset,
                    'position' => $validation->offset,
                    'message' => $message,
                    'source' => $source,
                    'validation' => $validation,
                    'tip' => $this->getTipForValidationError($message, $occurrence->pattern, $validation),
                ];

                if ($progress) {
                    $progress();
                }

                continue;
            }

            $ast = $this->regex->parse($occurrence->pattern);
            $skipRiskAnalysis = $this->shouldSkipRiskAnalysis($occurrence);
            $lintIssues = [];

            // Run linter if enabled
            if ($this->lintEnabled) {
                $linter = new PatternLinter($this->lintRules);
                $ast->accept($linter);

                foreach ($linter->getIssues() as $issue) {
                    if ($skipRiskAnalysis && isset(self::RISK_LINT_ISSUE_IDS[$issue->id])) {
                        continue;
                    }

                    // No automatic rewrite for the risk rules: wrapping the
                    // operand in an atomic group while leaving the outer
                    // quantifier in place removes the cross-iteration
                    // backtracking the engine may need, so any rewrite the
                    // user applies has to be verified by hand.
                    $issueEntry = [
                        'type' => self::issueType($issue->severity),
                        'file' => $occurrence->file,
                        'line' => $occurrence->line,
                        'column' => $this->resolveColumn($occurrence),
                        'fileOffset' => $occurrence->fileOffset,
                        'position' => $issue->offset,
                        'issueId' => $issue->id,
                        'message' => $issue->message,
                        'hint' => $issue->hint,
                        'source' => $source,
                    ];

                    $lintIssues[] = $issueEntry;
                }
            }

            $riskIssues = [];
            if (!$skipRiskAnalysis) {
                if ($validation->complexityScore >= $this->warningThreshold) {
                    $riskIssues[] = [
                        'type' => 'warning',
                        'file' => $occurrence->file,
                        'line' => $occurrence->line,
                        'column' => $this->resolveColumn($occurrence),
                        'fileOffset' => $occurrence->fileOffset,
                        'issueId' => self::ISSUE_ID_COMPLEXITY,
                        'message' => \sprintf('Pattern is complex (score: %d).', $validation->complexityScore),
                        'source' => $source,
                    ];
                }

                $redos = (new RedosAnalyzer($this->regex))->analyze(
                    $occurrence->pattern,
                    $this->redosSeverityThreshold,
                    $this->redosMode,
                    $this->redosConfirmOptions,
                );

                // The heuristic rules guess what the analysis just proved:
                // once it shows the pattern linear, their warnings go.
                if ($redos->isProvenSafe()) {
                    $lintIssues = array_values(array_filter(
                        $lintIssues,
                        static fn (array $issue): bool => !isset(self::RISK_LINT_ISSUE_IDS[$issue['issueId']]),
                    ));
                }

                if ($this->shouldReportRedos($redos, $this->redosSeverityThreshold)) {
                    $riskIssues[] = [
                        'type' => $this->resolveRedosIssueType($redos),
                        'file' => $occurrence->file,
                        'line' => $occurrence->line,
                        'column' => $this->resolveColumn($occurrence),
                        'fileOffset' => $occurrence->fileOffset,
                        'issueId' => self::ISSUE_ID_REDOS,
                        'message' => $this->formatRedosMessage($redos),
                        'hint' => $this->getReDoSHint($redos, $occurrence->pattern),
                        'source' => $source,
                        'analysis' => $redos,
                    ];
                }
            }

            array_push($issues, ...$lintIssues, ...$riskIssues);

            if ($progress) {
                $progress();
            }
        }

        return $issues;
    }

    private function resolveColumn(PatternOccurrence $occurrence): int
    {
        return $occurrence->column ?? 1;
    }

    /**
     * @param array<PatternOccurrence> $patterns
     *
     * @return array<array{file: string, line: int, column?: int, fileOffset?: int|null, analysis: RedosAnalysis}>
     */
    private function analyzeRedosChunk(array $patterns, RedosSeverity $threshold): array
    {
        $issues = [];

        foreach ($patterns as $occurrence) {
            if ($occurrence->isIgnored) {
                continue;
            }

            $validation = $this->regex->validate($occurrence->pattern);
            if (!$validation->isValid) {
                continue;
            }

            $analysis = (new RedosAnalyzer($this->regex))->analyze(
                $occurrence->pattern,
                $threshold,
                $this->redosMode,
                $this->redosConfirmOptions,
            );

            if (!$this->shouldReportRedos($analysis, $threshold)) {
                continue;
            }

            $issues[] = [
                'file' => $occurrence->file,
                'line' => $occurrence->line,
                'column' => $this->resolveColumn($occurrence),
                'fileOffset' => $occurrence->fileOffset,
                'analysis' => $analysis,
            ];
        }

        return $issues;
    }

    /**
     * @param array<PatternOccurrence> $patterns
     *
     * @return array<array{file: string, line: int, column?: int, fileOffset?: int|null, optimization: OptimizationResult, savings: int, source?: string}>
     */
    private function suggestOptimizationsChunk(array $patterns, int $minSavings, OptimizerOptions $options): array
    {
        $suggestions = [];
        $verifyWithAutomata = $options->verifyWithAutomata;

        foreach ($patterns as $occurrence) {
            if ($occurrence->isIgnored) {
                continue;
            }

            $validation = $this->regex->validate($occurrence->pattern);
            $source = $occurrence->source;
            if (!$validation->isValid) {
                continue;
            }

            $isExtended = $this->usesExtendedMode($occurrence->pattern);

            try {
                if ($isExtended) {
                    // Under /x, factorizing alternatives would reflow what the
                    // author laid out: it stays off whatever the options say.
                    $optimizer = new Rewriter(
                        optimizeDigits: $options->digits,
                        optimizeWord: $options->word,
                        ranges: $options->ranges,
                        canonicalizeCharClasses: $options->canonicalizeCharClasses,
                        autoPossessify: $options->possessive,
                        allowAlternationFactorization: false,
                        minQuantifierCount: $options->minQuantifierCount,
                    );

                    $ast = $this->regex->parse($occurrence->pattern);
                    $pretty = str_contains($ast->flags, 'x');
                    $baseline = $ast->accept(new PatternPrinter($pretty));

                    $optimizedAst = $ast->accept($optimizer);
                    $optimizedPattern = $optimizedAst->accept(new PatternPrinter($pretty));

                    if ($baseline === $optimizedPattern) {
                        continue;
                    }

                    if ($verifyWithAutomata) {
                        $isEquivalent = $this->verifyOptimizationWithAutomata($baseline, $optimizedPattern);
                        if (false === $isEquivalent) {
                            continue;
                        }
                    }

                    $optimization = new OptimizationResult($baseline, $optimizedPattern, ['Optimized pattern.']);
                } else {
                    $optimization = (new Optimizer($this->regex))->optimize($occurrence->pattern, $options);
                }
            } catch (\Throwable) {
                continue;
            }

            if (!$optimization->isChanged()) {
                continue;
            }

            $savings = \strlen($optimization->original) - \strlen($optimization->optimized);
            if ($savings < $minSavings) {
                continue;
            }

            $suggestions[] = [
                'file' => $occurrence->file,
                'line' => $occurrence->line,
                'column' => $this->resolveColumn($occurrence),
                'fileOffset' => $occurrence->fileOffset,
                'optimization' => $optimization,
                'savings' => $savings,
                'source' => $source,
            ];
        }

        return $suggestions;
    }

    /**
     * @template T
     *
     * @param array<PatternOccurrence>                    $patterns
     * @param callable(array<PatternOccurrence>):array<T> $worker
     *
     * @return array<T>
     */
    private function runInParallel(array $patterns, int $workers, callable $worker, ?callable $progress = null): array
    {
        $patternCount = \count($patterns);
        if (0 === $patternCount) {
            return [];
        }

        $workerCount = max(1, min($workers, $patternCount));
        $chunkSize = max(1, (int) ceil($patternCount / $workerCount));
        $chunks = array_chunk($patterns, $chunkSize);
        $children = [];
        $failed = false;

        foreach ($chunks as $index => $chunk) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'regexparser_');
            if (false === $tmpFile) {
                $failed = true;

                break;
            }

            $pid = (new ForkedWorkerPool())->fork(
                static fn (): array => $worker($chunk),
                $tmpFile,
            );
            if (-1 === $pid) {
                $failed = true;

                break;
            }

            $children[$pid] = [
                'file' => $tmpFile,
                'index' => $index,
                'count' => \count($chunk),
            ];
        }

        if ($failed) {
            foreach ($children as $pid => $meta) {
                pcntl_waitpid($pid, $status);
                @unlink($meta['file']);
            }

            $results = $worker($patterns);
            if ($progress) {
                for ($i = 0; $i < $patternCount; $i++) {
                    $progress();
                }
            }

            return $results;
        }

        $resultsByIndex = [];
        foreach ($children as $pid => $meta) {
            pcntl_waitpid($pid, $status);
            $payload = $this->readWorkerPayload($meta['file']);
            @unlink($meta['file']);

            if (!($payload['ok'] ?? false)) {
                $error = $payload['error'] ?? ['message' => 'Unknown worker failure.', 'class' => \RuntimeException::class];
                $errorClass = \is_array($error) && isset($error['class']) && \is_string($error['class']) ? $error['class'] : \RuntimeException::class;
                $errorMessage = \is_array($error) && isset($error['message']) && \is_string($error['message']) ? $error['message'] : 'Unknown worker failure.';

                throw new LintException(\sprintf('Parallel analysis failed: %s: %s', $errorClass, $errorMessage));
            }

            $resultsByIndex[$meta['index']] = $payload['result'] ?? [];
            if ($progress) {
                for ($i = 0; $i < $meta['count']; $i++) {
                    $progress();
                }
            }
        }

        ksort($resultsByIndex);
        $results = [];
        foreach ($resultsByIndex as $chunkResults) {
            if (!\is_array($chunkResults)) {
                continue;
            }

            foreach ($chunkResults as $item) {
                $results[] = $item;
            }
        }

        return $results;
    }

    private function canRunInParallel(): bool
    {
        return \PHP_SAPI === 'cli'
            && \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid');
    }

    /**
     * @return array{ok: bool, result?: mixed, error?: array{message: string, class: string}}
     */
    private function readWorkerPayload(string $path): array
    {
        $data = @file_get_contents($path);
        if (false === $data) {
            return [
                'ok' => false,
                'error' => [
                    'message' => 'Failed to read worker output.',
                    'class' => \RuntimeException::class,
                ],
            ];
        }

        $payload = @unserialize($data, ['allowed_classes' => self::allowedWorkerClasses()]);
        if (!\is_array($payload) || !\array_key_exists('ok', $payload) || !\is_bool($payload['ok'])) {
            return [
                'ok' => false,
                'error' => [
                    'message' => 'Invalid worker output.',
                    'class' => \RuntimeException::class,
                ],
            ];
        }

        if (false === $payload['ok']) {
            $error = $payload['error'] ?? null;
            if (!\is_array($error) || !isset($error['message'], $error['class']) || !\is_string($error['message']) || !\is_string($error['class'])) {
                return [
                    'ok' => false,
                    'error' => [
                        'message' => 'Invalid worker error payload.',
                        'class' => \RuntimeException::class,
                    ],
                ];
            }

            return [
                'ok' => false,
                'error' => [
                    'message' => $error['message'],
                    'class' => $error['class'],
                ],
            ];
        }

        return [
            'ok' => true,
            'result' => $payload['result'] ?? null,
        ];
    }

    /**
     * @return array<string>
     */
    private static function allowedWorkerClasses(): array
    {
        /** @var array<string>|null $allowed */
        static $allowed = null;
        if (null !== $allowed) {
            return $allowed;
        }

        $allowed = [
            ValidationResult::class,
            ValidationErrorCategory::class,
            OptimizationResult::class,
            RedosAnalysis::class,
        ];

        // Every class of the AST and of the ReDoS result, found next to a class
        // of each: installed on its own, a sibling package is not at ../.
        $allowed = array_merge(
            $allowed,
            self::classNamesBeside(NodeInterface::class),
            self::classNamesBeside(RedosAnalysis::class),
        );

        $allowed = array_values(array_unique($allowed));

        return $allowed;
    }

    /**
     * The classes under the directory and namespace of $class, subdirectories included.
     *
     * @param class-string $class
     *
     * @return array<string>
     */
    private static function classNamesBeside(string $class): array
    {
        $dir = \dirname((string) (new \ReflectionClass($class))->getFileName());
        $namespace = substr($class, 0, (int) strrpos($class, '\\') + 1);
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $path) {
            \assert($path instanceof \SplFileInfo);
            if ('php' !== $path->getExtension()) {
                continue;
            }

            $relative = substr($path->getPathname(), \strlen($dir) + 1, -4);
            $classes[] = $namespace.str_replace(\DIRECTORY_SEPARATOR, '\\', $relative);
        }

        return $classes;
    }

    private function shouldSkipRiskAnalysis(PatternOccurrence $occurrence): bool
    {
        $rawPattern = $occurrence->displayPattern ?? $occurrence->pattern;
        $fragment = $this->extractFragment($rawPattern);
        $body = $this->trimPatternBody($occurrence->pattern);

        return $this->isIgnored($fragment)
            || $this->isIgnored($body)
            || $this->isTriviallySafe($fragment)
            || $this->isTriviallySafe($body);
    }

    private function extractFragment(string $pattern): string
    {
        if ('' === $pattern) {
            return '';
        }

        $first = $pattern[0];
        $last = $pattern[-1];

        if ($first === $last && \in_array($first, self::PATTERN_DELIMITERS, true)) {
            $pattern = substr($pattern, 1, -1);
        }

        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        }

        if (str_ends_with($pattern, '$')) {
            $pattern = substr($pattern, 0, -1);
        }

        return $pattern;
    }

    private function trimPatternBody(string $pattern): string
    {
        if ('' === $pattern) {
            return '';
        }

        $first = $pattern[0];
        $last = $pattern[-1];

        if ($first === $last) {
            $pattern = substr($pattern, 1, -1);
        }

        if (str_starts_with($pattern, '^')) {
            $pattern = substr($pattern, 1);
        }

        if (str_ends_with($pattern, '$')) {
            $pattern = substr($pattern, 0, -1);
        }

        return $pattern;
    }

    private function isIgnored(string $body): bool
    {
        if ('' === $body) {
            return false;
        }

        return \in_array($body, $this->ignoredPatterns, true);
    }

    private function isTriviallySafe(string $body): bool
    {
        if ('' === $body) {
            return false;
        }

        $parts = explode('|', $body);
        if (\count($parts) < 2) {
            return false;
        }

        foreach ($parts as $part) {
            if (!LibraryPcre::match('#^[A-Za-z0-9._-]+$#', $part)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string> $userIgnored
     * @param array<string> $redosIgnored
     *
     * @return array<string>
     */
    private function buildIgnoredPatterns(array $userIgnored, array $redosIgnored): array
    {
        return array_values(array_unique([...$redosIgnored, ...$userIgnored]));
    }

    /**
     * Detect whether a pattern uses extended (/x) mode, where whitespace and
     * inline comments are significant for readability. We treat these patterns
     * specially to preserve comments and pretty formatting when suggesting
     * optimizations.
     */
    private function usesExtendedMode(string $pattern): bool
    {
        // Fast path: if there is no trailing flag block, there's no /x.
        $pattern = Ascii::trimLeadingSpaces($pattern);
        if ('' === $pattern) {
            return false;
        }

        try {
            /** @var array{0: string, 1: string, 2: string} $parts */
            $parts = PatternParser::extractPatternAndFlags($pattern, $this->regex->target());
        } catch (\Throwable) {
            // If we cannot reliably extract flags, fall back to not treating it
            // as extended mode to avoid false positives.
            return false;
        }

        $flags = $parts[1] ?? '';

        return \is_string($flags) && str_contains($flags, 'x');
    }

    private function getTipForValidationError(string $message, string $pattern, ValidationResult $validation): ?string
    {
        // Try to provide intelligent, pattern-specific tips
        $intelligentTip = $this->generateIntelligentTip($message, $pattern, $validation);
        if (null !== $intelligentTip) {
            return $intelligentTip;
        }

        // Fallback to generic tips
        return $this->getGenericTipForValidationError($message);
    }

    private function generateIntelligentTip(string $message, string $pattern, ValidationResult $validation): ?string
    {
        if (str_contains($message, 'No closing delimiter')) {
            return $this->suggestDelimiterFix($pattern);
        }

        if (str_contains($message, 'Unclosed character class')) {
            return $this->suggestCharacterClassFix($pattern, $validation);
        }

        if (str_contains($message, 'Invalid quantifier range')) {
            return $this->suggestQuantifierRangeFix($pattern, $validation);
        }

        if (str_contains($message, 'Backreference to non-existent group')) {
            return $this->suggestBackreferenceFix($pattern, $validation);
        }

        if (str_contains($message, 'Lookbehind is unbounded')) {
            return $this->suggestLookbehindFix($pattern, $validation);
        }

        return null;
    }

    private function suggestDelimiterFix(string $pattern): string
    {
        // Find the delimiter used
        if (!LibraryPcre::match('/^([#~\-%@!])(.*)$/', $pattern, $matches)) {
            $matches = ['', '/', $pattern];
        }

        $delimiter = $matches[1];
        $content = $matches[2];

        // Check if delimiter appears in content
        if (str_contains($content, $delimiter)) {
            $escaped = preg_quote($delimiter, '/');

            return "Your pattern contains the delimiter '$delimiter' inside. Either escape it as \\$delimiter or use a different delimiter like #pattern#.";
        }

        // Missing closing delimiter
        $suggested = $pattern.$delimiter;

        return "Add the missing closing delimiter: $suggested";
    }

    private function suggestCharacterClassFix(string $pattern, ValidationResult $validation): ?string
    {
        // For patterns like /[a-z/ we need to add ] before the final delimiter
        if (str_contains($pattern, '[') && !str_contains($pattern, ']')) {
            // Find the last delimiter
            $lastDelimiterPos = strrpos($pattern, '/');
            if (false !== $lastDelimiterPos) {
                $suggested = substr_replace($pattern, ']', $lastDelimiterPos, 0);

                return "Add missing closing bracket: $suggested";
            }
        }

        return null;
    }

    private function suggestQuantifierRangeFix(string $pattern, ValidationResult $validation): ?string
    {
        // Look for quantifier ranges in the pattern
        if (LibraryPcre::match('/\{(\d+),(\d+)\}/', $pattern, $matches)) {
            $min = (int) $matches[1];
            $max = (int) $matches[2];

            if ($min > $max) {
                $fixed = '{'.$max.','.$min.'}';
                $suggested = str_replace($matches[0], $fixed, $pattern);

                return "Swap min and max values: $suggested";
            }
        }

        return null;
    }

    private function suggestBackreferenceFix(string $pattern, ValidationResult $validation): ?string
    {
        // Find all backreferences in the pattern
        if (LibraryPcre::matchAll('/\\\\(\d+)/', $pattern, $matches)) {
            // Count opening parentheses (capturing groups)
            $openCount = substr_count($pattern, '(');

            foreach ($matches[1] as $match) {
                $refNum = (int) $match;
                if ($refNum > $openCount) {
                    return "Backreference \\$refNum refers to group $refNum, but only $openCount capturing groups exist in the pattern. Valid backreferences are \\1 through \\$openCount.";
                }
            }
        }

        return null;
    }

    private function suggestLookbehindFix(string $pattern, ValidationResult $validation): ?string
    {
        $offset = $validation->offset ?? 0;

        // Find the lookbehind content
        $before = substr($pattern, 0, $offset);
        $lookbehindStart = strrpos($before, '(?<=');

        if (false === $lookbehindStart) {
            $lookbehindStart = strrpos($before, '(?<!');
        }

        if (false === $lookbehindStart) {
            return null;
        }

        $lookbehindContent = substr($pattern, $lookbehindStart, $offset - $lookbehindStart);

        // Check for unbounded quantifiers in lookbehind
        if (LibraryPcre::match('/[+*][?]?/', $lookbehindContent)) {
            return "Replace unbounded quantifiers in lookbehind with fixed-length alternatives. For example, change (?<=\w*) to (?<=\w{0,10}) with an appropriate maximum length.";
        }

        return null;
    }

    private function getGenericTipForValidationError(string $message): ?string
    {
        if (str_contains($message, 'No closing delimiter')) {
            return 'Escape "/" inside the pattern (\/) or use a different delimiter, e.g. #pattern#.';
        }

        if (str_contains($message, 'Unclosed character class')) {
            return 'Character classes must be closed with "]". Check for missing or extra "[".';
        }

        if (str_contains($message, 'Invalid quantifier range')) {
            return 'Quantifier ranges must have min <= max. For example, {3,2} is invalid; use {2,3} or {2} instead.';
        }

        if (str_contains($message, 'Unknown regex flag')) {
            return 'Only valid PCRE flags are: i (case-insensitive), m (multiline), s (dot matches newline), x (extended), U (ungreedy), J (duplicate names).';
        }

        if (str_contains($message, 'Backreference to non-existent group')) {
            return 'Backreferences like \\1 refer to capturing groups. Make sure the group number exists.';
        }

        if (str_contains($message, 'Lookbehind is unbounded')) {
            return 'Variable-length lookbehinds are not allowed in PCRE. Use fixed-length alternatives like (?<=\w{3}) instead of (?<=\w*).';
        }

        if (str_contains($message, 'Invalid conditional construct')) {
            return 'Conditionals need a valid condition: group reference (?(1)...), lookaround (?(?=...)...), or (?(DEFINE)...).';
        }

        return null;
    }

    private function getReDoSHint(RedosAnalysis $analysis, string $pattern): string
    {
        // The attack and its replay first: a hint cut short still shows them.
        $hints = RedosVerdict::evidence($analysis);
        $evidenceCount = \count($hints);

        if (!empty($analysis->recommendations)) {
            $hints = array_merge($hints, $analysis->recommendations);
        }

        if (null !== $analysis->vulnerableSubpattern) {
            $hints[] = \sprintf('The risky part is: %s', $analysis->vulnerableSubpattern);
        }

        $hotspot = $analysis->getPrimaryHotspot();
        if (null !== $hotspot) {
            $hints[] = \sprintf('Hotspot offsets: %d-%d', $hotspot->start, $hotspot->end);
        }

        // Try to suggest specific fixes based on the pattern
        $patternHints = $this->suggestReDoSFixes($pattern, $analysis);
        if (!empty($patternHints)) {
            $hints = array_merge($hints, $patternHints);
        }

        if (\count($hints) === $evidenceCount) {
            $hints[] = 'Use possessive quantifiers (*+ instead of *, ++ instead of +, or {m,n}+ instead of {m,n}) to prevent ReDoS.';
        }

        // A replayed witness already says what the engine did, in its own line.
        // Any other reported confirmation that did not confirm is a skipped
        // one, which ran no check: its message gives the cause.
        if (RedosMode::Confirmed === $analysis->mode && null === $analysis->replayed && true === $analysis->confirmation?->confirmed) {
            $hints[] = 'Confirmation: bounded runtime checks observed evidence of excessive backtracking.';
        }

        $hints[] = 'Test with adversarial inputs like repeated strings followed by a non-matching character.';

        return implode(' ', $hints);
    }

    /**
     * @return bool|null true when equivalent, false when not, null when unsupported
     */
    private function verifyOptimizationWithAutomata(string $original, string $optimized): ?bool
    {
        try {
            $solver = new LanguageSolver($this->regex);
            $result = $solver->equivalent($original, $optimized);

            return $result->isEquivalent;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string>
     */
    private function suggestReDoSFixes(string $pattern, RedosAnalysis $analysis): array
    {
        $hints = [];

        // Look for common vulnerable patterns and suggest fixes
        if (LibraryPcre::match('/\((?:(?:[^()][^)]*)?\)\+|\([^)]*(?:\+\)\)|\)\+))/', $pattern)) {
            $hints[] = 'Suggested (verify behavior): replace nested quantifiers like (a+)+ with atomic groups (?>a+) or possessive quantifiers a++.';
        }

        if (str_contains($pattern, '.*') && str_contains($pattern, '+')) {
            $hints[] = 'Suggested (verify behavior): use possessive quantifiers .*+ instead of .* to prevent backtracking.';
        }

        if (LibraryPcre::match('/\([^)]*\*\)/', $pattern)) {
            $hints[] = 'Suggested (verify behavior): replace * with *+ in groups to prevent backtracking or wrap in (?>...).';
        }

        // If we have a vulnerable subpattern, try to suggest a specific fix
        if (null !== $analysis->vulnerableSubpattern) {
            $vulnerable = $analysis->vulnerableSubpattern;
            if (LibraryPcre::match('/(\w+)\+(\)\+)/', $vulnerable, $matches)) {
                $char = $matches[1];
                $hints[] = "Suggested (verify behavior): replace ($char+)+ with atomic group (?>$char+) or possessive $char++.";
            }
        }

        return $hints;
    }

    /**
     * The one mapping from a rule severity to an issue type: Critical and
     * Error fail the run, Warning warns, Style, Perf and Info inform.
     */
    private static function issueType(LintSeverity $severity): string
    {
        return match ($severity) {
            LintSeverity::Critical, LintSeverity::Error => 'error',
            LintSeverity::Warning => 'warning',
            LintSeverity::Style, LintSeverity::Perf, LintSeverity::Info => 'info',
        };
    }

    private function resolveRedosIssueType(RedosAnalysis $analysis): string
    {
        if (RedosVerdict::standsConfirmed($analysis) && $analysis->exceedsThreshold(RedosSeverity::High)) {
            return 'error';
        }

        return 'warning';
    }

    private function formatRedosMessage(RedosAnalysis $analysis): string
    {
        return RedosVerdict::message($analysis);
    }

    /**
     * Whether a verdict is reported: at or above the threshold, and in
     * confirmed mode only when the engine confirmed it, except a verdict the
     * engine could not replay, a proof reported as a confirmed one and a
     * heuristic verdict as a warning, and a polynomial verdict, which is
     * never replayed and is reported as it stands.
     */
    private function shouldReportRedos(RedosAnalysis $analysis, RedosSeverity $threshold): bool
    {
        if (!$analysis->exceedsThreshold($threshold)) {
            return false;
        }

        if (RedosMode::Confirmed !== $this->redosMode
            || RedosVerdict::standsConfirmed($analysis)
            || RedosVerdict::replaySkipped($analysis)) {
            return true;
        }

        return RedosComplexity::Polynomial === $analysis->complexity && null === $analysis->replayed;
    }

    private function isLikelyPartialRegexError(string $errorMessage): bool
    {
        $indicators = [
            'No closing delimiter',
            'Regex too short',
            'Unknown modifier',
            'Unexpected end',
        ];

        foreach ($indicators as $indicator) {
            if (false !== stripos($errorMessage, (string) $indicator)) {
                return true;
            }
        }

        return false;
    }
}
