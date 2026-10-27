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

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A seed makes the samples reproducible, given to the constructor or set
 * later: both lock the same random source, and each sample drawn on one
 * instance starts again from it.
 */
final class SampleGeneratorSeedTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSeededPatterns')]
    public function test_the_constructor_seed_draws_what_set_seed_draws(string $pattern, int $seed): void
    {
        $set = new SampleGenerator();
        $set->setSeed($seed);

        $this->assertSame($set->generate($pattern), (new SampleGenerator(seed: $seed))->generate($pattern));
        $tree = RegexParser::create(['cache' => null])->parse($pattern);
        $this->assertSame($tree->accept($set), $tree->accept(new SampleGenerator(seed: $seed)));
    }

    #[Test]
    #[DataProvider('provideSeededPatterns')]
    public function test_every_sample_on_one_seeded_instance_restarts_from_the_seed(string $pattern, int $seed): void
    {
        $set = new SampleGenerator();
        $set->setSeed($seed);
        $first = $set->generate($pattern);

        $this->assertSame($first, $set->generate($pattern));
        $this->assertSame($first, $set->generate($pattern));

        $tree = RegexParser::create(['cache' => null])->parse($pattern);
        $drawn = $tree->accept($set);
        $this->assertSame($drawn, $tree->accept($set));
    }

    #[Test]
    #[DataProvider('provideSeededPatterns')]
    public function test_a_constructor_seed_restarts_every_sample_too(string $pattern, int $seed): void
    {
        $generator = new SampleGenerator(seed: $seed);
        $first = $generator->generate($pattern);

        $this->assertSame($first, $generator->generate($pattern));
        $this->assertSame(1, preg_match($pattern, $first));
    }

    /**
     * The seed comes last, after the repetition cap and the engine.
     */
    #[Test]
    public function test_the_seed_is_the_last_constructor_argument(): void
    {
        $parameters = (new \ReflectionMethod(SampleGenerator::class, '__construct'))->getParameters();

        $this->assertSame(['maxRepetition', 'engine', 'seed'], array_map(static fn (\ReflectionParameter $parameter): string => $parameter->getName(), $parameters));
        $this->assertTrue($parameters[2]->isDefaultValueAvailable());
        $this->assertNull($parameters[2]->getDefaultValue());
    }

    #[Test]
    public function test_a_seed_set_after_a_reset_draws_what_the_constructor_seed_draws(): void
    {
        $pattern = '/[A-Z]{3}-\d{4}/';
        $generator = new SampleGenerator(seed: 42);
        $generator->resetSeed();
        $generator->setSeed(42);

        $this->assertSame((new SampleGenerator(seed: 42))->generate($pattern), $generator->generate($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, seed: int}>
     */
    public static function provideSeededPatterns(): iterable
    {
        yield 'a code, seed 42' => ['pattern' => '/[A-Z]{3}-\d{4}/', 'seed' => 42];
        yield 'an alternation repeated, seed 1' => ['pattern' => '/(?:a|b|c)+\d*/', 'seed' => 1];
        yield 'an address, seed 7' => ['pattern' => '/\w{2,8}@\w+\.(?:com|org)/', 'seed' => 7];
        yield 'a backreference, seed 3' => ['pattern' => '/(\d{2,5})-\1/', 'seed' => 3];
    }
}
