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
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rewrite of a pattern under /x is checked as the optimizer checks every
 * other rewrite: first on the $matches it writes, then, where the match
 * solver does not read the pattern or gives up, on the strings it matches. A
 * check that gave up on its budget proves nothing, so the suggestion is not
 * made.
 */
final class ExtendedRewriteVerificationTest extends TestCase
{
    /**
     * Under m the match check does not read the pattern, and the fifteenth
     * character from the end is a 0: 2^15 DFA states for the language check.
     */
    #[Test]
    public function test_a_rewrite_the_language_check_gives_up_on_is_not_suggested(): void
    {
        $this->assertSame([], $this->suggestions('/^[0-9]*0[0-9]{14}$/mx', true));
        $this->assertSame(['/^\d*0\d{14}$/mx'], $this->suggestions('/^[0-9]*0[0-9]{14}$/mx', false), 'Unchecked, the rewrite is suggested.');
    }

    /**
     * The match check steps the threads of both patterns side by side, and
     * settles at once a rewrite whose DFA would need 2^15 states.
     */
    #[Test]
    public function test_a_rewrite_the_match_check_proves_is_suggested_though_the_language_check_gives_up(): void
    {
        $this->assertSame(['/^\d*0\d{14}$/x'], $this->suggestions('/^[0-9]*0[0-9]{14}$/x', true));
    }

    /**
     * Symfony's URL pattern, whose "{0,1}" the rewrite spells "?": its \pL
     * classes split the alphabet into some two thousand ranges, and the
     * language check gives up on its budget before the table of the DFA is
     * built. The match check proves the rewrite, as it does for the
     * optimizer.
     */
    #[Test]
    public function test_a_rewrite_of_a_large_unicode_pattern_the_match_check_proves_is_suggested(): void
    {
        /** @var string $pattern */
        $pattern = require \dirname(__DIR__, 2).'/Fixtures/Automata/url_validator_pattern.php';
        $optimized = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, ['verify_with_automata' => true])->optimized;
        $this->assertNotSame($pattern, $optimized, 'The optimizer proves the rewrite.');
        $this->assertStringContainsString('{0,1}', $pattern);
        $this->assertStringNotContainsString('{0,1}', $optimized);

        $this->assertSame([$optimized], $this->suggestions($pattern, true));
        $this->assertSame([$optimized], $this->suggestions($pattern, false), 'Unchecked, the same rewrite is suggested.');
    }

    /**
     * [\pL0-9] splits the alphabet into hundreds of ranges, a transition
     * each for every state, and under m the match check does not read the
     * pattern: the check of the rewrite runs out of the work it may spend,
     * though the solver's default budget would settle it.
     */
    #[Test]
    public function test_a_rewrite_whose_check_outgrows_its_work_is_not_suggested(): void
    {
        $pattern = '/^[\pL0-9]*0[0-9]{6}$/umx';
        $this->assertTrue((new LanguageSolver())->equivalent($pattern, '/^[0-9\pL]*0[0-9]{6}$/umx')->isEquivalent, 'The default budget settles it.');

        $this->assertSame([], $this->suggestions($pattern, true));
        $this->assertSame(['/^[0-9\pL]*0[0-9]{6}$/umx'], $this->suggestions($pattern, false), 'Unchecked, the rewrite is suggested.');
    }

    #[Test]
    public function test_a_rewrite_the_language_check_proves_is_suggested(): void
    {
        $this->assertSame(['/^\d*0\d{3}$/mx'], $this->suggestions('/^[0-9]*0[0-9]{3}$/mx', true));
    }

    /**
     * /(a|ab)/ and /(ab|a)/ match the same strings, yet on "ab" the first
     * captures "a" and the second "ab": the language check alone proved it.
     */
    #[Test]
    public function test_a_rewrite_the_match_check_refuses_is_not_suggested(): void
    {
        $verify = new \ReflectionMethod(AnalysisService::class, 'verifyOptimizationWithAutomata');
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]));
        $this->assertTrue((new LanguageSolver())->equivalent('/(a|ab)/x', '/(ab|a)/x')->isEquivalent, 'The two match the same strings.');

        $this->assertFalse($verify->invoke($analysis, '/(a|ab)/x', '/(ab|a)/x'));
        $this->assertFalse($verify->invoke($analysis, '/ab/x', '/ac/x'));
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
