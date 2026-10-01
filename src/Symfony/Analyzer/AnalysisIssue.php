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

namespace PhpRegex\Symfony\Analyzer;

/**
 * @internal
 */
final readonly class AnalysisIssue
{
    /**
     * @param array<int, \PhpRegex\Symfony\Analyzer\IssueDetail> $details
     * @param array<int, string>                                 $notes
     */
    public function __construct(
        public string $kind,
        public CheckOutcome $severity,
        public string $title,
        public array $details = [],
        public array $notes = [],
    ) {}
}
