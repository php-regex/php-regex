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
 * Severity levels for lint issues.
 *
 * - Critical: Security vulnerabilities (e.g., severe ReDoS)
 * - Error: Critical issues that will cause the regex to fail or have security implications
 * - Warning: Potential bugs or issues that should be addressed
 * - Style: Style preferences that don't affect functionality
 * - Perf: Performance suggestions for optimization
 * - Info: Informational messages
 */
enum LintSeverity: string
{
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Style = 'style';
    case Perf = 'perf';
    case Info = 'info';
}
