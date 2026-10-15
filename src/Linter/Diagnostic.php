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

/**
 * Unified representation of syntax, semantic, lint, security, or optimization issues.
 *
 * @internal
 */
final class Diagnostic
{
    public function __construct(
        public readonly DiagnosticType $type,
        public readonly LintSeverity $severity,
        public readonly string $message,
        public readonly ?string $code = null,
        public readonly ?int $position = null,
        public readonly ?string $snippet = null,
        public readonly ?string $suggestion = null,
        public readonly ?string $docsAnchor = null,
        public readonly ?string $tip = null,
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
