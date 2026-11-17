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

use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\ConfirmationRunner;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedosConfirmationRunnerTest extends TestCase
{
    /**
     * Oracle: without the JIT, "^(a+)+$" over twenty-one "a" and a "!" fails
     * with no error under a billion backtracks, and takes tens of
     * milliseconds doing so: far past the one millisecond allowed here. The
     * run stops at the first length, before the longer one.
     */
    #[Test]
    public function test_a_run_slower_than_the_timeout_stops_timed_out_without_confirming(): void
    {
        $limit = (string) \ini_get('pcre.backtrack_limit');
        \ini_set('pcre.backtrack_limit', '1000000000');

        try {
            $oracle = preg_match('/(*NO_JIT)^(a+)+$/', str_repeat('a', 21).'!');
        } finally {
            \ini_set('pcre.backtrack_limit', $limit);
        }
        $this->assertSame(0, $oracle, 'The oracle matches, or gives up.');

        $confirmation = (new ConfirmationRunner())->confirm(
            '/^(a+)+$/',
            new RedosAnalysis(RedosSeverity::High, 8),
            new ConfirmationOptions(
                minInputLength: 22,
                maxInputLength: 23,
                steps: 2,
                iterations: 3,
                timeoutMs: 1.0,
                backtrackLimit: 1_000_000_000,
                recursionLimit: 1_000_000,
            ),
        );

        $this->assertTrue($confirmation->timedOut);
        $this->assertFalse($confirmation->confirmed);
        $this->assertNull($confirmation->evidence);
        $this->assertNull($confirmation->error);
        $this->assertCount(1, $confirmation->samples);
        $this->assertSame(22, $confirmation->samples[0]->inputLength);
        $this->assertGreaterThan(1.0, $confirmation->samples[0]->durationMs);
        $this->assertNull($confirmation->samples[0]->pregErrorCode);
    }

    /**
     * PCRE2 looks for the character every match requires before it tries
     * the pattern, and rejects a subject without it at once: the runs end
     * with it, after the character that fails the match. Oracle (JIT off,
     * the backtrack limit of the options): fifty letters then "!" are
     * rejected without an error, and give up at the limit with the ":"
     * after them, anchored or not.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatternsRequiringACharacter(): iterable
    {
        yield 'anchored, a colon before a reference' => ['pattern' => '/^(x)?(?:\w+\s?)+:\1/'];
        yield 'unanchored, a colon before a reference' => ['pattern' => '/(x)?(?:\w+\s?)+:\1/'];
    }

    #[Test]
    #[DataProvider('providePatternsRequiringACharacter')]
    public function test_a_run_holds_the_character_every_match_requires(string $pattern): void
    {
        $limit = (string) \ini_get('pcre.backtrack_limit');
        \ini_set('pcre.backtrack_limit', '100000');

        try {
            $without = @preg_match(PcreInterpreter::pattern($pattern), str_repeat('a', 50).'!');
            $withoutError = preg_last_error();
            $with = @preg_match(PcreInterpreter::pattern($pattern), str_repeat('a', 50).'!:');
            $withError = preg_last_error();
        } finally {
            \ini_set('pcre.backtrack_limit', $limit);
        }
        $this->assertSame([0, \PREG_NO_ERROR], [$without, $withoutError], 'Oracle: rejected at once without the colon.');
        $this->assertSame([false, \PREG_BACKTRACK_LIMIT_ERROR], [$with, $withError], 'Oracle: gives up with it.');

        $confirmation = (new ConfirmationRunner())->confirm($pattern, new RedosAnalysis(RedosSeverity::Critical, 10));

        $this->assertTrue($confirmation->confirmed);
        $this->assertSame('backtrack_limit', $confirmation->evidence);

        $analysis = Regex::create(['cache' => null])->redos($pattern, mode: RedosMode::Confirmed);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof);
        $this->assertTrue($analysis->isConfirmed());
    }

    /**
     * A pattern without delimiters confirms nothing: its flags read as none,
     * and each run reports the compile error PHP gives (preg_match('abc', '')
     * is false, preg_last_error() 1).
     */
    #[Test]
    public function test_a_pattern_without_delimiters_runs_with_no_flags_and_confirms_nothing(): void
    {
        $confirmation = (new ConfirmationRunner())->confirm(
            'abc',
            new RedosAnalysis(RedosSeverity::High, 8),
            new ConfirmationOptions(minInputLength: 2, maxInputLength: 3, steps: 2, iterations: 1, timeoutMs: 50.0),
        );

        $this->assertFalse($confirmation->confirmed);
        $this->assertCount(2, $confirmation->samples);
        $this->assertSame(\PREG_INTERNAL_ERROR, $confirmation->samples[0]->pregErrorCode);
    }
}
