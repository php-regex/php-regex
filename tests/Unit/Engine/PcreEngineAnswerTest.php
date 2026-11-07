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
 * answers of the same call compare as strings.
 */
final class PcreEngineAnswerTest extends TestCase
{
    /**
     * @param 'preg_match'|'preg_match_all'|'preg_replace'|'preg_replace_callback'|'preg_split'   $function
     * @param array{matches?: bool, flags?: int, offset?: int, limit?: int, replacement?: string} $arguments
     */
    #[Test]
    #[DataProvider('provideAnswers')]
    public function test_each_call_answers_as_php_does(string $function, string $regex, string $subject, array $arguments, mixed $expected): void
    {
        $this->assertSame(serialize($expected), (new PcreEngine())->answer($function, $regex, $subject, $arguments));
        $this->assertSame(\PREG_NO_ERROR, preg_last_error());
    }

    /**
     * @return iterable<string, array{function: 'preg_match'|'preg_match_all'|'preg_replace'|'preg_replace_callback'|'preg_split', regex: string, subject: string, arguments: array<string, bool|int|string>, expected: mixed}>
     */
    public static function provideAnswers(): iterable
    {
        yield 'preg_match, the answer' => ['function' => 'preg_match', 'regex' => '/a+/', 'subject' => 'baa', 'arguments' => [], 'expected' => 1];
        yield 'preg_match, the matches' => ['function' => 'preg_match', 'regex' => '/a+/', 'subject' => 'baa', 'arguments' => ['matches' => true], 'expected' => ['aa']];
        yield 'preg_match, offsets and nulls' => ['function' => 'preg_match', 'regex' => '/(x)?(a)/', 'subject' => 'ba', 'arguments' => ['matches' => true, 'flags' => \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL], 'expected' => [['a', 1], [null, -1], ['a', 1]]];
        yield 'preg_match, from an offset' => ['function' => 'preg_match', 'regex' => '/a/', 'subject' => 'ab', 'arguments' => ['matches' => true, 'offset' => 1], 'expected' => []];
        yield 'preg_match_all, the count' => ['function' => 'preg_match_all', 'regex' => '/a/', 'subject' => 'aba', 'arguments' => [], 'expected' => 2];
        yield 'preg_match_all, the matches' => ['function' => 'preg_match_all', 'regex' => '/a/', 'subject' => 'aba', 'arguments' => ['matches' => true], 'expected' => [['a', 'a']]];
        yield 'preg_replace' => ['function' => 'preg_replace', 'regex' => '/a/', 'subject' => 'aba', 'arguments' => ['replacement' => '_', 'limit' => 1], 'expected' => '_ba'];
        yield 'preg_replace_callback' => ['function' => 'preg_replace_callback', 'regex' => '/(x)?a/', 'subject' => 'ba', 'arguments' => [], 'expected' => [[['a'], 1]]];
        yield 'preg_replace_callback, limited' => ['function' => 'preg_replace_callback', 'regex' => '/a/', 'subject' => 'aa', 'arguments' => ['limit' => 1], 'expected' => [[['a'], 0]]];
        yield 'preg_split' => ['function' => 'preg_split', 'regex' => '/(,)/', 'subject' => 'a,,b', 'arguments' => ['flags' => \PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE], 'expected' => ['a', ',', ',', 'b']];
    }

    #[Test]
    public function test_a_call_the_engine_gives_up_on_answers_nothing(): void
    {
        $engine = new PcreEngine();
        $before = ini_get('pcre.backtrack_limit');

        $this->assertNull($engine->answer('preg_match', '/(a+)+$/', str_repeat('a', 17).'!', [], new PcreLimits(100_000, 10_000)));
        $this->assertNull($engine->answer('preg_match', '/a/u', "\xff"));
        $this->assertSame($before, ini_get('pcre.backtrack_limit'));
        // The last error is the call's, as after any call of the engine.
        $this->assertSame(\PREG_BAD_UTF8_ERROR, preg_last_error());
    }

    #[Test]
    public function test_a_pattern_with_no_delimiter_left_runs_without_the_jit(): void
    {
        // Every other delimiter stands in the body: the JIT is turned off
        // for the call instead, and put back after it.
        $regex = "_a\\Q\x01#~%!@;,)]}>\\E_";
        $before = ini_get('pcre.jit');

        $this->assertSame(serialize(1), (new PcreEngine())->answer('preg_match', $regex, "a\x01#~%!@;,)]}>"));
        $this->assertSame($before, ini_get('pcre.jit'));
    }
}
