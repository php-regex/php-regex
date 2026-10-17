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

namespace PHPRegex\Automata\Solver;

use PHPRegex\Parser\Hir\AlternationHir;
use PHPRegex\Parser\Hir\AtomicHir;
use PHPRegex\Parser\Hir\CaptureHir;
use PHPRegex\Parser\Hir\ConcatHir;
use PHPRegex\Parser\Hir\EmptyHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\LookHir;
use PHPRegex\Parser\Hir\LookKind;
use PHPRegex\Parser\Hir\RepetitionHir;

/**
 * The places where two normal forms differ, each as the smallest pair of
 * parts that holds the change: the rest of both forms is the same, node for
 * node. A sequence that grew or shrank is cut down to what lies between its
 * common first and last parts, and an alternation that only lost the later
 * copies of a branch is read branch by branch: a later copy is only tried
 * where an earlier one ended and what follows failed.
 *
 * @internal the check of a rewrite proves each place alone
 */
final class RewriteSites
{
    /**
     * @var list<array{Hir, Hir, bool}>
     */
    private array $sites = [];

    private bool $insideLookbehind = false;

    private function __construct() {}

    /**
     * The pairs of parts that differ, each with whether it lies inside a
     * lookbehind, where what a part may hold differs by release.
     *
     * @return list<array{Hir, Hir, bool}>
     */
    public static function between(Hir $left, Hir $right): array
    {
        $sites = new self();
        $sites->compare($left, $right);

        return $sites->sites;
    }

    /**
     * A value with the positions of every node in it left out.
     */
    public static function shape(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(self::shape(...), $value);
        }

        if ($value instanceof \UnitEnum) {
            return $value::class.'::'.$value->name;
        }

        if (!\is_object($value)) {
            return $value;
        }

        $shape = ['' => $value::class];
        foreach ((array) $value as $property => $field) {
            if ('startPosition' !== $property && 'endPosition' !== $property) {
                $shape[$property] = self::shape($field);
            }
        }

        return $shape;
    }

    private function compare(Hir $left, Hir $right): void
    {
        if (self::shape($left) === self::shape($right)) {
            return;
        }

        if ($left instanceof ConcatHir && $right instanceof ConcatHir) {
            $this->compareParts($left->parts, $right->parts);

            return;
        }

        $pairs = self::childPairs($left, $right);
        if (null === $pairs) {
            $this->site($left, $right);

            return;
        }

        $outer = $this->insideLookbehind;
        $this->insideLookbehind = $outer || ($left instanceof LookHir && \in_array($left->kind, [LookKind::Behind, LookKind::NegativeBehind], true));

        foreach ($pairs as [$leftChild, $rightChild]) {
            $this->compare($leftChild, $rightChild);
        }

        $this->insideLookbehind = $outer;
    }

    /**
     * @param list<Hir> $left
     * @param list<Hir> $right
     */
    private function compareParts(array $left, array $right): void
    {
        $first = 0;
        $shortest = min(\count($left), \count($right));
        while ($first < $shortest && self::shape($left[$first]) === self::shape($right[$first])) {
            $first++;
        }

        $last = 0;
        while ($last < $shortest - $first && self::shape($left[\count($left) - 1 - $last]) === self::shape($right[\count($right) - 1 - $last])) {
            $last++;
        }

        $leftMiddle = \array_slice($left, $first, \count($left) - $first - $last);
        $rightMiddle = \array_slice($right, $first, \count($right) - $first - $last);

        if (\count($leftMiddle) === \count($rightMiddle)) {
            foreach ($leftMiddle as $index => $part) {
                $this->compare($part, $rightMiddle[$index]);
            }

            return;
        }

        $this->site(self::concat($leftMiddle), self::concat($rightMiddle));
    }

    /**
     * The children of two nodes of one kind that differ in their children
     * only, paired, or null when the nodes differ themselves.
     *
     * @return list<array{Hir, Hir}>|null
     */
    private static function childPairs(Hir $left, Hir $right): ?array
    {
        if ($left instanceof AlternationHir && $right instanceof AlternationHir && \count($left->branches) !== \count($right->branches)) {
            return self::withoutLaterCopies($left->branches, $right->branches);
        }

        $same = match (true) {
            $left instanceof AlternationHir && $right instanceof AlternationHir => true,
            $left instanceof RepetitionHir && $right instanceof RepetitionHir => $left->min === $right->min && $left->max === $right->max && $left->greed === $right->greed,
            $left instanceof CaptureHir && $right instanceof CaptureHir => $left->index === $right->index && $left->name === $right->name,
            $left instanceof LookHir && $right instanceof LookHir => $left->kind === $right->kind && $left->atomic === $right->atomic,
            $left instanceof AtomicHir && $right instanceof AtomicHir => true,
            default => false,
        };

        if (!$same) {
            return null;
        }

        $rightChildren = $right->children();

        return array_map(static fn (Hir $child, int $index): array => [$child, $rightChildren[$index]], $left->children(), array_keys($left->children()));
    }

    /**
     * The branches of the left alternation paired with those of the right,
     * which left out later copies of left branches only; null when the
     * right one differs otherwise.
     *
     * @param list<Hir> $left
     * @param list<Hir> $right
     *
     * @return list<array{Hir, Hir}>|null
     */
    private static function withoutLaterCopies(array $left, array $right): ?array
    {
        $pairs = [];
        $seen = [];
        $next = 0;
        foreach ($left as $branch) {
            $shape = serialize(self::shape($branch));
            $copy = isset($seen[$shape]);
            $seen[$shape] = true;
            $kept = $right[$next] ?? null;
            if ($copy && (null === $kept || self::shape($kept) !== self::shape($branch))) {
                continue;
            }

            if (null === $kept) {
                return null;
            }

            $pairs[] = [$branch, $kept];
            $next++;
        }

        return \count($right) === $next ? $pairs : null;
    }

    /**
     * @param list<Hir> $parts
     */
    private static function concat(array $parts): Hir
    {
        return match (\count($parts)) {
            0 => new EmptyHir(),
            1 => $parts[0],
            default => new ConcatHir($parts),
        };
    }

    private function site(Hir $left, Hir $right): void
    {
        $this->sites[] = [$left, $right, $this->insideLookbehind];
    }
}
