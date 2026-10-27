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
use PHPRegex\Optimizer\RewriteCheck;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rewrite of a pattern under /x is checked as the optimizer checks every
 * other rewrite: on the $matches it writes, or, where the match solver does
 * not read the pattern or gives up, on whether it reads as the pattern does
 * but for parts proven alone. The strings both match prove nothing, and
 * neither does a check that gave up on its budget, so the suggestion is not
 * made. A rewrite that only respells a class, [0-9] as \d, reads as the
 * pattern does and needs no automaton; a run of x's spelled as a repeat is
 * a part proven alone, and "x|xy" spelled "x(?:y)??" is not, as "x" begins
 * "xy".
 */
final class ExtendedRewriteVerificationTest extends TestCase
{
    /**
     * Under m the match check does not read the pattern, and the run of x's
     * spelled as a repeat keeps the rewrite from reading as it does: the
     * run is proven alone, and the rewrite is suggested. The branches
     * spelled as a lazy option are not.
     */
    #[Test]
    public function test_a_rewrite_the_language_check_gives_up_on_is_suggested_only_when_its_parts_are_proven_alone(): void
    {
        $this->assertSame(['/\d*0\d{14}x{6}$/mx'], $this->suggestions('/[0-9]*0[0-9]{14}xxxxxx$/mx', true));

        $this->assertFalse(self::check()->verify('/[0-9]*0[0-9]{14}(?:x|xy)$/mx', '/\d*0\d{14}(?:x(?:y)??)$/mx'));
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
        $optimized = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(verifyWithAutomata: true))->optimized;
        $this->assertNotSame($pattern, $optimized, 'The optimizer proves the rewrite.');
        $this->assertStringContainsString('{0,1}', $pattern);
        $this->assertStringNotContainsString('{0,1}', $optimized);

        $this->assertSame([$optimized], $this->suggestions($pattern, true));
        $this->assertSame([$optimized], $this->suggestions($pattern, false), 'Unchecked, the same rewrite is suggested.');
    }

    /**
     * Under m the match check does not read the pattern, and the part the
     * rewrite changed, eighty \pL spelled as a repeat, has a state for each
     * of them over an alphabet of hundreds of ranges: past the work the
     * check of a rewrite may spend, it proves nothing, though the solver's
     * default budget would settle it.
     */
    #[Test]
    public function test_a_rewrite_whose_check_outgrows_its_work_is_not_suggested(): void
    {
        $pattern = '/[\pL0-9]*0'.str_repeat('\pL', 80).'$/umx';
        $this->assertTrue((new LanguageSolver(RegexParser::create(['cache' => null])))->rewritesAlikeInPlace($pattern, '/[0-9\pL]*0\pL{80}$/umx'), 'The default budget settles it.');

        $this->assertSame([], $this->suggestions($pattern, true));
        $this->assertSame(['/[0-9\pL]*0\pL{80}$/umx'], $this->suggestions($pattern, false), 'Unchecked, the rewrite is suggested.');
    }

    /**
     * Under m the match check does not read the pattern: the two match the
     * same strings, which proves no $matches, and "x" begins "xy", so the
     * part the rewrite changed is not proven alone either: that rewrite is
     * not proven.
     */
    #[Test]
    public function test_a_rewrite_only_the_strings_matched_agree_on_is_not_suggested(): void
    {
        $this->assertTrue((new LanguageSolver())->equivalent('/[0-9]*0[0-9]{3}(?:x|xy)$/mx', '/\d*0\d{3}(?:x(?:y)??)$/mx')->isEquivalent, 'The two match the same strings.');

        $this->assertFalse(self::check()->verify('/[0-9]*0[0-9]{3}(?:x|xy)$/mx', '/\d*0\d{3}(?:x(?:y)??)$/mx'));
        $this->assertSame(['/\d*0\d{3}x{6}$/mx'], $this->suggestions('/[0-9]*0[0-9]{3}xxxxxx$/mx', true), 'A run of x\'s spelled as a repeat is proven alone.');
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
        $this->assertTrue((new LanguageSolver())->equivalent('/(a|ab)/x', '/(ab|a)/x')->isEquivalent, 'The two match the same strings.');

        $this->assertFalse(self::check()->verify('/(a|ab)/x', '/(ab|a)/x'));
        $this->assertFalse(self::check()->verify('/ab/x', '/ac/x'));
    }

    /**
     * A rewrite the check cannot read is not proven; a side it cannot even
     * parse raises, which the optimizer takes for no proof.
     */
    #[Test]
    public function test_a_rewrite_the_check_cannot_read_is_not_proven(): void
    {
        $this->assertFalse(self::check()->verify('/(a)\1/x', '/(a)a/x'));
        $this->expectException(ParserException::class);
        self::check()->verify('/a/x', '/(a/x');
    }

    private static function check(): RewriteCheck
    {
        return new RewriteCheck(new LanguageSolver(RegexParser::create(['cache' => null])));
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
