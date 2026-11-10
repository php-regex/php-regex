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

namespace PHPRegex\Parser\Resolution;

/**
 * A pattern argument as the code that builds it lets it be read: a list of
 * alternatives, one for each value the argument may take, each a list of
 * parts. A part is a string, or a hole standing for a text known at run
 * time only. Strings that follow each other are one string.
 *
 * @internal
 */
final class PatternArgument
{
    /**
     * The most alternatives a composition may give: past it, what a
     * concatenation multiplies is not built.
     */
    public const MAX_CANDIDATES = 32;

    /**
     * What an alternative is, from the most to the least known: a whole
     * pattern, a pattern around quoted texts, a pattern around some other
     * text, or nothing whose delimiter is known.
     */
    private const WHOLE = 0;

    private const QUOTED_SHELL = 1;

    private const RAW_SHELL = 2;

    private const NOTHING_STATIC = 3;

    /**
     * The state of an argument: that of the least known of its alternatives,
     * and nothing static with no alternative. Several whole patterns are a
     * set, however many: the same one written twice is one.
     *
     * @param list<list<string|Hole>> $alternatives
     */
    public static function state(array $alternatives): ArgumentState
    {
        if ([] === $alternatives) {
            return ArgumentState::Opaque;
        }

        $weakest = self::WHOLE;
        $wholes = [];
        foreach ($alternatives as $alternative) {
            $kind = self::kind($alternative);
            $weakest = max($weakest, $kind);
            if (self::WHOLE === $kind) {
                $wholes[implode('', array_filter($alternative, \is_string(...)))] = true;
            }
        }

        return match ($weakest) {
            self::WHOLE => 1 === \count($wholes) ? ArgumentState::Constant : ArgumentState::FiniteSet,
            self::QUOTED_SHELL => ArgumentState::QuotedShell,
            self::RAW_SHELL => ArgumentState::RawShell,
            default => ArgumentState::Opaque,
        };
    }

    /**
     * Each alternative of the left followed by each of the right, as a
     * concatenation reads its two operands; null when there would be more
     * than the cap, and nothing is built then.
     *
     * @param list<list<string|Hole>> $left
     * @param list<list<string|Hole>> $right
     *
     * @return list<list<string|Hole>>|null
     */
    public static function concat(array $left, array $right): ?array
    {
        if (\count($left) * \count($right) > self::MAX_CANDIDATES) {
            return null;
        }

        $product = [];
        foreach ($left as $before) {
            foreach ($right as $after) {
                $product[] = [...$before, ...$after];
            }
        }

        return $product;
    }

    /**
     * What an alternative is. The first part carries the delimiter: around
     * a hole, a pattern is read only when that part is a string PHP finds a
     * delimiter in, one that is not empty and does not start with the white
     * space PHP skips before it.
     *
     * @param list<string|Hole> $parts
     */
    private static function kind(array $parts): int
    {
        $holes = array_filter($parts, static fn (string|Hole $part): bool => $part instanceof Hole);
        if ([] === $holes) {
            return [] === $parts ? self::NOTHING_STATIC : self::WHOLE;
        }

        // The strings before the first hole are one string.
        $first = '';
        foreach ($parts as $part) {
            if ($part instanceof Hole) {
                break;
            }

            $first .= $part;
        }

        if ('' === $first || 1 === strspn($first, " \t\n\v\f\r", 0, 1)) {
            return self::NOTHING_STATIC;
        }

        foreach ($holes as $hole) {
            if (!$hole->quoted) {
                return self::RAW_SHELL;
            }
        }

        return self::QUOTED_SHELL;
    }
}
