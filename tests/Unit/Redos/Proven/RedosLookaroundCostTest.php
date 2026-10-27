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

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The work done inside a lookaround. A lookaround's body runs again each
 * time the attempt passes its place: in a loop, or after one the attempt
 * backtracks into, a body that reads a run reads it again from each
 * position, and one attempt costs a product of the two, though the search
 * and the body are each linear. PCRE2's step counter grows only linearly
 * there (a run of one character class costs it one step), so neither the
 * backtrack limit nor a replay sees it: the proof has to.
 *
 * Engine: PHP 8.4.26 / PCRE2 10.49, preg_match() timed on the subject at n,
 * 2n, 4n and 8n, the fastest of seven runs, JIT on and with (*NO_JIT); the
 * figures are the times without the JIT and the ratio each time the subject
 * doubles, about 4 for a quadratic attempt and 2 for a linear one.
 */
final class RedosLookaroundCostTest extends TestCase
{
    use PcreGates;

    /**
     * A lookbehind of more than one length needs PCRE2 10.43.
     */
    private const RELEASES = ['10.43' => ['/^a(?:(?<=a{1,200})a)+$/', '/^(?:(?<!b{1,200})a)+$/']];

    #[Test]
    #[DataProvider('provideSuperLinearPatterns')]
    public function test_a_lookaround_reading_a_run_again_in_a_loop_is_not_proven_linear(string $pattern, int $offset): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is '.$analysis->headline());
        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity, $pattern);
        $this->assertContains(\sprintf('lookaround at offset %d read again at each step of a loop', $offset), $analysis->abstractions, $pattern);
        // The ceiling counts the body's loops on top of the attempt's.
        $this->assertGreaterThanOrEqual(2, $analysis->upperBoundDegree, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideSuperLinearPatterns(): iterable
    {
        // "a" x n . "!": 1.07, 4.15, 16.2, 73.8 ms from n = 2,000 (3.9 and 4.0 per doubling with the JIT).
        yield 'positive lookahead holding a+, in a loop' => ['pattern' => '/^(?:(?=a+)a)+$/', 'offset' => 4];
        // "a" x n . "!": 3.9 and 4.0 each time n doubles, JIT and not.
        yield 'positive lookahead holding a*, in a loop' => ['pattern' => '/^(?:(?=a*)a)+$/', 'offset' => 4];
        // "a" x n, a match: 1.06, 4.13, 16.2 ms from n = 2,000.
        yield 'the attempt matches all the same' => ['pattern' => '/^(?:(?=a+)a)+/', 'offset' => 4];
        // "a" x n . "!": 3.7 and 3.9 per doubling without the JIT.
        yield 'possessive body' => ['pattern' => '/^(?:(?=a++)a)+$/', 'offset' => 4];
        // "a" x n . "!": 3.8 and 4.0 per doubling.
        yield 'atomic body' => ['pattern' => '/^(?:(?=(?>a+))a)+$/', 'offset' => 4];
        // "a" x n, a match: 1.04, 4.03, 15.9, 63.6 ms from n = 2,000.
        yield 'lazy body tied to the end' => ['pattern' => '/^(?:(?=a+?$)a)+$/', 'offset' => 4];
        // "a" x n . "b": 0.71, 2.71, 10.5, 42.3 ms from n = 1,000.
        yield 'positive lookahead holding .*b' => ['pattern' => '/^(?:(?=.*b)a)+$/', 'offset' => 4];
        // "a" x n . "!": 3.9 and 4.1 per doubling.
        yield 'atomic group around the loop' => ['pattern' => '/^(?>(?:(?=a+)a)+)$/', 'offset' => 7];
        // "a" x n . "!": 3.9 and 5.5 per doubling.
        yield 'possessive loop' => ['pattern' => '/^(?:(?=a+)a)++$/', 'offset' => 4];
        // "x" . "a" x n . "!": 3.8 and 4.9 per doubling.
        yield 'inner loop of two' => ['pattern' => '/^(?:x(?:(?=a+)a)+)+$/', 'offset' => 8];
        // "a" x n . "!b": 1.06, 4.12, 19.8, 78.3 ms from n = 2,000: the loop
        // gives back one character at a time, the body reads the run again.
        yield 'after a loop the attempt backtracks into' => ['pattern' => '/^a*(?=a+)b/', 'offset' => 3];
        // "a" x n . "!": 4.5, 3.7 and 4.7 per doubling.
        yield 'after a loop, tied to the end' => ['pattern' => '/^a+(?=a*$)/', 'offset' => 3];
        // "a" x n . "!": 1.12, 4.24, 16.4 ms from n = 2,000.
        yield 'lookbehind holding a lookahead' => ['pattern' => '/^a(?:(?<=a(?=a+))a)+$/', 'offset' => 10];
        // "a" x n . "!": 1.20, 4.33, 16.5, 64.1 ms from n = 2,000, and as
        // much in the other order: a bounded lookahead beside it changes
        // nothing.
        yield 'beside a bounded lookahead' => ['pattern' => '/^(?:(?=a+)(?=a{1,100})a)+$/', 'offset' => 4];
        // "a" x n . "!": 87 ms at n = 1,000, 1.7 s at 2,000, 84 s at 8,000: cubic.
        yield 'lookahead in a lookahead, both in loops' => ['pattern' => '/^(?:(?=(?:(?=a+)a)+)a)+$/', 'offset' => 4];
    }

    /**
     * A negative lookahead whose body fails all over the witness holds at
     * each step of the loop, and its body reads the rest of the run each
     * time: the search read with the lookahead in place, its body a branch
     * tried first that ends nowhere, proves the product, on a witness the
     * body fails on from each position. PCRE2 makes "a+" before "b"
     * possessive, which reads the same characters but counts none of them:
     * the steps are counted with "(*NO_AUTO_POSSESS)".
     */
    #[Test]
    #[DataProvider('provideProvenProducts')]
    public function test_a_failing_negative_lookahead_read_again_in_a_loop_is_proven_polynomial(string $pattern, int $degree): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = $pattern.' is '.$analysis->headline();

        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame(RedosComplexity::Polynomial, $analysis->complexity, $message);
        $this->assertSame($degree, $analysis->degree, $message);

        // The attempt fails on the witness, and the counter grows 2^degree
        // times from n to 2n.
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $message);
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($pattern).'A', $witness->build(20)), $message);
        $short = self::steps($pattern, $witness->build(100));
        $long = self::steps($pattern, $witness->build(200));
        $this->assertGreaterThan(0.85 * 2 ** $degree, $long / $short, \sprintf('%s: %d -> %d steps.', $message, $short, $long));
        $this->assertLessThan(1.15 * 2 ** $degree, $long / $short, \sprintf('%s: %d -> %d steps.', $message, $short, $long));
    }

    /**
     * @return iterable<string, array{pattern: string, degree: int}>
     */
    public static function provideProvenProducts(): iterable
    {
        // "a" x n . "!": 1.07, 4.14, 17.1 ms from n = 2,000.
        yield 'negative lookahead holding a+b' => ['pattern' => '/^(?:(?!a+b)a)+$/', 'degree' => 2];
        // "a" x n . "\n": 3.9, 5.0 and 7.0 per doubling with the JIT; on the
        // witness, "!" x n . "\n!", 0.26, 1.01, 3.99, 15.9 ms from n = 1,000
        // with the JIT, 3.9 to 4.0 per doubling.
        yield 'tempered token whose lookahead holds .*' => ['pattern' => '/^(?:(?!.*foo).)*$/', 'degree' => 2];
    }

    /**
     * A lookahead that reads a run but stops at once costs the attempt
     * nothing more, yet the analysis does not tell when a body stops: the
     * proof is refused all the same. On "a" x n both take twice as long
     * each time n doubles, JIT and not.
     */
    #[Test]
    #[DataProvider('provideBodiesThatStopAtOnce')]
    public function test_a_lookahead_that_stops_at_once_is_refused_all_the_same(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse($analysis->isProvenSafe(), $pattern);
        $this->assertSame(['lookaround at offset 4 read again at each step of a loop'], $analysis->abstractions, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBodiesThatStopAtOnce(): iterable
    {
        yield 'lazy body, done after one character' => ['pattern' => '/^(?:(?=a+?)a)+$/'];
        yield 'negative body that matches, so the loop never turns' => ['pattern' => '/^(?:(?!a+)a)+$/'];
    }

    #[Test]
    #[DataProvider('provideLinearPatterns')]
    public function test_a_lookaround_whose_work_stays_linear_keeps_its_proof(string $pattern): void
    {
        $this->requiresPcre2For($pattern, self::RELEASES);
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertTrue($analysis->isProvenSafe(), $pattern.' is '.$analysis->headline());
        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLinearPatterns(): iterable
    {
        // "a" x n . "!": 0.59, 1.18, 2.49, 4.76 ms from n = 16,000.
        yield 'bounded lookahead in a loop' => ['pattern' => '/^(?:(?=a)a)+$/'];
        yield 'bounded negative lookahead in a loop' => ['pattern' => '/^(?:(?!b)a)+$/'];
        // "a" x n . "\n": 2.0 per doubling.
        yield 'tempered token of a literal' => ['pattern' => '/^(?:(?!foo).)*$/'];
        // "a" x n . "!": 0.68, 1.35, 2.83, 5.74 ms from n = 8,000: at most
        // 100 characters each time.
        yield 'bounded repeat in a lookahead' => ['pattern' => '/^(?:(?=a{1,100})a)+$/'];
        // "a" x n . "!": 1.6, 1.8, 1.9 per doubling: at most 1,000 passes.
        yield 'bounded loop around a lookahead' => ['pattern' => '/^(?:(?=a+)a){1,1000}$/'];
        // A lookbehind reads at most 255 characters back (PCRE2's default):
        // 2.0 per doubling for each of these.
        yield 'lookbehind in a loop' => ['pattern' => '/^a(?:(?<=a)a)+$/'];
        yield 'negative lookbehind in a loop' => ['pattern' => '/^(?:(?<!b)a)+$/'];
        yield 'lookbehind of two alternatives in a loop' => ['pattern' => '/^a(?:(?<=a|a)a)+$/'];
        yield 'variable lookbehind in a loop' => ['pattern' => '/^aaa(?:(?<=a|aa|aaa)a)+$/'];
        yield 'bounded repeat in a lookbehind' => ['pattern' => '/^a(?:(?<=a{1,200})a)+$/'];
        yield 'bounded repeat in a negative lookbehind' => ['pattern' => '/^(?:(?<!b{1,200})a)+$/'];
        // "a" x n . "!b": 2.0 per doubling: the run is read once, then the
        // lookahead once.
        yield 'after a possessive run' => ['pattern' => '/^a*+(?=a+)b/'];
        // "a" x n . "!": 2.0 per doubling: the body reads no word character.
        yield 'after a loop over other characters' => ['pattern' => '/^\w+(?=\s*$)/'];
        // "SELECT" . " " x n . "TOP(": 2.0 per doubling: a keyword stands
        // between the loop and the lookahead.
        yield 'a keyword between the loop and the lookahead' => ['pattern' => '/^([\s(])*SELECT([\s]+(DISTINCT|ALL))?(?!\s*TOP\s*\()/i'];
        // 2.0 per doubling for each: a lookaround outside any loop runs once.
        yield 'lookahead before the loop' => ['pattern' => '/^(?=a+)a+$/'];
        yield 'password rules' => ['pattern' => '/^(?=.*\d)(?=.*[a-z]).{8,}$/'];
        yield 'negative lookahead before the loop' => ['pattern' => '/^(?!a+b)a+$/'];
        yield 'lookbehind after the loop' => ['pattern' => '/^a+(?<=a)$/'];
    }

    /**
     * The steps the interpreter takes, its start-up study and its automatic
     * possession off: the smallest backtrack limit under which it finishes.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $limit = ini_get('pcre.backtrack_limit');
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)(*NO_AUTO_POSSESS)'.substr($pattern, 1));

        try {
            $low = 1;
            $high = 1 << 26;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($unoptimized, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
    }
}
