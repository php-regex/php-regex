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
 * other rewrite: on the $matches it writes, or, where the match solver does
 * not read the pattern or gives up, on whether it reads as the pattern does.
 * The strings both match prove nothing, and neither does a check that gave
 * up on its budget, so the suggestion is not made. A rewrite that only respells a class, [0-9] as \d, reads as the
 * pattern does and needs no automaton: the rewrites here also spell a run
 * of x's as a repeat.
 */
final class ExtendedRewriteVerificationTest extends TestCase
{
    /**
     * Under m the match check does not read the pattern, and the run of x's
     * spelled as a repeat keeps the rewrite from reading as it does.
     */
    #[Test]
    public function test_a_rewrite_the_language_check_gives_up_on_is_not_suggested(): void
    {
        $this->assertSame([], $this->suggestions('/[0-9]*0[0-9]{14}xxxxxx$/mx', true));
        $this->assertSame(['/\d*0\d{14}x{6}$/mx'], $this->suggestions('/[0-9]*0[0-9]{14}xxxxxx$/mx', false), 'Unchecked, the rewrite is suggested.');
    }

    /**
     * The match check steps the threads of both patterns side by side, and
     * settles at once a rewrite whose DFA would need 2^15 states.
     */
    #[Test]
    public function test_a_rewrite_the_match_check_proves_is_suggested_though_the_language_check_gives_up(): void
    {
        $this->assertSame(['/^\d*0\d{14}x{6}$/x'], $this->suggestions('/^[0-9]*0[0-9]{14}xxxxxx$/x', true));
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
     * Under m the match check does not read the pattern, and the strings
     * both sides match, which the solver's default budget settles, prove no
     * $matches.
     */
    #[Test]
    public function test_a_rewrite_whose_check_outgrows_its_work_is_not_suggested(): void
    {
        $pattern = '/[\pL0-9]*0[0-9]{6}xxxxxx$/umx';
        $this->assertTrue((new LanguageSolver())->equivalent($pattern, '/[0-9\pL]*0[0-9]{6}x{6}$/umx')->isEquivalent, 'The default budget settles it.');

        $this->assertSame([], $this->suggestions($pattern, true));
        $this->assertSame(['/[0-9\pL]*0[0-9]{6}x{6}$/umx'], $this->suggestions($pattern, false), 'Unchecked, the rewrite is suggested.');
    }

    /**
     * Under m the match check does not read the pattern: the two match the
     * same strings, which proves no $matches, and the rewrite is not
     * suggested.
     */
    #[Test]
    public function test_a_rewrite_only_the_strings_matched_agree_on_is_not_suggested(): void
    {
        $this->assertTrue((new LanguageSolver())->equivalent('/[0-9]*0[0-9]{3}xxxxxx$/mx', '/\d*0\d{3}x{6}$/mx')->isEquivalent, 'The two match the same strings.');

        $this->assertSame([], $this->suggestions('/[0-9]*0[0-9]{3}xxxxxx$/mx', true));
        $this->assertSame(['/\d*0\d{3}x{6}$/mx'], $this->suggestions('/[0-9]*0[0-9]{3}xxxxxx$/mx', false), 'Unchecked, the rewrite is suggested.');
    }

    /**
     * A rewrite that only respells a class reads as the pattern does: it is
     * suggested where the match check does not read the pattern.
     */
    #[Test]
    public function test_a_rewrite_reading_alike_is_suggested_where_the_match_check_does_not_read(): void
    {
        $this->assertSame(['/\d*0\d{3} x$/mx'], $this->suggestions('/[0-9]*0[0-9]{3} x$/mx', true));
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
     * A rewrite the check cannot read, or a side it cannot even parse, is
     * not proven.
     */
    #[Test]
    public function test_a_rewrite_the_check_cannot_read_is_not_proven(): void
    {
        $verify = new \ReflectionMethod(AnalysisService::class, 'verifyOptimizationWithAutomata');
        $analysis = new AnalysisService(RegexParser::create(['cache' => null]));

        $this->assertFalse($verify->invoke($analysis, '/(a)\1/x', '/(a)a/x'));
        $this->assertFalse($verify->invoke($analysis, '/a/x', '/(a/x'));
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
