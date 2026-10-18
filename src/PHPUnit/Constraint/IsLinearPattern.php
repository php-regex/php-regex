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

namespace PHPRegex\PHPUnit\Constraint;

use PHPRegex\PHPUnit\PatternLab;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Whether the pattern is proven to match in linear time, the name staying
 * honest: the constraint passes only when one match attempt is proven
 * linear and the unanchored search carries no quadratic-cost witness.
 *
 * The failure's first line carries the verdict's stable prefix, so a
 * quarantine can tell a held witness from an unproven verdict apart:
 * "proven unsafe:" for an unsafe attempt or a quadratic search,
 * "could not analyze:" for a budget, a model or a parse refusal.
 */
final class IsLinearPattern extends Constraint
{
    private ?string $failure = null;

    /**
     * Returns a string representation of the constraint.
     */
    public function toString(): string
    {
        return 'is proven to match in linear time';
    }

    protected function matches(mixed $other): bool
    {
        if (!\is_string($other)) {
            $this->failure = sprintf('the pattern must be a string, %s given', get_debug_type($other));

            return false;
        }

        $this->failure = PatternLab::linearFailure($other);

        return null === $this->failure;
    }

    /**
     * The beginning of the failure message: the pattern and the constraint.
     */
    protected function failureDescription(mixed $other): string
    {
        return sprintf('%s %s', PatternLab::export($other), $this->toString());
    }

    /**
     * The verdict in the assertion's own words, its stable prefix leading.
     */
    protected function additionalFailureDescription(mixed $other): string
    {
        return $this->failure ?? '';
    }
}
