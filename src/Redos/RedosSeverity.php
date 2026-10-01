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

namespace PHPRegex\Redos;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * @api
 */
enum RedosSeverity: string
{
    /**
     * No significant ReDoS risk detected.
     */
    case Safe = 'safe';

    /**
     * Low risk.
     */
    case Low = 'low';

    /**
     * Medium risk.
     */
    case Medium = 'medium';

    /**
     * Analysis could not determine the risk.
     */
    case Unknown = 'unknown';

    /**
     * High risk.
     */
    case High = 'high';

    /**
     * Critical risk.
     */
    case Critical = 'critical';

    /**
     * The one order of severities: safe 0, low 1, unknown 2, medium 3,
     * high 4, critical 5. A verdict the analysis could not reach ranks
     * above a low risk and below a medium one.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Safe => 0,
            self::Low => 1,
            self::Unknown => 2,
            self::Medium => 3,
            self::High => 4,
            self::Critical => 5,
        };
    }

    /**
     * The severity a configured threshold names: low, medium, high or
     * critical, in any case. "safe" and "unknown" are verdicts a pattern
     * gets, not levels to report from, and are refused like any other word.
     *
     * @throws InvalidRegexOptionException when the value names no threshold
     */
    public static function fromConfig(string $value): self
    {
        $severity = self::tryFrom(strtolower($value));

        if (null === $severity || self::Safe === $severity || self::Unknown === $severity) {
            throw new InvalidRegexOptionException(\sprintf(
                '"%s" is not a ReDoS threshold; expected low, medium, high or critical.',
                $value,
            ));
        }

        return $severity;
    }
}
