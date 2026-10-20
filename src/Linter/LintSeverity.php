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
 * The severity of a finding. Every report shows one of three levels for
 * it (level()): Critical and Error are errors, and fail the run; Warning
 * warns; Style, Perf and Info inform.
 *
 * - Critical: a security risk, as a ReDoS verdict confirmed critical
 * - Error: a pattern that does not match what it says, or that is refused
 * - Warning: a likely bug, or a pattern left unjudged at a limit
 * - Style: a spelling that reads better another way, the match unchanged
 * - Perf: a spelling the engine runs faster, the match unchanged
 * - Info: a fact worth knowing, no change needed
 */
enum LintSeverity: string
{
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Style = 'style';
    case Perf = 'perf';
    case Info = 'info';

    /**
     * The level every report shows: "error", "warning" or "info".
     *
     * @return 'error'|'warning'|'info'
     */
    public function level(): string
    {
        return match ($this) {
            self::Critical, self::Error => 'error',
            self::Warning => 'warning',
            self::Style, self::Perf, self::Info => 'info',
        };
    }

    /**
     * Whether a finding of this severity fails a lint run.
     */
    public function failsRun(): bool
    {
        return 'error' === $this->level();
    }
}
