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

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosConfidence;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A witness whose rejecting suffix is the end of the input: "a" x n
 * rejects (no "b" follows), but PCRE gives up at once on a subject without
 * the required "b". The replay must append a character outside the pumped
 * class and the required literal, and publish that witness.
 *
 * Engine (PCRE2 10.49, JIT off, pcre.backtrack_limit 100000, the default of
 * the confirmed replay): str_repeat('a', 30).'!b' fails with "Backtrack
 * limit exhausted"; str_repeat('a', 30) and str_repeat('a', 64) do not.
 */
final class RedosEmptySuffixReplayTest extends TestCase
{
    private const PATTERN = '/(a{1,20})+(b{1,30})+$/';

    private const MAX_INPUT_LENGTH = 64;

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
    public function test_confirmed_replay_of_an_empty_suffix_witness_reproduces(): void
    {
        $analysis = (new RedosAnalyzer())->analyze(self::PATTERN, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity);
        $this->assertTrue($analysis->replayed);
        $this->assertSame(RedosConfidence::High, $analysis->confidenceLevel());

        // The published witness is the one replayed: built and re-run as it stands.
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness);
        $reproduced = false;
        for ($n = 1; !$reproduced && \strlen($witness->build($n)) <= self::MAX_INPUT_LENGTH; $n++) {
            $reproduced = false === @preg_match(self::PATTERN, $witness->build($n));
        }
        $this->assertTrue($reproduced, $witness->render().' does not reproduce');
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }

    #[Test]
    public function test_engine_fact_the_bare_witness_does_not_reproduce(): void
    {
        $this->assertSame(0, preg_match(self::PATTERN, str_repeat('a', 30)));
        $this->assertSame(0, preg_match(self::PATTERN, str_repeat('a', self::MAX_INPUT_LENGTH)));
        $this->assertFalse(@preg_match(self::PATTERN, str_repeat('a', 30).'!b'));
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
    }
}
