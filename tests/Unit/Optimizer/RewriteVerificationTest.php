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
     * Under m the match check does not read the pattern (a "$" there looks
     * right only, so offset 0 says it all; the rewrite spells the repeat
     * otherwise, so the two do not read alike), and the language
     * check of [\pL0-9] writes a transition per range of an alphabet split
     * into hundreds of them, for each of a few hundred states: past the work
     * the check of a rewrite may spend, it proves nothing, though the
     * solver's default budget would settle it.
     */
    #[Test]
    public function test_a_rewrite_whose_language_check_outgrows_its_work_is_not_offered(): void
    {
        $original = '/[\pL0-9]*0[0-9]{6}$/um';
        $rewrite = '/[\pL0-9]*0[0-9]{5}[0-9]$/um';
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
        yield 'a shorthand for a class, anchored' => ['original' => '/^[0-9]+$/', 'rewrite' => '/^\d+$/', 'verdict' => true];
        yield 'a shorthand for a class, matching empty' => ['original' => '/[0-9]*/', 'rewrite' => '/\d*/', 'verdict' => true];
        yield 'a class for an alternation, anchored and matching empty' => ['original' => '/^(?:a|b)*/', 'rewrite' => '/^[ab]*/', 'verdict' => true];
        yield 'a useless flag dropped around a lookbehind' => ['original' => '/(?<!x)a/s', 'rewrite' => '/(?<!x)a/', 'verdict' => true];
        yield 'a useless flag dropped around a start of line' => ['original' => '/^\s*$/sm', 'rewrite' => '/^\s*$/m', 'verdict' => true];
    }

    /**
     * A rewrite is offered for every call the pattern may go to: proven from
     * offset 0 is not enough where the start of the subject, an empty match
     * or the text before the match decides. Each pair writes the same
     * $matches in preg_match() from offset 0, and the engine tells them
     * apart in the call named.
     */
    #[Test]
    #[DataProvider('provideRewritesAnotherCallTellsApart')]
    public function test_a_rewrite_another_call_tells_apart_is_not_proven(string $original, string $rewrite, string $call, string $subject, int $offset = 0): void
    {
        $this->assertNotSame(self::called($call, $original, $subject, $offset), self::called($call, $rewrite, $subject, $offset), 'The engine tells them apart.');

        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');

        $this->assertFalse($verify->invoke(new Optimizer(RegexParser::create(['cache' => null])), $original, $rewrite));
    }

    /**
     * A rewrite proven for every call writes what the pattern writes in each
     * of them, from every offset, on every subject over its letters.
     */
    #[Test]
    #[DataProvider('provideRewritesProvenForEveryCall')]
    public function test_a_rewrite_proven_for_every_call_agrees_with_the_engine_in_each(string $original, string $rewrite, string $letters): void
    {
        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');
        $this->assertTrue($verify->invoke(new Optimizer(RegexParser::create(['cache' => null])), $original, $rewrite));

        $subjects = [''];
        $layer = [''];
        for ($size = 1; $size <= 4; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (str_split($letters) as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $subjects = [...$subjects, ...$next];
            $layer = $next;
        }

        foreach ($subjects as $subject) {
            foreach (['preg_match_all', 'preg_replace', 'preg_split'] as $call) {
                $this->assertSame(self::called($call, $original, $subject), self::called($call, $rewrite, $subject), \sprintf('%s() on %s.', $call, json_encode($subject)));
            }
            for ($offset = 0; $offset <= \strlen($subject); $offset++) {
                $this->assertSame(self::called('preg_match', $original, $subject, $offset), self::called('preg_match', $rewrite, $subject, $offset), \sprintf('preg_match() on %s from %d.', json_encode($subject), $offset));
            }
        }
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string, call: string, subject: string, offset?: int}>
     */
    public static function provideRewritesAnotherCallTellsApart(): iterable
    {
        yield 'the start of the subject, past it' => ['original' => '/^[\s\S]|a/', 'rewrite' => '/^[\s\S]|b/', 'call' => 'preg_match_all', 'subject' => 'xa'];
        yield 'the start of the subject, from an offset' => ['original' => '/^[\s\S]|a/', 'rewrite' => '/^[\s\S]|b/', 'call' => 'preg_match', 'subject' => 'xa', 'offset' => 1];
        yield 'an empty match retried' => ['original' => '/a??/', 'rewrite' => '/(?:)/', 'call' => 'preg_replace', 'subject' => 'a'];
        yield 'a lazy star retried' => ['original' => '/a*?/', 'rewrite' => '/(?:)/', 'call' => 'preg_split', 'subject' => 'ab'];
        yield 'a lookbehind' => ['original' => '/(?<!x)a/', 'rewrite' => '/a/', 'call' => 'preg_match', 'subject' => 'xa'];
        yield 'a word boundary before' => ['original' => '/\ba/', 'rewrite' => '/a/', 'call' => 'preg_match', 'subject' => 'xa'];
        yield 'a word boundary after' => ['original' => '/a\b/', 'rewrite' => '/a/', 'call' => 'preg_match', 'subject' => 'ab'];
        yield 'the start of a line' => ['original' => '/^[\s\S]|a/m', 'rewrite' => '/^[\s\S]|b/m', 'call' => 'preg_match_all', 'subject' => 'xa'];
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string, letters: string}>
     */
    public static function provideRewritesProvenForEveryCall(): iterable
    {
        yield 'a shorthand for a class, anchored' => ['original' => '/^[0-9]+$/', 'rewrite' => '/^\d+$/', 'letters' => "0a\n"];
        yield 'a shorthand for a class, matching empty' => ['original' => '/[0-9]*/', 'rewrite' => '/\d*/', 'letters' => '0a'];
        yield 'a class for an alternation, anchored and matching empty' => ['original' => '/^(?:a|b)*/', 'rewrite' => '/^[ab]*/', 'letters' => 'abc'];
        yield 'a class for an alternation' => ['original' => '/(?:a|b)c/', 'rewrite' => '/[ab]c/', 'letters' => 'abc'];
    }

    /**
     * @return array<mixed>|string|false|null
     */
    private static function called(string $call, string $pattern, string $subject, int $offset = 0): array|string|false|null
    {
        $matches = [];

        return match ($call) {
            'preg_match' => [preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL, $offset), $matches],
            'preg_match_all' => [preg_match_all($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL), $matches],
            'preg_replace' => preg_replace($pattern, '<$0>', $subject),
            default => preg_split($pattern, $subject, -1, \PREG_SPLIT_OFFSET_CAPTURE),
        };
    }
}
