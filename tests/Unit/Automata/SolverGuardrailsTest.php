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
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The refusals and answers that keep the solver honest at its edges: a
 * possessive quantifier may only be answered when what may follow it, in
 * every direction the engine can take, provably takes none of its
 * characters; an anchor may only be dropped where it says nothing; and the
 * pattern that names a surrogate code point is refused whatever spelling
 * names it. Every refusal row is pinned to the engine oracle.
 */
final class SolverGuardrailsTest extends TestCase
{
    /**
     * Oracle: each pattern fails to compile in the running engine ("UTF-8
     * codepoints ... are not supported" for the surrogate block), so no
     * subject the engine can read ever runs it: the solver must refuse the
     * pattern, whatever spelling names the forbidden code point — a
     * caseless literal, a range endpoint, a member after a range.
     */
    #[Test]
    #[DataProvider('provideSurrogateSpellings')]
    public function test_a_pattern_naming_a_surrogate_in_any_spelling_is_refused(string $pattern): void
    {
        $this->assertFalse(@\preg_match($pattern, 'a'), $pattern.' must not compile.');

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::SURROGATE_MESSAGE);

        (new LanguageSolver())->subsetOf($pattern, '/a/', $this->fullMatchOptions());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSurrogateSpellings(): iterable
    {
        yield 'a caseless literal escapes through the fold walk' => ['pattern' => '/(?i)\x{D800}/u'];
        yield 'a member after a range still names the block' => ['pattern' => '/[a-\x{D7FF}\x{D800}]/u'];
        yield 'the last surrogate as a range endpoint' => ['pattern' => '/[a-\x{DFFF}]/u'];
    }

    /**
     * Oracle: the two rows are spellings whose possessive would have to
     * give characters back to another iteration of its own loop — the
     * first characters of the loop follow the quantifier, so no proof of
     * safety exists and the pattern is refused, bounded or not.
     */
    #[Test]
    #[DataProvider('provideLoopOwnFirstRows')]
    public function test_a_possessive_followed_by_its_own_loop_is_refused(string $pattern, string $subject): void
    {
        $this->assertTrue($this->matchesFull(\str_replace('++', '+', $pattern), $subject), 'the greedy spelling must accept "'.$subject.'".');

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::POSSESSIVE_MESSAGE);

        (new LanguageSolver())->intersection($pattern, '/a/', $this->fullMatchOptions());
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideLoopOwnFirstRows(): iterable
    {
        yield 'an unbounded loop with data "aa"' => ['pattern' => '/(?:(a)a++)*/', 'subject' => 'aa'];
        yield 'a bounded loop with data "aaaa"' => ['pattern' => '/(?:(a)a++){2}/', 'subject' => 'aaaa'];
    }

    /**
     * Oracle: the pattern matches "ba" and "baba": every iteration starts
     * with a character the possessive never takes, so the language is the
     * greedy one and the pattern is answered, not refused.
     */
    #[Test]
    public function test_a_possessive_inside_a_loop_starting_elsewhere_is_answered(): void
    {
        $this->assertTrue((bool) @\preg_match('/^(?:(b)a++)*$/', 'ba'));

        $dfa = (new LanguageSolver())->compile('/(?:(b)a++)*/', $this->fullMatchOptions());

        $this->assertSame(255, $dfa->maxCodePoint);
    }

    /**
     * Oracle: "/(a)++b/" accepts "ab" and "/(?:ab)++c/" accepts "abc": the
     * atom may sit in a capture or be a sequence, the proof reads through
     * both and the patterns are answered.
     */
    #[Test]
    #[DataProvider('provideAnswerablePossessiveAtoms')]
    public function test_a_possessive_atom_is_answered_whatever_wraps_it(string $pattern, string $subject): void
    {
        $this->assertTrue($this->matchesFull($pattern, $subject), $pattern.' must accept "'.$subject.'".');

        $result = (new LanguageSolver())->intersection($pattern, $pattern, $this->fullMatchOptions());

        $this->assertFalse($result->isEmpty);
        $this->assertSame($subject, $result->example);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideAnswerablePossessiveAtoms(): iterable
    {
        yield 'a capture around the atom' => ['pattern' => '/(a)++b/', 'subject' => 'ab'];
        yield 'a two-character literal atom' => ['pattern' => '/(?:ab)++c/', 'subject' => 'abc'];
        yield 'a sequence atom of a class and a literal' => ['pattern' => '/(?:[ab]c)++x/', 'subject' => 'acx'];
    }

    /**
     * Oracle: the greedy spellings of "/a++x((?:c)b++)y/" and
     * "/((?:y)a++)qz++/" accept their subjects, but each possessive would
     * face another one further along the same sequence — one proof cannot
     * be composed with the other, so both are refused.
     */
    #[Test]
    #[DataProvider('providePossessiveCompositionRows')]
    public function test_two_possessives_along_one_sequence_are_refused(string $pattern, string $subject): void
    {
        $this->assertTrue($this->matchesFull(\str_replace('++', '+', $pattern), $subject), 'the greedy spelling must accept "'.$subject.'".');

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::POSSESSIVE_MESSAGE);

        (new LanguageSolver())->compile($pattern, $this->fullMatchOptions());
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function providePossessiveCompositionRows(): iterable
    {
        yield 'a possessive nested beyond a follower' => ['pattern' => '/a++x((?:c)b++)y/', 'subject' => 'axcbby'];
        yield 'a possessive ending a part before another' => ['pattern' => '/((?:y)a++)qz++/', 'subject' => 'yaqzz'];
    }

    /**
     * Oracle: "\P{Any}" matches no character at all, so "/\P{Any}++x/"
     * accepts nothing — a possessive whose atom can never take a character
     * has no first set to prove safety with, and the pattern is refused
     * rather than answered as the language that drops it.
     */
    #[Test]
    public function test_a_possessive_of_an_empty_class_is_refused(): void
    {
        $this->assertSame(0, @\preg_match('/^\P{Any}++x$/', 'x'));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::POSSESSIVE_MESSAGE);

        (new LanguageSolver())->compile('/\P{Any}++x/', $this->fullMatchOptions());
    }

    /**
     * Oracle: "/(?:[b]x)++b/" accepts "bxb" where the engine could still
     * ask the possessive for the "b" of its own next iteration — the proof
     * reads the FIRST characters of the atom, which meet what follows, so
     * the pattern is refused whatever its later parts take.
     */
    #[Test]
    public function test_a_possessive_atom_is_judged_by_its_first_part(): void
    {
        $this->assertTrue((bool) @\preg_match('/^(?:[b]x)++b$/', 'bxb'));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::POSSESSIVE_MESSAGE);

        (new LanguageSolver())->compile('/(?:[b]x)++b/', $this->fullMatchOptions());
    }

    /**
     * Oracle: "/(?:\1)+/" names a backreference the engine reads with the
     * state of the match so far; the walk meets it as a body whose first
     * characters cannot be worked out, and the refusal names the
     * backreference, not the possessive that wraps it.
     */
    #[Test]
    public function test_a_possessive_of_an_opaque_body_names_the_opaque_reason(): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::OPAQUE_MESSAGE);

        $this->nfaOf('/(?:\1)+/');
    }

    /**
     * Oracle: the star pattern accepts "" and "a"; its NFA is built from
     * exactly four states — loop entry and exit around the two of the
     * class — so a budget of four answers and a budget of three refuses.
     * A star built through an epsilon detour would spend a fifth state and
     * be refused by the budget the language fits in.
     */
    #[Test]
    public function test_a_star_is_built_within_four_states(): void
    {
        $solver = new LanguageSolver();

        $dfa = $solver->compile('/a*/', new SolverOptions(maxNfaStates: 4));

        $this->assertCount(2, $dfa->states);

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('NFA state limit exceeded (3).');

        $solver->compile('/a*/', new SolverOptions(maxNfaStates: 3));
    }

    /**
     * Oracle: "/a{1,3}/" accepts "a" where "/a{2,3}/" refuses it, and
     * "/a{2,3}/" accepts "aa" where "/aaa/" refuses it: a bounded
     * repetition accepts every count from its minimum to its maximum, and
     * the counterexample of each pair names the shortest missing count.
     */
    #[Test]
    public function test_a_bounded_repetition_accepts_every_count_from_its_minimum(): void
    {
        $solver = new LanguageSolver();

        $this->assertSame(1, \preg_match('/^a{1,3}$/', 'a'));
        $this->assertSame(0, \preg_match('/^a{2,3}$/', 'a'));

        $one = $solver->equivalent('/a{1,3}/', '/a{2,3}/', $this->fullMatchOptions());
        $this->assertFalse($one->isEquivalent);
        $this->assertSame('a', $one->leftOnlyExample);

        $this->assertSame(1, \preg_match('/^a{2,3}$/', 'aa'));
        $this->assertSame(0, \preg_match('/^aaa$/', 'aa'));

        $two = $solver->equivalent('/a{2,3}/', '/aaa/', $this->fullMatchOptions());
        $this->assertFalse($two->isEquivalent);
        $this->assertSame('aa', $two->leftOnlyExample);
    }

    /**
     * Oracle: "/a\Z/" accepts "a" — "\Z" reads as the subject's end at the
     * end of an alternative, so the anchor says nothing there and the
     * pattern is answered like "/a/".
     */
    #[Test]
    public function test_an_end_anchor_at_the_end_is_answered(): void
    {
        $this->assertSame(1, \preg_match('/^a\Z$/', 'a'));

        $result = (new LanguageSolver())->intersection('/a\Z/', '/a/', $this->fullMatchOptions());

        $this->assertFalse($result->isEmpty);
        $this->assertSame('a', $result->example);
    }

    /**
     * Oracle: PCRE compiles "/(a$)b/" and "/^a(b^)/" — both match nothing,
     * because the anchor reads the subject where the pattern has already
     * moved past it. An anchor under a group follows the group, not the
     * subject, and the refusal says so in its own words.
     */
    #[Test]
    #[DataProvider('provideNestedAnchorRows')]
    public function test_a_nested_anchor_is_read_where_it_stands(string $pattern): void
    {
        foreach (['ab', "a\nb", "ab\n", 'b'] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), $pattern.' must match nothing.');
        }

        $this->assertTrue((new LanguageSolver())->intersection($pattern, '/[\s\S]*/', $this->fullMatchOptions())->isEmpty);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNestedAnchorRows(): iterable
    {
        yield 'a dollar inside a group' => ['pattern' => '/(a$)b/'];
        yield 'a caret deeper in the sequence' => ['pattern' => '/^a(b^)/'];
        yield 'a subject start inside a group' => ['pattern' => '/(a\A)b/'];
    }

    /**
     * Oracle: "/(a\G)b/" compiles — "\G" reads where the match started,
     * nested or not, and the refusal keeps the one message it shares with
     * "\K".
     */
    #[Test]
    public function test_a_nested_match_start_keeps_the_condition_message(): void
    {
        $this->assertNotFalse(@\preg_match('/(a\G)b/', ''));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::ASSERTION_MESSAGE);

        (new LanguageSolver())->compile('/(a\G)b/', $this->fullMatchOptions());
    }

    /**
     * Oracle: "/a\Kb/" and "/\G/" compile — the ladder itself refuses them
     * even when the pattern is handed straight to the transformer, without
     * the AST gate in front of it: the graph command does exactly that.
     */
    #[Test]
    #[DataProvider('provideDirectTransformerRows')]
    public function test_the_transformer_refuses_unreadable_anchors_on_its_own(string $pattern): void
    {
        $this->assertNotFalse(@\preg_match($pattern, ''), $pattern.' must compile.');

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage(HirToNfaTransformer::ASSERTION_MESSAGE);

        $this->nfaOf($pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideDirectTransformerRows(): iterable
    {
        yield 'a match reset after a character' => ['pattern' => '/a\Kb/'];
        yield 'a whole pattern of match start' => ['pattern' => '/\G/'];
        yield 'a match start inside a sequence' => ['pattern' => '/a\Gb/'];
    }

    /**
     * Oracle: "/^|b/" matches every subject, its empty first branch at the
     * start; "/a$|b/" matches "xa" and "b", not "ax". One alternative
     * anchors where its sibling does not: each anchor reads where it
     * stands.
     */
    #[Test]
    #[DataProvider('provideMixedPartialAnchorRows')]
    public function test_mixed_anchors_across_alternatives_are_read_in_partial_mode(string $pattern, string $same, string $other): void
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(matchMode: MatchMode::Partial);

        $this->assertTrue($solver->equivalent($pattern, $same, $options)->isEquivalent);
        $this->assertFalse($solver->equivalent($pattern, $other, $options)->isEquivalent);
    }

    /**
     * @return iterable<string, array{pattern: string, same: string, other: string}>
     */
    public static function provideMixedPartialAnchorRows(): iterable
    {
        yield 'one branch anchored at the start' => ['pattern' => '/^|b/', 'same' => '//', 'other' => '/b/'];
        yield 'one branch anchored at the end' => ['pattern' => '/a$|b/', 'same' => '/a\n?\z|b/', 'other' => '/a|b/'];
    }

    /**
     * Oracle: every alternative reads the subject the same way at each
     * edge — anchored together at the start, at the end, or a branch that
     * is nothing but the anchor beside an anchored sibling — and the
     * partial questions are answered.
     */
    #[Test]
    #[DataProvider('provideAgreeingPartialAnchorRows')]
    public function test_agreeing_alternative_anchors_are_answered_in_partial_mode(string $pattern, string $other, string $subject): void
    {
        $this->assertSame(1, @\preg_match($pattern, $subject), $pattern.' must accept "'.$subject.'".');

        $result = (new LanguageSolver())->intersection($pattern, $other, new SolverOptions(matchMode: MatchMode::Partial));

        $this->assertFalse($result->isEmpty);
        $this->assertSame($subject, $result->example);
    }

    /**
     * @return iterable<string, array{pattern: string, other: string, subject: string}>
     */
    public static function provideAgreeingPartialAnchorRows(): iterable
    {
        yield 'both branches anchored at the start' => ['pattern' => '/^a|^b/', 'other' => '/a/', 'subject' => 'a'];
        yield 'both branches anchored at the end' => ['pattern' => '/a$|b$/', 'other' => '/a/', 'subject' => 'a'];
        yield 'a bare caret beside an anchored branch' => ['pattern' => '/^|^x/', 'other' => '/x/', 'subject' => 'x'];
    }

    /**
     * Oracle: "/a[\x00-\x60]/" accepts "a\x00" — the witness a merged
     * alphabet range proves its pair with names the FIRST character of
     * that range, the smallest string of its length the two languages
     * share.
     */
    #[Test]
    public function test_the_witness_names_the_first_character_of_the_range_it_proves(): void
    {
        $this->assertSame(1, \preg_match('/^a[\x00-\x60]$/', "a\x00"));

        $result = (new LanguageSolver())->intersection('/a[\x00-\x60]/', '/a[\x00-\x60]/', $this->fullMatchOptions());

        $this->assertFalse($result->isEmpty);
        $this->assertSame("a\x00", $result->example);
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

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }

    private function nfaOf(string $pattern): Nfa
    {
        $ast = RegexParser::create()->parse($pattern);

        return (new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($ast)))
            ->transform((new HirTranslator())->translate($ast), new SolverOptions());
    }
}
