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
     * @internal built by RegexParser::validate() and Regex::validate()
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly ?string $error = null,
        public readonly int $complexityScore = 0,
        public readonly ?ValidationErrorCategory $category = null,
        public readonly ?int $offset = null,
        public readonly ?string $caretSnippet = null,
        public readonly ?string $hint = null,
        public readonly ?ErrorCode $errorCode = null,
    ) {}

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
            'category' => $this->category?->value,
            'offset' => $this->offset,
            'caret_snippet' => $this->caretSnippet,
            'hint' => $this->hint,
            'error_code' => $this->errorCode?->value,
        ];
    }
}
