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

use PHPRegex\Redos\Finding;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A recursion into the whole pattern that the attempt tries first, before
 * it reads a character, enters the pattern again where it started: PCRE2
 * stops the match there with an error, so the attempt never backtracks.
 * Oracle (PCRE2 10.39 to 10.49): every attempt the engine runs on these
 * patterns fails, whatever the subject and its length, without the JIT on
 * a recursion loop (PREG_INTERNAL_ERROR, in 7 steps on 10.49), and with it
 * once the JIT stack runs out (PREG_JIT_STACKLIMIT_ERROR, in about 0.01 ms
 * at 1, 1,000 and 100,000 letters).
 */
final class RedosRecursionLoopTest extends TestCase
{
    private const LOOP = 'PCRE stops every match attempt it runs before it backtracks';

    private const SUBJECTS = ['', 'a', 'b', 'aaaa!', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!'];

    #[Test]
    #[DataProvider('provideFirstTriedRecursions')]
    public function test_the_interpreter_stops_each_attempt_on_a_recursion_loop(string $pattern): void
    {
        $started = PcreInterpreter::pattern((string) preg_replace('~^/~', '/(*NO_START_OPT)', $pattern));
        foreach (self::SUBJECTS as $subject) {
            $this->assertFalse(@preg_match($started, $subject), 'Oracle: the attempt stops on '.var_export($subject, true).'.');
            $this->assertSame(\PREG_INTERNAL_ERROR, preg_last_error());
        }
    }

    #[Test]
    #[DataProvider('provideFirstTriedRecursions')]
    public function test_the_jit_stops_each_attempt_when_its_stack_runs_out(string $pattern): void
    {
        $started = (string) preg_replace('~^/~', '/(*NO_START_OPT)', $pattern);
        $jit = true === \constant('PCRE_JIT_SUPPORT') && '1' === ini_get('pcre.jit');
        foreach (self::SUBJECTS as $subject) {
            $this->assertFalse(@preg_match($started, $subject), 'Oracle: the attempt stops on '.var_export($subject, true).'.');
            $this->assertSame($jit ? \PREG_JIT_STACKLIMIT_ERROR : \PREG_INTERNAL_ERROR, preg_last_error());
        }
    }

    #[Test]
    #[DataProvider('provideFirstTriedRecursions')]
    public function test_the_verdict_says_the_engine_stops_at_the_recursion(string $pattern): void
    {
        $analysis = Regex::create(['cache' => null])->redos($pattern);

        $this->assertSame(RedosSeverity::Low, $analysis->severity);
        $this->assertSame('Potential backtracking (heuristic)', $analysis->headline());
        $this->assertCount(1, $analysis->findings);
        $this->assertStringContainsString(self::LOOP, $analysis->findings[0]->message);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideFirstTriedRecursions(): iterable
    {
        yield 'an optional recursion before an optional letter' => ['pattern' => '/((?R)?a?)+/'];
        yield 'an optional recursion before a possessive run' => ['pattern' => '/((?R)?a*+)+/'];
        yield 'an optional recursion before an optional atomic letter' => ['pattern' => '/((?R)?(?>a)?)+/'];
        yield 'an optional recursion before an atomic optional letter' => ['pattern' => '/((?R)?(?>a?))+/'];
        yield 'the recursion as the first branch' => ['pattern' => '/(?:(?R)|a)/'];
        yield 'a recursion written (?0)' => ['pattern' => '/(?0)?a/'];
        yield 'a recursion written \g<0>' => ['pattern' => '/\g<0>?a/'];
        yield 'a possessive optional recursion' => ['pattern' => '/(?R)?+a/'];
        yield 'a recursion repeated twice or more' => ['pattern' => '/(?R){2,}a/'];
        yield 'a lazy letter skipped before the recursion' => ['pattern' => '/a??(?R)?b/'];
        yield 'an option setting and a comment before the recursion' => ['pattern' => '/(?i)(?#c)(?R)?a/'];
        yield 'a start option before the recursion' => ['pattern' => '/(*UTF)(?R)?a/u'];
        yield 'other start options before the recursion' => ['pattern' => '/(*CRLF)(*NO_AUTO_POSSESS)(*NOTEMPTY)(?R)?a/'];
        yield 'an exponential loop the attempt never reaches' => ['pattern' => '/((?R)?a?)+(a+)+$/'];
        yield 'a \\K before the recursion' => ['pattern' => '/\\K(?R)?a/'];
        yield 'a DEFINE block before the recursion' => ['pattern' => '/(?(DEFINE)(?<x>b))(?R)?a/'];
        yield 'the recursion in a branch reset group' => ['pattern' => '/(?|(?R)|a)/'];
        yield 'the recursion in a named group' => ['pattern' => '/(?<n>(?R))?a/'];
        yield 'the recursion in an atomic group' => ['pattern' => '/(?>(?R))?a/'];
    }

    #[Test]
    #[DataProvider('provideRecursionsAfterAnotherStep')]
    public function test_a_recursion_the_attempt_does_not_try_first_is_not_called_a_loop(string $pattern): void
    {
        $analysis = Regex::create(['cache' => null])->redos($pattern);
        $loops = array_filter($analysis->findings, static fn (Finding $finding): bool => str_contains($finding->message, self::LOOP));

        $this->assertSame([], $loops);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRecursionsAfterAnotherStep(): iterable
    {
        // Oracle: no subject makes these fail with an error.
        yield 'a letter read before the recursion' => ['pattern' => '/a(?R)?b/'];
        yield 'a recursion never run' => ['pattern' => '/(?R){0}a/'];
        yield 'a call to a group that reads a letter' => ['pattern' => '/(?1)?(a)/'];
        // Oracle: "a" matches; only a failing subject reaches the loop.
        yield 'a lazy recursion tried last' => ['pattern' => '/(?R)??a/'];
        // An anchor or a lookaround may fail before the recursion: the
        // analysis does not decide whether the attempt reaches it.
        yield 'an anchor before the recursion' => ['pattern' => '/^(?R)?a/'];
        yield 'a lookahead before the recursion' => ['pattern' => '/(?=a)(?R)?a/'];
    }

    /**
     * Never weaken what the engine shows superlinear: the recursion comes
     * after the loop, which backtracks before the attempt reaches it.
     * Oracle (PCRE2 10.49, JIT off): 17,496 steps at 8 letters, 1,417,176
     * at 12.
     */
    #[Test]
    public function test_a_loop_before_the_recursion_keeps_its_verdict(): void
    {
        $pattern = PcreInterpreter::pattern('/(*NO_START_OPT)(a+)+(?R)?$/');
        $saved = (string) ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', '100000');
            $this->assertFalse(@preg_match($pattern, str_repeat('a', 16).'!'));
            $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
        } finally {
            ini_set('pcre.backtrack_limit', $saved);
        }

        $this->assertSame(RedosSeverity::Critical, Regex::create(['cache' => null])->redos('/(a+)+(?R)?$/')->severity);
    }
}
