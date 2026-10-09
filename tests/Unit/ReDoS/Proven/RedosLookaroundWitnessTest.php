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
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosWitness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A witness through a lookaround the model leaves undecided, checked on the
 * engine before it proves a superlinear class.
 *
 * Engine: PHP 8.4.26 / PCRE2 10.49, pcre.jit 0. The timings quoted are
 * preg_match() on the witness, and stay flat past both of PCRE2's caps on
 * its required code unit search (5,000 code units anchored, 5,000,000
 * unanchored) where a row says linear.
 */
final class RedosLookaroundWitnessTest extends TestCase
{
    private string|false $jit = false;

    protected function setUp(): void
    {
        $this->jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
    }

    protected function tearDown(): void
    {
        if (false !== $this->jit) {
            ini_set('pcre.jit', $this->jit);
        }
    }

    #[Test]
    #[DataProvider('provideWitnessesTheEngineMatches')]
    public function test_lookaround_witness_the_engine_matches_proves_no_superlinear_class(string $pattern, string $subject): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse(
            RedosProof::Proven === $analysis->proof && RedosComplexity::Linear !== $analysis->complexity,
            \sprintf('%s is "%s" with the witness %s', $pattern, $analysis->headline(), $analysis->witness?->render() ?? 'none'),
        );
    }

    #[Test]
    #[DataProvider('provideWitnessesTheEngineMatches')]
    public function test_engine_fact_the_former_witness_matches(string $pattern, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
    }

    /**
     * Each pattern with the witness it was proven with, at two pumps: the
     * engine matches it, and the same witness stays flat however long.
     *
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideWitnessesTheEngineMatches(): iterable
    {
        // 0.03 ms at 6,000 bytes, 2.6 ms at 5,000,100: any space given back
        // lets [^;]+ match.
        yield 'lookbehind, then an optional last character' => [
            'pattern' => '/(?<![a-z-])background-color\s*:\s*[^;]+;?/i',
            'subject' => 'BACKGROUND-COLOR:  !background-color',
        ];
        // 0.03 ms at 6,000 bytes, 3.1 ms at 5,000,100.
        yield 'lookbehind and negative lookahead around a URL' => [
            'pattern' => '/(?<![a-zA-Z0-9.-])https?:\/\/[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}[a-zA-Z0-9\/?=&%_.~+#-]*(?![a-zA-Z0-9._~+#-])/',
            'subject' => 'http://-.AA-.AA!',
        ];
        // 0.03 ms at 6,000 bytes, 3.1 ms at 5,000,100.
        yield 'negative lookahead in the middle' => [
            'pattern' => '/\s*SET\s+NAMES\s+utf8(?!mb4)(?:\s*;|\s+COLLATE\s+[\w_]+\s*;?)\s*/i',
            'subject' => 'SET NAMES UTF8 COLLATE 0  !utf8',
        ];
        // 0.03 ms at 6,000 bytes, 2.8 ms at 5,000,100.
        yield 'negative lookahead after a literal' => [
            'pattern' => '/@import (?!url)[\'\"]{0,1}(\S*?\.css(\?[^\s\'\"]+)?)[\'\"]{0,1}\;?/si',
            'subject' => '@IMPORT !.CSS?!.CSS?".css',
        ];
        // 0.05 ms at 6,000 bytes, 5.0 ms at 5,000,100.
        yield 'negative lookahead after the first character' => [
            'pattern' => '#<(?!!--|!\[)((?<start>/*\s*)((?<tagName>[\p{L}:]+)(?=[^\p{L}]|$|)|.+)[^\s"\'\p{L}>/=]*[^>]*)(?<closeTag>>)?#iusS',
            'subject' => '<//!',
        ];
        // Exponential: 0.00 ms at 5,100 bytes; the repeat matches at once.
        yield 'lookbehind before a repeated group' => [
            'pattern' => '~(?<![A-Za-z0-9])(?:[A-Za-z]:[\\\\/]|/)(?:[^\s:]+[\\\\/]?)+~u',
            'subject' => '/!!:',
        ];
        // Exponential: (\w+)+ with nothing after it matches the first word.
        yield 'lookbehind before a repeat with nothing after' => [
            'pattern' => '/(?<=\$)(\w+)+/i',
            'subject' => '$00!',
        ];
    }

    #[Test]
    #[DataProvider('provideLookbehindsAtTheAttemptStart')]
    public function test_lookbehind_at_the_attempt_start_leads_the_witness(string $pattern, string $witness): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Polynomial, $analysis->complexity, $pattern);
        $this->assertSame(2, $analysis->degree, $pattern);
        $this->assertInstanceOf(RedosWitness::class, $analysis->witness, $pattern);
        $this->assertSame($witness, $analysis->witness->render(), $pattern);
    }

    /**
     * Before, the witness started the subject, where the lookbehind fails:
     * flat on the engine. Led by the character the lookbehind asks for, the
     * same pump is quadratic.
     *
     * @return iterable<string, array{pattern: string, witness: string}>
     */
    public static function provideLookbehindsAtTheAttemptStart(): iterable
    {
        // "[" . "**" x n . "\n": 14 ms at n = 500, 55 ms at 1,000, 289 ms
        // at 2,000; without "[", 0.5 ms at 6,000 bytes.
        yield 'lookbehind on a bracket, in both alternatives' => [
            'pattern' => '/(?<=\[)(?:[^\r\n]*[?*][^\r\n]*)(?=\])|(?<=\[)(?:[^\r\n*?]+)(?=\])(?![^\[]*Comment=)/m',
            'witness' => '"[" . "**" x n . "\n"',
        ];
        // "\nSTARTXREF" . "\n" x n . "!%%EOF": 1.2 ms at n = 2,000, 5.1 ms
        // at 4,000, 35 ms at 8,000; without the newline, 0.03 ms at 12,000.
        yield 'lookbehind on a line break' => [
            'pattern' => '/(?<=[\r\n])startxref[\s]*[\r\n]+([0-9]+)[\s]*[\r\n]+%%EOF/i',
            'witness' => '"\nSTARTXREF" . "\n" x n . "!%%EOF"',
        ];
        // The lookbehind holds after a word character only when it is an
        // x: the witness carries it, or the attempt never reaches the loop.
        yield 'lookbehind on one word character' => [
            'pattern' => '/\B(?<=x)\w+\w+!/',
            'witness' => '"x" . "0" x n . "\"!"',
        ];
    }

    #[Test]
    #[DataProvider('provideLinearProofs')]
    public function test_lookaround_without_an_ambiguity_stays_a_linear_proof(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertTrue($analysis->isProvenSafe(), $pattern.': '.$analysis->headline());
    }

    /**
     * A pattern holding a lookaround but no ambiguity: the witness search
     * runs, finds nothing to check with the engine, and the proof stands.
     * The second row's lookaround can match no input at all.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideLinearProofs(): iterable
    {
        yield 'a lookahead between literals' => ['/x(?=y)z/'];
        yield 'a lookahead whose class is empty' => ['/a(?=[^\s\S])/'];
    }

    /**
     * Every attempt is pinned to the subject's start by \A, where the
     * lookbehind for a newline fails: the lead is tried, its context the
     * one after a newline, and no prefix start matches it — nothing is
     * witnessed, and the heuristics decide.
     */
    #[Test]
    public function test_lead_no_prefix_start_matches_stays_unwitnessed(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/(?m)^\A(?<=\n)\w+\w+!/');

        $this->assertFalse(RedosProof::Proven === $analysis->proof && RedosComplexity::Linear !== $analysis->complexity, $analysis->headline());
        $this->assertContains('ambiguity without witness at offset 14', $analysis->abstractions);
    }

    /**
     * An ambiguity no witness was found for, with no lookaround before it
     * to hold: the verdict stays unwitnessed, and the heuristics decide.
     */
    #[Test]
    public function test_unwitnessed_ambiguity_without_a_lookaround_before_it_stays_unwitnessed(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/');

        $this->assertFalse(RedosProof::Proven === $analysis->proof && RedosComplexity::Linear !== $analysis->complexity, $analysis->headline());
        $this->assertContains('ambiguity without witness at offset 9', $analysis->abstractions);
    }

    /**
     * No delimiter is left for the verbs that pin the attempt: the engine
     * cannot be asked, so nothing is proven through the lookahead. The
     * pattern moves to no other delimiter and to no bracket pair: the class
     * holds the \x01 byte itself and the closer of each pair.
     */
    #[Test]
    public function test_witness_through_a_lookaround_without_a_delimiter_for_the_verbs_proves_nothing(): void
    {
        $pattern = "_a(?=b)(\w+\w+!)|[\x01#~%!@;,)}>[]_";

        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse(RedosProof::Proven === $analysis->proof && RedosComplexity::Linear !== $analysis->complexity, $analysis->headline());
    }

    #[Test]
    #[DataProvider('provideProvenVerdicts')]
    public function test_growing_witness_stays_proven(string $pattern, RedosComplexity $complexity, ?int $degree): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame($complexity, $analysis->complexity, $pattern);
        $this->assertSame($degree, $analysis->degree, $pattern);
    }

    /**
     * Verdicts the engine shows growing, quadratic ones timed at n = 2,000
     * and 4,000.
     *
     * @return iterable<string, array{pattern: string, complexity: RedosComplexity, degree: int|null}>
     */
    public static function provideProvenVerdicts(): iterable
    {
        // "FILENAME*=" . " " x n . "!'": four times slower per doubling.
        yield 'header parameter' => ['pattern' => '/filename\*\s*=\s*[^\']*\'[^\']*\'(?<encoded_value>[^;]+)/i', 'complexity' => RedosComplexity::Polynomial, 'degree' => 2];
        yield 'changelog line' => ['pattern' => '/^## +(\[?[^] ]+\]?) - (.+?) *$/', 'complexity' => RedosComplexity::Polynomial, 'degree' => 2];
        yield 'HTML tag' => ['pattern' => '%</?[a-z][a-z0-9]*[^<>]*>%sim', 'complexity' => RedosComplexity::Polynomial, 'degree' => 2];
        yield 'nested plus' => ['pattern' => '/^(a+)+$/', 'complexity' => RedosComplexity::Exponential, 'degree' => null];
        // Flat up to 5,000,000 bytes, where PCRE2 stops looking for ";"
        // first: killed after 8 s at 5,000,106.
        yield 'past the unanchored required code unit cap' => ['pattern' => '/color\s*:\s*[^;]+;/', 'complexity' => RedosComplexity::Polynomial, 'degree' => 2];
        // "0" x n . "\"!": 0 at once, a lookbehind before it holding.
        yield 'lookbehind before adjacent repeats' => ['pattern' => '/(?<!a)\w+\w+!/', 'complexity' => RedosComplexity::Polynomial, 'degree' => 2];
        // "0(" . "0" x n . "!)": matched, by the empty alternative, only once
        // [0-9]+,?[0-9]* has failed every split: 1.2 ms at n = 2,000, 4.6 ms
        // at 4,000, 18 ms at 8,000.
        yield 'lookbehind, the witness matched after the cost' => ['pattern' => '/(?<!a)(\w+)(?:\(([0-9]+,?[0-9]*)\)|)/i', 'complexity' => RedosComplexity::Polynomial, 'degree' => 2];
    }

    /**
     * The witness used to end with "!!", which the engine matches; the
     * lookbehind holds at the subject's start, and the successes after it
     * count: the suffix carries the "!" PCRE2 requires after a character
     * that stops the second repeat.
     */
    #[Test]
    public function test_lookbehind_at_the_start_keeps_a_rejecting_suffix(): void
    {
        $witness = (new RedosAnalyzer())->analyze('/(?<!a)\w+\w+!/')->witness;

        $this->assertInstanceOf(RedosWitness::class, $witness);
        $this->assertSame('"0" x n . "\\"!"', $witness->render());
        $this->assertSame(0, preg_match('/(?<!a)\w+\w+!/', $witness->build(3)), $witness->render());
    }
}
