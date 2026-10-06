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

namespace PHPRegex\Parser\Validation;

/**
 * On which PHP versions and PCRE2 releases a pattern is valid: one verdict
 * per point of the matrix the library knows, ordered by PHP version, then
 * by PCRE2 release.
 *
 * Validity is not monotone: "\K" in a lookaround is valid up to PHP 8.4 and
 * refused from 8.5. The matrix widens with each PHP or PCRE2 release the
 * library learns, so its length, and isValidEverywhere(), may change in a
 * minor release.
 */
final readonly class PatternCompatibility
{
    /**
     * @internal built by CompatibilityChecker::check()
     *
     * @param list<TargetVerdict> $verdicts
     */
    public function __construct(private array $verdicts) {}

    /**
     * @return list<TargetVerdict>
     */
    public function verdicts(): array
    {
        return $this->verdicts;
    }

    /**
     * The verdicts that refuse the pattern, in the same order.
     *
     * @return list<TargetVerdict>
     */
    public function invalidVerdicts(): array
    {
        return array_values(array_filter(
            $this->verdicts,
            static fn (TargetVerdict $verdict): bool => !$verdict->validation->isValid,
        ));
    }

    public function isValidEverywhere(): bool
    {
        return [] === $this->invalidVerdicts();
    }
}
