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

namespace PHPRegex\Tests\Unit\Bridge\Infection;

use PHPRegex\Infection\Judge\CallReplay;
use PHPRegex\Infection\Site\PatternCall;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The replay of a call as it is written: what each preg_*() function, with
 * the arguments it was given, lets a test tell apart.
 */
final class CallReplayTest extends TestCase
{
    #[Test]
    #[DataProvider('provideReplays')]
    public function test_replay_answers_as_the_call_as_written(string $code, string $original, string $mutant, string $subject, bool $differs): void
    {
        $this->assertSame($differs, (new CallReplay())->differs(self::call($code), $original, $mutant, $subject));
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
    }

    /**
     * @return iterable<string, array{code: string, original: string, mutant: string, subject: string, differs: bool}>
     */
    public static function provideReplays(): iterable
    {
        // preg_match() with a pattern and a subject alone answers 1 or 0.
        yield 'yes or no' => ['code' => "preg_match('/a+/', \$s)", 'original' => '/a+/', 'mutant' => '/a/', 'subject' => 'aa', 'differs' => false];
        // Without $matches, PHP retries an empty match anchored, which
        // here backtracks past the replay's limit: the original gives no
        // answer.
        yield 'yes or no, no answer' => ['code' => "preg_match('/(?:|(?:a+)+\$)/', \$s)", 'original' => '/(?:|(?:a+)+$)/', 'mutant' => '/x/', 'subject' => str_repeat('a', 17).'!', 'differs' => false];
        yield 'matches' => ['code' => "preg_match('/a+/', \$s, \$m)", 'original' => '/a+/', 'mutant' => '/a/', 'subject' => 'aa', 'differs' => true];
        // Offsets are written only with PREG_OFFSET_CAPTURE.
        yield 'same text elsewhere' => ['code' => "preg_match('/a/', \$s, \$m)", 'original' => '/a/', 'mutant' => '/a(?=$)/', 'subject' => 'aa', 'differs' => false];
        yield 'same text elsewhere, offsets captured' => ['code' => "preg_match('/a/', \$s, \$m, PREG_OFFSET_CAPTURE)", 'original' => '/a/', 'mutant' => '/a(?=$)/', 'subject' => 'aa', 'differs' => true];
        // An unset group and an empty one read alike without
        // PREG_UNMATCHED_AS_NULL.
        yield 'unset or empty group' => ['code' => "preg_match('/(x)?(a)/', \$s, \$m)", 'original' => '/(x)?(a)/', 'mutant' => '/(x?)(a)/', 'subject' => 'a', 'differs' => false];
        yield 'unset or empty group, unmatched as null' => ['code' => "preg_match('/(x)?(a)/', \$s, \$m, PREG_UNMATCHED_AS_NULL)", 'original' => '/(x)?(a)/', 'mutant' => '/(x?)(a)/', 'subject' => 'a', 'differs' => true];
        yield 'both flags' => ['code' => "preg_match('/a/', \$s, \$m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)", 'original' => '/a/', 'mutant' => '/a(?=$)/', 'subject' => 'aa', 'differs' => true];
        // The search starts at the offset.
        yield 'from the start' => ['code' => "preg_match('/a/', \$s, \$m)", 'original' => '/a/', 'mutant' => '/[ab]/', 'subject' => 'ab', 'differs' => false];
        yield 'from an offset' => ['code' => "preg_match('/a/', \$s, \$m, 0, 1)", 'original' => '/a/', 'mutant' => '/[ab]/', 'subject' => 'ab', 'differs' => true];
        // Without $matches, preg_match_all() answers the count alone.
        yield 'count of all matches' => ['code' => "preg_match_all('/ab?/', \$s)", 'original' => '/ab?/', 'mutant' => '/ab??/', 'subject' => 'ab', 'differs' => false];
        yield 'all matches' => ['code' => "preg_match_all('/a(b)/', \$s, \$m)", 'original' => '/a(b)/', 'mutant' => '/(a)b/', 'subject' => 'ab', 'differs' => true];
        // In set order, no match leaves no group to count either.
        yield 'all matches in set order, none' => ['code' => "preg_match_all('/a(b)/', \$s, \$m, PREG_SET_ORDER)", 'original' => '/a(b)/', 'mutant' => '/ab/', 'subject' => 'x', 'differs' => false];
        yield 'all matches from an offset' => ['code' => "preg_match_all('/a/', \$s, \$m, 0, 1)", 'original' => '/a/', 'mutant' => '/[ab]/', 'subject' => 'ab', 'differs' => true];
        // A replacement reads the groups it names, no more.
        yield 'replacement naming no group' => ['code' => "preg_replace('/a(b)/', '', \$s)", 'original' => '/a(b)/', 'mutant' => '/(a)b/', 'subject' => 'ab', 'differs' => false];
        yield 'replacement naming a group' => ['code' => "preg_replace('/a(b)/', '\$1', \$s)", 'original' => '/a(b)/', 'mutant' => '/(a)b/', 'subject' => 'ab', 'differs' => true];
        yield 'replacement limit' => ['code' => "preg_replace('/a/', '_', \$s, 1)", 'original' => '/a/', 'mutant' => '/a|b/', 'subject' => 'ab', 'differs' => false];
        yield 'no replacement limit' => ['code' => "preg_replace('/a/', '_', \$s)", 'original' => '/a/', 'mutant' => '/a|b/', 'subject' => 'ab', 'differs' => true];
        yield 'filter, nothing replaced' => ['code' => "preg_filter('/x/', '_', \$s)", 'original' => '/x/', 'mutant' => '/y/', 'subject' => 'z', 'differs' => false];
        yield 'filter, one replaces' => ['code' => "preg_filter('/z/', '_', \$s)", 'original' => '/z/', 'mutant' => '/y/', 'subject' => 'z', 'differs' => true];
        yield 'filter, replacement naming a group' => ['code' => "preg_filter('/a(b)/', '\$1', \$s)", 'original' => '/a(b)/', 'mutant' => '/(a)b/', 'subject' => 'ab', 'differs' => true];
        // A callback receives each match with its offset.
        yield 'filter, the same subjects matched' => ['code' => "preg_filter('/a/', '_', \$s)", 'original' => '/a/', 'mutant' => '/a|b/', 'subject' => 'ab', 'differs' => true];
        yield 'callback, other offsets' => ['code' => "preg_replace_callback('/a(?=a)/', \$f, \$s)", 'original' => '/a(?=a)/', 'mutant' => '/a(?=b)/', 'subject' => 'aab', 'differs' => true];
        // The callback gets an unset group as "" unless the call asks for
        // null: it cannot tell it from an empty one.
        yield 'callback, unset or empty group' => ['code' => "preg_replace_callback('/(a*)?(b)/', \$f, \$s)", 'original' => '/(a*)?(b)/', 'mutant' => '/(a+)?(b)/', 'subject' => 'b', 'differs' => false];
        yield 'callback, unset or empty group, unmatched as null' => ['code' => "preg_replace_callback('/(a*)?(b)/', \$f, \$s, -1, \$count, PREG_UNMATCHED_AS_NULL)", 'original' => '/(a*)?(b)/', 'mutant' => '/(a+)?(b)/', 'subject' => 'b', 'differs' => true];
        yield 'callback limit' => ['code' => "preg_replace_callback('/a/', \$f, \$s, 1)", 'original' => '/a/', 'mutant' => '/a|b/', 'subject' => 'ab', 'differs' => false];
        // A limit of 0 replaces nothing: the callback is never called.
        yield 'callback limit of zero' => ['code' => "preg_replace_callback('/a/', \$f, \$s, 0)", 'original' => '/a/', 'mutant' => '/b/', 'subject' => 'a', 'differs' => false];
        yield 'no callback limit' => ['code' => "preg_replace_callback('/a/', \$f, \$s)", 'original' => '/a/', 'mutant' => '/a|b/', 'subject' => 'ab', 'differs' => true];
        // Pieces, empty ones and captured delimiters as the flags say.
        yield 'split' => ['code' => "preg_split('/,/', \$s)", 'original' => '/,/', 'mutant' => '/,+/', 'subject' => 'a,,b', 'differs' => true];
        yield 'split, no empty piece' => ['code' => "preg_split('/,/', \$s, -1, PREG_SPLIT_NO_EMPTY)", 'original' => '/,/', 'mutant' => '/,+/', 'subject' => 'a,,b', 'differs' => false];
        yield 'split, delimiter not captured' => ['code' => "preg_split('/(,)/', \$s)", 'original' => '/(,)/', 'mutant' => '/,/', 'subject' => 'a,b', 'differs' => false];
        yield 'split, delimiter captured' => ['code' => "preg_split('/(,)/', \$s, -1, PREG_SPLIT_DELIM_CAPTURE)", 'original' => '/(,)/', 'mutant' => '/,/', 'subject' => 'a,b', 'differs' => true];
        yield 'split limit' => ['code' => "preg_split('/,/', \$s, 2)", 'original' => '/,/', 'mutant' => '/,|;/', 'subject' => 'a,b;c', 'differs' => false];
        yield 'no split limit' => ['code' => "preg_split('/,/', \$s)", 'original' => '/,/', 'mutant' => '/,|;/', 'subject' => 'a,b;c', 'differs' => true];
        yield 'grep' => ['code' => "preg_grep('/a/', \$list)", 'original' => '/a/', 'mutant' => '/b/', 'subject' => 'a', 'differs' => true];
        yield 'grep, inverted' => ['code' => "preg_grep('/a/', \$list, PREG_GREP_INVERT)", 'original' => '/a/', 'mutant' => '/b/', 'subject' => 'a', 'differs' => true];
        // preg_grep() keeps an element without retrying an empty match, as
        // preg_match() given $matches runs.
        yield 'grep, no retry of an empty match' => ['code' => "preg_grep('/(?:|(?:a+)+\$)/', \$list)", 'original' => '/(?:|(?:a+)+$)/', 'mutant' => '/x/', 'subject' => str_repeat('a', 17).'!', 'differs' => true];
        yield 'grep, from the first byte' => ['code' => "preg_grep('/a/', \$list)", 'original' => '/a/', 'mutant' => '/b/', 'subject' => 'ab', 'differs' => false];
        yield 'grep, both keep it' => ['code' => "preg_grep('/a/', \$list)", 'original' => '/a/', 'mutant' => '/a|b/', 'subject' => 'a', 'differs' => false];
    }

    #[Test]
    #[DataProvider('provideReplayability')]
    public function test_a_call_replays_when_every_argument_that_shapes_it_is_a_literal(string $code, bool $replayable): void
    {
        $this->assertSame($replayable, (new CallReplay())->replayable(self::call($code)));
    }

    /**
     * @return iterable<string, array{code: string, replayable: bool}>
     */
    public static function provideReplayability(): iterable
    {
        yield 'preg_match' => ['code' => "preg_match('/a/', \$s, \$m, PREG_OFFSET_CAPTURE, 2)", 'replayable' => true];
        yield 'preg_match, flags at run time' => ['code' => "preg_match('/a/', \$s, \$m, \$flags)", 'replayable' => false];
        yield 'preg_match, offset at run time' => ['code' => "preg_match('/a/', \$s, \$m, 0, \$offset)", 'replayable' => false];
        yield 'preg_match_all, flags at run time' => ['code' => "preg_match_all('/a/', \$s, \$m, \$flags)", 'replayable' => false];
        yield 'preg_replace' => ['code' => "preg_replace('/a/', 'b', \$s, 2)", 'replayable' => true];
        yield 'preg_replace, replacement at run time' => ['code' => "preg_replace('/a/', \$b, \$s)", 'replayable' => false];
        yield 'preg_replace, limit at run time' => ['code' => "preg_replace('/a/', 'b', \$s, \$limit)", 'replayable' => false];
        yield 'preg_filter, replacement at run time' => ['code' => "preg_filter('/a/', \$b, \$s)", 'replayable' => false];
        yield 'preg_filter, limit at run time' => ['code' => "preg_filter('/a/', 'b', \$s, \$limit)", 'replayable' => false];
        yield 'preg_replace_callback' => ['code' => "preg_replace_callback('/a/', \$f, \$s, 1)", 'replayable' => true];
        yield 'preg_replace_callback, limit at run time' => ['code' => "preg_replace_callback('/a/', \$f, \$s, \$limit)", 'replayable' => false];
        yield 'preg_replace_callback, flags at run time' => ['code' => "preg_replace_callback('/a/', \$f, \$s, -1, \$count, \$flags)", 'replayable' => false];
        yield 'preg_split' => ['code' => "preg_split('/a/', \$s, 2, PREG_SPLIT_NO_EMPTY)", 'replayable' => true];
        yield 'preg_split, limit at run time' => ['code' => "preg_split('/a/', \$s, \$limit)", 'replayable' => false];
        yield 'preg_split, flags at run time' => ['code' => "preg_split('/a/', \$s, -1, \$flags)", 'replayable' => false];
        yield 'preg_grep, flags at run time' => ['code' => "preg_grep('/a/', \$list, \$flags)", 'replayable' => true];
    }

    #[Test]
    public function test_replay_runs_under_its_own_backtracking_limit_and_puts_the_ini_back(): void
    {
        $call = self::call("preg_match('/(a+)+\$/', \$s)");
        $before = [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')];

        // Seventeen "a" then "!" exhaust 100,000 backtracks but not the
        // 1,000,000 of PHP's default: the original gives no answer, so the
        // subject tells nothing.
        $this->assertFalse((new CallReplay())->differs($call, '/(a+)+$/', '/a/', str_repeat('a', 17).'!'));
        $this->assertSame($before, [ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')]);
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
    }

    #[Test]
    #[DataProvider('provideFailingOriginals')]
    public function test_a_call_one_pattern_cannot_answer_tells_nothing(string $code): void
    {
        // The original exhausts the replay's backtracking, the mutant
        // answers: no subject on which only one answers is a kill string.
        $this->assertFalse((new CallReplay())->differs(self::call($code), '/(a+)+$/', '/a/', str_repeat('a', 17).'!'));
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
    }

    /**
     * @return iterable<string, array{code: string}>
     */
    public static function provideFailingOriginals(): iterable
    {
        yield 'preg_match with matches' => ['code' => "preg_match('/(a+)+\$/', \$s, \$m)"];
        yield 'preg_match_all' => ['code' => "preg_match_all('/(a+)+\$/', \$s, \$m)"];
        yield 'preg_replace' => ['code' => "preg_replace('/(a+)+\$/', '', \$s)"];
        yield 'preg_replace_callback' => ['code' => "preg_replace_callback('/(a+)+\$/', \$f, \$s)"];
        yield 'preg_split' => ['code' => "preg_split('/(a+)+\$/', \$s)"];
        yield 'preg_grep' => ['code' => "preg_grep('/(a+)+\$/', \$list)"];
    }

    #[Test]
    public function test_replay_keeps_a_warning_out_of_the_error_log(): void
    {
        error_clear_last();

        $this->assertFalse((new CallReplay())->differs(self::call("preg_match('/a/', \$s)"), '/a/', '/(/', 'a'));
        $this->assertNull(error_get_last());
    }

    private static function call(string $code): PatternCall
    {
        $call = PatternCall::fromNode(MutatorHarness::call($code) ?? throw new \LogicException($code));

        return $call ?? throw new \LogicException($code);
    }
}
