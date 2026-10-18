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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Linter\Config\TargetConstraint;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Text\Ascii;

/**
 * What a guard on the PHP version says, read alike by both extraction
 * strategies: the versions where it holds and those where it does not,
 * each at least every version it may hold on, never fewer. A form the
 * reader does not know is no guard: the code keeps the whole range.
 *
 * The forms: PHP_VERSION_ID compared to an integer literal, either side,
 * with >=, >, <, <=, ===, !==, == or !=; version_compare(PHP_VERSION,
 * 'x.y' or 'x.y.z', op) with op one of >=, ge, >, gt, <, lt, <=, le; "&&"
 * of guards.
 *
 * @internal
 */
final class VersionGuard
{
    /**
     * @param TargetConstraint $holds the versions the guard may hold on
     * @param TargetConstraint $fails the versions it may not hold on
     */
    public function __construct(public readonly TargetConstraint $holds, public readonly TargetConstraint $fails) {}

    /**
     * PHP_VERSION_ID compared to $value: "PHP_VERSION_ID >= 80400" when the
     * constant comes first, "80400 <= PHP_VERSION_ID" the other way round.
     * Null for an operator that is no comparison.
     */
    public static function versionId(string $operator, int $value, bool $constantFirst): ?self
    {
        if (!$constantFirst) {
            $operator = match ($operator) {
                '>' => '<',
                '<' => '>',
                '>=' => '<=',
                '<=' => '>=',
                default => $operator,
            };
        }

        $below = [[0, $value]];
        $upTo = [[0, $value + 1]];
        $from = [[$value, null]];
        $above = [[$value + 1, null]];
        $exactly = [[$value, $value + 1]];
        $other = [[0, $value], [$value + 1, null]];

        [$holds, $fails] = match ($operator) {
            '>=' => [$from, $below],
            '>' => [$above, $upTo],
            '<' => [$below, $from],
            '<=' => [$upTo, $above],
            '===', '==' => [$exactly, $other],
            '!==', '!=' => [$other, $exactly],
            default => [null, null],
        };

        return null === $holds || null === $fails ? null : new self(new TargetConstraint($holds), new TargetConstraint($fails));
    }

    /**
     * version_compare(PHP_VERSION, $version, $operator). Every release of
     * the series x.y compares above "x.y": "8.4.0" > "8.4". A release
     * candidate of x.y.z compares below "x.y.z" though its PHP_VERSION_ID
     * is that of x.y.z: x.y.z is kept on both sides. An equality is not
     * read. Null for anything else.
     */
    public static function versionCompare(string $version, string $operator): ?self
    {
        if (1 !== LibraryPcre::match('/^(\d+)\.(\d+)(?:\.(\d+))?$/', $version, $parts)) {
            return null;
        }

        $id = (int) $parts[1] * 10000 + (int) $parts[2] * 100 + (int) ($parts[3] ?? 0);
        $series = !isset($parts[3]);

        [$holds, $fails] = match (Ascii::lower($operator)) {
            '>=', 'ge' => [[[$id, null]], [[0, $series ? $id : $id + 1]]],
            '>', 'gt' => [[[$series ? $id : $id + 1, null]], [[0, $series ? $id : $id + 1]]],
            '<', 'lt' => [[[0, $series ? $id : $id + 1]], [[$id, null]]],
            '<=', 'le' => [[[0, $series ? $id : $id + 1]], [[$series ? $id : $id + 1, null]]],
            default => [null, null],
        };

        return null === $holds || null === $fails ? null : new self(new TargetConstraint($holds), new TargetConstraint($fails));
    }

    /**
     * Both guards: holds where both hold, fails where either fails.
     */
    public function and(self $other): self
    {
        return new self($this->holds->intersect($other->holds), $this->fails->union($other->fails));
    }

    /**
     * Whether a file declares something named as PHP's own version
     * constants or version_compare(): in its namespace, the unqualified
     * name may be that, and a guard written so is not read.
     */
    public static function shadowedIn(string $content): bool
    {
        return 1 === LibraryPcre::match('/\bconst\s+PHP_VERSION(?:_ID)?\b|\bfunction\s+version_compare\b|\bdefine\s*\(\s*[\'"][^\'"]*PHP_VERSION/i', $content);
    }
}
