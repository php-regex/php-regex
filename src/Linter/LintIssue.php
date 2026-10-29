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

use PHPRegex\Linter\Advice\RedosHints;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;

/**
 * One finding of a lint run: where it lies, what was found, and how bad it
 * is. Every report reads it: the JSON report key by key, the console its
 * message and hint, the GitHub, Checkstyle and JUnit reports its
 * diagnostic, a baseline its identifier.
 *
 * $file, $line, $column and $fileOffset place the pattern in its file,
 * $position the finding in the pattern's body. $validation is the verdict
 * on a pattern that does not compile, at the floor or at the version of
 * $target; $analysis the verdict of a ReDoS finding; $target the PHP and
 * PCRE2 that reported the finding, when it is not the floor.
 *
 * @internal
 *
 * @phpstan-import-type TargetDescription from ProjectTarget
 */
final class LintIssue
{
    /**
     * The lines of the hint, one sentence each: what the console lists
     * behind its arrow; the hint itself is their join for the reports that
     * keep one string.
     *
     * @var list<string>
     */
    public readonly array $hints;

    /**
     * @param TargetDescription|null $target
     * @param list<string>|null      $hints
     */
    public function __construct(
        public readonly LintSeverity $severity,
        public readonly string $message,
        public readonly string $file,
        public readonly int $line,
        public readonly ?int $column = null,
        public readonly ?int $fileOffset = null,
        public readonly ?int $position = null,
        public readonly ?string $issueId = null,
        public readonly ?string $hint = null,
        public readonly ?string $tip = null,
        public readonly ?string $source = null,
        public readonly ?ValidationResult $validation = null,
        public readonly ?RedosAnalysis $analysis = null,
        /**
         * @var TargetDescription|null
         */
        public readonly ?array $target = null,
        ?array $hints = null,
    ) {
        $this->hints = $hints ?? (null === $hint ? [] : [$hint]);
    }

    /**
     * A finding in the pattern of an occurrence, placed where it is.
     *
     * @param TargetDescription|null $target
     * @param list<string>|null      $hints
     */
    public static function at(
        PatternOccurrence $occurrence,
        LintSeverity $severity,
        string $message,
        ?string $issueId = null,
        ?int $position = null,
        ?string $hint = null,
        ?string $tip = null,
        ?ValidationResult $validation = null,
        ?RedosAnalysis $analysis = null,
        ?array $target = null,
        ?array $hints = null,
    ): self {
        return new self($severity, $message, $occurrence->file, $occurrence->line, $occurrence->column, $occurrence->fileOffset, $position, $issueId, $hint, $tip, $occurrence->source, $validation, $analysis, $target, $hints);
    }

    /**
     * The identifier the finding is reported, silenced and baselined under:
     * its own, else the error code of the pattern that does not compile.
     */
    public function id(): ?string
    {
        return $this->issueId ?? $this->validation?->errorCode?->value;
    }

    /**
     * The section of the docs that explains the finding, by its
     * identifier; null when the docs hold none of its own, as for the error
     * code of a pattern that does not compile.
     */
    public function docsUrl(): ?string
    {
        $id = $this->id();

        return null === $id ? null : RuleCatalog::docsUrl($id);
    }

    /**
     * The same finding, its file named as the reports name it.
     */
    public function withFile(string $file): self
    {
        return new self($this->severity, $this->message, $file, $this->line, $this->column, $this->fileOffset, $this->position, $this->issueId, $this->hint, $this->tip, $this->source, $this->validation, $this->analysis, $this->target, $this->hints);
    }

    /**
     * The same finding, reported by the PHP and PCRE2 of $target.
     *
     * @param TargetDescription $target
     */
    public function withTarget(array $target): self
    {
        return new self($this->severity, $this->message, $this->file, $this->line, $this->column, $this->fileOffset, $this->position, $this->issueId, $this->hint, $this->tip, $this->source, $this->validation, $this->analysis, $target, $this->hints);
    }

    /**
     * The finding as the GitHub, Checkstyle and JUnit reports show it: its
     * kind, the snippet under its message and the suggestion after it.
     */
    public function diagnostic(): Diagnostic
    {
        if (null !== $this->validation) {
            $type = match ($this->validation->category) {
                ValidationErrorCategory::Semantic => DiagnosticType::Semantic,
                ValidationErrorCategory::Limit => DiagnosticType::Limit,
                default => DiagnosticType::Syntax,
            };

            return new Diagnostic($type, $this->severity, $this->message, $this->validation->errorCode?->value, $this->validation->caretSnippet, $this->validation->hint);
        }

        if (null === $this->analysis) {
            return new Diagnostic(DiagnosticType::Lint, $this->severity, $this->message, $this->issueId, null, $this->hint);
        }

        // A search cost stands beside a linear attempt: its own attack, and
        // no advice of the per-attempt verdict.
        $searchCost = $this->analysis->searchCost;
        if (RedosVerdict::SEARCH_ISSUE_ID === $this->issueId && null !== $searchCost) {
            return new Diagnostic(DiagnosticType::Security, $this->severity, $this->message, $this->issueId, implode("\n", RedosVerdict::searchEvidence($searchCost, $this->analysis->pcreVersion)));
        }

        // The attack, already escaped, its replay, then what each finding
        // says the risk is: the reports that keep only the message and the
        // suggestion still show the evidence and the diagnosis.
        $lines = [...RedosVerdict::evidence($this->analysis), ...RedosHints::messages($this->analysis)];

        return new Diagnostic(DiagnosticType::Security, $this->severity, $this->message, $this->issueId, [] !== $lines ? implode("\n", $lines) : null, $this->suggestion($this->analysis));
    }

    /**
     * The rewrite the hint lists first, without its prefix: what the
     * console lists first too. An issue built without its hint lines
     * falls back on the first recommendation of its analysis.
     */
    private function suggestion(RedosAnalysis $analysis): ?string
    {
        foreach ($this->hints as $line) {
            if (str_starts_with($line, RedosHints::SUGGESTION)) {
                return substr($line, \strlen(RedosHints::SUGGESTION));
            }
        }

        return $analysis->recommendations[0] ?? null;
    }
}
