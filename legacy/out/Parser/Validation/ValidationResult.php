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

/**
 * Outcome of a regex validation check.
 */
final class ValidationResult implements \JsonSerializable
{
    /**
     * @readonly
     * @var bool
     */
    public $isValid;
    /**
     * @readonly
     * @var string|null
     */
    public $error;
    /**
     * @readonly
     * @var int
     */
    public $complexityScore = 0;
    /**
     * @readonly
     * @var \PHPRegex\Parser\Validation\ValidationErrorCategory|null
     */
    public $category;
    /**
     * @readonly
     * @var int|null
     */
    public $offset;
    /**
     * @readonly
     * @var string|null
     */
    public $caretSnippet;
    /**
     * @readonly
     * @var string|null
     */
    public $hint;
    /**
     * @readonly
     * @var \PHPRegex\Parser\ErrorCode|null
     */
    public $errorCode;
    /**
     * @internal built by RegexParser::validate() and Regex::validate()
     * @param ?\PHPRegex\Parser\Validation\ValidationErrorCategory::* $category
     * @param ?\PHPRegex\Parser\ErrorCode::* $errorCode
     */
    public function __construct(bool $isValid, ?string $error = null, int $complexityScore = 0, ?ValidationErrorCategory $category = null, ?int $offset = null, ?string $caretSnippet = null, ?string $hint = null, ?ErrorCode $errorCode = null)
    {
        $this->isValid = $isValid;
        $this->error = $error;
        $this->complexityScore = $complexityScore;
        $this->category = $category;
        $this->offset = $offset;
        $this->caretSnippet = $caretSnippet;
        $this->hint = $hint;
        $this->errorCode = $errorCode;
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
