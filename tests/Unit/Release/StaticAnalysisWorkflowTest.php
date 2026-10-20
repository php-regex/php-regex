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

namespace PHPRegex\Tests\Unit\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each job of the static-analysis workflow runs one dev tool from its own
 * vendor under tools/, and carries that tool's name, so a red check names
 * the tool to run locally.
 */
final class StaticAnalysisWorkflowTest extends TestCase
{
    #[Test]
    public function test_every_job_is_named_after_the_tool_it_runs(): void
    {
        $jobs = self::jobs();
        $misnamed = [];
        foreach ($jobs as $id => $body) {
            if (1 !== preg_match(\sprintf('~run: tools/%1$s/vendor/bin/%1$s(?: |$)~m', preg_quote($id, '~')), $body)) {
                $misnamed[] = $id;
            }
        }

        $this->assertSame(['phpstan', 'php-cs-fixer', 'rector', 'phplint', 'deptrac'], array_keys($jobs));
        $this->assertSame([], $misnamed);
    }

    #[Test]
    public function test_the_code_style_job_carries_the_name_of_php_cs_fixer(): void
    {
        $this->assertMatchesRegularExpression('~^    name: PHP-CS-Fixer$~m', self::jobs()['php-cs-fixer'] ?? '');
    }

    /**
     * @return array<string, string> the body of each job, by job id
     */
    private static function jobs(): array
    {
        $workflow = (string) file_get_contents(\dirname(__DIR__, 3).'/.github/workflows/static-analysis.yml');
        $jobs = substr($workflow, (int) strpos($workflow, "\njobs:\n"));
        preg_match_all('~^  ([a-z][a-z-]*):\n((?:(?:    .*)?\n)*)~m', $jobs, $matches, \PREG_SET_ORDER);

        $bodies = [];
        foreach ($matches as $match) {
            $bodies[$match[1]] = $match[2];
        }

        return $bodies;
    }
}
