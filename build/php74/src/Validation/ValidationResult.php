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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Node\RegexNode;

/**
 * Outcome of a regex validation check.
 */
final class ValidationResult implements \JsonSerializable
{
    /**
     */
    public bool $isValid;
    /**
     */
    public ?string $error = null;
    /**
     */
    public int $complexityScore = 0;
    /**
     */
    public ?ValidationErrorCategory $category = null;
    /**
     */
    public ?int $offset = null;
    /**
     */
    public ?string $caretSnippet = null;
    /**
     */
    public ?string $hint = null;
    /**
     */
    public ?ErrorCode $errorCode = null;
    /**
     */
    public ?RegexNode $ast = null;

    /**
     * @internal built by RegexParser::validate() and Regex::validate()
     *
     * @param RegexNode|null $ast the tree of a valid pattern, so one
     *                            validate() call gives the verdict and the
     *                            tree; null whenever the pattern is not valid
     */
    public function __construct(bool $isValid, ?string $error = null, int $complexityScore = 0, ?ValidationErrorCategory $category = null, ?int $offset = null, ?string $caretSnippet = null, ?string $hint = null, ?ErrorCode $errorCode = null, ?RegexNode $ast = null)
    {
        $this->isValid = $isValid;
        $this->error = $error;
        $this->complexityScore = $complexityScore;
        $this->category = $category;
        $this->offset = $offset;
        $this->caretSnippet = $caretSnippet;
        $this->hint = $hint;
        $this->errorCode = $errorCode;
        $this->ast = $ast;
    }

    /**
     * Whether the library stopped before reading the whole pattern: its
     * own limit — length, recursion, anything it bounds — was reached.
     * Never a verdict on the pattern: the engine may well take what the
     * library stopped reading, so a consumer degrades, it does not
     * refuse.
     */
    public function isLimit(): bool
    {
        return !$this->isValid && ValidationErrorCategory::Limit() === $this->category;
    }

    /**
     * Whether the pattern is refused — for the engine the library judges,
     * or by its own grammar. Null when the run said nothing: the pattern
     * is valid, or the library stopped at a limit of its own.
     */
    public function isPatternRefused(): ?bool
    {
        if ($this->isValid) {
            return null;
        }

        return !$this->isLimit();
    }

    /**
     * The result as plain text, as a console prints it: the message, the
     * line of the pattern with a caret under the fault, then the hint
     * ("Hint: …"), each part left out when the result does not carry it.
     * An empty string for a valid pattern. No colour, no trailing newline.
     */
    public function render(): string
    {
        if ($this->isValid) {
            return '';
        }

        $lines = [];
        foreach ([$this->error, $this->caretSnippet] as $part) {
            if (null !== $part && '' !== $part) {
                $lines[] = $part;
            }
        }

        if (null !== $this->hint && '' !== $this->hint) {
            $lines[] = 'Hint: '.$this->hint;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array{is_valid: bool, error: string|null, complexity_score: int, category: string|null, offset: int|null, caret_snippet: string|null, hint: string|null, error_code: string|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'is_valid' => $this->isValid,
            'error' => $this->error,
            'complexity_score' => $this->complexityScore,
            'category' => ($nullsafeVariable1 = $this->category) ? $nullsafeVariable1->value : null,
            'offset' => $this->offset,
            'caret_snippet' => $this->caretSnippet,
            'hint' => $this->hint,
            'error_code' => ($nullsafeVariable2 = $this->errorCode) ? $nullsafeVariable2->value : null,
        ];
    }
}
