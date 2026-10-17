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
 * the match solver cannot read a pattern, only a rewrite that reads as the
 * pattern does is proven.
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
     * its work budget, not after hundreds of configurations. It took seconds.
     * Each part the rewrite changed, a class for an alternation inside a
     * group, is then proven alone, at once.
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
     * An anchored alternation of some thirty SQL statements: the rewrite
     * spells "(?:S)?" as "S?" and drops a repeated branch, and the match
     * check settles it after some six hundred configurations, within its
     * cap and its work, in a fraction of a second.
     */
    #[Test]
    public function test_a_wide_anchored_alternation_is_proven_within_the_configurations_the_check_explores(): void
    {
        /** @var string $original */
        $original = require \dirname(__DIR__, 2).'/Fixtures/Automata/sql_table_pattern.php';
        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));
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
     * The match check gives up on its configurations (2^14 for the last
     * fourteen letters), and so does the language check, while the rewrite
     * does not read as the pattern does. A class for an alternation inside
     * a group is proven alone; "x|xy" for "xy?" is not, as "x" begins "xy",
     * and the second rewrite is unproven.
     */
    #[Test]
    public function test_a_rewrite_both_solvers_give_up_on_is_offered_only_when_its_parts_are_proven_alone(): void
    {
        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));

        $this->assertSame('/^([ab])*a([ab]){14}$/', $optimizer->optimize('/^(a|b)*a(a|b){14}$/')->optimized, 'Unchecked, the rewrite is made.');
        $this->assertSame('/^([ab])*a([ab]){14}$/', $optimizer->optimize('/^(a|b)*a(a|b){14}$/', ['verify_with_automata' => true])->optimized);

        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');
        $this->assertFalse($verify->invoke($optimizer, '/^(a|b)*a(a|b){14}(?:x|xy)$/', '/^(a|b)*a(a|b){14}xy?$/'));
    }

    /**
     * Under m the match check does not read the pattern (a "$" there looks
     * right only, so offset 0 says it all; the rewrite spells the repeat
     * otherwise, so the two do not read alike), and the check of the part
     * it changed, [\pL0-9]{80}, writes a transition per range of an
     * alphabet split into hundreds of them, for each of some eighty states:
     * past the work the check of a rewrite may spend, it proves nothing,
     * though the solver's default budget would settle it.
     */
    #[Test]
    public function test_a_rewrite_whose_language_check_outgrows_its_work_is_not_offered(): void
    {
        $original = '/[\pL0-9]*0[\pL0-9]{80}$/um';
        $rewrite = '/[\pL0-9]*0[\pL0-9]{79}[\pL0-9]$/um';
        $this->assertTrue((new LanguageSolver(RegexParser::create(['cache' => null])))->rewritesAlikeInPlace($original, $rewrite), 'The default budget settles it.');

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
        yield 'possessive, never giving back' => ['original' => '/a+b/', 'rewrite' => '/a++b/', 'verdict' => true];
        yield 'possessive before a lookahead' => ['original' => '/a+(?!b)/', 'rewrite' => '/a++(?!b)/', 'verdict' => false];
        yield 'other strings' => ['original' => '/ab/', 'rewrite' => '/ac/', 'verdict' => false];
        yield 'beyond both solvers' => ['original' => '/(a)\1/', 'rewrite' => '/(a)a/', 'verdict' => false];
        yield 'beyond the match solver, reading alike' => ['original' => '/(a)\1[0-9]/', 'rewrite' => '/(a)\1\d/', 'verdict' => true];
        yield 'a shorthand for a class, anchored' => ['original' => '/^[0-9]+$/', 'rewrite' => '/^\d+$/', 'verdict' => true];
        yield 'a shorthand for a class, matching empty' => ['original' => '/[0-9]*/', 'rewrite' => '/\d*/', 'verdict' => true];
        yield 'a class for an alternation, anchored and matching empty' => ['original' => '/^(?:a|b)*/', 'rewrite' => '/^[ab]*/', 'verdict' => true];
        yield 'a useless flag dropped around a lookbehind' => ['original' => '/(?<!x)a/s', 'rewrite' => '/(?<!x)a/', 'verdict' => true];
        yield 'a useless flag dropped around a start of line' => ['original' => '/^\s*$/sm', 'rewrite' => '/^\s*$/m', 'verdict' => true];
        yield 'a class for an alternation beside a lookbehind' => ['original' => '/(?<!\\\\)(?:_|%)/', 'rewrite' => '/(?<!\\\\)[_%]/', 'verdict' => true];
        yield 'a class for an alternation inside a lookahead' => ['original' => '/(?=\s*(?:,|\n))/', 'rewrite' => '/(?=\s*[,\n])/', 'verdict' => true];
        yield 'a count for a repeated character after a start of line' => ['original' => '/^\/\/\/\/x/m', 'rewrite' => '/^\/{4}x/m', 'verdict' => true];
        yield 'a class for an alternation inside a group a backreference reads' => ['original' => '/(?<!x)((?:_|%)b)\1/', 'rewrite' => '/(?<!x)([_%]b)\1/', 'verdict' => true];
        yield 'a class for an alternation inside a caseless group' => ['original' => '/(?<!x)(?i:a|b)c/', 'rewrite' => '/(?<!x)(?i:[ab])c/', 'verdict' => true];
        yield 'a count for a repeated character beside a match start reset' => ['original' => '/(?<!x)a\Kbbbb/', 'rewrite' => '/(?<!x)a\Kb{4}/', 'verdict' => true];
        yield 'a class for an alternation inside a lookbehind' => ['original' => '/(?<=(?:_|%))a/', 'rewrite' => '/(?<=[_%])a/', 'verdict' => true];
        yield 'a repeated branch dropped beside a word boundary' => ['original' => '/\b(?:ab|cd|ab|abc)/', 'rewrite' => '/\b(?:ab|cd|abc)/', 'verdict' => true];
        yield 'a branch moved where its first copy was' => ['original' => '/\b(?:a|ab|a)/', 'rewrite' => '/\b(?:ab|a)/', 'verdict' => false];
        // Each would run alike, but the proof of a rewritten part does not
        // read a pattern a subroutine call may enter elsewhere, a part of
        // more than one length inside a lookbehind, whose length rules vary
        // by release, or a pattern holding a backtracking verb.
        yield 'a rewritten part a subroutine call enters' => ['original' => '/(?<!x)((?:_|%))(?1)/', 'rewrite' => '/(?<!x)([_%])(?1)/', 'verdict' => false];
        yield 'a rewritten part of more than one length inside a lookbehind' => ['original' => '/(?<=x(?:a|bc))y/', 'rewrite' => '/(?<=x(?:bc|a))y/', 'verdict' => false];
        yield 'branches of more than one length merged beside a word boundary' => ['original' => '/\b(?:a|bc|d)/', 'rewrite' => '/\b(?:[ad]|bc)/', 'verdict' => true];
        yield 'branches of more than one length merged inside a lookbehind' => ['original' => '/(?<=x(?:a|bc|d))y/', 'rewrite' => '/(?<=x(?:[ad]|bc))y/', 'verdict' => false];
        // The branches of a lookbehind each have one length on every
        // release: there, only which strings may end where it stands counts,
        // not the order of the branches that match them.
        yield 'branches of other lengths merged at the top of a lookbehind' => ['original' => '/(?<=a|bc|d)y/', 'rewrite' => '/(?<=[ad]|bc)y/', 'verdict' => true];
        yield 'branches of other lengths merged at the top of a negative lookbehind' => ['original' => '/(?<![\xC2-\xDF]|[\xE0-\xEF]|[\xE0-\xEF][\x80-\xBF])/', 'rewrite' => '/(?<![\xC2-\xDF\xE0-\xEF]|[\xE0-\xEF][\x80-\xBF])/', 'verdict' => true];
        yield 'branches of other lengths merged inside a group a lookbehind holds' => ['original' => '/(?<=(?:a|bc|d))y/', 'rewrite' => '/(?<=(?:[ad]|bc))y/', 'verdict' => false];
        yield 'branches of a lookbehind merged into other strings' => ['original' => '/(?<=a|bc|d)y/', 'rewrite' => '/(?<=[ae]|bc)y/', 'verdict' => false];
        yield 'branches of a lookbehind merged beside a group' => ['original' => '/(?<=(a)|bc|d|e)y/', 'rewrite' => '/(?<=(a)|bc|[de])y/', 'verdict' => false];
        yield 'branches of a lookbehind merged into one of two lengths' => ['original' => '/(?<=a|bc|d)y/', 'rewrite' => '/(?<=[ad]|b(?:c|cc))y/', 'verdict' => false];
        yield 'branches of a backtracking lookbehind merged' => ['original' => '/(*naplb:a|bc|d)y/', 'rewrite' => '/(*naplb:[ad]|bc)y/', 'verdict' => false];
        // Parts of other counts on each side are paired by what both keep:
        // under "i" every character is a class, five of them for "-----"
        // and one count for "-{5}".
        yield 'caseless runs counted on both sides of a repeat' => ['original' => '/(?<!x)-----.*-----/i', 'rewrite' => '/(?<!x)-{5}.*-{5}/i', 'verdict' => true];
        yield 'caseless runs counted around a repeat, captured and repeated' => ['original' => '/(.*-----BEGIN CERTIFICATE-----.*-----END CERTIFICATE-----){50}$/Uims', 'rewrite' => '/(.*-{5}BEGIN CERTIFICATE-{5}.*-{5}END CERTIFICATE-{5}){50}$/Uims', 'verdict' => true];
        yield 'a class and a count paired around a repeat' => ['original' => '/(?<!x)(?:_|%).*-----/i', 'rewrite' => '/(?<!x)[_%].*-{5}/i', 'verdict' => true];
        yield 'parts of other counts paired into other strings' => ['original' => '/(?<!x)-----.*-----/i', 'rewrite' => '/(?<!x)-{5}.*-{4}/i', 'verdict' => false];
        yield 'parts of other counts swapped around a repeat' => ['original' => '/(?<!x)ab.*cde/', 'rewrite' => '/(?<!x)cde.*ab/', 'verdict' => false];
        yield 'a part of another count dropped beside a repeat' => ['original' => '/(?<!x)a.*b.*c/', 'rewrite' => '/(?<!x)a.*c/', 'verdict' => false];
        yield 'a part dropped before a part both keep' => ['original' => '/(?<!x)q.*-----/i', 'rewrite' => '/(?<!x).*-{5}/i', 'verdict' => false];
        yield 'a part added before a part both keep' => ['original' => '/(?<!x).*-----/i', 'rewrite' => '/(?<!x)q.*-{5}/i', 'verdict' => false];
        yield 'a paired part of one count changed beside counted runs' => ['original' => '/(?<!x)a.*-----/i', 'rewrite' => '/(?<!x)b.*-{5}/i', 'verdict' => false];
        yield 'branches of a lookbehind merged, a later part changed' => ['original' => '/(?<=a|bc|d)y(?<!x)z/', 'rewrite' => '/(?<=[ad]|bc)y(?<!x)w/', 'verdict' => false];
        yield 'branches of a lookbehind merged into one branch of two lengths' => ['original' => '/(?<=ab|a)x/', 'rewrite' => '/(?<=ab?)x/', 'verdict' => false];
        yield 'a rewritten part beside a backtracking verb' => ['original' => '/(?<!x)(?:_|%)(*PRUNE)a/', 'rewrite' => '/(?<!x)[_%](*PRUNE)a/', 'verdict' => false];
        yield 'a rewritten part of a group only a DEFINE holds' => ['original' => '/(?(DEFINE)(?<n>a|b))x(?&n)/', 'rewrite' => '/(?(DEFINE)(?<n>[ab]))x(?&n)/', 'verdict' => false];
        // The condition of a conditional stays as written in the normal
        // form: what it reads is not proven there.
        yield 'a class for an alternation in the condition of a conditional' => ['original' => '/url\(\s*(?(?=\'|").)(.+?)\)/', 'rewrite' => '/url\(\s*(?(?=[\'"]).)(.+?)\)/', 'verdict' => false];
    }

    /**
     * Where the match solver does not read the pattern, the parts a rewrite
     * changes are proven alone: each matches the strings the other does,
     * and none begins another, so from any position it ends in one place
     * at most, whatever the pattern around it does. Each rewrite writes
     * what the pattern writes in every call, from every offset, on every
     * subject over its letters.
     */
    #[Test]
    #[DataProvider('provideRewritesProvenInPlace')]
    public function test_a_rewrite_proven_in_place_agrees_with_the_engine_in_every_call(string $original, string $rewrite, string $letters, int $length): void
    {
        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');
        $this->assertTrue($verify->invoke(new Optimizer(RegexParser::create(['cache' => null])), $original, $rewrite));

        foreach (self::subjects($letters, $length) as $subject) {
            foreach (['preg_match_all', 'preg_replace', 'preg_split'] as $call) {
                $this->assertSame(self::called($call, $original, $subject), self::called($call, $rewrite, $subject), \sprintf('%s() on %s.', $call, json_encode($subject)));
            }
            for ($offset = 0; $offset <= \strlen($subject); $offset++) {
                $this->assertSame(self::called('preg_match', $original, $subject, $offset), self::called('preg_match', $rewrite, $subject, $offset), \sprintf('preg_match() on %s from %d.', json_encode($subject), $offset));
            }
        }
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string, letters: string, length: int}>
     */
    public static function provideRewritesProvenInPlace(): iterable
    {
        yield 'a class for an alternation beside a lookbehind' => ['original' => '/(?<!\\\\)(?:_|%)/', 'rewrite' => '/(?<!\\\\)[_%]/', 'letters' => '_%\\a', 'length' => 4];
        yield 'a class for an alternation inside a lookahead' => ['original' => '/(?=\s*(?:,|\n))/', 'rewrite' => '/(?=\s*[,\n])/', 'letters' => " ,\na", 'length' => 4];
        yield 'a count for a repeated character after a start of line' => ['original' => '/^\/\/\/\/x/m', 'rewrite' => '/^\/{4}x/m', 'letters' => "/x\n", 'length' => 6];
        yield 'a class for an alternation inside a group a backreference reads' => ['original' => '/(?<!x)((?:_|%)b)\1/', 'rewrite' => '/(?<!x)([_%]b)\1/', 'letters' => '_%bx', 'length' => 5];
        yield 'a class for an alternation inside a caseless group' => ['original' => '/(?<!x)(?i:a|b)c/', 'rewrite' => '/(?<!x)(?i:[ab])c/', 'letters' => 'aBcx', 'length' => 4];
        yield 'a count for a repeated character beside a match start reset' => ['original' => '/(?<!x)a\Kbbbb/', 'rewrite' => '/(?<!x)a\Kb{4}/', 'letters' => 'abx', 'length' => 6];
        yield 'a class for an alternation inside a lookbehind' => ['original' => '/(?<!:|\'|")\/\//', 'rewrite' => '/(?<![:\'"])\/\//', 'letters' => ':"/a', 'length' => 4];
        yield 'a class for an alternation inside a group inside a lookbehind' => ['original' => '/(?<=(\}|;))a/', 'rewrite' => '/(?<=([};]))a/', 'letters' => '};a', 'length' => 4];
        yield 'a repeated branch dropped beside a word boundary' => ['original' => '/\b(?:ab|cd|ab|abc)/', 'rewrite' => '/\b(?:ab|cd|abc)/', 'letters' => 'abcd ', 'length' => 4];
        yield 'branches of more than one length merged beside a word boundary' => ['original' => '/\b(?:a|bc|d)/', 'rewrite' => '/\b(?:[ad]|bc)/', 'letters' => 'abcd ', 'length' => 4];
        yield 'a class for an alternation inside an atomic group' => ['original' => '/(?<!x)(?>(?:_|%))b/', 'rewrite' => '/(?<!x)(?>[_%])b/', 'letters' => '_%bx', 'length' => 4];
        yield 'branches of more than one length merged inside a group' => ['original' => '/\b((?:a|bc|d))/', 'rewrite' => '/\b((?:[ad]|bc))/', 'letters' => 'abcd ', 'length' => 4];
        yield 'a repeated branch dropped, another caseless copy kept' => ['original' => '/\b(?:ab|AB|cd|ab)/i', 'rewrite' => '/\b(?:ab|AB|cd)/i', 'letters' => 'aBcd ', 'length' => 4];
        yield 'branches of other lengths merged at the top of a lookbehind' => ['original' => '/(?<=a|bc|d)y/', 'rewrite' => '/(?<=[ad]|bc)y/', 'letters' => 'abcdy', 'length' => 4];
        yield 'branches of other lengths merged at the top of a negative lookbehind' => ['original' => '/(?<!a|bc|d)y/', 'rewrite' => '/(?<![ad]|bc)y/', 'letters' => 'abcdy', 'length' => 4];
        yield 'branches of other lengths merged, bytes before the position' => ['original' => '/(?<![\xC2-\xDF]|[\xE0-\xEF]|[\xE0-\xEF][\x80-\xBF])/', 'rewrite' => '/(?<![\xC2-\xDF\xE0-\xEF]|[\xE0-\xEF][\x80-\xBF])/', 'letters' => "\xC2\xE0\x80a", 'length' => 4];
        yield 'branches of other lengths merged at the top of a caseless lookbehind' => ['original' => '/(?<=a|BC|d)y/i', 'rewrite' => '/(?<=[ad]|bc)y/i', 'letters' => 'aBcDy', 'length' => 4];
        yield 'caseless runs counted on both sides of a repeat' => ['original' => '/(?<!x)--.*--/i', 'rewrite' => '/(?<!x)-{2}.*-{2}/i', 'letters' => '-ax', 'length' => 6];
        yield 'a class and a count paired around a repeat' => ['original' => '/(?<!x)(?:_|%).*--/i', 'rewrite' => '/(?<!x)[_%].*-{2}/i', 'letters' => '_%-x', 'length' => 5];
        yield 'caseless runs counted around a repeat, captured and repeated' => ['original' => '/(.*--A.*--){2}$/Uims', 'rewrite' => '/(.*-{2}A.*-{2}){2}$/Uims', 'letters' => "-a\n", 'length' => 7];
    }

    /**
     * A part whose own match check passes may still be told apart by the
     * pattern around it: /(?:a|ab)/ and /a/ write the same $matches on
     * every subject, yet after a lookbehind and before a "c" the first finds
     * "abc" in "xabc" and the second nothing, as the "c" backtracks into
     * the alternation. Each pair below differs in a part the proof does not
     * read alone, and the engine tells it apart.
     */
    #[Test]
    #[DataProvider('provideRewritesThePatternAroundTellsApart')]
    public function test_a_rewrite_the_pattern_around_its_part_tells_apart_is_not_proven(string $original, string $rewrite, string $subject): void
    {
        $this->assertNotSame(self::called('preg_match', $original, $subject), self::called('preg_match', $rewrite, $subject), 'The engine tells them apart.');

        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');

        $this->assertFalse($verify->invoke(new Optimizer(RegexParser::create(['cache' => null])), $original, $rewrite));
    }

    #[Test]
    public function test_a_part_whose_own_match_check_passes_is_not_proven_where_the_pattern_backtracks_into_it(): void
    {
        $solver = new LanguageSolver(RegexParser::create(['cache' => null]));
        $this->assertTrue($solver->matchEquivalent('/(?:a|ab)/', '/a/')->isEquivalent, 'Alone, the two parts write the same $matches.');
        $this->assertNotSame(self::called('preg_match', '/(?<=x)(?:a|ab)c/', 'xabc'), self::called('preg_match', '/(?<=x)ac/', 'xabc'), 'The engine tells the patterns apart.');

        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');

        $this->assertFalse($verify->invoke(new Optimizer(RegexParser::create(['cache' => null])), '/(?<=x)(?:a|ab)c/', '/(?<=x)ac/'));
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string, subject: string}>
     */
    public static function provideRewritesThePatternAroundTellsApart(): iterable
    {
        yield 'a part the rest backtracks into' => ['original' => '/(?<=x)(?:a|ab)c/', 'rewrite' => '/(?<=x)ac/', 'subject' => 'xabc'];
        yield 'a group in the part' => ['original' => '/(?<!x)(?:(a)|a)/', 'rewrite' => '/(?<!x)a/', 'subject' => 'a'];
        yield 'a match start reset in the part' => ['original' => '/(?<!x)(?:a\Kb)/', 'rewrite' => '/(?<!x)ab/', 'subject' => 'ab'];
        yield 'a verb in the part' => ['original' => '/(?<!x)(?:a(*COMMIT)b|ac)/', 'rewrite' => '/(?<!x)a[bc]/', 'subject' => 'ac'];
        yield 'a lookahead in the part' => ['original' => '/(?<!x)a(?!b)/', 'rewrite' => '/(?<!x)a/', 'subject' => 'ab'];
        yield 'an anchor in the part' => ['original' => '/(?<!x)a$/', 'rewrite' => '/(?<!x)a/', 'subject' => 'ab'];
        yield 'a caseless option dropped from the part' => ['original' => '/(?<!x)(?i)(?:a|b)/', 'rewrite' => '/(?<!x)[ab]/', 'subject' => 'A'];
        yield 'a possessive part' => ['original' => '/(?<!x)a+(?!b)/', 'rewrite' => '/(?<!x)a++(?!b)/', 'subject' => 'aab'];
        yield 'a branch moved where its first copy was' => ['original' => '/\b(?:a|ab|a)/', 'rewrite' => '/\b(?:ab|a)/', 'subject' => 'ab'];
        yield 'a branch dropped that is no copy' => ['original' => '/\b(?:a|b|c)/', 'rewrite' => '/\b(?:a|b)/', 'subject' => 'c'];
        yield 'a part whose strings begin one another' => ['original' => '/\b(?:x|xy)/', 'rewrite' => '/\bxy?/', 'subject' => 'xy'];
        yield 'strings of three lengths, one beginning another past a step' => ['original' => '/\b(?:a|abcd)/', 'rewrite' => '/\ba(?:bcd)?/', 'subject' => 'abcd'];
        yield 'options the pattern opens with dropped' => ['original' => '/(*UTF)(?<!\xA9)(?:_|%)/', 'rewrite' => '/(?<!\xA9)[_%]/', 'subject' => "\u{E9}_"];
        yield 'a part of two lengths for one of one character' => ['original' => '/(?<!x)(?:ab|c)/', 'rewrite' => '/(?<!x)[abc]/', 'subject' => 'ab'];
        yield 'a repeat of another count' => ['original' => '/(?<!x)(?:ab){1,3}c/', 'rewrite' => '/(?<!x)(?:ab){1,4}c/', 'subject' => 'ababababc'];
        yield 'a group of another name' => ['original' => '/(?<!y)(?<a>x)/', 'rewrite' => '/(?<!y)(?<b>x)/', 'subject' => 'x'];
        yield 'a lookahead of another kind' => ['original' => '/(?<!x)a(?=b)/', 'rewrite' => '/(?<!x)a(?!b)/', 'subject' => 'ab'];
        yield 'a group made atomic' => ['original' => '/(?<!x)(a|ab)c/', 'rewrite' => '/(?<!x)(?>a|ab)c/', 'subject' => 'abc'];
        yield 'a repeated part dropped' => ['original' => '/(?<!y)a\d\d/', 'rewrite' => '/(?<!y)a\d/', 'subject' => 'a12'];
        yield 'a possessive part only a second DEFINE holds' => ['original' => '/(?(DEFINE)(?<a>x))(?(DEFINE)(?<n>\d+))(?&n)1/', 'rewrite' => '/(?(DEFINE)(?<a>x))(?(DEFINE)(?<n>\d++))(?&n)1/', 'subject' => '21'];
        yield 'a possessive part only a DEFINE holds' => ['original' => '/(?(DEFINE)(?<n>\d+))(?&n)1/', 'rewrite' => '/(?(DEFINE)(?<n>\d++))(?&n)1/', 'subject' => '21'];
    }

    /**
     * The three rewrites the check of the whole pattern cannot prove, a
     * lookbehind, a lookahead and a start of line around them, are offered.
     */
    #[Test]
    #[DataProvider('provideRewritesOfferedInPlace')]
    public function test_a_rewrite_proven_in_place_is_offered(string $original, string $rewrite): void
    {
        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));

        $this->assertSame($rewrite, $optimizer->optimize($original, ['verify_with_automata' => true])->optimized);
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string}>
     */
    public static function provideRewritesOfferedInPlace(): iterable
    {
        yield 'beside a lookbehind' => ['original' => '/(?<!\\\\)(?:_|%)/', 'rewrite' => '/(?<!\\\\)[_%]/'];
        yield 'inside a lookahead' => ['original' => '/(?=\s*(?:,|\n))/', 'rewrite' => '/(?=\s*[,\n])/'];
        yield 'after a start of line' => ['original' => '/^\/\/\/\/x/m', 'rewrite' => '/^\/{4}x/m'];
    }

    /**
     * The match solver does not read a lookahead: the strings both patterns
     * match whole agree, yet preg_match() tells them apart, with a lookahead
     * at the edge of the match or alternatives taken in another order. Only a
     * rewrite that reads as the pattern does is proven there.
     */
    #[Test]
    #[DataProvider('provideRewritesOnlyTheLanguagesAgreeOn')]
    public function test_a_rewrite_only_the_languages_agree_on_is_not_proven(string $original, string $rewrite, string $subject): void
    {
        $solver = new LanguageSolver(RegexParser::create(['cache' => null]));
        $this->assertTrue($solver->equivalent($original, $rewrite)->isEquivalent, 'Both match the same strings whole.');
        $this->assertNotSame(self::called('preg_match', $original, $subject), self::called('preg_match', $rewrite, $subject), 'The engine tells them apart.');

        $verify = new \ReflectionMethod(Optimizer::class, 'verifyOptimizedPatternWithAutomata');

        $this->assertFalse($verify->invoke(new Optimizer(RegexParser::create(['cache' => null])), $original, $rewrite));
    }

    /**
     * A possessive quantifier before a lookahead gives nothing back where
     * the greedy one would: the strings matched whole agree, the engine does
     * not, and the rewrite is not made.
     */
    #[Test]
    public function test_a_possessive_rewrite_before_a_lookahead_is_not_made(): void
    {
        $this->assertNotSame(self::called('preg_match', '/a+(?!b)/', 'aab'), self::called('preg_match', '/a++(?!b)/', 'aab'), 'The engine tells them apart.');

        $optimizer = new Optimizer(RegexParser::create(['cache' => null]));

        $this->assertSame('/a+(?!b)/', $optimizer->optimize('/a+(?!b)/', ['possessive' => true])->optimized);
        $this->assertSame('/\d++a/', $optimizer->optimize('/\d+a/', ['possessive' => true])->optimized, 'A possessive that never gives back is still made.');
    }

    /**
     * @return iterable<string, array{original: string, rewrite: string, subject: string}>
     */
    public static function provideRewritesOnlyTheLanguagesAgreeOn(): iterable
    {
        yield 'a lookahead at the edge of the match' => ['original' => '/a(?!b)/', 'rewrite' => '/a/', 'subject' => 'ab'];
        yield 'alternatives swapped before a lookahead' => ['original' => '/(?:a|ab)(?!c)/', 'rewrite' => '/(?:ab|a)(?!c)/', 'subject' => 'ab'];
        yield 'a possessive before a lookahead' => ['original' => '/a+(?!b)/', 'rewrite' => '/a++(?!b)/', 'subject' => 'aab'];
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
     * Every subject of up to $length of the letters, the empty one first.
     *
     * @return list<string>
     */
    private static function subjects(string $letters, int $length): array
    {
        $subjects = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (str_split($letters) as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $subjects = [...$subjects, ...$next];
            $layer = $next;
        }

        return $subjects;
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
