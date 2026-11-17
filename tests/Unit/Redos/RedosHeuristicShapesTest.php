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
use PHPRegex\Redos\RedosProfiler;
use PHPRegex\Redos\RedosSeverity;
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
     * for "(*sr:x)".
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
     * verb inside a lookaround or an atomic group acts only there. The
     * nested loop stays critical, and the engine agrees: 765 -> 12 285 ->
     * 196 605 steps on 8 -> 12 -> 16 letters then "!:" (start
     * optimizations off, the JIT counting).
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
     * attempt the first time the engine backtracks onto it: 3 steps
     * whatever the letters, and the loop it ends is safe.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVerbsThatCut(): iterable
    {
        yield 'skip to a mark set in the iteration' => ['pattern' => '/^(?:(*MARK:n)\w+\s?(*SKIP:n))+:/'];
        yield 'skip to a mark set as (*:NAME) before the loop' => ['pattern' => '/^(*:n)(?:\w+\s?(*SKIP:n))+:/'];
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
     * (42 -> 58 -> 74 for nested parentheses after a "(").
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
