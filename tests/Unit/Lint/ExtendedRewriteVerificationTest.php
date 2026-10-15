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

use PHPRegex\Automata\LanguageSolver;
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

    /**
     * Symfony's URL pattern, whose "{0,1}" the rewrite spells "?": its \pL
     * classes split the alphabet into some two thousand ranges, and the
     * check gives up on its budget before the table of the DFA is built.
     */
    #[Test]
    public function test_a_rewrite_of_a_large_unicode_pattern_the_check_gives_up_on_is_not_suggested(): void
    {
        /** @var string $pattern */
        $pattern = require \dirname(__DIR__, 2).'/Fixtures/Automata/url_validator_pattern.php';

        $this->assertSame([], $this->suggestions($pattern, true));
        $this->assertCount(1, $this->suggestions($pattern, false), 'Unchecked, the rewrite is suggested.');
    }

    /**
     * [\pL0-9] splits the alphabet into hundreds of ranges, a transition
     * each for every state: the check of the rewrite runs out of the work
     * it may spend, though the solver's default budget would settle it.
     */
    #[Test]
    public function test_a_rewrite_whose_check_outgrows_its_work_is_not_suggested(): void
    {
        $pattern = '/^[\pL0-9]*0[0-9]{6}$/ux';
        $this->assertTrue((new LanguageSolver())->equivalent($pattern, '/^[0-9\pL]*0[0-9]{6}$/ux')->isEquivalent, 'The default budget settles it.');

        $this->assertSame([], $this->suggestions($pattern, true));
        $this->assertSame(['/^[0-9\pL]*0[0-9]{6}$/ux'], $this->suggestions($pattern, false), 'Unchecked, the rewrite is suggested.');
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
