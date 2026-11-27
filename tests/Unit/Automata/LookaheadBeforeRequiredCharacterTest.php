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
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Options\SolverStrategy;
use PHPRegex\Automata\Transform\AssertedFirstCharacter;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where a pattern sets no first character of its own, PCRE2 looks for the
 * one a lookahead opening it asserts, then for the character the pattern
 * requires, from past the first. Where the two are one character, PCRE2
 * 10.32 to 10.44 miss a match that holds it once, with the JIT and
 * without: "/(?=a)b?a/" finds nothing in "a" (PCRE2 ChangeLog 10.45, item
 * 16, pull request #444). From 10.45 the asserted character is left out
 * when the required one has the same value; one in the other case still
 * counts, and "/(?=a)b?A/i" finds nothing in "a" in every release up to
 * 10.49. "(*NO_START_OPT)" brings the match back.
 *
 * The rows were measured in the official images php:8.1-cli (PCRE2 10.39),
 * php:8.2-cli (10.40), php:8.3-cli (10.42) and php:8.4-cli (10.44), with
 * pcre.jit=0 and pcre.jit=1, and on PCRE2 10.49; the first rows in
 * php:7.3-cli (10.32), php:7.4-cli and php:8.0-cli (10.35) and php:8.5-cli
 * (10.44) too.
 */
final class LookaheadBeforeRequiredCharacterTest extends TestCase
{
    use PcreGates;
    use PhpUnitForwardCompat;

    private const MESSAGE = 'PCRE2 looks for the character a lookahead opening the pattern asserts, then for the one the pattern requires past it, and misses a match where the two are one character: the same before PCRE2 10.45, "/(?=a)b?a/" finding nothing in "a", or one in the other case, "/(?=a)b?A/i" nothing in "a"; and it takes the case of the first alternative of the lookahead that starts with the character, "/(?=A|[Aa])/" finding nothing in "a", which the automata solver does not read.';

    private const MATCH_MESSAGE = 'PCRE2 looks for the character a lookahead opening the pattern asserts, then for the one the pattern requires past it, and misses a match where the two are one character: the same before PCRE2 10.45, "/(?=a)b?a/" finding nothing in "a", or one in the other case, "/(?=a)b?A/i" nothing in "a"; and it takes the case of the first alternative of the lookahead that starts with the character, "/(?=A|[Aa])/" finding nothing in "a", which the match solver does not read.';

    /**
     * PCRE2 10.39 to 10.44 find nothing in the witness, with the JIT and
     * without, where 10.45 and later find a match, as the pattern does once
     * no start of match is skipped: refused for a target before 10.45, read
     * for one from it.
     */
    #[Test]
    #[DataProvider('provideCharactersRequiredAgain')]
    public function test_a_lookahead_on_a_character_the_pattern_requires_again_is_refused_before_pcre2_10_45(string $pattern, string $witness): void
    {
        $found = PcreTarget::runtime()->pcreAtLeast('10.45') ? 1 : 0;
        $this->assertSame($found, JitInAFreshProcess::matched($pattern, $witness));
        $this->assertSame($found, preg_match('/(*NO_JIT)'.substr($pattern, 1), $witness));
        $this->assertSame(1, preg_match('/(*NO_START_OPT)'.substr($pattern, 1), $witness));

        $partial = new SolverOptions(matchMode: MatchMode::Partial);
        $this->assertTrue(self::accepts((new LanguageSolver(self::parser('10.45')))->compile($pattern, $partial), $witness));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains(self::MESSAGE);

        (new LanguageSolver(self::parser('10.44')))->compile($pattern, $partial);
    }

    /**
     * @return iterable<string, array{pattern: string, witness: string}>
     */
    public static function provideCharactersRequiredAgain(): iterable
    {
        yield 'a letter' => ['pattern' => '/(?=a)b?a/', 'witness' => 'a'];
        yield 'the report of the issue' => ['pattern' => '/(?=ba)b?b/', 'witness' => 'ba'];
        yield 'a caret under m' => ['pattern' => '/(?=b)^b/m', 'witness' => "a\nb"];
        yield 'more after the character' => ['pattern' => '/(?=a)b?a./', 'witness' => 'ab'];
        yield 'the character later in the lookahead' => ['pattern' => '/(?=ab)b?a/', 'witness' => 'ab'];
        yield 'caseless, written alike' => ['pattern' => '/(?=a)b?a/i', 'witness' => 'A'];
        yield 'under u' => ['pattern' => '/(?=a)b?a/u', 'witness' => 'a'];
        yield 'a byte past ASCII' => ['pattern' => '/(?=\xE9)b?\xE9/', 'witness' => "\xE9"];
        yield 'a digit' => ['pattern' => '/(?=1)\D?1/', 'witness' => '1'];
        yield 'a newline' => ['pattern' => '/(?=\n)x?\n/', 'witness' => "\n"];
        yield 'an escape' => ['pattern' => '/(?=a)b?\x61/', 'witness' => 'a'];
        yield 'a class of one character in the lookahead' => ['pattern' => '/(?=[a])b?a/', 'witness' => 'a'];
        yield 'a range of one character' => ['pattern' => '/(?=a)b?[a-a]/', 'witness' => 'a'];
        yield 'a repeat in the lookahead' => ['pattern' => '/(?=a+)b?a/', 'witness' => 'a'];
        yield 'a lookahead in a group' => ['pattern' => '/((?=a))b?a/', 'witness' => 'a'];
        yield 'a group in the lookahead' => ['pattern' => '/(?=(?:a))b?a/', 'witness' => 'a'];
        yield 'alternatives of the lookahead that agree' => ['pattern' => '/(?=a|ab)c?a/', 'witness' => 'a'];
        yield 'alternatives of the pattern that agree' => ['pattern' => '/(?:(?=a)b?a|(?=a)c?a)/', 'witness' => 'a'];
        yield 'after a negative lookahead' => ['pattern' => '/(?!x)(?=a)b?a/', 'witness' => 'a'];
        yield 'after a lookbehind' => ['pattern' => '/(?<!x)(?=a)b?a/', 'witness' => 'a'];
        yield 'after a word boundary' => ['pattern' => '/\b(?=a)b?a/', 'witness' => 'a'];
        yield 'after an option setting' => ['pattern' => '/(?i)(?=a)b?a/', 'witness' => 'A'];
        yield 'after a character repeated no time' => ['pattern' => '/x{0}(?=a)b?a/', 'witness' => 'a'];
        yield 'an empty alternative after the lookahead' => ['pattern' => '/(?=a)(?:b|)a/', 'witness' => 'a'];
    }

    /**
     * Where the character the pattern requires is the asserted one in the
     * other case, every release from 10.39 to 10.49 finds nothing in the
     * witness, with the JIT and without, though the pattern matches it:
     * refused whatever the target. Where the compiler takes no character
     * from the lookahead, the start-up study may still find the one every
     * match starts with there, past what may match nothing, and look for
     * the required one past it alike.
     */
    #[Test]
    #[DataProvider('provideCharactersRequiredInTheOtherCase')]
    public function test_a_lookahead_on_a_character_the_pattern_requires_in_the_other_case_is_refused_in_every_release(string $pattern, string $witness): void
    {
        $this->assertSame(0, JitInAFreshProcess::matched($pattern, $witness));
        $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($pattern, 1), $witness));
        $this->assertSame(1, preg_match('/(*NO_START_OPT)'.substr($pattern, 1), $witness));

        foreach (['10.44', '10.45', '10.49'] as $release) {
            try {
                (new LanguageSolver(self::parser($release)))->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));
                $this->fail($pattern.' is read for PCRE2 '.$release.'.');
            } catch (ComplexityException $refusal) {
                $this->assertSame(self::MESSAGE, $refusal->getMessage(), $release);
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, witness: string}>
     */
    public static function provideCharactersRequiredInTheOtherCase(): iterable
    {
        yield 'both caseless' => ['pattern' => '/(?=a)b?A/i', 'witness' => 'a'];
        yield 'the lookahead in capitals' => ['pattern' => '/(?=A)b?a/i', 'witness' => 'a'];
        yield 'the required character caseless' => ['pattern' => '/(?=a)b?(?i:A)/', 'witness' => 'a'];
        yield 'the lookahead caseless' => ['pattern' => '/(?i:(?=A))b?a/', 'witness' => 'a'];
        yield 'a class of both cases in the lookahead' => ['pattern' => '/(?=[Aa])b?a/', 'witness' => 'a'];
        yield 'a class of both cases required' => ['pattern' => '/(?=A)b?[aA]/i', 'witness' => 'a'];
        yield 'a letter, then a class of both its cases, in the lookahead' => ['pattern' => '/(?=A|[Aa])/', 'witness' => 'a'];
        yield 'a letter, then itself caseless, in the lookahead' => ['pattern' => '/(?=A|(?i)A)/', 'witness' => 'a'];
        yield 'alternatives in a group of the lookahead' => ['pattern' => '/(?=(?:A|[Aa]))/', 'witness' => 'a'];
        yield 'alternatives that go on' => ['pattern' => '/(?=AB|[Aa]C)/', 'witness' => 'aC'];
        yield 'a repeat, then a class of both cases' => ['pattern' => '/(?=A+|[Aa])/', 'witness' => 'a'];
        yield 'bytes past ASCII under (*UCP)' => ['pattern' => '/(*UCP)(?=\xE9)b?(?i:\xC9)/', 'witness' => "\xE9"];
        yield 'a character the lookahead may skip first' => ['pattern' => '/(?=(?:a?\x61))A?(?i:A)/', 'witness' => 'a'];
        yield 'a caret before the lookahead' => ['pattern' => '/^(?=a)b?(?i:A)/', 'witness' => 'a'];
        yield 'the character, optional, before the lookahead' => ['pattern' => '/a?(?=a)b?(?i:A)/', 'witness' => 'a'];
        yield 'an empty group before the lookahead' => ['pattern' => '/(?:)(?=a)b?(?i:A)/', 'witness' => 'a'];
        yield 'a class of the character twice' => ['pattern' => '/(?=[aa])b?(?i:A)/', 'witness' => 'a'];
        yield 'a second lookahead that may skip a character' => ['pattern' => '/(?=a)(?=b?a)c?(?i:A)/', 'witness' => 'a'];
        yield 'a dot that may match nothing' => ['pattern' => '/(?=a).?(?i:A)/', 'witness' => 'a'];
        yield 'a lookahead spelled as a verb' => ['pattern' => '/(*pla:a)b?A/i', 'witness' => 'a'];
        yield 'a lookahead spelled as a long verb' => ['pattern' => '/(*positive_lookahead:a)b?A/i', 'witness' => 'a'];
        yield 'a non-atomic lookahead' => ['pattern' => '/(?*a)b?A/i', 'witness' => 'a'];
        yield 'a non-atomic lookahead spelled as a verb' => ['pattern' => '/(*napla:a)b?A/i', 'witness' => 'a'];
    }

    /**
     * Where PCRE2 looks for no character a lookahead asserts, or for one the
     * pattern requires nowhere else, every release finds what the pattern
     * says, and a target before 10.45 reads the pattern as before: the
     * automaton accepts the subjects the engine finds a match in.
     */
    #[Test]
    #[DataProvider('provideLookaheadsLookedForAlone')]
    public function test_a_lookahead_pcre2_looks_for_alone_is_read_before_pcre2_10_45(string $pattern): void
    {
        $dfa = (new LanguageSolver(self::parser('10.44')))->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));

        foreach (self::subjects(['a', 'b', 'x'], 3) as $subject) {
            $found = preg_match($pattern, $subject);
            $this->assertSame($found, preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), $pattern.' on '.json_encode($subject).', without the JIT.');
            $this->assertSame($found, preg_match('/(*NO_START_OPT)'.substr($pattern, 1), $subject), $pattern.' on '.json_encode($subject).', every start tried.');
            $this->assertSame(1 === $found, self::accepts($dfa, $subject), $pattern.' on '.json_encode($subject));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLookaheadsLookedForAlone(): iterable
    {
        yield 'a character the pattern requires nowhere else' => ['pattern' => '/(?=a)b?\w/'];
        yield 'a lookahead on a class' => ['pattern' => '/(?=[abx])b?a/'];
        yield 'a character past ASCII under u' => ['pattern' => '/(?=é)b?é/u'];
        yield 'with no start optimisation' => ['pattern' => '/(*NO_START_OPT)(?=a)b?a/'];
    }

    /**
     * The engine finds no match the language of such a pattern does not
     * hold, only fewer: an empty intersection, and a subset of which the
     * pattern is the left side, are answers for the engine too. A common
     * string, a string the left side alone matches, a right side that holds
     * the left one, or an equivalence, may be one of the matches it misses,
     * as "a" here: refused.
     */
    #[Test]
    public function test_an_answer_the_missed_matches_cannot_change_is_given_before_pcre2_10_45(): void
    {
        $found = PcreTarget::runtime()->pcreAtLeast('10.45') ? 1 : 0;
        $this->assertSame($found, preg_match('/(?=a)b?a/', 'a'));
        $this->assertSame($found, preg_match('/(*NO_JIT)(?=a)b?a/', 'a'));
        $this->assertSame(1, preg_match('/(*NO_START_OPT)(?=a)b?a/', 'a'));
        foreach (self::subjects(['a', 'b', 'x'], 3) as $subject) {
            $this->assertFalse(1 === preg_match('/(?=a)b?a/', $subject) && 1 === preg_match('/^b+$/', $subject), json_encode($subject).' matches both.');
            $this->assertFalse(1 === preg_match('/(?=a)b?a/', $subject) && 0 === preg_match('/a/', $subject), json_encode($subject).' matches the left side alone.');
        }

        $solver = new LanguageSolver(self::parser('10.44'));
        $this->assertTrue($solver->intersection('/(?=a)b?a/', '/^b+$/')->isEmpty);
        $this->assertTrue($solver->intersection('/^b+$/', '/(?=a)b?a/')->isEmpty);
        $this->assertTrue($solver->subsetOf('/(?=a)b?a/', '/a/')->isSubset);

        $eager = new SolverOptions(strategy: SolverStrategy::Eager);
        $this->assertTrue($solver->intersection('/(?=a)b?a/', '/^b+$/', $eager)->isEmpty);
        $this->assertTrue($solver->subsetOf('/(?=a)b?a/', '/a/', $eager)->isSubset);
        $this->assertSame('b', $solver->subsetOf('/b/', '/a/', $eager)->counterExample);

        $questions = [
            'a common string' => static fn (): bool => $solver->intersection('/(?=a)b?a/', '/a/')->isEmpty,
            'a common string, the pattern on the right' => static fn (): bool => $solver->intersection('/a/', '/(?=a)b?a/')->isEmpty,
            'a string the left side alone matches' => static fn (): bool => $solver->subsetOf('/(?=a)b?a/', '/b/')->isSubset,
            'a right side that holds the left one' => static fn (): bool => $solver->subsetOf('/a/', '/(?=a)b?a/')->isSubset,
            'an equivalence' => static fn (): bool => $solver->equivalent('/(?=a)b?a/', '/(?=a)b?[a]/')->isEquivalent,
            'the language' => static fn (): bool => $solver->language('/(?=a)b?a/')->isFinite(),
            'a prefix' => static fn (): bool => $solver->acceptsPrefix('/(?=a)b?a/', 'a'),
            'the automaton' => static fn (): bool => [] !== $solver->nfa('/(?=a)b?a/')->states,
        ];
        foreach ($questions as $question => $ask) {
            try {
                $ask();
                $this->fail($question.' is answered for PCRE2 10.44.');
            } catch (ComplexityException $refusal) {
                $this->assertSame(self::MESSAGE, $refusal->getMessage(), $question);
            }
        }

        $this->assertFalse((new LanguageSolver(self::parser('10.45')))->intersection('/(?=a)b?a/', '/a/')->isEmpty);
    }

    /**
     * The match solver reads the lookahead where it builds one automaton
     * for both patterns; for a target before 10.45 it refuses the pattern
     * first, and for any target one whose required character is the
     * asserted one in the other case.
     */
    #[Test]
    public function test_the_match_solver_refuses_what_the_engine_misreads(): void
    {
        $this->assertSame(PcreTarget::runtime()->pcreAtLeast('10.45') ? 1 : 0, preg_match('/(?=a)b?a/', 'a'));
        $this->assertSame(0, preg_match('/(?=a)b?A/i', 'a'));
        $this->assertSame(1, preg_match('/(*NO_START_OPT)(?=a)b?A/i', 'a'));

        $this->assertTrue((new LanguageSolver(self::parser('10.45')))->matchEquivalent('/(?=a)b?a/', '/(?=a)b?[a]/')->isEquivalent);

        foreach ([['10.44', '/(?=a)b?a/', '/(?=a)b?[a]/'], ['10.49', '/(?=a)b?A/i', '/(?=a)b?[A]/i']] as [$release, $left, $right]) {
            try {
                (new LanguageSolver(self::parser($release)))->matchEquivalent($left, $right);
                $this->fail($left.' is read for PCRE2 '.$release.'.');
            } catch (ComplexityException $refusal) {
                $this->assertSame(self::MATCH_MESSAGE, $refusal->getMessage(), $release);
            }
        }
    }

    /**
     * An empty group before the lookahead leaves PCRE2 looking for no
     * character the lookahead asserts: before 10.45 "/(?:)(?=a)b?a/" finds
     * "a" in "a", where "/(?=a)b?a/" finds nothing. The two read alike only
     * for a target from 10.45.
     */
    #[Test]
    public function test_a_pattern_the_engine_misreads_reads_like_no_other_before_pcre2_10_45(): void
    {
        $this->assertSame(1, preg_match('/(?:)(?=a)b?a/', 'a'));
        $this->assertSame(PcreTarget::runtime()->pcreAtLeast('10.45') ? 1 : 0, preg_match('/(?=a)b?a/', 'a'));

        $before = new LanguageSolver(self::parser('10.44'));
        $this->assertFalse($before->readAlike('/(?:)(?=a)b?a/', '/(?=a)b?a/'));
        $this->assertFalse($before->readAlike('/(?=a)b?a/', '/(?:)(?=a)b?a/'));
        $this->assertFalse($before->rewritesAlikeInPlace('/(?:)(?=a)b?a/', '/(?=a)b?a/'));
        $this->assertFalse($before->rewritesAlikeInPlace('/(?=a)b?a/', '/(?:)(?=a)b?a/'));
        $this->assertNotSame([], $before->runsOfEveryCall('/(?:)(?=a)b?a/', '/(?=a)b?a/'));

        $from = new LanguageSolver(self::parser('10.45'));
        $this->assertTrue($from->readAlike('/(?:)(?=a)b?a/', '/(?=a)b?a/'));
        $this->assertTrue($from->rewritesAlikeInPlace('/(?:)(?=a)b?a/', '/(?=a)b?a/'));
    }

    /**
     * The refusal points at the lookahead whose character PCRE2 looks for.
     * Each row says which releases miss the witness: the engine running
     * here is held to it, and the lookahead is found for each target that
     * runs such a release, and only there.
     */
    #[Test]
    #[DataProvider('provideShapes')]
    public function test_the_lookahead_found_is_the_one_pcre2_looks_for(string $pattern, string $witness, string $missedBy, ?int $at): void
    {
        $running = PcreTarget::runtime()->pcreAtLeast('10.45');
        $missed = 'every release' === $missedBy || ('releases before 10.45' === $missedBy && !$running);
        $this->assertSame($missed ? 0 : 1, preg_match($pattern, $witness), $pattern);
        $this->assertSame($missed ? 0 : 1, preg_match('/(*NO_JIT)'.substr($pattern, 1), $witness), $pattern.', without the JIT.');
        $this->assertSame(1, preg_match('/(*NO_START_OPT)'.substr($pattern, 1), $witness), $pattern.', every start tried.');

        $parser = RegexParser::create(['cache' => null]);
        foreach (['10.44' => 'nothing' !== $missedBy, '10.45' => 'every release' === $missedBy] as $release => $found) {
            $lookahead = AssertedFirstCharacter::lookahead($parser->parse($pattern), new PcreTarget(80400, $release));
            if (!$found) {
                $this->assertNull($lookahead, $pattern.' for PCRE2 '.$release);

                continue;
            }

            $this->assertInstanceOf(GroupNode::class, $lookahead, $pattern.' for PCRE2 '.$release);
            $this->assertSame(GroupType::LookaheadPositive, $lookahead->type);
            $this->assertSame($at, $lookahead->getStartPosition(), $pattern);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, witness: string, missedBy: string, at: int|null}>
     */
    public static function provideShapes(): iterable
    {
        yield 'a letter' => ['pattern' => '/(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'the other case' => ['pattern' => '/(?=a)b?A/i', 'witness' => 'a', 'missedBy' => 'every release', 'at' => 0];
        yield 'in a capturing group' => ['pattern' => '/((?=a))b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 1];
        yield 'in an atomic group' => ['pattern' => '/(?>(?=a))b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 3];
        yield 'in a script run' => ['pattern' => '/(*sr:(?=a))b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 5];
        yield 'in a branch reset group' => ['pattern' => '/(?|(?=a))b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 3];
        yield 'in a group counted once' => ['pattern' => '/(?:(?=a)){1}b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 3];
        yield 'a repeated lookahead' => ['pattern' => '/(?=a){2}b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a lookahead in the lookahead' => ['pattern' => '/(?=(?=a))b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a non-atomic lookahead' => ['pattern' => '/(*napla:a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a control character' => ['pattern' => '/(?=\cA)b?\cA/', 'witness' => "\x01", 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'after a mark' => ['pattern' => '/(*MARK:m)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 9];
        yield 'after a start option' => ['pattern' => '/(*UTF)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 6];
        yield 'after a callout' => ['pattern' => '/(?C1)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 5];
        yield 'after a comment' => ['pattern' => '/(?#c)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 5];
        yield 'after a DEFINE' => ['pattern' => '/(?(DEFINE)x)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 12];
        yield 'after a version condition' => ['pattern' => '/(?(VERSION>=99)x)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 17];
        yield 'after a group repeated no time' => ['pattern' => '/(?:x){0}(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 8];
        yield 'after a limit' => ['pattern' => '/(*LIMIT_MATCH=100)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 18];
        yield 'a lookahead on a dot' => ['pattern' => '/(?=.)b?a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a lookahead on a negated class' => ['pattern' => '/(?=[^b])b?a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a lookahead on a class of three' => ['pattern' => '/(?=[a-c])b?a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character outside a lookahead' => ['pattern' => '/(?:a)(?=a)/', 'witness' => 'aa', 'missedBy' => 'nothing', 'at' => null];
        yield 'another letter in the other case' => ['pattern' => '/(?=a)b?B?a/i', 'witness' => 'ba', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a character past ASCII caseful under u' => ['pattern' => '/(?=é)b?é/u', 'witness' => 'é', 'missedBy' => 'nothing', 'at' => null];
        yield 'no start optimisation' => ['pattern' => '/(*NO_START_OPT)(?=a)b?a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'the character once' => ['pattern' => '/(?=a)b?\w/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'symbols 0x20 apart' => ['pattern' => '/(?=@)b?`?@/', 'witness' => '@', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a class of two letters required' => ['pattern' => '/(?=a)[ab]/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a class of two letters in the lookahead' => ['pattern' => '/(?=[a-b])b?a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after a caret' => ['pattern' => '/^(?=(?>a+)-)a+-$/', 'witness' => 'aa-', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after a group of lookaheads' => ['pattern' => '/(?:(?=a{99})){100}a{99}/', 'witness' => str_repeat('a', 99), 'missedBy' => 'nothing', 'at' => null];
        yield 'a class of two letters repeated with a lookahead' => ['pattern' => '/^(?:(?=.*b)[ab]){1,40}$/u', 'witness' => 'abab', 'missedBy' => 'nothing', 'at' => null];
        yield 'alternatives that require other characters' => ['pattern' => '/^(?:(?=.*a)x|(?=.*b)y){1,12}[ab]{2}$/su', 'witness' => 'yxab', 'missedBy' => 'nothing', 'at' => null];
        yield 'the character in one alternative only' => ['pattern' => '/(?=a)b?(?:a|b)/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'an alternative without a lookahead' => ['pattern' => '/(?:(?=a)b?a|x)/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'the character optional' => ['pattern' => '/(?=a)b?a?c/', 'witness' => 'ac', 'missedBy' => 'nothing', 'at' => null];
        yield 'the character first after a caret' => ['pattern' => '/^(?=a)a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after mixed alternatives' => ['pattern' => '/(?=A|[Aa])a/i', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'mixed alternatives' => ['pattern' => '/(?=A|[Aa])/', 'witness' => 'a', 'missedBy' => 'every release', 'at' => 0];
        yield 'the character in every alternative' => ['pattern' => '/(?=a)b?(?:a|ab)/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'the character in every alternative, later in one' => ['pattern' => '/(?=a)b?(?:ba|a)/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'the character in a repeated group' => ['pattern' => '/(?=a)(?:b?a){1,2}/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'the character in both branches of a conditional' => ['pattern' => '/(?=a)(?(?=x)xa|a)/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a caret under an option setting' => ['pattern' => '/(?m)(?=b)^b/', 'witness' => "a\nb", 'missedBy' => 'releases before 10.45', 'at' => 4];
        yield 'the character a lookahead requires in another alternative' => ['pattern' => '/(?=a)b?(?:(?=xa)|a)/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'the character a lookahead requires in another alternative, after one' => ['pattern' => '/(?=a)b?(?:(?=xa)x|a)/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'the character a lookahead requires in another alternative, by an escape' => ['pattern' => '/(?=a)(?:(?=\na)|\x61)(?<!b)/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a character of its own read first' => ['pattern' => '/(?=A)a/i', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after \K' => ['pattern' => '/(?=a)\Ka/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after what sets none' => ['pattern' => '/(?=a)(?#c)(?!x)\b(*MARK:m)(?C1)(?i)a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after lookbehinds' => ['pattern' => '/(?=a)(?<!x)(?<=)a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after a limit' => ['pattern' => '/(*LIMIT_MATCH=9)(?=a)a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first after a commitment' => ['pattern' => '/(?=a)(*COMMIT)a/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character repeated read first' => ['pattern' => '/(?=a)a{1,2}/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'an escape read first' => ['pattern' => '/(?=a)\x61/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'the last ASCII character read first' => ['pattern' => '/(?=\x7F)\x7F/', 'witness' => "\x7F", 'missedBy' => 'nothing', 'at' => null];
        yield 'a character that may be skipped first' => ['pattern' => '/(?=a)a?a/', 'witness' => 'a', 'missedBy' => 'releases before 10.45', 'at' => 0];
        yield 'a character read first in a group' => ['pattern' => '/(?i:(?=A)a(?s:.)*)/', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first in a capturing group' => ['pattern' => '/((?=A)a)/i', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first in an atomic group' => ['pattern' => '/(?>(?=A)a)/i', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a character read first in a repeated group' => ['pattern' => '/(?:(?=A)a)+/i', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'a byte past ASCII read first' => ['pattern' => '/(?=\xE9)\xE9/i', 'witness' => "\xE9", 'missedBy' => 'nothing', 'at' => null];
        yield 'k read first, bytes' => ['pattern' => '/(?=K)k/i', 'witness' => 'k', 'missedBy' => 'nothing', 'at' => null];
        yield 'the character twice in the lookahead' => ['pattern' => '/(?=aa)/', 'witness' => 'aa', 'missedBy' => 'nothing', 'at' => null];
        yield 'the other case in a later lookahead' => ['pattern' => '/(?=a)b?(?=A)/i', 'witness' => 'a', 'missedBy' => 'nothing', 'at' => null];
        yield 'characters the lookahead requires' => ['pattern' => '/(?={{!)(.*)(}})/sU', 'witness' => '{{!x}}', 'missedBy' => 'nothing', 'at' => null];
    }

    /**
     * The character required is not read, a class of two characters may be
     * one PCRE2 compiles to a character, and a lookahead counts wherever a
     * match may start, past what may match nothing, whatever the other
     * alternatives start with: a lookahead is found on more than PCRE2
     * looks for amiss. Without UTF a byte past ASCII may be
     * the other case of another, or of "i" and "I" under Turkish tables,
     * which the C tables these rows run under do not pair: the engine finds
     * what it finds once every start is tried, and the lookahead is found
     * all the same.
     */
    #[Test]
    #[DataProvider('provideLookaheadsFoundWider')]
    public function test_a_lookahead_is_found_wider_than_pcre2_looks_for_it_amiss(string $pattern, string $witness, string $release): void
    {
        // Before 10.45 the engine misses this one: it is refused there for
        // what it is, and read wider from 10.45.
        $this->requiresPcre2For($pattern, ['10.45' => ['/(?=a)b?[aA]/']]);

        $found = preg_match('/(*NO_START_OPT)'.substr($pattern, 1), $witness);
        $this->assertSame($found, preg_match($pattern, $witness), $pattern);
        $this->assertSame($found, preg_match('/(*NO_JIT)'.substr($pattern, 1), $witness), $pattern.', without the JIT.');

        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $this->assertInstanceOf(GroupNode::class, AssertedFirstCharacter::lookahead($regex, new PcreTarget(80400, $release)), $pattern.' for PCRE2 '.$release);
    }

    /**
     * @return iterable<string, array{pattern: string, witness: string, release: string}>
     */
    public static function provideLookaheadsFoundWider(): iterable
    {
        yield 'a class of a character and its other case' => ['pattern' => '/(?=a)b?[aA]/', 'witness' => 'a', 'release' => '10.45'];
        yield 'a class of both cases, then the letter' => ['pattern' => '/(?=[Aa]|A)/', 'witness' => 'a', 'release' => '10.45'];
        yield 'the letter, then itself caseless in a group' => ['pattern' => '/(?=A|(?i:A))/', 'witness' => 'a', 'release' => '10.45'];
        yield 'two bytes past ASCII' => ['pattern' => '/(?=\xE9)b?\xC9/', 'witness' => "\xE9", 'release' => '10.45'];
        yield 'a byte past ASCII and i' => ['pattern' => '/(?=\xDD)b?i/', 'witness' => "\xDDi", 'release' => '10.45'];
        yield 'I and a byte past ASCII' => ['pattern' => '/(?=I)b?\xFD/', 'witness' => "I\xFD", 'release' => '10.45'];
        yield 'a byte past ASCII read first under (*UCP)' => ['pattern' => '/(*UCP)(?=\xC9)\xE9/i', 'witness' => "\xE9", 'release' => '10.45'];
        yield 'a caret before the lookahead' => ['pattern' => '/^(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield '\A before the lookahead' => ['pattern' => '/\A(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'an empty group before the lookahead' => ['pattern' => '/(?:)(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'alternatives of the lookahead that disagree' => ['pattern' => '/(?=a|b)x?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'a lookahead that may match nothing first' => ['pattern' => '/(?=b?a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'after a commitment' => ['pattern' => '/(*COMMIT)(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'after \K' => ['pattern' => '/\K(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'after \G' => ['pattern' => '/\G(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'after a conditional' => ['pattern' => '/(?(?=x)x)(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'after an optional lookahead' => ['pattern' => '/(?=x)?(?=a)b?a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'k read first under u' => ['pattern' => '/(?=k)k/iu', 'witness' => 'k', 'release' => '10.44'];
        yield 's read first under (*UCP)' => ['pattern' => '/(*UCP)(?=s)s/', 'witness' => 's', 'release' => '10.44'];
        yield 'i read first under u' => ['pattern' => '/(?=i)i/u', 'witness' => 'i', 'release' => '10.44'];
        yield 'alternatives that read a character first' => ['pattern' => '/(?=a)a|(?=a)a/', 'witness' => 'a', 'release' => '10.44'];
        yield 'a character after an accepting verb' => ['pattern' => '/(?=a)(*ACCEPT:x)a/', 'witness' => 'a', 'release' => '10.44'];
    }

    private static function parser(string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $release]);
    }

    private static function accepts(Dfa $dfa, string $subject): bool
    {
        $state = $dfa->startState;
        foreach ('' === $subject ? [] : str_split($subject) as $byte) {
            $next = $dfa->getState($state)->transitionFor(\ord($byte));
            if (null === $next) {
                return false;
            }
            $state = $next;
        }

        return $dfa->getState($state)->isAccepting;
    }

    /**
     * @param list<string> $letters
     *
     * @return list<string>
     */
    private static function subjects(array $letters, int $length): array
    {
        $subjects = [''];
        $last = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($last as $prefix) {
                foreach ($letters as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $subjects = [...$subjects, ...$next];
            $last = $next;
        }

        return $subjects;
    }
}
