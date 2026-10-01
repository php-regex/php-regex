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
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Zero-width checks after an ambiguous loop.
 *
 * A lookaround the model does not evaluate may fail: a run that reaches the
 * end through one is not a success, so the search keeps backtracking and the
 * class is the loop's. \b and \B are evaluated exactly from the word class of
 * the characters on each side.
 *
 * Engine figures: PHP 8.4.26 / PCRE2 10.49, pcre.backtrack_limit 1000000,
 * "fails at n" is the smallest pump count for which preg_match() returns
 * false with "Backtrack limit exhausted", JIT on and off alike.
 */
final class RedosZeroWidthSoundnessTest extends TestCase
{
    private const MAX_INPUT_LENGTH = 64;

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

    #[Test]
    #[DataProvider('provideVulnerablePatterns')]
    public function test_zero_width_check_after_an_ambiguous_loop_is_proven_exponential(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity, $pattern);
        $this->assertNotNull($analysis->witness, $pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern);
    }

    /**
     * The witness each pattern publishes must make the engine fail once
     * replayed: the lookaround and \b cases are not over-approximation
     * artefacts.
     */
    #[Test]
    #[DataProvider('provideVulnerablePatterns')]
    public function test_confirmed_mode_replays_the_zero_width_witness(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed);

        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $this->assertTrue($analysis->replayed, $pattern);
        $this->assertSame(RedosConfidence::High, $analysis->confidenceLevel(), $pattern);

        $witness = $analysis->witness;
        $this->assertNotNull($witness, $pattern);
        $reproduced = false;
        for ($n = 1; !$reproduced && \strlen($witness->build($n)) <= self::MAX_INPUT_LENGTH; $n++) {
            $reproduced = false === @preg_match($pattern, $witness->build($n));
        }
        $this->assertTrue($reproduced, $pattern.': the published witness does not reproduce');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideVulnerablePatterns(): iterable
    {
        foreach (self::provideEngineFacts() as $name => [$pattern, , , , $fails]) {
            if ($fails) {
                yield $name => [$pattern];
            }
        }
    }

    #[Test]
    #[DataProvider('provideSafePatterns')]
    public function test_exact_word_boundary_keeps_a_safe_verdict(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertSame(RedosSeverity::Safe, $analysis->severity, $pattern);
        $this->assertNull($analysis->witness, $pattern);
        $this->assertTrue($analysis->isProvenSafe(), $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSafePatterns(): iterable
    {
        foreach (self::provideEngineFacts() as $name => [$pattern, , , , $fails]) {
            if (!$fails) {
                yield $name => [$pattern];
            }
        }
    }

    /**
     * Grounds every row above on the running engine: the CI matrix fails
     * here first if a PCRE2 release disagrees.
     */
    #[Test]
    #[DataProvider('provideEngineFacts')]
    public function test_engine_fact(string $pattern, string $prefix, string $pump, string $suffix, bool $fails): void
    {
        $failing = null;
        for ($n = 1; null === $failing && \strlen($prefix.str_repeat($pump, $n).$suffix) <= self::MAX_INPUT_LENGTH; $n++) {
            if (false === @preg_match($pattern, $prefix.str_repeat($pump, $n).$suffix)) {
                $failing = $n;
                $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), $pattern);
            }
        }

        $this->assertSame($fails, null !== $failing, $pattern);
    }

    /**
     * @return iterable<string, array{string, string, string, string, bool}>
     */
    public static function provideEngineFacts(): iterable
    {
        // Lookarounds after the loop: the model ignores their constraint, the engine does not.
        yield 'positive lookahead, a…a! fails at n=19' => ['/(a+)+(?=b)/', '', 'a', '!', true];
        yield 'negative lookahead on any character, a…a! fails at n=19' => ['/(a+)+(?!.)/', '', 'a', '!', true];
        yield 'negative lookahead on a class, a…a! fails at n=19' => ['/(a+)+(?![a!])/', '', 'a', '!', true];
        yield 'positive lookbehind, a…a! fails at n=19' => ['/(a+)+(?<=b)/', '', 'a', '!', true];
        yield 'negative lookbehind, a…a! fails at n=19' => ['/(a+)+(?<!a)/', '', 'a', '!', true];
        // \b evaluated exactly: after a word run it fails only before another word character.
        yield 'word boundary after a word loop, a…ab fails at n=19' => ['/(a+)+\b/', '', 'a', 'b', true];
        // "-" is not a word character: \b never holds inside or after the run.
        yield 'word boundary after a non-word loop, -…-! fails at n=19' => ['/(-+)+\b/', '', '-', '!', true];
        // U+2014 is not \w under /u (PHP sets UCP with /u): —…—! fails at n=19.
        yield 'word boundary after an em dash loop under u' => ['/(—+)+\b/u', '', '—', '!', true];
        yield 'word boundary before a word loop, a…a! fails at n=19' => ['/\b(\w+\s?)+$/', '', 'a', '!', true];
        // End anchors as PCRE reads them.
        yield 'dollar under D, a…a\n fails at n=19' => ['/(a+)+$/D', '', 'a', "\n", true];
        yield 'absolute end, a…a\n fails at n=19' => ['/(a+)+\z/', '', 'a', "\n", true];
        yield 'end before a final newline, a…a\n\n fails at n=19' => ['/(a+)+\Z/', '', 'a', "\n\n", true];

        // Never fails up to 64 bytes: precision of the exact \b.
        yield 'word run between boundaries, never fails' => ['/\ba+\b/', '', 'a', '!', false];
        // a+ must end on a boundary, so it takes the whole run; with \b read as nothing it would be (a+)+$.
        yield 'boundary inside the loop, never fails' => ['/(a+\b)+$/', '', 'a', '!', false];
        // \B holds between two a: the first backtrack step matches.
        yield 'non-boundary after a word loop, never fails' => ['/(a+)+\B/', '', 'a', '!', false];
        // é is \w under /u: \b holds before "!" (é…é! never fails), but not before
        // another word character, so é…é0 fails at n=19 exactly like a…ab.
        yield 'word boundary after an e acute loop under u, é…é0 fails at n=19' => ['/(é+)+\b/u', '', 'é', '0', true];
    }
}
