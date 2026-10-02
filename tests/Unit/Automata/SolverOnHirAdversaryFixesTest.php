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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Cli\Graph\MermaidDumper;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Soundness holes the migration's adversary round found, each pinned with
 * the engine oracle: a surrogate code point the engine refuses to compile,
 * a witness dropped because one DFA has no transition for the other's
 * alphabet, a possessive gate that stops at a group boundary the engine
 * does not, an inline caseless-restrict flag, a "(*UTF)" start verb read
 * with the Unicode properties its pattern never asked for.
 */
final class SolverOnHirAdversaryFixesTest extends TestCase
{
    /**
     * Oracle: preg_match('/[a\x{D800}]/u', 'a') is false and the compile
     * fails ("disallowed Unicode code point"): no subject the engine can
     * read ever holds a surrogate, and the pattern that names one never
     * runs. The solver must refuse it, not answer a language for it.
     */
    #[Test]
    #[DataProvider('provideSurrogateRefusalRows')]
    public function test_a_pattern_naming_a_surrogate_is_refused(string $pattern, string $other): void
    {
        $solver = new LanguageSolver();

        $this->assertFalse(false !== @\preg_match($pattern, 'a'), $pattern.' must not compile.');

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('PCRE refuses any pattern that names a surrogate code point, which the automata solver cannot read as a pure language.');

        $solver->subsetOf($pattern, $other, $this->fullMatchOptions());
    }

    /**
     * @return iterable<string, array{pattern: string, other: string}>
     */
    public static function provideSurrogateRefusalRows(): iterable
    {
        yield 'a surrogate class swallows a smaller class' => ['pattern' => '/[a\x{D800}]/u', 'other' => '/[ab]/u'];
        yield 'a bare surrogate literal' => ['pattern' => '/\x{D800}/u', 'other' => '/a/'];
    }

    /**
     * Oracle: "/[\x{D7FF}-\x{E000}]/u" compiles and matches U+D7FF and
     * U+E000: only a range straddling the surrogate block with both
     * endpoints outside it is legal, and no subject ever holds the code
     * points between. The set keeps its two real ends and the hole stays
     * out of the DFA ranges.
     */
    #[Test]
    public function test_a_straddling_range_keeps_the_hole_out(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue((bool) @\preg_match('/^[\x{D7FF}-\x{E000}]$/u', "\u{D7FF}"));

        $result = $solver->equivalent('/[\x{D7FF}-\x{E000}]/u', '/[\x{D7FF}\x{E000}]/u', $this->fullMatchOptions());
        $this->assertTrue($result->isEquivalent);

        // The smallest member is the witness before and after the fix: the
        // hole never had a representative to lose (surrogate re-blessing:
        // none moved).
        $intersection = $solver->intersection('/[\x{D7FF}-\x{E000}]/u', '/./u', $this->fullMatchOptions());
        $this->assertFalse($intersection->isEmpty);
        $this->assertSame("\u{D7FF}", $intersection->example);

        $dfa = $solver->compile('/[\x{D7FF}-\x{E000}]/u', $this->fullMatchOptions());
        foreach ($dfa->states as $state) {
            foreach ($state->ranges as [$start, $end]) {
                $this->assertFalse($start <= 0xDFFF && $end >= 0xD800, sprintf('DFA range %04X-%04X covers the surrogate block.', $start, $end));
            }
        }
    }

    /**
     * Oracle: "/k/iu" matches the Kelvin sign U+212A (1) where "/k/i" does
     * not (0), and "(*UTF)k/i" matches it (1) where "[kK]" does not (0).
     * One DFA reading code points and one reading bytes must still observe
     * the character only the code-point side can take.
     */
    #[Test]
    public function test_mixed_alphabets_keep_their_witnesses(): void
    {
        $solver = new LanguageSolver();
        $kelvin = "\u{212A}";

        $this->assertTrue((bool) @\preg_match('/k/iu', $kelvin));
        $this->assertFalse((bool) @\preg_match('/k/i', $kelvin));

        $equivalence = $solver->equivalent('/k/iu', '/k/i', $this->fullMatchOptions());
        $this->assertFalse($equivalence->isEquivalent);
        $this->assertSame($kelvin, $equivalence->leftOnlyExample);
        $this->assertTrue($this->matchesFull('/k/iu', $equivalence->leftOnlyExample ?? ''));
        $this->assertFalse($this->matchesFull('/k/i', $equivalence->leftOnlyExample ?? ''));

        $subset = $solver->subsetOf('/k/iu', '/k/i', $this->fullMatchOptions());
        $this->assertFalse($subset->isSubset);
        $this->assertSame($kelvin, $subset->counterExample);

        $utfVerb = $solver->equivalent('/(*UTF)k/i', '/[kK]/', $this->fullMatchOptions());
        $this->assertFalse($utfVerb->isEquivalent);
        $this->assertSame($kelvin, $utfVerb->leftOnlyExample);
    }

    /**
     * Oracle: "/((a++)b?)a/" refuses "aa" (0) where "/(a+b?)a/" accepts it
     * (1): the possessive quantifier inside the group would have to give an
     * "a" back to what follows the group, so the two patterns read different
     * languages and the gate may not call the possessive safe. The follow
     * set it judges against must read through the group boundary, to what
     * follows it — or the pattern is refused.
     */
    #[Test]
    #[DataProvider('provideBoundaryRefusalRows')]
    public function test_the_possessive_gate_reads_past_the_boundary(string $pattern): void
    {
        $solver = new LanguageSolver();

        $greedy = str_replace('++', '+', $pattern);
        $this->assertTrue((bool) @\preg_match($greedy, 'aa'), $greedy.' must accept "aa".');
        $this->assertFalse((bool) @\preg_match($pattern, 'aa'), $pattern.' must refuse "aa".');

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.');

        $solver->intersection($pattern, '/a/', $this->fullMatchOptions());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBoundaryRefusalRows(): iterable
    {
        yield 'through a capture boundary' => ['pattern' => '/((a++)b?)a/'];
        yield 'through an alternation branch boundary' => ['pattern' => '/(?:(?:(a++)b?)|q)a/'];
    }

    /**
     * The same gate from the consumer side: the possessive rewrite of
     * "/(a+b?)a/" is exactly the unsound "/((a++)b?)a/" shape above, so the
     * optimizer must not ship it — the original pattern comes back.
     */
    #[Test]
    public function test_the_optimizer_does_not_ship_an_unsound_possessive_rewrite(): void
    {
        $optimizer = new Optimizer(RegexParser::create());

        $result = $optimizer->optimize('/(a+b?)a/', ['possessive' => true]);

        $this->assertSame('/(a+b?)a/', $result->optimized);
        $this->assertFalse($result->isChanged());
    }

    /**
     * Oracle: "/(?ri)k/u" accepts "k" and "K" (1, 1) and refuses the Kelvin
     * sign (0): the /r restrict blocks every caseless match between an
     * ASCII and a non-ASCII character, so the inline flag reads the letter
     * as exactly "[kK]". The global /r stays refused by the flag gate; the
     * inline one is answered, with its restrict honoured.
     */
    #[Test]
    public function test_the_inline_caseless_restrict_is_read(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue((bool) @\preg_match('/^(?ri)k$/u', 'k'));
        $this->assertTrue((bool) @\preg_match('/^(?ri)k$/u', 'K'));
        $this->assertFalse((bool) @\preg_match('/^(?ri)k$/u', "\u{212A}"));

        $result = $solver->equivalent('/(?ri)k/u', '/[kK]/u', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
        $this->assertNull($result->leftOnlyExample);
        $this->assertNull($result->rightOnlyExample);
    }

    /**
     * Oracle: "/(*UTF)^\w$/" on "é" is 0 and on "a" is 1, the same for
     * "[[:alpha:]]" — the start verb makes the engine read UTF-8 code
     * points WITHOUT the Unicode properties the /u flag of this PHP build
     * also turns on, so the classes stay ASCII under it, as in byte mode.
     */
    #[Test]
    #[DataProvider('provideUtfVerbRows')]
    public function test_a_utf_start_verb_stays_without_unicode_properties(string $left, string $right): void
    {
        $solver = new LanguageSolver();

        $this->assertFalse((bool) @\preg_match('/(*UTF)^\w$/', "\u{E9}"));
        $this->assertTrue((bool) @\preg_match('/(*UTF)^\w$/', 'a'));

        $result = $solver->equivalent($left, $right, $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent, $left.' <=> '.$right);
        $this->assertNull($result->leftOnlyExample);
        $this->assertNull($result->rightOnlyExample);
    }

    /**
     * @return iterable<string, array{left: string, right: string}>
     */
    public static function provideUtfVerbRows(): iterable
    {
        yield 'word stays ascii under the utf verb' => ['left' => '/(*UTF)\w/', 'right' => '/\w/'];
        yield 'posix alpha stays ascii under the utf verb' => ['left' => '/(*UTF)[[:alpha:]]/', 'right' => '/[[:alpha:]]/'];
    }

    /**
     * The full-set label follows the alphabet it is drawn for: the bytes
     * 00-FF are the whole byte alphabet ("Σ") but a proper subset of the
     * code-point one ("[\x00-\xFF]").
     */
    #[Test]
    public function test_the_graph_full_set_symbol_follows_the_alphabet(): void
    {
        $unicode = (new MermaidDumper())->dump($this->nfaOf('/[\x00-\xFF]/u'));
        $bytes = (new MermaidDumper())->dump($this->nfaOf('/[\x00-\xFF]/'));

        $this->assertStringContainsString(': [\x00-\xFF]', $unicode);
        $this->assertStringNotContainsString('Σ', $unicode);
        $this->assertStringContainsString('Σ', $bytes);
    }

    /**
     * The oracle: whether the running engine accepts the subject as a match
     * of the whole pattern.
     */
    private function matchesFull(string $pattern, string $subject): bool
    {
        $matches = [];
        $result = @\preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);
        if (1 !== $result) {
            return false;
        }

        return $matches[0][0] === $subject && 0 === $matches[0][1];
    }

    private function nfaOf(string $pattern): Nfa
    {
        $ast = RegexParser::create()->parse($pattern);

        return (new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($ast)))
            ->transform((new HirTranslator())->translate($ast), new SolverOptions());
    }

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
