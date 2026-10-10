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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * renderSummary() receives what LintReport::$stats holds: the LintStats
 * shape, single-sourced on LintReport — errors, warnings and optimizations
 * required, the kind counts optional. The @param still says
 * array<string, int>, which both over- and under-promises: it accepts an
 * array with none of the keys the body reads ($stats['errors']), and
 * rejects nothing. Naming the imported shape ties the renderer to the
 * producer for real.
 *
 * Landing the docblock has a follow-through the builder owes in the same
 * commit: LintOutputRendererSummaryTest types its provider rows and its
 * failLine() helper array<string, int>, which argument.type refuses against
 * the sealed shape — that file (and, if the analyser asks, the two other
 * renderSummary() callers under tests/) adopt the import too.
 */
final class LintOutputRendererContractTest extends TestCase
{
    #[Test]
    public function test_renderer_imports_the_stats_shape_from_its_home(): void
    {
        $docComment = (string) (new \ReflectionClass(LintOutputRenderer::class))->getDocComment();

        $this->assertStringContainsString(
            '@phpstan-import-type LintStats from LintReport',
            $docComment,
            'LintOutputRenderer imports LintStats from LintReport — the shape is single-sourced on the report'
            .' class; at class level, the only place an import resolves.',
        );
    }

    #[Test]
    public function test_render_summary_param_names_the_stats_shape(): void
    {
        $docComment = (string) (new \ReflectionMethod(LintOutputRenderer::class, 'renderSummary'))->getDocComment();

        $this->assertSame(
            1,
            preg_match('/@param\s+(\S+)\s+\$stats\b/', $docComment, $param),
            'renderSummary() must document its $stats: the body reads $stats[\'errors\'] and friends, and the'
            .' producer is LintReport::$stats.',
        );

        $this->assertSame(
            'LintStats',
            $param[1],
            sprintf(
                'The @param for $stats in renderSummary() must name LintStats, not %s: LintCommand passes'
                .' $report->stats straight through, the FAIL line reads errors, warnings, optimizations, redos'
                .' and lintErrors from it — the sealed shape is the only honest type for that.',
                $param[1],
            ),
        );
    }
}
