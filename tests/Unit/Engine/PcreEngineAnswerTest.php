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

namespace PHPRegex\Tests\Unit\Engine;

use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a preg_*() call answers, run as every call of the engine runs: two
 * answers of the same call compare as strings. Each expected answer is the
 * native call's own.
 */
final class PcreEngineAnswerTest extends TestCase
{
    /**
     * @param 'preg_match'|'preg_match_all'|'preg_replace'|'preg_replace_callback'|'preg_split'   $function
     * @param array{matches?: bool, flags?: int, offset?: int, limit?: int, replacement?: string} $arguments
     * @param \Closure(): mixed                                                                   $native    the call as PHP runs it
     */
    #[Test]
    #[DataProvider('provideAnswers')]
    public function test_each_call_answers_as_php_does(string $function, string $regex, string $subject, array $arguments, \Closure $native): void
    {
        $this->assertSame(serialize($native()), (new PcreEngine())->answer($function, $regex, $subject, $arguments));
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
    }

    /**
     * @return iterable<string, array{function: 'preg_match'|'preg_match_all'|'preg_replace'|'preg_replace_callback'|'preg_split', regex: string, subject: string, arguments: array{matches?: bool, flags?: int, offset?: int, limit?: int, replacement?: string}, native: \Closure(): mixed}>
     */
    public static function provideAnswers(): iterable
    {
        yield 'preg_match, the answer' => ['function' => 'preg_match', 'regex' => '/a+/', 'subject' => 'baa', 'arguments' => [], 'native' => static fn (): mixed => preg_match('/a+/', 'baa')];
        yield 'preg_match, the matches' => ['function' => 'preg_match', 'regex' => '/a+/', 'subject' => 'baa', 'arguments' => ['matches' => true], 'native' => static function (): mixed {
            preg_match('/a+/', 'baa', $matches);

            return $matches;
        }];
        yield 'preg_match, offsets and nulls' => ['function' => 'preg_match', 'regex' => '/(x)?(a)/', 'subject' => 'ba', 'arguments' => ['matches' => true, 'flags' => \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL], 'native' => static function (): mixed {
            preg_match('/(x)?(a)/', 'ba', $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL);

            return $matches;
        }];
        yield 'preg_match, from an offset' => ['function' => 'preg_match', 'regex' => '/a/', 'subject' => 'ab', 'arguments' => ['matches' => true, 'offset' => 1], 'native' => static function (): mixed {
            preg_match('/a/', 'ab', $matches, 0, 1);

            return $matches;
        }];
        yield 'preg_match, an offset but no matches' => ['function' => 'preg_match', 'regex' => '/a/', 'subject' => 'ab', 'arguments' => ['offset' => 1], 'native' => static fn (): mixed => preg_match('/a/', 'ab', offset: 1)];
        yield 'preg_match_all, the count' => ['function' => 'preg_match_all', 'regex' => '/a/', 'subject' => 'aba', 'arguments' => [], 'native' => static fn (): mixed => preg_match_all('/a/', 'aba')];
        yield 'preg_match_all, the matches' => ['function' => 'preg_match_all', 'regex' => '/a/', 'subject' => 'aba', 'arguments' => ['matches' => true], 'native' => static function (): mixed {
            preg_match_all('/a/', 'aba', $matches);

            return $matches;
        }];
        yield 'preg_match_all, set order' => ['function' => 'preg_match_all', 'regex' => '/a(b)/', 'subject' => 'abab', 'arguments' => ['matches' => true, 'flags' => \PREG_SET_ORDER], 'native' => static function (): mixed {
            preg_match_all('/a(b)/', 'abab', $matches, \PREG_SET_ORDER);

            return $matches;
        }];
        yield 'preg_match_all, set order, no match' => ['function' => 'preg_match_all', 'regex' => '/a(b)/', 'subject' => 'x', 'arguments' => ['matches' => true, 'flags' => \PREG_SET_ORDER], 'native' => static function (): mixed {
            preg_match_all('/a(b)/', 'x', $matches, \PREG_SET_ORDER);

            return $matches;
        }];
        yield 'preg_replace' => ['function' => 'preg_replace', 'regex' => '/a/', 'subject' => 'aba', 'arguments' => ['replacement' => '_', 'limit' => 1], 'native' => static fn (): mixed => preg_replace('/a/', '_', 'aba', 1)];
        yield 'preg_replace_callback' => ['function' => 'preg_replace_callback', 'regex' => '/(x)?a/', 'subject' => 'ba', 'arguments' => [], 'native' => static fn (): mixed => self::callbackSees('/(x)?a/', 'ba', -1)];
        yield 'preg_replace_callback, limited' => ['function' => 'preg_replace_callback', 'regex' => '/a/', 'subject' => 'aa', 'arguments' => ['limit' => 1], 'native' => static fn (): mixed => self::callbackSees('/a/', 'aa', 1)];
        yield 'preg_split' => ['function' => 'preg_split', 'regex' => '/(,)/', 'subject' => 'a,,b', 'arguments' => ['flags' => \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE], 'native' => static fn (): mixed => preg_split('/(,)/', 'a,,b', -1, \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE)];
    }

    #[Test]
    public function test_a_call_the_engine_gives_up_on_answers_nothing(): void
    {
        $engine = new PcreEngine();
        $before = ini_get('pcre.backtrack_limit');

        $this->assertNull($engine->answer('preg_match', '/(a+)+$/', str_repeat('a', 17).'!', [], new PcreLimits(100_000, 10_000)));
        $this->assertNull($engine->answer('preg_match', '/a/u', "\xff"));
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
        // PHP refuses that subject itself.
        $this->assertFalse(@preg_match('/a/u', "\xff"));
    }

    #[Test]
    public function test_preg_match_without_matches_is_called_without_them(): void
    {
        // Without $matches, PHP retries an empty match anchored and
        // refusing it, which here backtracks past the limit; with $matches,
        // the empty match stands.
        $regex = '/(?:|(?:a+)+$)/';
        $subject = str_repeat('a', 17).'!';
        $engine = new PcreEngine();

        $before = (string) ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        try {
            $bare = @preg_match($engine->prepare($regex), $subject);
            $withMatches = @preg_match($engine->prepare($regex), $subject, $matches);
        } finally {
            ini_set('pcre.backtrack_limit', $before);
        }

        $this->assertSame([false, 1], [$bare, $withMatches]);
        $this->assertNull($engine->answer('preg_match', $regex, $subject, [], new PcreLimits(100_000, 10_000)));
        $this->assertSame(serialize(['']), $engine->answer('preg_match', $regex, $subject, ['matches' => true], new PcreLimits(100_000, 10_000)));
    }

    #[Test]
    public function test_a_pattern_with_no_delimiter_left_runs_without_the_jit(): void
    {
        // Every other delimiter stands in the body: the JIT is turned off
        // for the call instead, and put back after it.
        $regex = "_a\\Q\x01#~%!@;,)]}>\\E_";
        $subject = "a\x01#~%!@;,)]}>";
        $before = ini_get('pcre.jit');

        $this->assertSame(serialize(preg_match($regex, $subject)), (new PcreEngine())->answer('preg_match', $regex, $subject));
        $this->assertSame($before, ini_get('pcre.jit'));
    }

    #[Test]
    public function test_replacement_replay_refuses_unbounded_expansion(): void
    {
        $engine = new PcreEngine();
        $this->assertNull($engine->answer('preg_replace', '/a/', str_repeat('a', 4096), ['replacement' => str_repeat('x', 4096)]));
        $this->assertNull($engine->answer('preg_replace', '/(?=(a+))/', str_repeat('a', 120), ['replacement' => str_repeat('$1', 100)]));
        $this->assertNull($engine->answer('preg_replace', '//', '', ['replacement' => str_repeat('x', 1_048_577)]));
        $this->assertNull($engine->answer('preg_replace', '/a/', str_repeat('a', 1_048_577)));
    }

    #[Test]
    public function test_bounded_replacements_preserve_native_answers(): void
    {
        $engine = new PcreEngine();
        $subject = str_repeat('a', 120);
        foreach (['$1', '${1}', '\\1', '\\\\1'] as $replacement) {
            $this->assertSame(serialize(preg_replace('/(?=(a+))/', $replacement, $subject)), $engine->answer('preg_replace', '/(?=(a+))/', $subject, ['replacement' => $replacement]));
        }
        $replacement = str_repeat('x', 4096);
        $this->assertSame(serialize(preg_replace('/a/', $replacement, $subject, 1)), $engine->answer('preg_replace', '/a/', $subject, ['replacement' => $replacement, 'limit' => 1]));
        $this->assertSame(serialize($subject), $engine->answer('preg_replace', '/a/', $subject, ['replacement' => $replacement, 'limit' => 0]));
        $this->assertSame(serialize('xxx'), $engine->answer('preg_replace', '/(?:|a)/', 'a', ['replacement' => 'x']));
    }

    /**
     * What the callback of preg_replace_callback() receives for each match,
     * with where the match stands in the subject.
     *
     * @return list<array{array<array-key, string>, int}>
     */
    private static function callbackSees(string $regex, string $subject, int $limit): array
    {
        $seen = [];
        $replaced = (string) preg_replace_callback($regex, static function (array $match) use (&$seen): string {
            $seen[] = $match;

            return "\0";
        }, $subject, $limit);

        // Each match became one NUL: its position, shifted back by what the
        // earlier matches lost, is where it stood.
        $sees = [];
        $shift = 0;
        $from = 0;
        foreach ($seen as $match) {
            $at = (int) strpos($replaced, "\0", $from);
            $sees[] = [$match, $at + $shift];
            $shift += \strlen($match[0]) - 1;
            $from = $at + 1;
        }

        return $sees;
    }
}
