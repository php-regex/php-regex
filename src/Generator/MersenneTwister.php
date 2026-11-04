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

namespace PHPRegex\Generator;

use PHPRegex\Generator\Exception\SampleGenerationException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * PHP's Mersenne Twister, held by the generator alone: a seed draws the same
 * numbers as mt_srand($seed) then mt_rand($min, $max), or as the PHP 8.2
 * randomizer over its Mt19937 engine, on every PHP the library runs on, and
 * the process's own mt_rand() sequence is left alone.
 *
 * A PHP whose integers hold 32 bits cannot hold a word of the twister: there
 * the draws come from PHP 8.2's randomizer over its own Mt19937 engine, an
 * engine of this instance's own that draws the same numbers; on PHP 8.1 the
 * process's own twister is seeded and drawn instead, and the process's
 * sequence moves with the generator's.
 *
 * @internal
 */
final class MersenneTwister
{
    /**
     * The twister held here, word by word.
     */
    public const OWN = 'own';

    /**
     * PHP 8.2's randomizer over an Mt19937 engine of this instance's own.
     */
    public const RANDOMIZER = 'randomizer';

    /**
     * The process's own twister, mt_srand() then mt_rand().
     */
    public const HOST = 'host';

    private const N = 624;

    private const M = 397;

    private const WORD = 0xFFFFFFFF;

    /**
     * How many draws over the unbiased limit are thrown away before the
     * draw gives up, as PHP's randomizer does.
     */
    private const ATTEMPTS = 50;

    /**
     * @var array<int, int>
     */
    private array $state = [];

    private int $next = self::N;

    private readonly string $engine;

    private readonly ?Randomizer $randomizer;

    /**
     * @param int|null    $seed   cut to its low 32 bits, as PHP does; a
     *                            random one when null
     * @param string|null $engine OWN, RANDOMIZER or HOST; by default OWN, or
     *                            on a PHP whose integers hold 32 bits the
     *                            RANDOMIZER when it has one, else the HOST
     */
    public function __construct(?int $seed = null, ?string $engine = null)
    {
        $engine ??= \PHP_INT_SIZE >= 8 ? self::OWN : (class_exists(Randomizer::class) ? self::RANDOMIZER : self::HOST);
        if (!\in_array($engine, [self::OWN, self::RANDOMIZER, self::HOST], true)) {
            throw new \LogicException(\sprintf('Unknown engine "%s": one of "own", "randomizer" or "host".', $engine));
        }
        if (self::OWN === $engine && \PHP_INT_SIZE < 8) {
            throw new \LogicException('The own engine holds 32-bit words, which an integer of this PHP cannot: ask for the randomizer or the host.');
        }
        $this->engine = $engine;
        $this->randomizer = self::RANDOMIZER === $engine ? new Randomizer(new Mt19937($seed)) : null;
        if (self::HOST === $engine) {
            null === $seed ? mt_srand() : mt_srand($seed, \MT_RAND_MT19937);
        }
        if (self::OWN !== $engine) {
            return;
        }

        $this->state[0] = ($seed ?? random_int(0, self::WORD)) & self::WORD;
        for ($i = 1; $i < self::N; $i++) {
            $previous = $this->state[$i - 1];
            $this->state[$i] = (1812433253 * ($previous ^ ($previous >> 30)) + $i) & self::WORD;
        }

        $this->reload();
    }

    /**
     * A number from $min to $max, both included, with no bias.
     *
     * @throws SampleGenerationException when the span holds more than 2^32 values
     */
    public function between(int $min, int $max): int
    {
        if ($max < $min || $max - $min > self::WORD) {
            throw new SampleGenerationException(\sprintf('Cannot draw a number among more than 2^32 values, from %d to %d.', $min, $max));
        }

        if (null !== $this->randomizer) {
            return $this->randomizer->getInt($min, $max);
        }
        if (self::HOST === $this->engine) {
            return mt_rand($min, $max);
        }

        $span = $max - $min;
        $word = $this->word();
        if (self::WORD === $span) {
            return $min + $word;
        }

        $count = $span + 1;
        if (0 === ($count & ($count - 1))) {
            return $min + ($word & ($count - 1));
        }

        $limit = self::WORD - (self::WORD % $count) - 1;
        for ($attempt = 1; $word > $limit; $attempt++) {
            if ($attempt > self::ATTEMPTS) {
                throw new SampleGenerationException(\sprintf('No unbiased number from %d to %d in %d draws.', $min, $max, self::ATTEMPTS));
            }

            $word = $this->word();
        }

        return $min + $word % $count;
    }

    /**
     * The next 32-bit word, tempered.
     */
    private function word(): int
    {
        if ($this->next >= self::N) {
            $this->reload();
        }

        $word = $this->state[$this->next++];
        $word ^= $word >> 11;
        $word ^= ($word << 7) & 0x9D2C5680;
        $word ^= ($word << 15) & 0xEFC60000;

        return $word ^ ($word >> 18);
    }

    /**
     * The next 624 words of state, each from the one 397 places on and the
     * two at its own place.
     */
    private function reload(): void
    {
        for ($i = 0; $i < self::N; $i++) {
            $mixed = ($this->state[$i] & 0x80000000) | ($this->state[($i + 1) % self::N] & 0x7FFFFFFF);
            $this->state[$i] = $this->state[($i + self::M) % self::N] ^ ($mixed >> 1) ^ (0 === ($mixed & 1) ? 0 : 0x9908B0DF);
        }

        $this->next = 0;
    }
}
