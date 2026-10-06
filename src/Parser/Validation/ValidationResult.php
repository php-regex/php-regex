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
final readonly class ValidationResult implements \JsonSerializable
{
    /**
     * @internal built by RegexParser::validate() and Regex::validate()
     */
    public function __construct(
        public bool $isValid,
        public ?string $error = null,
        public int $complexityScore = 0,
        public ?ValidationErrorCategory $category = null,
        public ?int $offset = null,
        public ?string $caretSnippet = null,
        public ?string $hint = null,
        public ?ErrorCode $errorCode = null,
    ) {}

    /**
     * @deprecated read the public $isValid property instead
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * @deprecated read the public $error property instead
     */
    public function getErrorMessage(): ?string
    {
        return $this->error;
    }

    public function getComplexityScore(): int
    {
        return $this->complexityScore;
    }

    public function getErrorCategory(): ?ValidationErrorCategory
    {
        return $this->category;
    }

    public function getErrorOffset(): ?int
    {
        return $this->offset;
    }

    public function getCaretSnippet(): ?string
    {
        return $this->caretSnippet;
    }

    public function getHint(): ?string
    {
        return $this->hint;
    }

    public function getErrorCode(): ?ErrorCode
    {
        return $this->errorCode;
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
            'category' => $this->category?->value,
            'offset' => $this->offset,
            'caret_snippet' => $this->caretSnippet,
            'hint' => $this->hint,
            'error_code' => $this->errorCode?->value,
        ];
    }
}
