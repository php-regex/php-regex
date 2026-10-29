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

namespace PHPRegex\Linter\Formatter;

use PHPRegex\Linter\LintReport;

/**
 * Base class for output formatters.
 *
 * @phpstan-import-type LintResult from LintReport
 *
 * @internal
 */
abstract class AbstractOutputFormatter implements OutputFormatterInterface
{
    public function __construct(protected OutputConfiguration $config = new OutputConfiguration()) {}

    /**
     * Format and output the lint report.
     */
    abstract public function format(LintReport $report): string;

    /**
     * Format an error message.
     */
    public function formatError(string $message): string
    {
        return $message;
    }

    /**
     * The hint as written, or nothing when hints are off. A hint is never
     * shortened: the fix it names comes after its evidence, and a ReDoS
     * replay alone passes 200 bytes.
     */
    protected function formatHint(string $hint): string
    {
        return $this->config->shouldShowHints() ? $hint : '';
    }

    /**
     * Group results by file if configured.
     *
     * @param array<LintResult> $results
     *
     * @return array<string, array<LintResult>>
     */
    protected function groupResults(array $results): array
    {
        if (!$this->config->groupByFile) {
            return ['' => $results];
        }

        $grouped = [];
        foreach ($results as $result) {
            $grouped[$result['file']][] = $result;
        }

        return $grouped;
    }
}
