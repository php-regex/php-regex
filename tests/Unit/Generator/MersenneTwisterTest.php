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

namespace PHPRegex\Tests\Unit\Generator;

use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Generator\MersenneTwister;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A seeded sample is the same on every PHP the generator runs on: the
 * twister draws what PHP's own Mersenne Twister draws for the seed, without
 * touching the process's mt_rand() state.
 */
final class MersenneTwisterTest extends TestCase
{
    protected function tearDown(): void
    {
        mt_srand();
    }

    #[Test]
    #[DataProvider('provideDraws')]
    public function test_draws_what_mt_rand_draws_for_the_seed(int $seed, int $min, int $max): void
    {
        $twister = new MersenneTwister($seed);
        $drawn = [];
        for ($i = 0; $i < 1500; $i++) {
            $drawn[] = $twister->between($min, $max);
        }

        mt_srand($seed, \MT_RAND_MT19937);
        $expected = [];
        for ($i = 0; $i < 1500; $i++) {
            $expected[] = mt_rand($min, $max);
        }

        $this->assertSame($expected, $drawn);
    }

    /**
     * @return iterable<string, array{seed: int, min: int, max: int}>
     */
    public static function provideDraws(): iterable
    {
        yield 'coin' => ['seed' => 42, 'min' => 0, 'max' => 1];
        yield 'power of two' => ['seed' => 7, 'min' => 0, 'max' => 255];
        yield 'odd range' => ['seed' => 123456, 'min' => 0, 'max' => 9];
        yield 'code points' => ['seed' => 99, 'min' => 0x20, 'max' => 0x10FFFF];
        yield 'negative bounds' => ['seed' => 1, 'min' => -5, 'max' => 5];
        yield 'zero seed' => ['seed' => 0, 'min' => 3, 'max' => 3];
        yield 'seed past 32 bits' => ['seed' => 0x1_0000_0005, 'min' => 0, 'max' => 1000];
        yield 'negative seed' => ['seed' => -1, 'min' => 0, 'max' => 1000];
        yield 'full 32-bit range' => ['seed' => 5, 'min' => 0, 'max' => 0xFFFFFFFF];
    }

    /**
     * The samples never draw among more than 2^32 values (a count, a code
     * point, an alternative): a wider span is refused rather than drawn
     * with a bias.
     */
    #[Test]
    public function test_a_span_past_32_bits_is_refused(): void
    {
        $this->expectException(SampleGenerationException::class);

        (new MersenneTwister(1))->between(0, 0x1_0000_0000);
    }

    #[Test]
    public function test_an_unseeded_twister_draws_within_the_bounds(): void
    {
        $twister = new MersenneTwister();
        for ($i = 0; $i < 100; $i++) {
            $drawn = $twister->between(10, 20);
            $this->assertGreaterThanOrEqual(10, $drawn);
            $this->assertLessThanOrEqual(20, $drawn);
        }
    }
}
