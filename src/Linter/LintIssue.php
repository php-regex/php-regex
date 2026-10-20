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

use PHPRegex\Linter\Config\ProjectTarget;
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
     * @param TargetDescription|null $target
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
    ) {}

    /**
     * A finding in the pattern of an occurrence, placed where it is.
     *
     * @param TargetDescription|null $target
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
    ): self {
        return new self($severity, $message, $occurrence->file, $occurrence->line, $occurrence->column, $occurrence->fileOffset, $position, $issueId, $hint, $tip, $occurrence->source, $validation, $analysis, $target);
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
     * The same finding, its file named as the reports name it.
     */
    public function withFile(string $file): self
    {
        return new self($this->severity, $this->message, $file, $this->line, $this->column, $this->fileOffset, $this->position, $this->issueId, $this->hint, $this->tip, $this->source, $this->validation, $this->analysis, $this->target);
    }

    /**
     * The same finding, reported by the PHP and PCRE2 of $target.
     *
     * @param TargetDescription $target
     */
    public function withTarget(array $target): self
    {
        return new self($this->severity, $this->message, $this->file, $this->line, $this->column, $this->fileOffset, $this->position, $this->issueId, $this->hint, $this->tip, $this->source, $this->validation, $this->analysis, $target);
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

        // The attack, already escaped, and its replay: the reports that only
        // keep the message and the suggestion still show them.
        $evidence = RedosVerdict::evidence($this->analysis);

        return new Diagnostic(DiagnosticType::Security, $this->severity, $this->message, $this->issueId, [] !== $evidence ? implode("\n", $evidence) : null, $this->analysis->recommendations[0] ?? null);
    }
}
