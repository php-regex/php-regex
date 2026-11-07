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

namespace PHPRegex\Linter\Formatter;

/**
 * The diff a console report shows of a suggested rewrite: between lines,
 * the longest run kept, the others deleted then inserted; inside a line,
 * the prefix and the suffix both sides share.
 *
 * @internal
 */
final class LineDiff
{
    private const MAX_MATRIX_CELLS = 65_536;

    /**
     * The lines of $old turned into those of $new: each kept, deleted or
     * inserted, in order. Where two diffs are as short, the deletes come
     * before the inserts. Above the matrix budget, the changed middle is
     * deleted then inserted in full, without finding a shortest diff.
     *
     * @param list<string> $old
     * @param list<string> $new
     *
     * @return list<array{type: 'delete'|'equal'|'insert', line: string}>
     */
    public static function lines(array $old, array $new): array
    {
        $oldCount = \count($old);
        $newCount = \count($new);

        $prefix = 0;
        while ($prefix < $oldCount && $prefix < $newCount && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while ($suffix < $oldCount - $prefix && $suffix < $newCount - $prefix && $old[$oldCount - 1 - $suffix] === $new[$newCount - 1 - $suffix]) {
            $suffix++;
        }

        $ops = [];
        for ($i = 0; $i < $prefix; $i++) {
            $ops[] = ['type' => 'equal', 'line' => $old[$i]];
        }
        foreach (self::middle(array_slice($old, $prefix, $oldCount - $prefix - $suffix), array_slice($new, $prefix, $newCount - $prefix - $suffix)) as $op) {
            $ops[] = $op;
        }
        for ($i = $oldCount - $suffix; $i < $oldCount; $i++) {
            $ops[] = ['type' => 'equal', 'line' => $old[$i]];
        }

        return $ops;
    }

    /**
     * The bytes two lines share at the start, then at the end, the two
     * never overlapping: what is left between is what changed.
     *
     * @return array{int, int} the length of the shared prefix, then of the shared suffix
     */
    public static function affixes(string $old, string $new): array
    {
        $oldLength = \strlen($old);
        $newLength = \strlen($new);

        $prefix = 0;
        $shortest = min($oldLength, $newLength);
        while ($prefix < $shortest && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        $room = $shortest - $prefix;
        while ($suffix < $room && $old[$oldLength - 1 - $suffix] === $new[$newLength - 1 - $suffix]) {
            $suffix++;
        }

        return [$prefix, $suffix];
    }

    /**
     * @param list<string> $old
     * @param list<string> $new
     *
     * @return list<array{type: 'delete'|'equal'|'insert', line: string}>
     */
    private static function middle(array $old, array $new): array
    {
        $oldCount = \count($old);
        $newCount = \count($new);

        // Include the boundary row and column, and divide to avoid overflow.
        if (0 === $oldCount || 0 === $newCount || $oldCount + 1 > intdiv(self::MAX_MATRIX_CELLS, $newCount + 1)) {
            $ops = [];
            foreach ($old as $line) {
                $ops[] = ['type' => 'delete', 'line' => $line];
            }
            foreach ($new as $line) {
                $ops[] = ['type' => 'insert', 'line' => $line];
            }

            return $ops;
        }

        // The length of the longest common run of each pair of prefixes.
        $lcs = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        for ($i = 1; $i <= $oldCount; $i++) {
            for ($j = 1; $j <= $newCount; $j++) {
                $lcs[$i][$j] = $old[$i - 1] === $new[$j - 1]
                    ? $lcs[$i - 1][$j - 1] + 1
                    : max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
            }
        }

        // Read back from the ends: the last operation comes first.
        $ops = [];
        $i = $oldCount;
        $j = $newCount;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                $ops[] = ['type' => 'equal', 'line' => $old[$i - 1]];
                $i--;
                $j--;
            } elseif ($j > 0 && (0 === $i || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                $ops[] = ['type' => 'insert', 'line' => $new[$j - 1]];
                $j--;
            } else {
                $ops[] = ['type' => 'delete', 'line' => $old[$i - 1]];
                $i--;
            }
        }

        return array_reverse($ops);
    }
}
