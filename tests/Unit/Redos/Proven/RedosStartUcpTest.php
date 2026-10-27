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
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern that opens with "(*UCP)" alone is in the prover's model, every
 * set read under it: without /u, "\w", "\d", "\s", the POSIX classes and
 * caseless matching then follow the Unicode properties of the bytes past
 * ASCII. Any other start option, or a backtracking verb, keeps the pattern
 * outside the model.
 *
 * Each row carries its engine fact, measured in the same test: the steps
 * one match attempt needs ("(*NO_JIT)", "(*NO_START_OPT)", the smallest
 * pcre.backtrack_limit the call passes under) on 4 then 8 pump units and
 * the suffix. Engine: PHP 8.4.27, PCRE2 10.49; the same verdicts on the
 * official images of PHP 8.1 to 8.5 (PCRE2 10.39 to 10.44), 25 units:
 * "/(*UCP)(?:\w|\xC9)+$/" and "/(*UCP)^(?:\xE9|\xC9)+$/i" exhaust the
 * backtrack limit, "/(*UCP)^(?:\W|\xC9)+$/" does not, while
 * "/^(?:\W|\xC9)+$/" does.
 */
final class RedosStartUcpTest extends TestCase
{
    /**
     * The engine-step ratio, 8 units over 4, above which a row is exponential.
     */
    private const EXPONENTIAL_RATIO = 3;

    #[Test]
    #[DataProvider('provideUcpRows')]
    public function test_a_leading_ucp_reads_every_set_under_it(string $pattern, string $unit, string $suffix, bool $exponential): void
    {
        $short = self::steps($pattern, str_repeat($unit, 4).$suffix);
        $long = self::steps($pattern, str_repeat($unit, 8).$suffix);
        $this->assertSame($exponential, $long / $short > self::EXPONENTIAL_RATIO, \sprintf('Oracle disagrees with the row %s: %d -> %d steps.', $pattern, $short, $long));

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = \sprintf('%s is "%s", the engine says %s', $pattern, $analysis->headline(), $exponential ? 'exponential' : 'linear');

        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame($exponential ? RedosComplexity::Exponential : RedosComplexity::Linear, $analysis->complexity, $message);
        $this->assertSame(!$exponential, $analysis->isProvenSafe(), $message);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, suffix: string, exponential: bool}>
     */
    public static function provideUcpRows(): iterable
    {
        // Engine: 64 -> 1 024 steps; É is a word character under UCP.
        yield 'word class and E acute overlap' => ['pattern' => '/(*UCP)(?:\w|\xC9)+$/', 'unit' => "\xC9", 'suffix' => '!', 'exponential' => true];
        // Engine: the same, the option written twice.
        yield 'the option written twice' => ['pattern' => '/(*UCP)(*UCP)(?:\w|\xC9)+$/', 'unit' => "\xC9", 'suffix' => '!', 'exponential' => true];
        // Engine: 64 -> 1 024; é and É fold under UCP, not without it.
        yield 'caseless E acute folds' => ['pattern' => '/(*UCP)^(?:\xE9|\xC9)+$/i', 'unit' => "\xC9", 'suffix' => '!', 'exponential' => true];
        // Engine: ª is a letter, so a word character, under UCP.
        yield 'feminine ordinal is a word character' => ['pattern' => '/(*UCP)^(?:\w|\xAA)+$/', 'unit' => "\xAA", 'suffix' => '!', 'exponential' => true];
        // Engine: the no-break space is \s under UCP.
        yield 'no-break space is a space' => ['pattern' => '/(*UCP)^(?:\s|\xA0)+$/', 'unit' => "\xA0", 'suffix' => '!', 'exponential' => true];
        // Engine: linear; É leaves \W under UCP, where without it the same
        // loop is exponential.
        yield 'E acute is no longer a non-word character' => ['pattern' => '/(*UCP)^(?:\W|\xC9)+$/', 'unit' => "\xC9", 'suffix' => 'a', 'exponential' => false];
        // Engine: linear; ² is no decimal digit under UCP (No, not Nd).
        yield 'superscript two is no digit' => ['pattern' => '/(*UCP)^(?:\d|\xB2)+$/', 'unit' => "\xB2", 'suffix' => '!', 'exponential' => false];
        // Engine: linear.
        yield 'one word run' => ['pattern' => '/(*UCP)^\w+$/', 'unit' => "\xC9", 'suffix' => '!', 'exponential' => false];
    }

    /**
     * The witness of a proven exponential row is replayed on the engine.
     */
    #[Test]
    public function test_the_witness_under_ucp_replays(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(*UCP)(?:\w|\xC9)+$/', RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $analysis->headline());
        $this->assertTrue($analysis->replayed, $analysis->headline());
    }

    /**
     * Only "(*UCP)" enters the model: another start option beside it, or a
     * backtracking verb after it, leaves the pattern to the heuristics, as
     * before.
     */
    #[Test]
    #[DataProvider('provideOutOfModelRows')]
    public function test_other_start_options_and_verbs_stay_outside_the_model(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $pattern.' is "'.$analysis->headline().'"');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOutOfModelRows(): iterable
    {
        yield 'a newline convention after it' => ['pattern' => '/(*UCP)(*CR)a+$/'];
        yield 'a newline convention before it' => ['pattern' => '/(*ANY)(*UCP)a+$/'];
        yield 'UTF before it' => ['pattern' => '/(*UTF)(*UCP)\w+$/'];
        yield 'a match limit after it' => ['pattern' => '/(*UCP)(*LIMIT_MATCH=1000)a+$/'];
        yield 'a match limit before it' => ['pattern' => '/(*LIMIT_MATCH=1000)(*UCP)a+$/'];
        yield 'no JIT before it' => ['pattern' => '/(*NO_JIT)(*UCP)a+$/'];
        yield 'a commit after it' => ['pattern' => '/(*UCP)a(*COMMIT)a+$/'];
        yield 'a skip after it' => ['pattern' => '/(*UCP)a+(*SKIP)b/'];
        yield 'a fail after it' => ['pattern' => '/(*UCP)a+(*FAIL)|b/'];
    }

    /**
     * The smallest backtrack limit one match attempt runs under: in the
     * interpreter, start optimizations off.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $limit = ini_get('pcre.backtrack_limit');
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));

        try {
            $low = 1;
            $high = 1 << 22;
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
