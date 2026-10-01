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
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the proven path: the options' limits at their boundaries,
 * every delimiter shape, and the flags that change what the witness reads.
 *
 * Engine figures: PHP 8.4.26 / PCRE2 10.49, pcre.backtrack_limit 1000000,
 * JIT on and off alike.
 */
final class RedosEdgeCaseWalkTest extends TestCase
{
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

    /**
     * One state is not enough for any pattern: the build stops at once,
     * and the result says so on the facade's analyzer too.
     */
    #[Test]
    public function test_one_state_budget_through_an_analyzer_built_with_a_parser(): void
    {
        $analyzer = new RedosAnalyzer(Regex::create()->parser(), [], options: new RedosOptions(maxStates: 1));

        $analysis = $analyzer->analyze('/^a+$/');

        $this->assertSame(RedosProof::BudgetExceeded, $analysis->proof);
        $this->assertSame(RedosComplexity::Unknown, $analysis->complexity);
        $this->assertNull($analysis->witness);
        $this->assertFalse($analysis->isProvenSafe());
    }

    /**
     * The cutoff is inclusive: a bound equal to it is unrolled, one past it
     * is abstracted. {1,5} fails at n=20 and {1,6} at n=20 on a…a!.
     */
    #[Test]
    public function test_bounded_repeat_cutoff_option_is_inclusive(): void
    {
        $analyzer = new RedosAnalyzer(options: new RedosOptions(boundedRepeatCutoff: 5));

        $atCutoff = $analyzer->analyze('/(a{1,5})+$/');
        $pastCutoff = $analyzer->analyze('/(a{1,6})+$/');

        $this->assertSame(RedosComplexity::Exponential, $atCutoff->complexity);
        $this->assertSame([], $atCutoff->abstractions);
        $this->assertSame(RedosComplexity::Exponential, $pastCutoff->complexity);
        $this->assertSame(['{1,6} at offset 1 analysed as {1,}'], $pastCutoff->abstractions);
    }

    /**
     * A cutoff of zero abstracts every brace bound; ^a{0,}b{2,}$ stays
     * linear (one way to read any input).
     */
    #[Test]
    public function test_bounded_repeat_cutoff_zero_abstracts_every_brace_bound(): void
    {
        $analysis = (new RedosAnalyzer(options: new RedosOptions(boundedRepeatCutoff: 0)))->analyze('/^a{0,1}b{2,3}$/');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity);
        $this->assertSame(['{0,1} at offset 1 analysed as {0,}', '{2,3} at offset 7 analysed as {2,}'], $analysis->abstractions);
    }

    /**
     * Under /u a witness is valid UTF-8: an invalid one would make
     * preg_match() fail with "Malformed UTF-8" and pass for a reproduction.
     * Each row fails with "Backtrack limit exhausted" (PCRE2 10.49).
     */
    #[Test]
    #[DataProvider('provideUnicodeWitnesses')]
    public function test_unicode_witness_is_valid_utf8_and_fails_on_backtracking(string $pattern): void
    {
        $witness = (new RedosAnalyzer())->analyze($pattern)->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $pattern);
        $this->assertTrue($witness->unicode, $pattern);

        $error = null;
        for ($n = 1; null === $error && $n <= 64; $n++) {
            $subject = $witness->build($n);
            $this->assertTrue(mb_check_encoding($subject, 'UTF-8'), $pattern.' n='.$n);
            if (false === @preg_match($pattern, $subject)) {
                $error = preg_last_error();
            }
        }

        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, $error, $pattern);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnicodeWitnesses(): iterable
    {
        yield 'range around the surrogates' => ['/([\x{D7FF}-\x{E000}]+)+$/u'];
        yield 'every non-ascii code point' => ['/([^\x00-\x7F]+)+$/u'];
        yield 'last code point' => ['/(\x{10FFFF}+)+$/u'];
        yield 'negated class' => ['/([^a]+)+$/u'];
    }

    #[Test]
    #[DataProvider('provideDelimitersAndFlags')]
    public function test_proven_verdict_does_not_depend_on_the_delimiter(string $pattern, string $pump): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $pattern);
        $this->assertSame(RedosSeverity::Critical, $analysis->severity, $pattern);
        $this->assertInstanceOf(RedosWitness::class, $analysis->witness, $pattern);
        $this->assertSame($pump, $analysis->witness->pump, $pattern);

        // The witness reproduces on the engine as published.
        $failing = null;
        for ($n = 1; null === $failing && $n <= 64; $n++) {
            if (false === @preg_match($pattern, $analysis->witness->build($n))) {
                $failing = $n;
            }
        }
        $this->assertNotNull($failing, $pattern.': '.$analysis->witness->render().' does not reproduce');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDelimitersAndFlags(): iterable
    {
        // Each fails at n=19 on its pump followed by "!".
        yield 'hash delimiter' => ['#(a+)+$#', 'a'];
        yield 'percent delimiter' => ['%(a+)+$%', 'a'];
        yield 'brace delimiters' => ['{(a+)+$}', 'a'];
        yield 'tilde delimiter, x mode with whitespace' => ['~( a+ )+ $~x', 'a'];
        // Under /i the class is {A, a}: the smallest printable member is "A"; A…A! fails at n=19.
        yield 'upper case under i' => ['/(A+)+$/i', 'A'];
        yield 'lower case under i' => ['/(a+)+$/i', 'A'];
    }
}
