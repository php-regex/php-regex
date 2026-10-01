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

namespace PhpRegex\Linter;

/**
 * Unified representation of syntax, semantic, lint, security, or optimization issues.
 */
final readonly class Diagnostic
{
    public function __construct(
        public DiagnosticType $type,
        public LintSeverity $severity,
        public string $message,
        public ?string $code = null,
        public ?int $position = null,
        public ?string $snippet = null,
        public ?string $suggestion = null,
        public ?string $docsAnchor = null,
        public ?string $tip = null,
    ) {}

    /**
     * @return array{
     *     type: string,
     *     severity: string,
     *     message: string,
     *     code: ?string,
     *     position: ?int,
     *     snippet: ?string,
     *     suggestion: ?string,
     *     docsAnchor: ?string,
     *     tip: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'code' => $this->code,
            'position' => $this->position,
            'snippet' => $this->snippet,
            'suggestion' => $this->suggestion,
            'docsAnchor' => $this->docsAnchor,
            'tip' => $this->tip,
        ];
    }
}
