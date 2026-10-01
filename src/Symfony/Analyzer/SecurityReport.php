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

namespace PhpRegex\Symfony\Analyzer;

/**
 * @internal
 */
final readonly class SecurityReport
{
    /**
     * @param array<int, \PhpRegex\Symfony\Analyzer\ReportSection> $sections
     */
    public function __construct(public array $sections) {}

    /**
     * @param array<int, string> $kinds
     */
    public function hasIssuesOfKind(array $kinds): bool
    {
        $lookup = array_fill_keys($kinds, true);

        foreach ($this->sections as $section) {
            foreach ($section->issues as $issue) {
                if (isset($lookup[$issue->kind])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasSeverity(CheckOutcome $severity): bool
    {
        foreach ($this->sections as $section) {
            foreach ($section->issues as $issue) {
                if ($issue->severity === $severity) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasAnyIssues(): bool
    {
        foreach ($this->sections as $section) {
            if ([] !== $section->issues) {
                return true;
            }
        }

        return false;
    }
}
