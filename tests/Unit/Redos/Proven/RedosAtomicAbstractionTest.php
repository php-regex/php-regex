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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Atomic groups and possessive quantifiers.
 *
 * A body that is a single run over one character set is modelled exactly:
 * it reads the longest run, one deterministic step. Any other body is kept
 * as written, listed in the abstractions, and a verdict whose pump crosses it
 * is not a proof: PCRE commits to the body's first way and never tries the
 * others.
 *
 * Engine figures: PHP 8.4.26 / PCRE2 10.49, pcre.backtrack_limit 1000000.
 */
final class RedosAtomicAbstractionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideCrossedAtomicBodies')]
    public function test_pump_through_an_over_approximated_atomic_body_is_heuristic(string $pattern, int $offset): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertNull($analysis->error, $pattern);
        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
        $this->assertSame(self::heuristicSeverity($pattern), $analysis->severity, $pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern);
        $this->assertAtomicAbstractionAt($offset, $analysis, $pattern);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideCrossedAtomicBodies(): iterable
    {
        // a…a! never fails up to 60 "a": the atomic group commits to the first way each time.
        yield 'ambiguous loop inside an atomic group' => ['/(?>(a|aa)+)$/', 0];
        // x a…a! never fails up to 60 "a".
        yield 'ambiguous loop inside an atomic group after a literal' => ['/x(?>(a|aa)+)$/', 1];
        // a…a! never fails up to 60 "a": each atomic step reads one "a", one way.
        yield 'atomic alternation repeated' => ['/(?>a|aa)+$/', 0];
    }

    /**
     * X++ is (?>X+) in PCRE: the possessive body gets the atomic entry
     * ("atomic group at offset N over-approximated"). a…a! and x a…a!
     * never fail up to 64 bytes on the engine.
     */
    #[Test]
    #[DataProvider('provideCrossedPossessiveBodies')]
    public function test_pump_through_an_over_approximated_possessive_body_is_heuristic(string $pattern, int $offset): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
        $this->assertSame(self::heuristicSeverity($pattern), $analysis->severity, $pattern);
        $this->assertNotSame([], $analysis->abstractions, $pattern);
        $this->assertAtomicAbstractionAt($offset, $analysis, $pattern);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideCrossedPossessiveBodies(): iterable
    {
        yield 'possessive alternation' => ['/(a|aa)++$/', 0];
        yield 'possessive alternation after a literal' => ['/x(a|aa)++$/', 1];
    }

    /**
     * The pump "c" never enters the atomic group: the proof holds and the
     * abstraction is still listed. ab c…c! fails at n=19 on the engine.
     */
    #[Test]
    public function test_over_approximated_atomic_body_outside_the_pump_keeps_the_proof(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(?>ab|a)(c+)+$/');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertAtomicAbstractionAt(0, $analysis, '/(?>ab|a)(c+)+$/');
    }

    #[Test]
    #[DataProvider('provideSingleRunBodies')]
    public function test_single_run_atomic_body_keeps_an_exact_proven_verdict(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertSame(!$analysis->hasQuadraticSearch(), $analysis->isProvenSafe(), $pattern);
        $this->assertSame([], $analysis->abstractions, $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSingleRunBodies(): iterable
    {
        // a…a! never fails up to 64 bytes for any of them.
        yield 'atomic run' => ['/(?>a+)+$/'];
        yield 'possessive run' => ['/(a++)+$/'];
        yield 'atomic run over a class' => ['/(?>[ab]+)+$/'];
        // An alternation of one-character branches reads one character of
        // their union, given back to no other branch.
        yield 'ambiguous loop of one-character branches inside an atomic group' => ['/(?>(a|a)+)$/'];
        yield 'same after a literal' => ['/x(?>(a|a)+)$/'];
        yield 'atomic alternation of one-character branches repeated' => ['/(?>a|a)+$/'];
        yield 'possessive alternation of one-character branches' => ['/(a|a)++$/'];
        yield 'same after a literal, possessive' => ['/x(a|a)++$/'];
        // Greedy repeats of one run, each taken at most once at least: the
        // first way out of the body reads the longest run, and the body
        // fails only before a character outside the set, at once.
        yield 'possessive repeat of a run' => ['/^(?:a+)++$/'];
        yield 'atomic repeat of a captured run' => ['/(?>(a+)+)$/'];
        yield 'possessive repeat of a repeated optional run' => ['/((a*)+)++$/'];
        yield 'atomic repeat of a run over a class, repeated' => ['/(?>(?:[ab]+)+)+$/'];
    }

    /**
     * A body that must split a run into many copies is not one run: PCRE
     * tries every way to split it before it fails, though the group is
     * atomic. a…a! fails at n=29 for (?:a+){30,}+ on the engine.
     */
    #[Test]
    public function test_a_run_split_into_many_copies_stays_over_approximated(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/^(?:a+){30,}+$/');

        $this->assertFalse($analysis->isProvenSafe());
        $this->assertAtomicAbstractionAt(1, $analysis, '/^(?:a+){30,}+$/');
    }

    /**
     * A body whose branches each hold one run of one character set, or one
     * character, with "\K" and comments around it, and start with
     * characters no other branch starts with, gives nothing back and tries
     * no other branch once one has read its first character: it is the
     * alternation of its runs, each in an atomic group of its own, which the
     * model reads exactly. "(?>a+\K)" is "(?>a+)\K", "(?>a+|c)" is
     * "(?:(?>a+)|c)". A branch that may read nothing stands alone. The
     * engine agrees with that spelling on every subject over the row's
     * characters up to five long, and so does the verdict.
     */
    #[Test]
    #[DataProvider('provideBodiesOfRunsWithTheirOwnFirstCharacters')]
    public function test_atomic_body_of_runs_with_their_own_first_characters_is_read_exactly(string $pattern, string $spelled, string $characters): void
    {
        $subjects = [''];
        for ($length = 1, $last = ['']; $length <= 5; $length++) {
            $next = [];
            foreach ($last as $subject) {
                foreach (preg_split('//u', $characters, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    $next[] = $subject.$character;
                }
            }
            $subjects = [...$subjects, ...$next];
            $last = $next;
        }

        foreach ($subjects as $subject) {
            $this->assertSame(
                [preg_match(PcreInterpreter::pattern($spelled), $subject, $expected, \PREG_OFFSET_CAPTURE), $expected],
                [preg_match(PcreInterpreter::pattern($pattern), $subject, $actual, \PREG_OFFSET_CAPTURE), $actual],
                \sprintf('Oracle: %s and %s disagree on %s.', $pattern, $spelled, json_encode($subject)),
            );
        }

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $this->assertSame([RedosProof::Proven, []], [$analysis->proof, $analysis->abstractions], $pattern);

        $reference = $analyzer->analyze($spelled);
        $this->assertSame([RedosProof::Proven, []], [$reference->proof, $reference->abstractions], $spelled);
        $this->assertSame(
            [$reference->complexity, $reference->witness?->toArray(), $reference->searchCost?->toArray(), $reference->everyMatchSearchCost?->toArray()],
            [$analysis->complexity, $analysis->witness?->toArray(), $analysis->searchCost?->toArray(), $analysis->everyMatchSearchCost?->toArray()],
            $pattern.' and '.$spelled,
        );
    }

    /**
     * @return iterable<string, array{pattern: string, spelled: string, characters: string}>
     */
    public static function provideBodiesOfRunsWithTheirOwnFirstCharacters(): iterable
    {
        yield '\K after the run' => ['pattern' => '/(?>a+\K)b/', 'spelled' => '/(?>a+)\Kb/', 'characters' => 'ab!'];
        yield '\K after the run, under (*NOTEMPTY)' => ['pattern' => '/(*NOTEMPTY)(?>a+\K)/', 'spelled' => '/(*NOTEMPTY)(?>a+)\K/', 'characters' => 'ab!'];
        yield '\K after an atomic run' => ['pattern' => '/(?>(?>a+)\K)b/', 'spelled' => '/(?>a+)\Kb/', 'characters' => 'ab!'];
        yield '\K inside nested atomic groups' => ['pattern' => '/(?>(?>a+\K))b/', 'spelled' => '/(?>a+)\Kb/', 'characters' => 'ab!'];
        yield '\K before the run' => ['pattern' => '/(?>\Ka+|c)b/', 'spelled' => '/(?:\K(?>a+)|c)b/', 'characters' => 'abc'];
        yield '\K in a group with the run' => ['pattern' => '/(?>(a+\K)|c)b/', 'spelled' => '/(?:(?>(a+))\K|c)b/', 'characters' => 'abc'];
        yield 'run or a character' => ['pattern' => '/(?>a+|c)b/', 'spelled' => '/(?:(?>a+)|c)b/', 'characters' => 'abc'];
        yield 'run then \K, or a character, under (*NOTEMPTY)' => ['pattern' => '/(*NOTEMPTY)(?>a+\K|b)/', 'spelled' => '/(*NOTEMPTY)(?:(?>a+)\K|b)/', 'characters' => 'ab!'];
        yield 'captured runs of a character and of a class' => ['pattern' => '/(?>(a+)|[cd]+)b/', 'spelled' => '/(?:(?>(a+))|(?>[cd]+))b/', 'characters' => 'abcd'];
        yield 'counted run or a character' => ['pattern' => '/(?>a{2,}|c)b/', 'spelled' => '/(?:(?>a{2,})|c)b/', 'characters' => 'abc'];
        yield 'run of one-character branches or a character' => ['pattern' => '/(?>(?:a|c)+|b)d/', 'spelled' => '/(?:(?>(?:a|c)+)|b)d/', 'characters' => 'abcd'];
        yield 'repeated run or a character' => ['pattern' => '/(?>(?:a+)+|c)b/', 'spelled' => '/(?:(?>(?:a+)+)|c)b/', 'characters' => 'abc'];
        yield 'run or a character under i' => ['pattern' => '/(?>a+|B)c/i', 'spelled' => '/(?:(?>a+)|B)c/i', 'characters' => 'aAbBc'];
        yield 'run or a character under u' => ['pattern' => '/(?>é+|c)b/u', 'spelled' => '/(?:(?>é+)|c)b/u', 'characters' => 'ébc'];
        yield 'run that may read nothing, alone' => ['pattern' => '/(?>a*\K)b/', 'spelled' => '/(?>a*)\Kb/', 'characters' => 'ab!'];
        yield 'atomic alternation of characters or a character' => ['pattern' => '/(?>(?>a|c)|b)d/', 'spelled' => '/(?:(?>a|c)|b)d/', 'characters' => 'abcd'];
        yield 'lazy atomic run or a character' => ['pattern' => '/(?>(?>a+?)|c)b/', 'spelled' => '/(?:(?>a+?)|c)b/', 'characters' => 'abc'];
        yield 'lazy atomic run that may read nothing, alone' => ['pattern' => '/(?>(?>a*?)\K)b/', 'spelled' => '/(?>a*?)\Kb/', 'characters' => 'ab!'];
    }

    /**
     * Where two branches may start with the same character, or one that
     * may read nothing comes before another, the group commits to the
     * first branch that matches and never tries the others: a branch the
     * alternation of their atomic groups would try matches on the row's
     * subject. Such a body stays kept with every way through it, and
     * listed.
     */
    #[Test]
    #[DataProvider('provideBodiesWhoseBranchesMayStartAlike')]
    public function test_atomic_body_whose_branches_may_start_alike_stays_over_approximated(string $pattern, string $spelled, string $subject): void
    {
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern).'A', $subject), 'Oracle: '.$pattern.' matches '.$subject.' at its start.');
        $this->assertSame(1, preg_match(PcreInterpreter::pattern($spelled).'A', $subject), 'Oracle: '.$spelled.' does not match '.$subject.' at its start.');

        $this->assertAtomicAbstractionAt(0, (new RedosAnalyzer())->analyze($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, spelled: string, subject: string}>
     */
    public static function provideBodiesWhoseBranchesMayStartAlike(): iterable
    {
        yield 'branches starting with the same character' => ['pattern' => '/(?>a+|ab)c/', 'spelled' => '/(?:(?>a+)|ab)c/', 'subject' => 'abc'];
        // A first branch that is one repeat allowed no round is the whole
        // body (RedosAtomicFirstBranchTest); one of two parts is not read so.
        yield 'branch that may read nothing first' => ['pattern' => '/(?>a*x?|b)c/', 'spelled' => '/(?:(?>a*x?)|b)c/', 'subject' => 'bc'];
        yield 'branches of overlapping classes' => ['pattern' => '/(?>[ab]+|bc)d/', 'spelled' => '/(?:(?>[ab]+)|bc)d/', 'subject' => 'bcd'];
    }

    /**
     * Engine facts behind the rows above, on the CI matrix.
     */
    #[Test]
    #[DataProvider('provideEngineFacts')]
    public function test_engine_fact(string $pattern, string $prefix, string $pump, string $suffix, bool $fails): void
    {
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $failing = false;
            for ($n = 1; !$failing && \strlen($prefix.str_repeat($pump, $n).$suffix) <= 64; $n++) {
                $failing = false === @preg_match($pattern, $prefix.str_repeat($pump, $n).$suffix);
            }
        } finally {
            if (false !== $limit) {
                ini_set('pcre.backtrack_limit', $limit);
            }
        }

        $this->assertSame($fails, $failing, $pattern);
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function provideEngineFacts(): iterable
    {
        yield 'ambiguous loop inside an atomic group' => ['/(?>(a|a)+)$/', '', 'a', '!', false];
        yield 'ambiguous loop inside an atomic group after a literal' => ['/x(?>(a|a)+)$/', 'x', 'a', '!', false];
        yield 'atomic alternation repeated' => ['/(?>a|a)+$/', '', 'a', '!', false];
        yield 'possessive alternation' => ['/(a|a)++$/', '', 'a', '!', false];
        yield 'possessive alternation after a literal' => ['/x(a|a)++$/', 'x', 'a', '!', false];
        yield 'atomic group before the loop, ab c…c! fails at n=19' => ['/(?>ab|a)(c+)+$/', 'ab', 'c', '!', true];
        yield 'atomic run' => ['/(?>a+)+$/', '', 'a', '!', false];
        yield 'possessive run' => ['/(a++)+$/', '', 'a', '!', false];
        yield 'atomic run over a class' => ['/(?>[ab]+)+$/', '', 'a', '!', false];
        yield 'possessive repeat of a run' => ['/^(?:a+)++$/', '', 'a', '!', false];
        yield 'atomic repeat of a captured run' => ['/(?>(a+)+)$/', '', 'a', '!', false];
        yield 'possessive repeat of a repeated optional run' => ['/((a*)+)++$/', '', 'a', '!', false];
        yield 'atomic repeat of a run over a class, repeated' => ['/(?>(?:[ab]+)+)+$/', '', 'a', '!', false];
        yield 'a run split into thirty copies, a…a! fails at n=29' => ['/^(?:a+){30,}+$/', '', 'a', '!', true];
    }

    /**
     * Exactly one entry names the atomic group with its offset:
     * "atomic group at offset N over-approximated".
     */
    private function assertAtomicAbstractionAt(int $offset, RedosAnalysis $analysis, string $pattern): void
    {
        $atomic = array_values(array_filter(
            $analysis->abstractions,
            static fn (string $entry): bool => str_contains($entry, 'atomic'),
        ));

        $this->assertCount(1, $atomic, $pattern.': '.json_encode($analysis->abstractions));
        $this->assertSame('atomic group at offset '.$offset.' over-approximated', $atomic[0], $pattern);
    }

    /**
     * What the structural heuristics alone say about a pattern.
     */
    private static function heuristicSeverity(string $pattern): RedosSeverity
    {
        $ast = RegexParser::create()->parse($pattern);
        $profiler = new RedosProfiler(new CharSetAnalyzer($ast->flags));
        $ast->accept($profiler);

        return $profiler->getResult()['severity'];
    }
}
