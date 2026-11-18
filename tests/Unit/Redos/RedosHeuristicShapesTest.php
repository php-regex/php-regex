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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repeated group whose body the proof does not model, a reference to a
 * group inside it, a condition on a group, a recursion, is left to the
 * heuristic: never called safe, and never called proven.
 */
final class RedosHeuristicShapesTest extends TestCase
{
    use InstalledLocales;

    #[Test]
    #[DataProvider('provideUnmodelledBodies')]
    public function test_a_repeated_body_the_proof_does_not_model_is_never_called_safe(string $pattern): void
    {
        $this->assertTrue(self::compiles($pattern), 'Oracle: PCRE compiles the pattern.');

        $analysis = Regex::create(['cache' => null])->redos($pattern);

        $this->assertFalse($analysis->isProvenSafe());
        $this->assertNotSame(RedosSeverity::Safe, $analysis->severity);
        $this->assertSame('Potential backtracking (heuristic)', $analysis->headline());
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideUnmodelledBodies(): iterable
    {
        yield 'a reference to a group of the body' => ['pattern' => '/((a?)\2+)+/'];
        // The engine reads it in a constant number of steps: the heuristic
        // over-reports, and calls it no more than potential.
        yield 'a condition on the group itself' => ['pattern' => '/((?(1)|b))+/'];
        // With the start optimizations off, the engine stops the match on a
        // recursion that could loop, PREG_INTERNAL_ERROR, before any
        // backtracking.
        yield 'an optional recursion before an optional letter' => ['pattern' => '/((?R)?a?)+/'];
        yield 'an optional recursion before a possessive run' => ['pattern' => '/((?R)?a*+)+/'];
        yield 'an optional recursion before an optional atomic letter' => ['pattern' => '/((?R)?(?>a)?)+/'];
        yield 'an optional recursion before an atomic optional letter' => ['pattern' => '/((?R)?(?>a?))+/'];
    }

    /**
     * The reference shape is critical, and the engine agrees: with its
     * start optimizations off, each two more letters cost it more than
     * twice the steps before the "!" fails the match. Oracle (PCRE2 10.49,
     * JIT off): 60 steps at 6 letters, 411 at 10, 7,375 at 16.
     */
    #[Test]
    public function test_the_reference_shape_is_critical_as_the_engine_counts_it(): void
    {
        $pattern = '/(*NO_JIT)(*NO_START_OPT)((a?)\2+)+!/';
        $steps = [];
        foreach ([8, 10, 12] as $letters) {
            $steps[] = self::steps($pattern, str_repeat('a', $letters));
        }

        $this->assertGreaterThan(2 * $steps[0], $steps[1]);
        $this->assertGreaterThan(2 * $steps[1], $steps[2]);
        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/((a?)\2+)+/')->severity);
    }

    /**
     * A loop whose body reads its input one way, each alternation choosing
     * its branch by the first character and each count fixed, gives every
     * iteration one way through, a reference to a capture of any length
     * included: the engine gives the iterations back one at a time.
     * Doubling the unit repeated before "!" doubles the steps (1 000 ->
     * 2 000 -> 4 000 units): 3 004 -> 6 004 -> 12 004 for the PCRE2
     * manual's "(a|b\1)+", 4 005 -> 8 005 -> 16 005 for "((a|b)\2)+",
     * and 3 004 -> 6 004 -> 12 004 again with a class, a dot, a property,
     * a POSIX class, a control character, an anchor, an assertion, a
     * comment, a fixed count or a possessive repeat in a branch. A script
     * run reads its body one way when it is atomic or when its body does:
     * 5 004 -> 10 004 -> 20 004 for "(*asr:x+)", 4 004 -> 8 004 -> 16 004
     * for "(*sr:x)". Under i, set by the modifier or an option setting,
     * the branches "a" and "b" still start apart: 3 004 -> 6 004.
     *
     * @return iterable<string, array{pattern: string, unit: string}>
     */
    public static function provideReferencesInALoopReadOneWay(): iterable
    {
        yield 'reference in a branch of its own group' => ['pattern' => '/^(a|b\1)+$/', 'unit' => 'a'];
        yield 'reference after a group of disjoint branches' => ['pattern' => '/^((a|b)\2)+$/', 'unit' => 'aa'];
        yield 'class in a branch' => ['pattern' => '/^([ac]|b\1)+$/', 'unit' => 'a'];
        yield 'dot in a branch' => ['pattern' => '/^(a.|b\1)+$/', 'unit' => 'ax'];
        yield 'property in a branch' => ['pattern' => '/^(a\pL|b\1)+$/', 'unit' => 'ax'];
        yield 'POSIX class in a branch' => ['pattern' => '/^(a[[:digit:]]|b\1)+$/', 'unit' => 'a1'];
        yield 'control character in a branch' => ['pattern' => '/^(a\cA|b\1)+$/', 'unit' => "a\x01"];
        yield 'anchor in a branch' => ['pattern' => '/^(a$\n|b\1)+$/m', 'unit' => "a\n"];
        yield 'assertion in a branch' => ['pattern' => '/^(a\b-|b\1)+$/', 'unit' => 'a-'];
        yield 'comment in a branch' => ['pattern' => '/^(a(?#x)|b\1)+$/', 'unit' => 'a'];
        yield 'fixed count in a branch' => ['pattern' => '/^(a{2}|b\1)+$/', 'unit' => 'aa'];
        yield 'possessive repeat in a branch' => ['pattern' => '/^(ax++|b\1)+$/', 'unit' => 'ax'];
        yield 'atomic script run of a run in a branch' => ['pattern' => '/^(a(*asr:x+)|b\1)+$/', 'unit' => 'ax'];
        yield 'script run of one letter in a branch' => ['pattern' => '/^(a(*sr:x)|b\1)+$/', 'unit' => 'ax'];
        yield 'reference in a branch of its own group under i' => ['pattern' => '/^(a|b\1)+$/i', 'unit' => 'a'];
        yield 'reference in a branch after an option setting' => ['pattern' => '/(?i)^(a|b\1)+$/', 'unit' => 'a'];
    }

    #[Test]
    #[DataProvider('provideReferencesInALoopReadOneWay')]
    public function test_a_reference_in_a_loop_read_one_way_is_not_critical(string $pattern, string $unit): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, str_repeat($unit, 1000).'!');
        $long = self::steps($unoptimized, str_repeat($unit, 2000).'!');
        $this->assertLessThan(3 * $short, $long, 'Oracle: the engine stays linear.');

        $analysis = Regex::create(['cache' => null])->redos($pattern);

        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity);
        foreach ($analysis->findings as $finding) {
            $this->assertStringNotContainsString('backreferences', $finding->message);
        }
    }

    /**
     * A body read more than one way keeps the reference critical, and the
     * engine goes exponential on 8 -> 12 -> 16 letters then "!": a run
     * in a branch (896 -> 14 336 -> 229 376), a branch starting with the
     * reference, whose first character is not known (139 -> 799 -> 4 612),
     * two branches starting with the same letter (139 -> 799 -> 4 612), a
     * script run of a run (1 024 -> 16 384 -> 262 144), and a call, an item
     * the reading does not take apart, of an ambiguous alternation (2 044
     * -> 32 764 -> 524 284 on "ax" units). The finding on the reference
     * stays, beside any other the pattern holds.
     *
     * @return iterable<string, array{pattern: string, unit: string}>
     */
    public static function provideReferencesInALoopReadMoreThanOneWay(): iterable
    {
        yield 'run in a branch' => ['pattern' => '/^(a+|b\1)+$/', 'unit' => 'a'];
        yield 'branch starting with the reference' => ['pattern' => '/^(a|\1a)+$/', 'unit' => 'a'];
        yield 'branches starting with the same letter' => ['pattern' => '/^(a|a\1)+$/', 'unit' => 'a'];
        yield 'script run of a run in a branch' => ['pattern' => '/^(a(*sr:a*)|b\1)+$/', 'unit' => 'a'];
        yield 'call in a branch' => ['pattern' => '/^(a(?2)|b\1)+$(?(DEFINE)(x|x))/', 'unit' => 'ax'];
    }

    #[Test]
    #[DataProvider('provideReferencesInALoopReadMoreThanOneWay')]
    public function test_a_reference_in_a_loop_read_more_than_one_way_stays_critical(string $pattern, string $unit): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, str_repeat($unit, 8).'!');
        $long = self::steps($unoptimized, str_repeat($unit, 12).'!');
        $this->assertGreaterThan(4 * $short, $long, 'Oracle: the engine goes exponential.');

        $analysis = Regex::create(['cache' => null])->redos($pattern);

        $this->assertSame(RedosSeverity::Critical, $analysis->severity);
        $this->assertNotSame([], array_filter($analysis->findings, static fn ($finding): bool => RedosSeverity::Critical === $finding->severity && str_contains($finding->message, 'backreferences')));
    }

    /**
     * Under i the branches "a" and "A\1" both start with "a", and the loop
     * chooses between them at each letter: 139 -> 799 -> 4 612 steps on
     * 8 -> 12 -> 16 letters then "!". The reference stays critical, under
     * the modifier or an option setting.
     */
    #[Test]
    public function test_a_reference_in_a_loop_under_i_stays_critical(): void
    {
        $unoptimized = PcreInterpreter::pattern('/(*NO_START_OPT)^(a|A\1)+$/i');
        $short = self::steps($unoptimized, str_repeat('a', 8).'!');
        $long = self::steps($unoptimized, str_repeat('a', 12).'!');
        $this->assertGreaterThan(4 * $short, $long, 'Oracle: the engine goes exponential.');

        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/^(a|A\1)+$/i')->severity);
        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/(?i)^(a|A\1)+$/')->severity);
    }

    #[Test]
    public function test_the_engine_reads_the_condition_shape_in_constant_steps(): void
    {
        $pattern = '/(*NO_JIT)(*NO_START_OPT)((?(1)|b))+!/';

        $this->assertSame(self::steps($pattern, str_repeat('b', 6)), self::steps($pattern, str_repeat('b', 16)));
    }

    #[Test]
    public function test_the_engine_stops_the_recursion_shape_before_it_backtracks(): void
    {
        $this->assertFalse(@preg_match(PcreInterpreter::pattern('/(*NO_START_OPT)((?R)?a?)+!/'), str_repeat('a', 16)));
        $this->assertSame(\PREG_INTERNAL_ERROR, preg_last_error());
    }

    /**
     * An atomic group, a possessive loop, or a loop whose body ends with a
     * verb that cuts backtracking holds once its body has matched; until
     * then the engine backtracks inside the body as it does anywhere. A
     * nested loop the body can still fail after is critical, as it is
     * without the group, and the engine agrees: four more letters cost it
     * about sixteen times the steps before the match fails (start
     * optimizations off, PCRE2 10.49, JIT off): 641 -> 10 241 -> 163 841
     * for "(?>(a+)+b)" and "^(?>(\w+\s?)+:)", 385 -> 6 145 -> 98 305 for
     * "^(?:(?:a+)+b)++" and "^(*atomic:(?:a+)+b)", 384 -> 6 144 -> 98 304
     * with "(*COMMIT)", 4 376 -> 354 296 -> 16 777 216 when the possessive
     * loop needs thirty iterations the run never gives. An atomic script
     * run is no exception (386 -> 6 146 -> 98 306), nor one checked once
     * its body has matched (385 -> 6 145 -> 98 305).
     *
     * @return iterable<string, array{pattern: string, tail: string}>
     */
    public static function provideLoopsTheAtomicBodyMayStillFailAfter(): iterable
    {
        yield 'atomic group' => ['pattern' => '/(?>(a+)+b)/', 'tail' => '!b'];
        yield 'atomic group of word runs before a colon' => ['pattern' => '/^(?>(\w+\s?)+:)/', 'tail' => '!:'];
        yield 'possessive loop' => ['pattern' => '/^(?:(?:a+)+b)++/', 'tail' => '!b'];
        yield 'atomic verb' => ['pattern' => '/^(*atomic:(?:a+)+b)/', 'tail' => '!b'];
        yield 'loop ending with (*COMMIT)' => ['pattern' => '/^(?:(?:a+)+b(*COMMIT))+/', 'tail' => '!b'];
        yield 'possessive loop needing thirty iterations' => ['pattern' => '/^(?:(?:a+)+){30,}+b/', 'tail' => '!b'];
        yield 'atomic script run' => ['pattern' => '/^(*asr:(?:a+)+b)/', 'tail' => '!b'];
        yield 'script run' => ['pattern' => '/^(*sr:(?:a+)+b)/', 'tail' => '!b'];
    }

    #[Test]
    #[DataProvider('provideLoopsTheAtomicBodyMayStillFailAfter')]
    public function test_a_nested_loop_the_atomic_body_may_still_fail_after_is_critical(string $pattern, string $tail): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, str_repeat('a', 8).$tail);
        $long = self::steps($unoptimized, str_repeat('a', 12).$tail);
        $this->assertGreaterThan(8 * $short, $long, 'Oracle: the engine goes exponential.');

        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A verb that ends an iteration cuts backtracking only where the engine
     * honours it: "(*SKIP:NAME)" with no "(*MARK:NAME)" before it is
     * ignored, as is a mark of another name, a mark cuts nothing, and a
     * verb inside a lookaround or an atomic group acts only there. A mark
     * written before the skip is no help where the engine does not find it
     * on its way back: inside a lookahead or an atomic group, which it never
     * goes back into once they have matched, or on a branch the match did
     * not take (766 -> 12 286 -> 196 606). The nested loop stays critical,
     * and the engine agrees: 765 -> 12 285 -> 196 605 steps on 8 -> 12 ->
     * 16 letters then "!:" (start optimizations off, the JIT counting).
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVerbsThatCutNothing(): iterable
    {
        yield 'skip to a name no mark sets' => ['pattern' => '/^(?:\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a name another mark does not set' => ['pattern' => '/^(?:(*MARK:m)\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a mark set after it' => ['pattern' => '/^(?:\w+\s?(*SKIP:n))+(*MARK:n):/'];
        yield 'commit in a lookahead' => ['pattern' => '/^(?:\w+\s?(?=(*COMMIT)))+:/'];
        yield 'commit in an atomic group' => ['pattern' => '/^(?:\w+\s?(?>(*COMMIT)))+:/'];
        yield 'prune in a lookbehind' => ['pattern' => '/^(?:\w+\s?(?<=(*PRUNE)))+:/'];
        yield 'mark' => ['pattern' => '/^(?:\w+\s?(*MARK:n))+:/'];
        yield 'skip to a name a mark sets in a lookahead before it' => ['pattern' => '/^(?=(*MARK:n))(?:\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a name a mark sets in an atomic group before it' => ['pattern' => '/^(?>(*MARK:n))(?:\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a name a mark sets on a branch not taken' => ['pattern' => '/^(?:x(*MARK:n)|)(?:\w+\s?(*SKIP:n))+:/'];
    }

    #[Test]
    #[DataProvider('provideVerbsThatCutNothing')]
    public function test_a_verb_that_cuts_nothing_leaves_a_nested_loop_critical(string $pattern): void
    {
        if (true === \constant('PCRE_JIT_SUPPORT')) {
            $unoptimized = $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);
            $short = self::steps($unoptimized, str_repeat('a', 8).'!:', true);
            $long = self::steps($unoptimized, str_repeat('a', 12).'!:', true);
            $this->assertGreaterThan(8 * $short, $long, 'Oracle: the engine goes exponential.');
        }

        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A skip to a mark set before it, like a bare "(*COMMIT)", ends the
     * attempt the first time the engine backtracks onto it: 3 or 4 steps
     * whatever the letters, and the loop it ends is safe. A mark in a group
     * that only wraps it is found as one written in the sequence.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVerbsThatCut(): iterable
    {
        yield 'skip to a mark set in the iteration' => ['pattern' => '/^(?:(*MARK:n)\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a mark set as (*:NAME) before the loop' => ['pattern' => '/^(*:n)(?:\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a mark set in a group before the loop' => ['pattern' => '/^((*MARK:n))(?:\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a mark set in a group before the loop, beside a letter' => ['pattern' => '/^(?:(*MARK:n)\w?)(?:\w+\s?(*SKIP:n))+:/'];
        yield 'commit in a group' => ['pattern' => '/^(?:\w+\s?(?:(*COMMIT)))+:/'];
    }

    #[Test]
    #[DataProvider('provideVerbsThatCut')]
    public function test_a_verb_that_cuts_backtracking_keeps_a_nested_loop_safe(string $pattern): void
    {
        if (true === \constant('PCRE_JIT_SUPPORT')) {
            $unoptimized = $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);
            $short = self::steps($unoptimized, str_repeat('a', 8).'!:', true);
            $long = self::steps($unoptimized, str_repeat('a', 16).'!:', true);
            $this->assertSame($short, $long, 'Oracle: the engine ends the attempt at once.');
        }

        $this->assertSame(RedosSeverity::Safe, Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A nested loop that ends the body is matched the first way through,
     * and nothing in the body can fail after it: the engine takes as many
     * steps on 16 letters as on 8 (5, 6, 4, 6 and 6 steps), and the heuristic
     * finds nothing. So does a loop needing at most one iteration whose
     * body may fail after its alternation (21 -> 29 -> 37).
     *
     * @return iterable<string, array{pattern: string, prefix: string, tail: string}>
     */
    public static function provideLoopsEndingTheAtomicBody(): iterable
    {
        yield 'atomic group' => ['pattern' => '/^(?>(?:a+)+)b/', 'prefix' => '', 'tail' => '!b'];
        yield 'possessive loop' => ['pattern' => '/^(?:(?:a+)+)++b/', 'prefix' => '', 'tail' => '!b'];
        yield 'loop ending an iteration of a loop ending the body' => ['pattern' => '/^(?>(?:x(a+)+)*)y/', 'prefix' => 'x', 'tail' => '!y'];
        yield 'loop before (*COMMIT)' => ['pattern' => '/^(?:(?:a+)+(*COMMIT))+b/', 'prefix' => '', 'tail' => '!b'];
        yield 'atomic script run' => ['pattern' => '/^(*asr:(?:a+)+)b/', 'prefix' => '', 'tail' => '!b'];
        yield 'alternation an iteration may fail after' => ['pattern' => '/^(?>(?:(?:a|a)x?)*)y/', 'prefix' => '', 'tail' => '!y'];
    }

    #[Test]
    #[DataProvider('provideLoopsEndingTheAtomicBody')]
    public function test_a_nested_loop_ending_the_atomic_body_is_safe_for_the_heuristic(string $pattern, string $prefix, string $tail): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, $prefix.str_repeat('a', 8).$tail);
        $long = self::steps($unoptimized, $prefix.str_repeat('a', 16).$tail);
        $this->assertLessThan(2 * $short, $long, 'Oracle: the engine stays linear.');

        $tree = Regex::create(['cache' => null])->parse($pattern);
        $profiler = new RedosProfiler(CharSetAnalyzer::forRegex($tree));
        $tree->accept($profiler);

        $this->assertSame(RedosSeverity::Safe, $profiler->getResult()['severity']);
    }

    /**
     * A call in a loop runs the group it calls at each iteration, as if
     * written there: an ambiguous alternation or a run it calls is
     * critical, as it is written in the loop, and the engine agrees on the
     * unit repeated then "!" (8 -> 12 -> 16 units): 3 065 -> 49 145 ->
     * 786 425 steps for "(?1)" of "(a|a)", 1 024 -> 16 384 -> 262 144 for a
     * run of letters before an optional space, 2 554 -> 40 954 -> 655 354
     * for "(a|a)" before a comma. A separator after the called run keeps
     * the iterations apart (27 -> 39 -> 51), as one before it (3 -> 3 ->
     * 3), and a call of disjoint alternatives reads one way (35 -> 51 ->
     * 67). A comma before the call keeps apart a run that is one of the
     * alternatives called, whether the group calls itself (31 -> 43 -> 55)
     * or not (27 -> 39 -> 51). Past a branch reset, whose branches share
     * their numbers, "(?2)" runs "(a|a)" (3 068 -> 49 148 -> 786 428 after an
     * "x") and "(?1)" the "(x)" (47 -> 67 -> 87). "(?+1)" runs the group
     * after it (3 065 -> 49 145 -> 786 425), "(?10)" the tenth (4 099 ->
     * 65 539 -> 1 048 579), and a name two groups share under (?J) the
     * first of them: 67 -> 99 -> 131 steps when it is "(a|b)", 4 081 ->
     * 65 521 -> 1 048 561 when it is "(a|a)". A call in a loop inside an
     * atomic script run still backtracks until the body has matched (2 047
     * -> 32 767 -> 524 287). A branch that is a call starts as the group
     * it calls: one that shares a letter with the other branch is read
     * twice at each letter (2 045 -> 32 765 -> 524 285 for "[a-z]" beside
     * a call of "[a-z0-9]", 2 046 -> 32 766 -> 524 286 for "a" beside a
     * call of "(a)"), one that does not chooses its branch (69 -> 101 ->
     * 133 for "-" beside a call of "[a-z0-9]", on "a-" units). A branch
     * that recurses into the whole pattern is left out of the comparison
     * (42 -> 58 -> 74 for nested parentheses after a "("), and the other
     * branches are still compared: two "a" beside it are read twice at
     * each letter (2 556 -> 40 956 -> 655 356 after an "x"), an "a" and a
     * "b" are not (46 -> 66 -> 86). A call is read again wherever it runs:
     * reached first from a DEFINE block, outside any loop, then from a
     * repeated call of the group holding it (2 556 -> 40 956 -> 655 356),
     * or first from a loop where a comma keeps its runs apart, then from
     * a loop where nothing does (1 286 -> 20 486 -> 327 686 after "a,;").
     *
     * @return iterable<string, array{pattern: string, prefix: string, unit: string, critical: bool}>
     */
    public static function provideCallsInALoop(): iterable
    {
        yield 'call of an ambiguous alternation' => ['pattern' => '/^(?:(?1))+(a|a)$/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'call of a run before an optional space' => ['pattern' => '/^(?:(?&w)\s?)+$(?(DEFINE)(?<w>[a-z]+))/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'call of an ambiguous alternation before a comma' => ['pattern' => '/^(?:(?1),)+(a|a)$/', 'prefix' => '', 'unit' => 'a,', 'critical' => true];
        yield 'call of a run before a comma' => ['pattern' => '/^(?:(?&w),)+$(?(DEFINE)(?<w>[a-z]+))/', 'prefix' => '', 'unit' => 'a,', 'critical' => false];
        yield 'call of a run after a letter it cannot take' => ['pattern' => '/^(a+)(?:x(?1))+$/', 'prefix' => 'a', 'unit' => 'xa', 'critical' => false];
        yield 'call of disjoint alternatives' => ['pattern' => '/^(a|b)(?:(?1))+$/', 'prefix' => '', 'unit' => 'a', 'critical' => false];
        yield 'recursive call of a list after a comma' => ['pattern' => '/^(?<value>\[(?:(?&value)(?:,(?&value))*)?\]|\d+)$/', 'prefix' => '[', 'unit' => '1,', 'critical' => false];
        yield 'call of alternatives after a comma' => ['pattern' => '/^(?:,(?&v))*$(?(DEFINE)(?<v>\[\]|\d+))/', 'prefix' => '', 'unit' => ',1', 'critical' => false];
        yield 'call numbered past a branch reset' => ['pattern' => '/^(?|(x)|(y))(?:(?2))+(a|a)$/', 'prefix' => 'x', 'unit' => 'a', 'critical' => true];
        yield 'call of a branch of a branch reset' => ['pattern' => '/^(?|(x)|(y))(?:(?1))+(a|a)$/', 'prefix' => 'x', 'unit' => 'x', 'critical' => false];
        yield 'relative call back' => ['pattern' => '/^(a|a)(?:(?-1))+$/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'relative call forward' => ['pattern' => '/^(?:(?+1))+(a|a)$/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'call of the tenth group' => ['pattern' => '/^(x)(x)(x)(x)(x)(x)(x)(x)(x)(a|a)(?:(?10))+$/', 'prefix' => 'xxxxxxxxxa', 'unit' => 'a', 'critical' => true];
        yield 'call of a name two groups share, the first disjoint' => ['pattern' => '/(?J)^(?:(?&n))+(?<n>a|b)(?<n>a|a)$/', 'prefix' => '', 'unit' => 'a', 'critical' => false];
        yield 'call of a name two groups share, the first ambiguous' => ['pattern' => '/(?J)^(?:(?&n))+(?<n>a|a)(?<n>a|b)$/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'call in a loop of an atomic script run' => ['pattern' => '/^(*asr:(?:(?1))+c)(a|a)$/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'branch calling a group that shares a letter with the other branch' => ['pattern' => '/^(?:[a-z]|(?&c))+$(?(DEFINE)(?<c>[a-z0-9]))/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'branch calling a group before the letter it shares' => ['pattern' => '/^(?:(?&c)|[a-z])+$(?(DEFINE)(?<c>[a-z0-9]))/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'branch calling the group that opens the pattern' => ['pattern' => '/^(a)(?:a|(?1))+$/', 'prefix' => 'a', 'unit' => 'a', 'critical' => true];
        yield 'branch recursing into the whole pattern' => ['pattern' => '/^\((?:[^()]|(?R))*\)/', 'prefix' => '(', 'unit' => 'a', 'critical' => false];
        yield 'branch calling a group the other branch never starts' => ['pattern' => '/^(?:-|(?&c))+$(?(DEFINE)(?<c>[a-z0-9]))/', 'prefix' => '', 'unit' => 'a-', 'critical' => false];
        yield 'branch recursing into the whole pattern beside two that overlap' => ['pattern' => '/^x(?:a|a|(?R))+$/', 'prefix' => 'x', 'unit' => 'a', 'critical' => true];
        yield 'branch recursing into the whole pattern beside two that do not' => ['pattern' => '/^x(?:a|b|(?R))+$/', 'prefix' => 'x', 'unit' => 'a', 'critical' => false];
        yield 'call a DEFINE block reads first, then a repeated call' => ['pattern' => '/(?(DEFINE)(?<wrap>(?&atom))(?<atom>a|a))^(?&wrap)+$/', 'prefix' => '', 'unit' => 'a', 'critical' => true];
        yield 'call read first where a comma keeps its runs apart' => ['pattern' => '/^(?:(?&w),)+;(?:(?&w))+$(?(DEFINE)(?<w>(?&a))(?<a>[a-z]+))/', 'prefix' => 'a,;', 'unit' => 'a', 'critical' => true];
    }

    #[Test]
    #[DataProvider('provideCallsInALoop')]
    public function test_a_call_in_a_loop_is_read_as_the_group_it_calls(string $pattern, string $prefix, string $unit, bool $critical): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, $prefix.str_repeat($unit, 8).'!');
        $long = self::steps($unoptimized, $prefix.str_repeat($unit, 12).'!');
        $this->assertSame($critical, $long > 4 * $short, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($critical, RedosSeverity::Critical === Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A call outside any loop still runs the group it calls, there and not
     * where the group is written: nested loops kept safe by an atomic group
     * around their definition backtrack where the call runs them, and the
     * engine goes exponential on 8 -> 12 -> 16 letters then "!" (387 ->
     * 6 147 -> 98 307 steps). A call of a single loop (12 -> 16 -> 20), or
     * one that ends an atomic group itself (8 -> 8 -> 8), stays below
     * critical.
     *
     * @return iterable<string, array{pattern: string, prefix: string, critical: bool}>
     */
    public static function provideCallsOutsideALoop(): iterable
    {
        yield 'call in another branch of nested loops written in an atomic group' => ['pattern' => '/^(?:(?>x((?:a+)+))|y(?1)$)/', 'prefix' => 'y', 'critical' => true];
        yield 'call after an optional atomic group of nested loops' => ['pattern' => '/^(?>x((?:a+)+))?y(?1)$/', 'prefix' => 'y', 'critical' => true];
        yield 'call of a single loop' => ['pattern' => '/^(?:(?>x(a+))|y(?1)$)/', 'prefix' => 'y', 'critical' => false];
        yield 'call ending an atomic group' => ['pattern' => '/^(?:(?>x((?:a+)+))|y(?>(?1))$)/', 'prefix' => 'y', 'critical' => false];
    }

    #[Test]
    #[DataProvider('provideCallsOutsideALoop')]
    public function test_a_call_outside_a_loop_is_read_as_the_group_it_calls(string $pattern, string $prefix, bool $critical): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, $prefix.str_repeat('a', 8).'!');
        $long = self::steps($unoptimized, $prefix.str_repeat('a', 12).'!');
        $this->assertSame($critical, $long > 4 * $short, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($critical, RedosSeverity::Critical === Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * Where the proof does not decide, a mark here, a count of more than 16
     * repeats its body as a loop does, and a count of variable length in a
     * loop splits a run as a loop does. PCRE runs out of its default
     * backtrack limit on 24 units then "!" for an ambiguous alternation
     * repeated up to 40 times, runs of one to three digits each maybe
     * followed by a dot up to 30 times, and a call of an ambiguous
     * alternation up to 30 times. Up to 16 times the alternation never
     * reaches the limit (262 141 steps at most), and a dot after each run
     * keeps the runs apart (50 steps on 24 units).
     *
     * @return iterable<string, array{pattern: string, unit: string, critical: bool}>
     */
    public static function provideCountsReadAsLoops(): iterable
    {
        yield 'ambiguous alternation repeated up to 40 times' => ['pattern' => '/^(?:a|a){1,40}(*MARK:y)$/', 'unit' => 'a', 'critical' => true];
        yield 'runs of digits before an optional dot up to 30 times' => ['pattern' => '/^(?:\d{1,3}\.?){1,30}(*MARK:y)$/', 'unit' => '1', 'critical' => true];
        yield 'call of an ambiguous alternation up to 30 times' => ['pattern' => '/^(?:(?1)){1,30}(a|a)$/', 'unit' => 'a', 'critical' => true];
        yield 'runs of digits before an optional dot in a loop' => ['pattern' => '/^(?:\d{1,3}\.?)+(*MARK:y)$/', 'unit' => '1', 'critical' => true];
        yield 'ambiguous alternation repeated up to 16 times' => ['pattern' => '/^(?:a|a){1,16}(*MARK:y)$/', 'unit' => 'a', 'critical' => false];
        yield 'runs of digits each before a dot up to 30 times' => ['pattern' => '/^(?:\d{1,3}\.){1,30}(*MARK:y)$/', 'unit' => '1.', 'critical' => false];
    }

    #[Test]
    #[DataProvider('provideCountsReadAsLoops')]
    public function test_a_count_where_the_proof_does_not_decide_is_read_as_a_loop(string $pattern, string $unit, bool $critical): void
    {
        $saved = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $result = @preg_match(PcreInterpreter::pattern($pattern), str_repeat($unit, 24).'!');
            $error = preg_last_error();
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
        }

        $this->assertSame($critical, false === $result && \PREG_BACKTRACK_LIMIT_ERROR === $error, 'Oracle disagrees with the row.');

        $analysis = Regex::create(['cache' => null])->redos($pattern);
        $this->assertSame(RedosProof::Heuristic, $analysis->proof);
        $this->assertSame($critical, RedosSeverity::Critical === $analysis->severity);
    }

    /**
     * A branch whose first characters are not known, a backreference or a
     * Unicode property, may start as any other branch does: the verdict
     * does not depend on the order the branches are written in. The engine
     * reads each letter both ways, whichever comes first: 1 535 -> 24 575
     * -> 393 215 steps on 8 -> 12 -> 16 letters for "a" beside "\1" over
     * "(a)", 1 024 -> 16 384 -> 262 144 for "[a-z]" beside "\p{Ll}" after a
     * quote.
     *
     * @return iterable<string, array{pattern: string, prefix: string}>
     */
    public static function provideBranchesOfUnknownFirstCharacters(): iterable
    {
        yield 'a letter before a reference to it' => ['pattern' => '/^(a)(?:a|\1)+$/', 'prefix' => 'a'];
        yield 'a reference before the letter it reads' => ['pattern' => '/^(a)(?:\1|a)+$/', 'prefix' => 'a'];
        yield 'a class before a property' => ['pattern' => '/^(["\'])(?:[a-z]|\p{Ll})+\1$/u', 'prefix' => '"'];
        yield 'a property before a class' => ['pattern' => '/^(["\'])(?:\p{Ll}|[a-z])+\1$/u', 'prefix' => '"'];
    }

    #[Test]
    #[DataProvider('provideBranchesOfUnknownFirstCharacters')]
    public function test_a_branch_of_unknown_first_characters_overlaps_in_either_order(string $pattern, string $prefix): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, $prefix.str_repeat('a', 8).'!');
        $long = self::steps($unoptimized, $prefix.str_repeat('a', 12).'!');
        $this->assertGreaterThan(8 * $short, $long, 'Oracle: the engine goes exponential.');

        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A loop that ends an iteration meets the next iteration where its
     * head starts: one that never takes the head's first character ends
     * where the next iteration begins, as in Friedl's unrolled C comment,
     * and the engine stays linear on its units then a failing tail (18 ->
     * 26 -> 34 steps on 8 -> 12 -> 16 units of "*a", 27 -> 39 -> 51 on
     * "a**"). A head that may take that character leaves the loop critical:
     * 2 585 -> 121 394 -> 5 702 888 on "**". The callout keeps each pattern
     * from the proof, and the heuristic decides.
     *
     * @return iterable<string, array{pattern: string, prefix: string, unit: string, critical: bool}>
     */
    public static function provideLoopsEndingAnIteration(): iterable
    {
        yield 'unrolled C comment' => ['pattern' => '/(?C1)\/\*[^*]*\*+([^\/*][^*]*\*+)*\//', 'prefix' => '/*', 'unit' => '*a', 'critical' => false];
        yield 'run of stars before a letter' => ['pattern' => '/(?C1)^(?:a[^*]*\*+)*$/', 'prefix' => '', 'unit' => 'a**', 'critical' => false];
        yield 'run of stars before a head that takes a star' => ['pattern' => '/(?C1)^(?:[a*]\*+)*$/', 'prefix' => '', 'unit' => '**', 'critical' => true];
    }

    #[Test]
    #[DataProvider('provideLoopsEndingAnIteration')]
    public function test_a_loop_ending_an_iteration_is_read_against_the_next_head(string $pattern, string $prefix, string $unit, bool $critical): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, $prefix.str_repeat($unit, 8).'!');
        $long = self::steps($unoptimized, $prefix.str_repeat($unit, 12).'!');
        $this->assertSame($critical, $long > 4 * $short, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($critical, RedosSeverity::Critical === Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A branch written with escapes is read as the bytes the engine reads:
     * a UTF-8 sequence spelled byte by byte beside an ASCII class starts
     * apart from it, the loop gives its iterations back one at a time, and
     * the engine stays linear on 8 -> 12 -> 16 units then "!" (20 -> 28 ->
     * 36 steps, PCRE2 10.49, JIT and start optimizations off), in octal,
     * braced and under i, s or u alike. A branch that takes a character
     * the other takes stays critical, above ASCII too, and the engine
     * agrees (1 024 -> 16 384 -> 262 144 for "\h" beside "\xA0", 1 536 ->
     * 24 576 -> 393 216 for "[^a]" beside "\xC3", 2 046 -> 32 766 ->
     * 524 286 for "[^a]" beside "é" without u): "[^a]" and the dot take
     * every byte above ASCII, "\h" the no-break space, "\v" and "\R" the
     * next line, "\s" both under (*UCP) or u, and under i, set by the
     * modifier or by an option setting, "\x41" an "a".
     * Under (*ANY) the dot stops at the next line. Under u a character
     * above ASCII ends a repeat as it starts the next one: "é+é" in a loop
     * is critical as "a+a" is (69 -> 467 -> 3 195).
     *
     * @return iterable<string, array{pattern: string, unit: string, critical: bool}>
     */
    public static function provideBranchesWrittenWithEscapes(): iterable
    {
        yield 'a two-byte sequence beside an ASCII class' => ['pattern' => '/^((?:[a-z]|[\xC2-\xDF][\x80-\xBF])+)%?\1/', 'unit' => "\xC3\xA9", 'critical' => false];
        yield 'the sequence in octal' => ['pattern' => '/^((?:[a-z]|[\302-\337][\200-\277])+)%?\1/', 'unit' => "\xC3\xA9", 'critical' => false];
        yield 'the sequence in braced hex' => ['pattern' => '/^((?:[a-z]|[\x{C2}-\x{DF}][\x{80}-\x{BF}])+)%?\1/', 'unit' => "\xC3\xA9", 'critical' => false];
        yield 'the sequence in braced octal' => ['pattern' => '/^((?:[a-z]|[\o{302}-\o{337}][\o{200}-\o{277}])+)%?\1/', 'unit' => "\xC3\xA9", 'critical' => false];
        yield 'the sequence under i and s' => ['pattern' => '/^((?:[0-9a-zA-Z$_.`-]|[\xC2-\xDF][\x80-\xBF])+)%?\1/is', 'unit' => "\xC3\xA9", 'critical' => false];
        yield 'the code points under u' => ['pattern' => '/^((?:[a-z]|[\xC2-\xDF][\x80-\xBF])+)%?\1/u', 'unit' => "\u{C3}\u{A9}", 'critical' => false];
        yield 'the dot beside the next line under (*ANY)' => ['pattern' => '/(*ANY)^((?:.|\x85)+)\1$/', 'unit' => "\x85", 'critical' => false];
        yield 'a negated class beside a byte it takes' => ['pattern' => '/^((?:[^a]|\xC3)+)\1$/', 'unit' => "\xC3", 'critical' => true];
        yield 'a negated class beside a two-byte letter' => ['pattern' => '/^((?:[^a]|é)+)\1$/', 'unit' => 'é', 'critical' => true];
        yield 'a negated class beside a letter under u' => ['pattern' => '/^((?:[^a]|\xE9)+)\1$/u', 'unit' => 'é', 'critical' => true];
        yield 'the dot beside a byte above ASCII' => ['pattern' => '/^((?:.|\xE9)+)\1$/', 'unit' => "\xE9", 'critical' => true];
        yield 'a horizontal space beside the no-break space' => ['pattern' => '/^((?:\h|\xA0)+)\1$/', 'unit' => "\xA0", 'critical' => true];
        yield 'a vertical space beside the next line' => ['pattern' => '/^((?:\v|\x85)+)\1$/', 'unit' => "\x85", 'critical' => true];
        yield 'a line break beside the next line' => ['pattern' => '/^((?:\R|\x85)+)\1$/', 'unit' => "\x85", 'critical' => true];
        yield 'a space under (*UCP) beside the no-break space' => ['pattern' => '/(*UCP)^((?:\s|\xA0)+)\1$/', 'unit' => "\xA0", 'critical' => true];
        yield 'a space under u beside the no-break space' => ['pattern' => '/^((?:\s|\xA0)+)\1$/u', 'unit' => "\u{A0}", 'critical' => true];
        yield 'a letter beside its escape under i' => ['pattern' => '/^((?:a|\x41)+)\1$/i', 'unit' => 'a', 'critical' => true];
        yield 'a letter beside its escape after an option setting' => ['pattern' => '/(?i)^((?:a|\x41)+)\1$/', 'unit' => 'a', 'critical' => true];
        yield 'a letter beside its escape in a group that sets i' => ['pattern' => '/^((?i)(?:a|\x41)+)\1$/', 'unit' => 'a', 'critical' => true];
        yield 'a two-byte letter repeated before itself under u' => ['pattern' => '/^((?:é+é)+)\1$/u', 'unit' => 'é', 'critical' => true];
        yield 'its escape repeated before itself under u' => ['pattern' => '/^((?:\xE9+\xE9)+)\1$/u', 'unit' => 'é', 'critical' => true];
    }

    #[Test]
    #[DataProvider('provideBranchesWrittenWithEscapes')]
    public function test_a_branch_written_with_escapes_is_read_as_the_engine_reads_it(string $pattern, string $unit, bool $critical): void
    {
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, str_repeat($unit, 8).'!');
        $long = self::steps($unoptimized, str_repeat($unit, 12).'!');
        $this->assertSame($critical, $long > 4 * $short, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($critical, RedosSeverity::Critical === Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * Under i the branches are compared in the case the engine reads them,
     * where the modifier, an option setting or a group sets i, and in the
     * case they are written where an option setting turns it off again.
     * Each overlapping row goes exponential on 8 -> 12 units then "!"
     * (1 024 -> 16 384 steps, PCRE2 10.49, JIT and start optimizations off)
     * and each other row stays linear (20 -> 28, 22 -> 30 for the negated
     * class): "a" and "A" meet under i, "[^a]" leaves "A" out there, "k"
     * takes the Kelvin sign and "s" the long s under iu, but not under r,
     * the micro sign takes the Greek mu, and under (*UCP) "\xE9" takes
     * "\xC9", which PCRE2's own tables leave apart without it.
     *
     * @return iterable<string, array{pattern: string, unit: string, critical: bool}>
     */
    public static function provideBranchesInEitherCase(): iterable
    {
        yield 'a letter beside its capital under i' => ['pattern' => '/^((?:a|A)+)\1$/i', 'unit' => 'a', 'critical' => true];
        yield 'a letter beside its capital' => ['pattern' => '/^((?:a|A)+)\1$/', 'unit' => 'a', 'critical' => false];
        yield 'a range beside a capital it holds under i' => ['pattern' => '/^((?:[a-c]|B)+)\1$/i', 'unit' => 'b', 'critical' => true];
        yield 'a negated class beside the capital it leaves out under i' => ['pattern' => '/^((?:[^a]|A)+)\1$/i', 'unit' => 'A', 'critical' => false];
        yield 'an option setting before the pattern' => ['pattern' => '/(?i)^((?:a|A)+)\1$/', 'unit' => 'a', 'critical' => true];
        yield 'an option setting inside the group' => ['pattern' => '/^((?i)(?:a|A)+)\1$/', 'unit' => 'a', 'critical' => true];
        yield 'an option setting that turns i off again' => ['pattern' => '/(?i)^((?-i)(?:a|A)+)\1$/', 'unit' => 'a', 'critical' => false];
        yield 'a branch that sets i beside the capital' => ['pattern' => '/^((?:(?i:a)|A)+)\1$/', 'unit' => 'A', 'critical' => true];
        yield 'a branch that sets i beside another letter' => ['pattern' => '/^((?:(?i:a)|B)+)\1$/', 'unit' => 'A', 'critical' => false];
        yield 'a branch that turns i off under i' => ['pattern' => '/^((?:a|(?-i:A))+)\1$/i', 'unit' => 'A', 'critical' => true];
        yield 'the Kelvin sign beside k under iu' => ['pattern' => '/^((?:k|\x{212A})+)\1$/iu', 'unit' => "\u{212A}", 'critical' => true];
        yield 'the long s beside s under iu' => ['pattern' => '/^((?:s|\x{17F})+)\1$/iu', 'unit' => 's', 'critical' => true];
        yield 'a capital beside its letter under iu' => ['pattern' => '/^((?:K|k)+)\1$/iu', 'unit' => 'k', 'critical' => true];
        yield 'a Latin-1 letter beside its capital under iu' => ['pattern' => '/^((?:é|É)+)\1$/iu', 'unit' => 'é', 'critical' => true];
        yield 'the micro sign beside the Greek mu under iu' => ['pattern' => '/^((?:\x{3BC}|\xB5)+)\1$/iu', 'unit' => "\u{B5}", 'critical' => true];
        yield 'Latin-1 letters under (*UCP) and i' => ['pattern' => '/(*UCP)^((?:\xE9|\xC9)+)\1$/i', 'unit' => "\xE9", 'critical' => true];
        yield 'Latin-1 letters under i alone' => ['pattern' => '/^((?:\xE9|\xC9)+)\1$/i', 'unit' => "\xE9", 'critical' => false];
        // The r modifier, from PHP 8.4 and PCRE2 10.43.
        yield 'the Kelvin sign beside k under iur' => ['pattern' => '/^((?:k|\x{212A})+)\1$/iur', 'unit' => "\u{212A}", 'critical' => false];
    }

    #[Test]
    #[DataProvider('provideBranchesInEitherCase')]
    public function test_branches_are_compared_in_the_case_the_engine_reads_them(string $pattern, string $unit, bool $critical): void
    {
        if (false === @preg_match($pattern, '')) {
            $this->assertTrue(\PHP_VERSION_ID < 80400 || version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '<'), $pattern.' does not compile here.');

            return;
        }

        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = self::steps($unoptimized, str_repeat($unit, 8).'!');
        $long = self::steps($unoptimized, str_repeat($unit, 12).'!');
        $this->assertSame($critical, $long > 4 * $short, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($critical, RedosSeverity::Critical === Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * An item that reads nothing, an option setting above all, neither
     * separates a loop from what stands before or after it nor stands
     * between two loops: each row is read as its twin without the item,
     * written under the options in force, and the engine takes the same
     * steps on both (20 -> 28 on 8 -> 12 units of "a" then "!" for the
     * loop of "a" or "A", 10 -> 14 for "a+", 11 -> 15 in a branch or a
     * lookahead, 31 -> 69 for two loops of "a", 1 024 -> 16 384 where the
     * case makes the branches meet).
     *
     * @return iterable<string, array{pattern: string, twin: string, unit: string}>
     */
    public static function provideItemsThatReadNothing(): iterable
    {
        yield 'an option setting turning i off at the head of a captured loop' => ['pattern' => '/(?i)^((?-i)(?:a|A)+)\1$/', 'twin' => '/^((?:a|A)+)\1$/', 'unit' => 'a'];
        yield 'an option setting at the head of a captured loop' => ['pattern' => '/^((?i)a+)\1$/', 'twin' => '/^(a+)\1$/i', 'unit' => 'a'];
        yield 'an option setting that makes the branches meet' => ['pattern' => '/^((?i)(?:a|A)+)\1$/', 'twin' => '/^((?:a|A)+)\1$/i', 'unit' => 'a'];
        yield 'an option setting at the head of a branch' => ['pattern' => '/^(x|(?i)a+)\1$/', 'twin' => '/^(x|a+)\1$/i', 'unit' => 'a'];
        yield 'an option setting at the head of a lookahead' => ['pattern' => '/^(?=((?i)a+)\1$)/', 'twin' => '/^(?=(a+)\1$)/i', 'unit' => 'a'];
        yield 'an option setting in a nested group' => ['pattern' => '/^((?:(?i))(?:a|A)+)\1$/', 'twin' => '/^((?:a|A)+)\1$/', 'unit' => 'a'];
        yield 'an empty group that sets options' => ['pattern' => '/^((?i:)(?:a|A)+)\1$/', 'twin' => '/^((?:a|A)+)\1$/', 'unit' => 'a'];
        yield 'two option settings in a nested group' => ['pattern' => '/^((?:(?i)(?s))(?:a|A)+)\1$/', 'twin' => '/^((?:a|A)+)\1$/', 'unit' => 'a'];
        yield 'an option setting after the loop' => ['pattern' => '/^((?:a|A)+(?i))\1$/', 'twin' => '/^((?:a|A)+)\1$/', 'unit' => 'a'];
        yield 'an option setting between two loops' => ['pattern' => '/^(a+(?i)A+)\1$/', 'twin' => '/^(a+[aA]+)\1$/', 'unit' => 'a'];
        yield 'an option setting between two loops that stay apart' => ['pattern' => '/^(a+(?i)B+)\1$/', 'twin' => '/^(a+[bB]+)\1$/', 'unit' => 'a'];
        yield 'a comment at the head of a captured loop' => ['pattern' => '/^((?#c)a+)\1$/', 'twin' => '/^(a+)\1$/', 'unit' => 'a'];
    }

    #[Test]
    #[DataProvider('provideItemsThatReadNothing')]
    public function test_an_item_that_reads_nothing_is_read_as_its_twin_without_it(string $pattern, string $twin, string $unit): void
    {
        foreach ([8, 12] as $units) {
            $subject = str_repeat($unit, $units).'!';
            $this->assertSame(
                self::steps(PcreInterpreter::pattern($twin[0].'(*NO_START_OPT)'.substr($twin, 1)), $subject),
                self::steps(PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1)), $subject),
                'Oracle: the engine reads the row as its twin.',
            );
        }

        $this->assertSame(Regex::create(['cache' => null])->redos($twin)->severity, Regex::create(['cache' => null])->redos($pattern)->severity);
    }

    /**
     * A byte above ASCII is read under PCRE2's own tables, which PHP
     * compiles with under "C": there "\w" leaves "\xE9" out, and the loop
     * beside it gives its iterations back one at a time (8 -> 12 units of
     * "\xE9" then "!": 20 -> 28 steps). Under fr_FR.ISO8859-1 the tables put
     * "\xE9" in "\w", each one is read two ways, and the engine goes
     * exponential (1 024 -> 16 384): under another locale the heuristic
     * reads nothing above ASCII, and the loop stays critical.
     */
    #[Test]
    public function test_a_byte_above_ascii_is_read_under_pcre2s_own_tables_only(): void
    {
        $locale = 'fr_FR.ISO8859-1';
        $this->requiresLocale($locale, 'The Latin-1 row');
        $pattern = '/^((?:\w|\xE9)+)\1$/';
        $unoptimized = PcreInterpreter::pattern('/(*NO_START_OPT)^((?:\w|\xE9)+)\1$/');

        $short = self::steps($unoptimized, str_repeat("\xE9", 8).'!');
        $long = self::steps($unoptimized, str_repeat("\xE9", 12).'!');
        $this->assertLessThan(4 * $short, $long, 'Oracle: under C the engine stays linear.');
        CharacterTables::under($locale, static function () use ($unoptimized, &$short, &$long): void {
            $short = self::steps($unoptimized, str_repeat("\xE9", 8).'!');
            $long = self::steps($unoptimized, str_repeat("\xE9", 12).'!');
        });
        $this->assertGreaterThan(4 * $short, $long, 'Oracle: under the Latin-1 locale the engine goes exponential.');

        $this->assertNotSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos($pattern)->severity);
        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null, 'locale' => $locale])->redos($pattern)->severity);
    }

    /**
     * Whether PCRE compiles the pattern: a refused pattern warns, where a
     * match the engine stops, as on a recursion that could loop, does not.
     */
    private static function compiles(string $pattern): bool
    {
        $warned = false;
        set_error_handler(static function () use (&$warned): bool {
            $warned = true;

            return true;
        });

        try {
            preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        return !$warned;
    }

    /**
     * The smallest backtrack limit under which the match attempt ends, the
     * JIT on when asked.
     */
    private static function steps(string $pattern, string $subject, bool $jit = false): int
    {
        $saved = (string) ini_get('pcre.backtrack_limit');
        $savedJit = $jit ? ini_set('pcre.jit', '1') : false;

        try {
            $low = 1;
            $high = 1 << 24;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($pattern, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
            if (false !== $savedJit) {
                ini_set('pcre.jit', $savedJit);
            }
        }
    }
}
