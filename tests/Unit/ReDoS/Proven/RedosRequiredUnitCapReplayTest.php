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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\Confirmation;
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\Internal\Backtrack\WitnessReplayer;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 looks for the code unit a match requires before it tries an
 * anchored pattern only on a subject shorter than 5,000 code units: a
 * witness without that unit is rejected at once below, and backtracks
 * past it. The replay goes past it too.
 *
 * Engine: PHP 8.4.26 / PCRE2 10.49, pcre.jit 0, pcre.backtrack_limit
 * 100000 (the confirmation's default).
 */
final class RedosRequiredUnitCapReplayTest extends TestCase
{
    private const PATTERN = '#^<iframe(?:"[^"]*"|\'[^\']*\'|[^>])*>#i';

    private string|false $backtrackLimit = false;

    private string|false $jit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        $this->jit = ini_get('pcre.jit');
        ini_set('pcre.backtrack_limit', '100000');
        ini_set('pcre.jit', '0');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
        if (false !== $this->jit) {
            ini_set('pcre.jit', $this->jit);
        }
    }

    #[Test]
    public function test_engine_fact_the_witness_backtracks_only_past_the_anchored_cap(): void
    {
        $witness = (new RedosAnalyzer())->analyze(self::PATTERN)->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);

        // "<IFRAME" . "\"\"\"\"" x n . "!<iframe": 4,999 bytes at n = 1,246,
        // no ">" in it: rejected before any backtracking; 5,003 at 1,247.
        $this->assertSame(0, @preg_match(self::PATTERN, $witness->build(1246)));
        $this->assertFalse(@preg_match(self::PATTERN, $witness->build(1247)));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    #[Test]
    public function test_confirmed_replay_goes_past_the_required_code_unit_cap(): void
    {
        $analysis = (new RedosAnalyzer())->analyze(self::PATTERN, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertTrue($analysis->replayed);
        $this->assertInstanceOf(Confirmation::class, $analysis->confirmation);
        $this->assertTrue($analysis->confirmation->confirmed);
        $this->assertGreaterThanOrEqual(5000, $analysis->confirmation->samples[\count($analysis->confirmation->samples) - 1]->inputLength);
    }

    /**
     * A replay that runs out of work before the cap stops there: no sample
     * past it, the verdict not reproduced.
     */
    #[Test]
    public function test_replay_out_of_work_stops_before_the_cap(): void
    {
        $analysis = (new RedosAnalyzer())->analyze(
            '/^(-?[a-z]+){1,7}\.json$/i',
            RedosSeverity::Low,
            RedosMode::Confirmed,
            new ConfirmationOptions(backtrackLimit: 50_000_000),
        );

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertFalse($analysis->replayed);
        $confirmation = $analysis->confirmation;
        $this->assertInstanceOf(Confirmation::class, $confirmation);
        foreach ($confirmation->samples as $sample) {
            $this->assertLessThan(5000, $sample->inputLength);
        }
    }

    /**
     * The replay also runs the call without $matches, which PHP retries
     * after an empty match: it reproduces the same failure.
     */
    #[Test]
    public function test_replay_runs_the_call_without_matches(): void
    {
        [$confirmation] = (new WitnessReplayer())->replay(
            '/(a+)+$/',
            [new RedosWitness('', 'a', '!', false)],
            new ConfirmationOptions(),
            true,
        );

        $this->assertTrue($confirmation->confirmed);
        $this->assertSame(Confirmation::WITHOUT_MATCHES, $confirmation->note);
    }
}
