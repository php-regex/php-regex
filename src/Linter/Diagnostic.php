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

use PHPRegex\Linter\Rule\RuleCatalog;

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
        public readonly ?string $snippet = null,
        public readonly ?string $suggestion = null,
    ) {}

    /**
     * The section of the docs that explains the issue, by its code; null
     * when the docs hold none of its own.
     */
    public function docsUrl(): ?string
    {
        return null === $this->code ? null : RuleCatalog::docsUrl($this->code);
    }
}
