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

namespace PHPRegex\Linter\Rule;

use PHPRegex\Linter\LintSeverity;

/**
 * Represents a single linter diagnostic for a regex pattern.
 */
final class RuleViolation
{
    /**
     * @param int|null $offset where the reported text starts in the pattern body, in bytes
     * @param int|null $end    where it ends, exclusive: the end of the node the rule names
     *
     * @internal built by the lint rules PatternLinter runs, read through PatternLinter::getIssues()
     */
    public function __construct(
        public readonly string $id,
        public readonly string $message,
        public readonly ?int $offset = null,
        public readonly ?string $hint = null,
        public readonly LintSeverity $severity = LintSeverity::Warning,
        public readonly ?int $end = null,
    ) {}
}
