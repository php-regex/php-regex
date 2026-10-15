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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rewrite of a pattern under /x is checked by the language solver, as
 * every other rewrite: a check that gave up on its budget proves nothing, so
 * the suggestion is not made.
 */
final class ExtendedRewriteVerificationTest extends TestCase
{
    #[Test]
    public function test_a_rewrite_the_language_check_gives_up_on_is_not_suggested(): void
    {
        // The fifteenth character from the end is a 0: 2^15 DFA states.
        $this->assertSame([], $this->suggestions('/^[0-9]*0[0-9]{14}$/x', true));
        $this->assertSame(['/^\d*0\d{14}$/x'], $this->suggestions('/^[0-9]*0[0-9]{14}$/x', false), 'Unchecked, the rewrite is suggested.');
    }

    #[Test]
    public function test_a_rewrite_the_language_check_proves_is_suggested(): void
    {
        $this->assertSame(['/^\d*0\d{3}$/x'], $this->suggestions('/^[0-9]*0[0-9]{3}$/x', true));
    }

    /**
     * Only the automata's own budget proves nothing; a check that could not
     * even read a side answers nothing either way.
     */
    #[Test]
    public function test_a_rewrite_the_check_cannot_read_is_left_undecided(): void
    {
        $verify = new \ReflectionMethod(AnalysisService::class, 'verifyOptimizationWithAutomata');
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]));

        $this->assertNull($verify->invoke($analysis, '/(a)\1/x', '/(a)a/x'));
        $this->assertNull($verify->invoke($analysis, '/a/x', '/(a/x'));
    }

    /**
     * @return list<string>
     */
    private function suggestions(string $pattern, bool $verify): array
    {
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]));
        $result = $analysis->suggestOptimizations([new PatternOccurrence($pattern, 'test.php', 1, 'preg_match')], 0, new OptimizerOptions(verifyWithAutomata: $verify));

        return array_values(array_map(static fn (array $suggestion): string => $suggestion['optimization']->optimized, $result));
    }
}
