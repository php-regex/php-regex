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

use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\BacktrackProver;
use PHPRegex\Redos\Exception\ModelLimitException;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A bounded repeat past the cutoff, or one whose copies read the same input
 * in two ways, is analysed as unbounded. A pump that rests on such a repeat
 * alone proves nothing by itself: past its bound the repeat takes no more
 * copies, and the cost stops growing. It is a proof once the engine, run as
 * PHP runs it by default, gives up on the witness at the backtrack limit
 * within the bound.
 *
 * Engine: PHP 8.4.27 / PCRE2 10.49, the interpreter, pcre.backtrack_limit
 * 1000000, PHP's default.
 */
final class RedosBoundedRepeatReplayTest extends TestCase
{
    private string|false $backtrackLimit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
    }

    /**
     * Both exhaust the limit on 24 characters and a "!": two ways for each
     * "a" in up to forty copies, and up to thirty runs of one to three
     * digits. The witness runs from the text before the repeat: the forty
     * copies after an "x" exhaust it on "x", 24 letters and a "!".
     *
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideBoundedRepeatsTheEngineGivesUpOn(): iterable
    {
        yield 'forty copies of two ways to read a letter' => ['pattern' => '/^(?:a|a){1,40}$/', 'subject' => str_repeat('a', 24).'!'];
        yield 'thirty runs of up to three digits' => ['pattern' => '/^(?:\d{1,3}\.?){1,30}$/', 'subject' => str_repeat('1', 24).'!'];
        yield 'forty copies of two ways to read a letter after an x' => ['pattern' => '/^x(?:a|a){1,40}$/', 'subject' => 'x'.str_repeat('a', 24).'!'];
    }

    #[Test]
    #[DataProvider('provideBoundedRepeatsTheEngineGivesUpOn')]
    public function test_a_bounded_repeat_the_engine_gives_up_on_is_proven_exponential(string $pattern, string $subject): void
    {
        $this->assertFalse(@preg_match(PcreInterpreter::pattern($pattern), $subject), $pattern);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), $pattern);

        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern.' is "'.$analysis->headline().'"');
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity, $pattern);
        $this->assertNotSame([], $analysis->abstractions, $pattern);
    }

    /**
     * Without the pattern as written, the engine cannot be asked: the pump
     * through the bounded repeat alone stays no proof.
     */
    #[Test]
    public function test_a_bounded_repeat_is_no_proof_when_the_engine_cannot_be_asked(): void
    {
        $outcome = (new BacktrackProver(2_000, 250_000, 16))->prove(RegexParser::create()->parse('/^(?:a|a){1,40}$/'));

        $this->assertNull($outcome->result);
        $this->assertInstanceOf(ModelLimitException::class, $outcome->limit);
        $this->assertTrue($outcome->limit->approximated);
    }

    /**
     * Sixteen copies of two ways to read a letter, or four runs of digits,
     * cost less than the limit whatever follows: the verdict stays the
     * heuristics'.
     *
     * @return iterable<string, array{pattern: string, pump: string}>
     */
    public static function provideBoundedRepeatsTheEngineFinishes(): iterable
    {
        yield 'sixteen copies of two ways to read a letter' => ['pattern' => '/^(?:a|a){1,16}$/', 'pump' => 'a'];
        yield 'four digit groups before a colon' => ['pattern' => '/^(\d{1,3}){4}:\d+$/', 'pump' => '1'];
    }

    #[Test]
    #[DataProvider('provideBoundedRepeatsTheEngineFinishes')]
    public function test_a_bounded_repeat_the_engine_finishes_stays_heuristic(string $pattern, string $pump): void
    {
        foreach ([8, 16, 32, 64, 200] as $count) {
            foreach (['!', ':', ':1!'] as $suffix) {
                $this->assertNotFalse(@preg_match(PcreInterpreter::pattern($pattern), str_repeat($pump, $count).$suffix), $pattern);
            }
        }

        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern.' is "'.$analysis->headline().'"');
        $this->assertNotSame(RedosSeverity::Critical, $analysis->severity, $pattern);
    }

    /**
     * Two branches that only share their first letter read any input one
     * way, bound or not: 19 -> 35 -> 67 steps on 8 -> 16 -> 32 "ab" and a
     * "!", and the proof stays linear.
     */
    #[Test]
    public function test_branches_sharing_a_first_letter_stay_linear(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/^(?:ab|ac){1,40}$/');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity);
        $this->assertSame(RedosSeverity::Safe, $analysis->severity);
    }
}
