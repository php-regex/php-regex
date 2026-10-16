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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A rewrite ships only if it writes the same $matches as the pattern: the
 * same strings are not enough, as /(a|ab)/ and /(ab|a)/ show on "ab". Where
 * the match solver cannot read a pattern, the language solver judges it.
 */
final class RewriteVerificationTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRewrites')]
    public function test_a_rewrite_is_judged_on_the_matches_it_writes(string $original, string $rewrite, ?bool $verdict): void
    {
        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');

        $this->assertSame($verdict, $verify->invoke(new Optimizer(RegexParser::create()), $original, $rewrite));
    }

    /**
     * Hundreds of short branches, as in a user-agent list: the match check
     * steps a thread per branch for every character class, so it gives up on
     * its work budget, not after hundreds of configurations, and the
     * language check settles the rewrite. It took seconds.
     */
    #[Test]
    public function test_a_wide_alternation_is_judged_without_a_long_match_search(): void
    {
        $letters = range('a', 'z');
        $branches = [];
        for ($i = 0; $i < 340; $i++) {
            $branches[] = 0 === $i % 5
                ? $letters[$i % 26].$letters[intdiv($i, 26) % 26].'('.$letters[($i * 7) % 26].'|'.$letters[($i * 11) % 26].')'
                : $letters[$i % 26].$letters[intdiv($i, 26) % 26].$letters[($i * 7) % 26].$letters[($i * 3) % 26];
        }
        $original = '/'.implode('|', $branches).'/';
        $optimizer = new Optimizer(RegexParser::create());
        $rewrite = $optimizer->optimize($original)->optimized;
        $this->assertNotSame($original, $rewrite, 'The pattern has something to rewrite.');

        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');
        $start = hrtime(true);
        $verdict = $verify->invoke($optimizer, $original, $rewrite);
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertTrue($verdict);
        $this->assertLessThan(2.0, $seconds, \sprintf('The check took %.2f s.', $seconds));
    }

    /**
     * The match check gives up on its configurations and the language check
     * on its DFA states (2^14 for the last fourteen letters): the rewrite is
     * unproven, and only a rewrite the automata cannot read at all is kept
     * on trust.
     */
    #[Test]
    public function test_a_rewrite_both_solvers_give_up_on_is_not_offered(): void
    {
        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));

        $this->assertSame('/^([ab])*a([ab]){14}$/', $optimizer->optimize('/^(a|b)*a(a|b){14}$/')->optimized, 'Unchecked, the rewrite is made.');
        $this->assertSame('/^(a|b)*a(a|b){14}$/', $optimizer->optimize('/^(a|b)*a(a|b){14}$/', ['verify_with_automata' => true])->optimized);
    }

    /**
     * Under m the match check does not read the pattern, and the language
     * check of [\pL0-9] writes a transition per range of an alphabet split
     * into hundreds of them, for each of a few hundred states: past the work
     * the check of a rewrite may spend, it proves nothing, though the
     * solver's default budget would settle it.
     */
    #[Test]
    public function test_a_rewrite_whose_language_check_outgrows_its_work_is_not_offered(): void
    {
        $original = '/^[\pL0-9]*0[0-9]{6}$/um';
        $rewrite = '/^[0-9\pL]*0[0-9]{6}$/um';
        $this->assertTrue((new LanguageSolver())->equivalent($original, $rewrite)->isEquivalent, 'The default budget settles it.');

        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');

        $this->assertFalse($verify->invoke(new Optimizer(RegexParser::create(['cache' => null])), $original, $rewrite));
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string, verdict: bool|null}>
     */
    public static function provideRewrites(): iterable
    {
        yield 'branches swapped: same strings, other groups' => ['original' => '/(a|ab)/', 'rewrite' => '/(ab|a)/', 'verdict' => false];
        yield 'shorter branch first, made lazy' => ['original' => '/a|ab/', 'rewrite' => '/a(?:b)??/', 'verdict' => true];
        yield 'class for an alternation' => ['original' => '/(a|b)c/', 'rewrite' => '/([ab])c/', 'verdict' => true];
        yield 'possessive, judged on the language' => ['original' => '/a+b/', 'rewrite' => '/a++b/', 'verdict' => true];
        yield 'other strings' => ['original' => '/ab/', 'rewrite' => '/ac/', 'verdict' => false];
        yield 'beyond both solvers' => ['original' => '/(a)\1/', 'rewrite' => '/(a)a/', 'verdict' => null];
    }
}
