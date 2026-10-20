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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\QuantifierBounds;

/**
 * The least and the most repetitions of a quantifier, as the validation
 * classes read them: the validator, the lookbehind checks and the compiled
 * size each ask for the counts of the same quantifiers, which a node keeps
 * only as written, so the answer for each spelling is kept once for all.
 *
 * @internal
 */
final class QuantifierCounts
{
    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private static array $quantifierBoundsCache = [];

    /**
     * Clears the counts read so far.
     */
    public static function clearCaches(): void
    {
        self::$quantifierBoundsCache = [];
    }

    /**
     * The least and the most repetitions of "*", "+", "?" or "{n,m}", the
     * most -1 when it is open; [1, 1] for a spelling that is no quantifier.
     *
     * @return array{0: int, 1: int}
     */
    public static function of(string $quantifier): array
    {
        $normalized = self::normalize($quantifier);
        if (isset(self::$quantifierBoundsCache[$normalized])) {
            return self::$quantifierBoundsCache[$normalized];
        }

        StaticCaches::register(self::class, self::clearCaches(...));
        self::$quantifierBoundsCache = StaticCaches::makeRoom(self::$quantifierBoundsCache);

        $bounds = QuantifierBounds::parse($normalized);

        return self::$quantifierBoundsCache[$normalized] = null === $bounds ? [1, 1] : [$bounds->min, $bounds->max ?? -1];
    }

    /**
     * "{ 2 , 3 }" read as "{2,3}".
     */
    private static function normalize(string $quantifier): string
    {
        if (!str_starts_with($quantifier, '{') || !str_ends_with($quantifier, '}')) {
            return $quantifier;
        }

        $inner = substr($quantifier, 1, -1);
        $inner = LibraryPcre::replace('/\\s+/', '', $inner) ?? $inner;

        return '{'.$inner.'}';
    }
}
