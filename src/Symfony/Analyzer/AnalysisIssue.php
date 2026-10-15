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

namespace PHPRegex\Symfony\Analyzer;

/**
 * @internal
 */
final class AnalysisIssue
{
    /**
     * @param array<int, IssueDetail> $details
     * @param array<int, string>      $notes
     */
    public function __construct(
        public readonly string $kind,
        public readonly CheckOutcome $severity,
        public readonly string $title,
        public readonly array $details = [],
        public readonly array $notes = [],
    ) {}
}
