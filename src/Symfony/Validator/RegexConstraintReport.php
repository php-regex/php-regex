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

namespace PHPRegex\Symfony\Validator;

/**
 * What RegexConstraintAnalyzer found, in the order of the classes and their
 * members, and what it read.
 *
 * @internal
 *
 * @phpstan-import-type ValidatorFinding from RegexConstraintAnalyzer
 */
final class RegexConstraintReport
{
    /**
     * @param list<ValidatorFinding> $findings
     * @param array<string, int>     $stats    classes, properties, regex (constraints read) and html_compared
     */
    public function __construct(
        public readonly array $findings,
        public readonly array $stats,
    ) {}

    /**
     * @return list<ValidatorFinding>
     */
    public function ofSeverity(string $severity): array
    {
        return array_values(array_filter($this->findings, static fn (array $finding): bool => $severity === $finding['severity']));
    }

    public function hasErrors(): bool
    {
        return [] !== $this->ofSeverity('error');
    }
}
