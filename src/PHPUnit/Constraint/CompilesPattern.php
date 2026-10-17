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

use PHPRegex\PHPUnit\Internal\PatternLab;
use PHPUnit\Framework\Constraint\Constraint;

/**
 * Whether the running engine compiles the pattern: the truth is the
 * engine's alone, so a pattern the engine refuses fails the constraint
 * with the PCRE message and the offset it names, and a pattern beyond the
 * library's own parse limits still passes here.
 */
final class CompilesPattern extends Constraint
{
    private ?string $failure = null;

    /**
     * Returns a string representation of the constraint.
     */
    public function toString(): string
    {
        return 'is a pattern the running engine compiles';
    }

    protected function matches(mixed $other): bool
    {
        if (!\is_string($other)) {
            $this->failure = sprintf('the pattern must be a string, %s given', get_debug_type($other));

            return false;
        }

        $this->failure = PatternLab::compileFailure($other);

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
     * The engine's own words for its refusal, with the offset it names.
     */
    protected function additionalFailureDescription(mixed $other): string
    {
        return $this->failure ?? '';
    }
}
