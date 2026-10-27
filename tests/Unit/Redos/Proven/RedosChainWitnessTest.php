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
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosWitness;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A polynomial degree is the length of a chain of loops, each with a way to
 * the next on the word they all read: the published witness pumps that
 * word, so the engine pays the degree on it. In /^[!a]*.*a*b$/ the three
 * loops read "a", the first two "!" too: on "!" x n . "\nb" the engine pays
 * n^2, on "a" x n . "\nb" n^3.
 *
 * Engine: PHP 8.4.27 / PCRE2 10.49, without the JIT. On "a" x n . "\nb":
 * 2.7, 13.3, 149 ms on 250, 500, 1,000; on "!" x n . "\nb": 0.5, 1.7, 8.1
 * ms. PCRE2 makes "a*" before "b" possessive, which reads the same
 * characters but counts none of them: the steps are counted with
 * "(*NO_AUTO_POSSESS)", and grow 7.8 times per doubling on "a", 3.9 on "!".
 */
final class RedosChainWitnessTest extends TestCase
{
    #[Test]
    #[DataProvider('provideChains')]
    public function test_the_witness_pumps_the_word_every_loop_of_the_chain_reads(string $pattern, int $degree): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = $pattern.' is "'.$analysis->headline().'"';

        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame(RedosComplexity::Polynomial, $analysis->complexity, $message);
        $this->assertSame($degree, $analysis->degree, $message);

        // The counter's growth on the witness, n to 2n: 2^degree.
        $witness = $analysis->witness;
        $this->assertInstanceOf(RedosWitness::class, $witness, $message);
        $short = self::steps($pattern, $witness->build(40));
        $long = self::steps($pattern, $witness->build(80));
        $this->assertGreaterThan(0.85 * 2 ** $degree, $long / $short, \sprintf('%s on %s: %d -> %d steps.', $message, json_encode($witness->pump), $short, $long));
        $this->assertLessThan(1.15 * 2 ** $degree, $long / $short, \sprintf('%s on %s: %d -> %d steps.', $message, json_encode($witness->pump), $short, $long));
    }

    /**
     * @return iterable<string, array{pattern: string, degree: int}>
     */
    public static function provideChains(): iterable
    {
        yield 'a chain whose first link reads more' => ['pattern' => '/^[!a]*.*a*b$/', 'degree' => 3];
        yield 'a chain whose last link reads more' => ['pattern' => '/^a*.*[!a]*b$/', 'degree' => 3];
        yield 'a chain on one word' => ['pattern' => '/^a*a*a*b$/', 'degree' => 3];
        yield 'a quadratic pair' => ['pattern' => '/^[!a]*.*b$/', 'degree' => 2];
    }

    /**
     * In /^[!]*[!a]*a*b$/ the first link reads "!" alone and the second "a"
     * alone: no one word pumps the three loops, and no degree 3 is proven
     * on a witness that does not reach it. Counted as above, the steps grow
     * 7.8 times per doubling on "!" x n . "a" x n . "\nb", which no witness
     * of one pump writes, 3.9 on "!" x n . "\nb" and on "a" x n . "\nb", 2 on
     * "!a" x n . "\nb".
     */
    #[Test]
    public function test_a_chain_on_different_words_is_not_proven_on_one_pump(): void
    {
        $pattern = '/^[!]*[!a]*a*b$/';
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertFalse(RedosProof::Proven === $analysis->proof && 3 === $analysis->degree, $pattern.' is "'.$analysis->headline().'"');
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'"');
    }

    /**
     * The steps the interpreter takes, its start-up study and its automatic
     * possession off: the smallest backtrack limit under which it finishes.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $limit = ini_get('pcre.backtrack_limit');
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)(*NO_AUTO_POSSESS)'.substr($pattern, 1));

        try {
            $low = 1;
            $high = 1 << 26;
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
