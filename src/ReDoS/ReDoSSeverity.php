<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\ReDoS;

use RegexParser\Exception\InvalidRegexOptionException;

/**
 * @api
 */
enum ReDoSSeverity: string
{
    /**
     * No significant ReDoS risk detected.
     */
    case SAFE = 'safe';

    /**
     * Low risk.
     */
    case LOW = 'low';

    /**
     * Medium risk.
     */
    case MEDIUM = 'medium';

    /**
     * Analysis could not determine the risk.
     */
    case UNKNOWN = 'unknown';

    /**
     * High risk.
     */
    case HIGH = 'high';

    /**
     * Critical risk.
     */
    case CRITICAL = 'critical';

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

        if (null === $severity || self::SAFE === $severity || self::UNKNOWN === $severity) {
            throw new InvalidRegexOptionException(\sprintf(
                '"%s" is not a ReDoS threshold; expected low, medium, high or critical.',
                $value,
            ));
        }

        return $severity;
    }
}
