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
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An atomic group commits to the first match its body finds, and tries an
 * alternative only when the ones before it find none. A first alternative
 * that is a repeat allowed no round at all always matches: "(?>[^x]*|y)"
 * is "(?>[^x]*)", the longest run of what is not an "x", and "y" is never
 * tried. Such a body is read as its first alternative, exactly, where it
 * was kept with every way through it, and no success was taken as sure
 * after it.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, the interpreter, without
 * auto-possession or start optimizations, the steps of one attempt read
 * from the match limit.
 */
final class RedosAtomicFirstBranchTest extends TestCase
{
    /**
     * The run the group commits to reads up to the "x" or the end, where
     * the success is sure: the loop before it is linear.
     */
    #[Test]
    #[DataProvider('provideFirstBranchesThatAlwaysMatch')]
    public function test_an_atomic_body_whose_first_branch_always_matches_is_read_as_that_branch(string $pattern, string $tail): void
    {
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8).$tail);
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 16).$tail);
        $this->assertLessThan(5 * $short, 2 * $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 16 "a" and %s.', $pattern, $short, $long, json_encode($tail)));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
        $this->assertSame([], $analysis->abstractions, $pattern.' is read exactly.');
    }

    /**
     * @return iterable<string, array{pattern: string, tail: string}>
     */
    public static function provideFirstBranchesThatAlwaysMatch(): iterable
    {
        $run = str_repeat('b', 30);

        yield 'a run before an x or the end' => ['pattern' => '/^(?:a|a)+(?>[^x]*|y)(?:x|\z)/', 'tail' => $run];
        yield 'two alternatives after the run' => ['pattern' => '/^(?:a|a)+(?>[^x]*|y|z)(?:x|\z)/', 'tail' => $run];
        yield 'a captured run' => ['pattern' => '/^(?:a|a)+(?>([^x]*)|y)(?:x|\z)/', 'tail' => $run];
        yield 'an optional character' => ['pattern' => '/^(?:a|a)+(?>b?|y)./', 'tail' => $run];
        yield 'a lazy run, which commits to nothing' => ['pattern' => '/^(?:a|a)+(?>b*?|y)./', 'tail' => $run];
        yield 'the dot' => ['pattern' => '/^(?:a|a)+(?>.*|y)/', 'tail' => $run];
    }

    /**
     * Read exactly, the run commits as the engine does, and a suffix that
     * fails after every split of the loop before it is a witness: ".*"
     * reads to the end, where no word boundary holds after the "!".
     */
    #[Test]
    public function test_a_loop_before_the_run_its_success_does_not_save_is_exponential(): void
    {
        $pattern = '/^(?:a+)+(?>.*|y)\b/';
        $counted = PcreAttemptSteps::pinned('/(*NO_START_OPT)'.substr($pattern, 1));
        $short = PcreAttemptSteps::steps($counted, str_repeat('a', 8).'!');
        $long = PcreAttemptSteps::steps($counted, str_repeat('a', 12).'!');
        $this->assertGreaterThan(8 * $short, $long, \sprintf('Oracle: %s takes %d then %d steps on 8 then 12 "a" and a "!".', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Exponential], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * The group matches as its first alternative alone, on every subject:
     * "(?>a*|b)c" finds nothing in "bc", as "(?>a*)c" does, where the
     * alternation of the two atomic groups would try "b".
     */
    #[Test]
    public function test_an_atomic_body_whose_first_branch_always_matches_matches_as_that_branch(): void
    {
        foreach (['', 'a', 'b', 'c', 'bc', 'ac', 'aac', 'abc', 'ba', 'cb', 'aab', 'bbc'] as $subject) {
            $this->assertSame(preg_match('/(*NO_JIT)(?>a*)c/', $subject, $alone), preg_match('/(*NO_JIT)(?>a*|b)c/', $subject, $whole), 'Oracle: '.json_encode($subject).'.');
            $this->assertSame($alone, $whole, 'Oracle: '.json_encode($subject).'.');
        }

        $analysis = (new RedosAnalyzer())->analyze('/(?>a*|b)c/');
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], '/(?>a*|b)c/ is "'.$analysis->headline().'".');
        $this->assertSame([], $analysis->abstractions, '/(?>a*|b)c/ is read exactly.');
    }

    /**
     * A first alternative that must read once may fail, and the engine then
     * tries the next one: "(?>x+|[^x]*)" matches "b" through its second
     * alternative, and the body stays kept with every way through it.
     */
    #[Test]
    public function test_an_atomic_body_whose_first_branch_may_fail_is_kept_whole(): void
    {
        $pattern = '/^(?:a|a)+(?>x+|[^x]*)(?:x|\z)/';
        $this->assertSame(1, preg_match($pattern, str_repeat('a', 8).'b'), 'Oracle: '.$pattern.' matches eight "a" and a "b".');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertNotSame(RedosComplexity::Exponential, $analysis->complexity, $pattern.' is "'.$analysis->headline().'".');
        $this->assertContains('atomic group at offset 9 over-approximated', $analysis->abstractions, $pattern.' keeps its atomic body whole.');
    }
}
