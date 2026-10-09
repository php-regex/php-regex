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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPStan\Analyser\Scope;
use PHPStan\Php\PhpVersion;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The checks of one constant pattern, as the "phpRegex" parameter sets
 * them: validity for the target and the later PHPs of PHPStan's range, then
 * lint, ReDoS risks and optimizations on demand. Both rules read their
 * patterns through it.
 *
 * @internal
 */
final class PatternChecker
{
    private const ISSUE_ID_REDOS = 'regex.lint.redos';
    private const ISSUE_ID_REDOS_SEARCH = 'regex.lint.redos.search';
    private const ISSUE_ID_COMPLEXITY = 'regex.lint.complexity';
    private const MAX_PATTERN_DISPLAY_LENGTH = 50;

    private const DOC_BASE_URL = 'https://github.com/php-regex/php-regex/blob/2.x/docs/reference.md';

    private const DOC_LINKS = [
        // Flags - Use PHP.net where possible, or precise concept pages
        'Flag \'s\' is useless' => self::DOC_BASE_URL.'#useless-flag-s-dotall',
        'Flag \'m\' is useless' => self::DOC_BASE_URL.'#useless-flag-m-multiline',
        'Flag \'i\' is useless' => self::DOC_BASE_URL.'#useless-flag-i-caseless',
        'Flag \'D\' is useless' => self::DOC_BASE_URL.'#useless-flag-d-dollar-end-only',
        'Flag \'x\' is useless' => self::DOC_BASE_URL.'#useless-flag-x-extended',

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
        'regex.lint.flag.useless.D' => self::DOC_BASE_URL.'#useless-flag-d-dollar-end-only',
        'regex.lint.flag.useless.x' => self::DOC_BASE_URL.'#useless-flag-x-extended',
        'regex.lint.anchor.impossible.start' => self::DOC_BASE_URL.'#anchor-conflicts',
        'regex.lint.anchor.impossible.end' => self::DOC_BASE_URL.'#anchor-conflicts',
        'regex.lint.quantifier.nested' => self::DOC_BASE_URL.'#nested-quantifiers-redos-risk',
        'regex.lint.dotstar.nested' => self::DOC_BASE_URL.'#dot-star-in-quantifier',
        'regex.lint.quantifier.useless' => self::DOC_BASE_URL.'#useless-quantifier',
        'regex.lint.quantifier.zero' => self::DOC_BASE_URL.'#zero-quantifier',
        'regex.lint.group.redundant' => self::DOC_BASE_URL.'#redundant-non-capturing-group',
        'regex.lint.alternation.duplicateDisjunction' => self::DOC_BASE_URL.'#duplicate-alternation-branches',
        'regex.lint.alternation.empty' => self::DOC_BASE_URL.'#empty-alternatives',
        'regex.lint.alternation.overlap' => self::DOC_BASE_URL.'#overlapping-alternation-branches',
        'regex.lint.overlap.charset' => self::DOC_BASE_URL.'#overlapping-character-sets',
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
        'regex.lint.quantifier.lazyEnd' => self::DOC_BASE_URL.'#lazy-quantifier-at-the-end',
        'regex.lint.charclass.literalMetachar' => self::DOC_BASE_URL.'#literal-metacharacter-in-a-character-class',
        'regex.lint.unicode.multibyteInClassWithoutU' => self::DOC_BASE_URL.'#multibyte-character-in-a-class',
        'regex.lint.unicode.quantifiedMultibyteWithoutU' => self::DOC_BASE_URL.'#quantifier-after-a-multibyte-character',
        'regex.lint.unicode.propertyWithoutU' => self::DOC_BASE_URL.'#bytes-without-u',
        'regex.lint.quantifier.emptyRepeat' => self::DOC_BASE_URL.'#repeat-that-can-match-empty',
        'regex.lint.anchor.alternationPrecedence' => self::DOC_BASE_URL.'#anchor-precedence-in-alternation',
        'regex.lint.quantifier.possessiveImpossible' => self::DOC_BASE_URL.'#impossible-possessive-quantifier',
        'regex.lint.anchor.impossible.boundary' => self::DOC_BASE_URL.'#anchor-conflicts',
        'regex.lint.lookaround.impossible' => self::DOC_BASE_URL.'#impossible-lookaround',
        'regex.lint.group.empty' => self::DOC_BASE_URL.'#empty-group',
        'regex.lint.charclass.single' => self::DOC_BASE_URL.'#single-character-class',
        'regex.lint.literal.multipleSpaces' => self::DOC_BASE_URL.'#multiple-spaces',
        'regex.lint.quantifier.lazyToClass' => self::DOC_BASE_URL.'#lazy-quantifier-before-a-delimiter',
    ];

    /**
     * Judges patterns for the target; its cache stays in memory.
     */
    private readonly RegexParser $regex;

    /**
     * "PHP 8.2 with PCRE2 10.40", or null when the target is the running
     * engine: what it refuses is reported apart.
     */
    private readonly ?string $targetLabel;

    private readonly bool $lintEnabled;

    private readonly bool $redosEnabled;

    private readonly string $redosThreshold;

    private readonly bool $optimizationsEnabled;

    private readonly int $optimizationMinSavings;

    private readonly OptimizerOptions $optimizationOptions;

    /**
     * The later PHP versions of PHPStan's range a pattern is validated at
     * too, each with its label ("PHP 8.5 with PCRE2 10.44").
     *
     * @var list<array{RegexParser, string}>
     */
    private readonly array $range;

    private ?AnalysisService $analysis = null;

    /**
     * @param array<string, mixed> $config          the "phpRegex" parameter: "phpVersion", "pcreVersion" and
     *                                              "checks" ("lint", "redos", "optimizations"), every key optional
     * @param PhpVersion|null      $phpVersion      the PHP version PHPStan analyses the project for: patterns are
     *                                              judged for it, with the PCRE2 it bundles, unless "phpVersion"
     *                                              is "runtime" or names a version, and "pcreVersion" a release
     * @param mixed                $phpVersionRange PHPStan's "phpVersion" parameter: with {min, max}, a pattern is
     *                                              also validated at each later PHP up to max where a rule of the
     *                                              library changes, unless "phpVersion" names one version
     *
     * @throws InvalidRegexOptionException when "phpVersion", "pcreVersion" or "checks.redos.threshold" cannot be read
     */
    public function __construct(array $config = [], ?PhpVersion $phpVersion = null, mixed $phpVersionRange = null)
    {
        // Built now, so that an unreadable version stops PHPStan before any file is read.
        $this->regex = RegexParser::create(self::targetOptions($config, $phpVersion));
        $target = $this->regex->target();
        $this->range = self::rangeParsers($config, $target, $phpVersionRange);
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

    /**
     * The parser that judges patterns for the target.
     */
    public function parser(): RegexParser
    {
        return $this->regex;
    }

    public function optimizationsEnabled(): bool
    {
        return $this->optimizationsEnabled;
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
     * The errors of a pattern the running engine compiles; the caller
     * reports one it refuses (PHPStan core does in a preg_*() call).
     *
     * @param string $source where the pattern was found, as "php:preg_match()"
     *
     * @return array<IdentifierRuleError>
     */
    public function check(string $pattern, int $lineNumber, Scope $scope, string $source, bool $constantSubject): array
    {
        if (null === $this->targetLabel && [] === $this->range && !$this->lintEnabled && !$this->redosEnabled && !$this->optimizationsEnabled) {
            return [];
        }

        // What the running engine refuses is the caller's to report.
        if (!$this->runningEngineCompiles($pattern)) {
            return [];
        }

        // The floor first, then the later PHPs of PHPStan's range: the first
        // that refuses the pattern is reported.
        $targets = null === $this->targetLabel ? $this->range : [[$this->regex, $this->targetLabel], ...$this->range];
        foreach ($targets as [$parser, $label]) {
            $validation = $parser->validate($pattern);
            if (!$validation->isValid) {
                $reason = $this->firstLine($validation->error ?? 'Invalid regex.');
                $builder = RuleErrorBuilder::message(\sprintf(
                    'Regex pattern is invalid for %s: %s%s',
                    $label,
                    $reason,
                    str_ends_with($reason, '.') ? '' : '.',
                ))
                    ->line($lineNumber)
                    ->identifier(RegexPatternRule::IDENTIFIER_INVALID_FOR_TARGET);
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
            $source,
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

                if (self::ISSUE_ID_REDOS === $issueId || self::ISSUE_ID_REDOS_SEARCH === $issueId) {
                    $redosIssues[] = $issue;

                    continue;
                }

                $lintIssues[] = $issue;
            }
        }

        foreach ($constantSubject ? [] : $redosIssues as $issue) {
            $analysis = $issue['analysis'] ?? null;
            if (!$analysis instanceof RedosAnalysis) {
                continue; // never: a ReDoS issue carries its analysis; the check is for the type
            }

            // One attempt proven linear, the search retrying it is not: its
            // own message, frozen like the three others, and identifier.
            if (self::ISSUE_ID_REDOS_SEARCH === ($issue['issueId'] ?? null)) {
                $errors[] = RuleErrorBuilder::message(\sprintf('Quadratic search (ReDoS): %s', self::displayPattern($pattern)))
                    ->line($lineNumber)
                    ->tip(self::getTipForSearchCost($issue['message'], $issue['hint'] ?? null))
                    ->identifier(RegexPatternRule::IDENTIFIER_REDOS_SEARCH)
                    ->build();

                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                self::redosMessageFormat($analysis),
                self::displayPattern($pattern),
            ))
                ->line($lineNumber)
                ->tip($this->getTipForReDoS($analysis, $pattern))
                ->identifier(RegexPatternRule::IDENTIFIER_REDOS)
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
                $errors[] = RuleErrorBuilder::message(\sprintf('Regex pattern can be optimized: "%s"', self::displayPattern($pattern)))
                    ->line($lineNumber)
                    ->identifier(RegexPatternRule::IDENTIFIER_OPTIMIZATION)
                    ->tip(\sprintf('Consider using: %s', DisplayEscaper::escape($optimization->optimized)))
                    ->build();
            }
        }

        foreach ($lintIssues as $issue) {
            $issueId = $issue['issueId'] ?? null;
            if (!\is_string($issueId) || '' === $issueId) {
                continue; // a pattern the library cannot read, which the running engine compiles
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
    public function runningEngineCompiles(string $pattern): bool
    {
        return null === (new PcreEngine())->compile($pattern);
    }

    /**
     * The pattern as the console shows it, on one line with the characters
     * that move or hide text escaped in the pattern's own mode, cut after
     * its first 50 characters (bytes when the rendering is not valid UTF-8)
     * and followed by "..." when cut. The cut never splits a character, nor
     * an escape sequence such as "\x{202E}", "\xC2", "\p{L}", "\pL" or "\d": the
     * text stops before the sequence it would otherwise fall inside.
     */
    public static function displayPattern(string $pattern, int $length = self::MAX_PATTERN_DISPLAY_LENGTH): string
    {
        $rendered = DisplayEscaper::escape($pattern);
        $modifiers = 1 === LibraryPcre::match('//u', $rendered) ? 'su' : 's';
        if (1 !== LibraryPcre::match('/\A.{'.$length.'}(?=.)/'.$modifiers, $rendered, $head)) {
            return $rendered;
        }

        $cut = \strlen($head[0]);
        for ($offset = 0; $offset < $cut; $offset++) {
            if ('\\' !== $rendered[$offset]) {
                continue;
            }
            $end = self::escapeSequenceEnd($rendered, $offset);
            if ($end > $cut) {
                $cut = $offset;

                break;
            }
            $offset = $end - 1;
        }

        return substr($rendered, 0, $cut).'...';
    }

    /**
     * The offset just past the escape sequence a backslash at $offset opens:
     * "\x" with its braced code point or up to two hex digits; "\N" or "\o"
     * with its braced operand ("\N{U+41}"); "\p" or "\P" with its braced
     * operand ("\p{L}") or the one letter naming a property ("\pL", "\pZs"
     * being "\pZ" then "s"); "\g" or
     * "\k" with its operand in braces, angle brackets or quotes ("\g{1}",
     * "\k<n>", "\k'n'"), or "\g" with an optionally signed number ("\g1",
     * "\g-1", "\g+1"); "\c" with the character it controls; "\0" with up
     * to two more octal digits; "\1" to "\9" with every digit after it, the
     * octal escape or back reference PCRE reads there being no longer; an
     * operand left open runs to the end of the text. Otherwise the backslash
     * and the whole character after it.
     */
    private static function escapeSequenceEnd(string $text, int $offset): int
    {
        // The operand is optional: a lone backslash is a sequence of one.
        LibraryPcre::match('/\G\\\\(?:x(?:\{[^}]*\}?|[0-9A-Fa-f]{0,2})|[No]\{[^}]*\}?|[Pp](?:\{[^}]*\}?|[A-Za-z])|[gk](?:\{[^}]*\}?|<[^>]*>?|\'[^\']*\'?)|g[+-]?[0-9]+|c(?:[\xC0-\xFF][\x80-\xBF]*|[\x00-\xFF])?|0[0-7]{0,2}|[1-9][0-9]*|[\xC0-\xFF][\x80-\xBF]*|[\x00-\xFF])?/', $text, $match, 0, $offset);

        return $offset + max(1, \strlen($match[0] ?? ''));
    }

    private function firstLine(string $message): string
    {
        $lines = explode("\n", $message);

        return $lines[0];
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
     * A parser and its label for each PHP where a rule of the library
     * changes, above the floor and up to the max of PHPStan's range; none
     * when "phpVersion" names the version patterns are judged for, or when
     * PHPStan names one version.
     *
     * @param array<string, mixed> $config
     *
     * @return list<array{RegexParser, string}>
     */
    private static function rangeParsers(array $config, PcreTarget $floor, mixed $phpVersionRange): array
    {
        $max = \is_array($phpVersionRange) ? ($phpVersionRange['max'] ?? null) : null;
        if (null !== ($config['phpVersion'] ?? null) || !\is_int($max)) {
            return [];
        }

        $pcreVersion = $config['pcreVersion'] ?? null;
        $parsers = [];
        foreach (PcreTarget::phpVersionBoundaries() as $phpVersionId) {
            if ($phpVersionId <= $floor->phpVersionId || $phpVersionId > $max) {
                continue;
            }

            $pcre = \is_string($pcreVersion) ? $pcreVersion : PcreTarget::bundledWith($phpVersionId)->pcreVersion;
            $parsers[] = [
                RegexParser::create(['php_version' => $phpVersionId, 'pcre_version' => $pcre, 'runtime_pcre_validation' => false]),
                \sprintf('PHP %s with PCRE2 %s', ProjectTarget::phpLabel($phpVersionId), $pcre),
            ];
        }

        return $parsers;
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
     * The message of a ReDoS error, frozen for 2.x so that a verdict fix never
     * breaks a baseline: the class only when the model proved it.
     */
    private static function redosMessageFormat(RedosAnalysis $analysis): string
    {
        if (RedosProof::Proven === $analysis->proof) {
            if (RedosComplexity::Exponential === $analysis->complexity) {
                return 'Exponential backtracking (ReDoS): %s';
            }

            if (RedosComplexity::Polynomial === $analysis->complexity) {
                return 'Polynomial backtracking (ReDoS): %s';
            }
        }

        return 'Potential backtracking (ReDoS): %s';
    }

    /**
     * "critical, exponential (proven)", "high, polynomial degree 3 (proven)",
     * "medium, heuristic": the severity, then how the verdict was reached.
     */
    private static function redosVerdict(RedosAnalysis $analysis): string
    {
        $how = match ($analysis->proof) {
            // The degree is only set on a polynomial.
            RedosProof::Proven => $analysis->complexity->value
                .(null === $analysis->degree ? '' : ' degree '.$analysis->degree)
                .' (proven)',
            RedosProof::Heuristic => 'heuristic',
            RedosProof::BudgetExceeded => 'heuristic (budget exceeded)',
            RedosProof::NotAnalyzed => 'not analyzed',
        };

        return $analysis->severity->value.', '.$how;
    }

    /**
     * What the search cost means and its attack, as the lint issue says
     * them, then the documentation link. A "<" is written "\x3C", as in the
     * per-attempt tip.
     */
    private static function getTipForSearchCost(string $message, ?string $hint): string
    {
        $lines = [str_replace('<', '\x3C', $message)];
        if (null !== $hint && '' !== $hint) {
            $lines[] = str_replace('<', '\x3C', $hint);
        }

        return implode("\n", $lines)."\n\nRead more about catastrophic backtracking: ".self::DOC_LINKS['catastrophic backtracking'];
    }

    /**
     * The verdict, the attack when there is a witness, the recommendations,
     * a blank line, then the documentation links.
     */
    private function getTipForReDoS(RedosAnalysis $analysis, string $pattern): string
    {
        $lines = ['Severity: '.self::redosVerdict($analysis).'.'];
        if (null !== $analysis->witness) {
            // A "<" would open console markup in PHPStan's table output: written
            // "\x3C", the literal stays valid PHP for the same bytes. The renderer
            // prints "<" raw and never inside one of its escape sequences.
            $lines[] = 'Attack: '.str_replace('<', '\x3C', $analysis->witness->render());
        }

        // A rewrite proven to match the same subjects and proven linear.
        $repair = (new RedosRepairer($this->regex))->repair($pattern)[0] ?? null;
        if (null !== $repair && $repair->isCertified()) {
            $lines[] = \sprintf('Proven repair: %s (same subjects%s, linear).', str_replace('<', '\x3C', $repair->pattern), true === $repair->sameMatches ? ', same matches' : '');
        }

        $recommendations = implode("\n", $analysis->recommendations);
        if ('' !== $recommendations) {
            $lines[] = $recommendations;
        }

        // Append links for relevant recommendations
        $additionalLinks = [];
        if (str_contains($recommendations, 'possessive')) {
            $additionalLinks[] = 'Read more about possessive quantifiers: '.self::DOC_LINKS['possessive quantifiers'];
        }
        if (str_contains($recommendations, 'atomic groups')) {
            $additionalLinks[] = 'Read more about atomic groups: '.self::DOC_LINKS['atomic groups'];
        }

        // Always append catastrophic backtracking link for ReDoS
        $additionalLinks[] = 'Read more about catastrophic backtracking: '.self::DOC_LINKS['catastrophic backtracking'];

        return implode("\n", $lines)."\n\n".implode("\n", $additionalLinks);
    }
}
