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

namespace PHPRegex\Parser\Internal;

use PHPRegex\Parser\Analysis\CaptureGroupShape;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\Participation;

/**
 * The keys preg_match() or preg_match_all() writes into $matches, in the
 * order PHP writes them, read from the merged view of a shape: the whole
 * match under 0; then, for each group, its name at the first group bearing
 * it, before its number; then "MARK" for the marks a verb leaves, unless a
 * group named MARK already holds that key, which the marks then share.
 *
 * Every rendering of a shape as a type reads this one walk, so they all say
 * the same keys.
 *
 * @internal
 */
final readonly class CaptureLayout
{
    /**
     * Past this many values, a group's literals say less than its facts.
     */
    private const MAX_LITERALS = 16;

    private const CONTROL_CHARACTERS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /**
     * @param list<CaptureKey> $keys
     */
    private function __construct(public array $keys) {}

    /**
     * Whether a type writes the values as a union of literal strings: there
     * are some, no more than 16, and each reads legibly in an issue (valid
     * UTF-8, no control character).
     *
     * @param list<string> $values
     *
     * @phpstan-assert-if-true non-empty-list<string> $values
     */
    public static function readsAsLiterals(array $values): bool
    {
        if ([] === $values || \count($values) > self::MAX_LITERALS) {
            return false;
        }

        foreach ($values as $value) {
            if (!mb_check_encoding($value, 'UTF-8') || false !== strpbrk($value, self::CONTROL_CHARACTERS)) {
                return false;
            }
        }

        return true;
    }

    /**
     * What preg_match() writes on a match. Without PREG_UNMATCHED_AS_NULL,
     * PHP leaves out the groups past the last one set, and a group left
     * unset before a set one reads ''. With it, every key is written, an
     * unset group reading null.
     */
    public static function ofMatch(CaptureShape $shape, bool $unmatchedAsNull = false): self
    {
        // Read once, so each key below costs no scan of the other groups.
        $lastAlways = 0;
        $lastMaySet = 0;
        $byName = [];
        foreach ($shape->groups as $group) {
            if (Participation::Always === $group->participation) {
                $lastAlways = $group->number;
            }

            if (Participation::Never !== $group->participation) {
                $lastMaySet = $group->number;
            }

            if (null !== $group->name) {
                $byName[$group->name][] = $group;
            }
        }

        $keys = [self::wholeKey($shape)];
        $marked = false;
        $named = [];
        foreach ($shape->groups as $group) {
            $optional = !$unmatchedAsNull && $group->number > $lastAlways;
            // Without the flag, PHP leaves out the groups past the last one set.
            if ($optional && Participation::Never === $group->participation && $group->number >= $lastMaySet) {
                continue;
            }

            $always = Participation::Always === $group->participation;
            $name = $group->name;
            if (null !== $name && !isset($named[$name])) {
                $named[$name] = true;
                $marked = $marked || 'MARK' === $name;
                [$groups, $unset] = self::nameValue($byName[$name], $unmatchedAsNull, $lastAlways, $lastMaySet);
                // A name several groups share (/J) is set whenever one of them always is.
                $nameAlways = [] !== array_filter($byName[$name], static fn (CaptureGroupShape $shared): bool => Participation::Always === $shared->participation);
                $keys[] = new CaptureKey($name, $optional, $groups, $unset, 'MARK' === $name && [] !== $shape->marks, $nameAlways);
            }

            $keys[] = new CaptureKey($group->number, $optional, self::setGroups([$group]), self::readsUnset($group, $unmatchedAsNull, $lastAlways, $lastMaySet), false, $always);
        }

        if ([] !== $shape->marks && !$marked) {
            $keys[] = self::marksKey();
        }

        return new self($keys);
    }

    /**
     * What preg_match_all() writes under PREG_PATTERN_ORDER, each key holding
     * one value per match: every group key, on every call, the unset value
     * included whenever some match may leave the group unset. A name several
     * groups share (/J) holds the values of the highest-numbered of them,
     * set or not. "MARK" is written once some match set a mark.
     */
    public static function ofMatchAll(CaptureShape $shape): self
    {
        // PHP writes a name at the place of its first group, with the list of its last one.
        $lastByName = [];
        foreach ($shape->groups as $group) {
            if (null !== $group->name) {
                $lastByName[$group->name] = $group;
            }
        }

        $keys = [self::wholeKey($shape)];
        $marked = false;
        $named = [];
        foreach ($shape->groups as $group) {
            $name = $group->name;
            if (null !== $name && !isset($named[$name])) {
                $named[$name] = true;
                $marked = $marked || 'MARK' === $name;
                $keys[] = self::listKey($name, $lastByName[$name], 'MARK' === $name && [] !== $shape->marks);
            }

            $keys[] = self::listKey($group->number, $group, false);
        }

        if ([] !== $shape->marks && !$marked) {
            $keys[] = self::marksKey();
        }

        return new self($keys);
    }

    private static function wholeKey(CaptureShape $shape): CaptureKey
    {
        return new CaptureKey(0, false, [$shape->whole], false, false, true);
    }

    private static function marksKey(): CaptureKey
    {
        return new CaptureKey('MARK', true, [], false, true, false);
    }

    private static function listKey(int|string $key, CaptureGroupShape $group, bool $marks): CaptureKey
    {
        $always = Participation::Always === $group->participation;

        return new CaptureKey($key, false, self::setGroups([$group]), !$always, $marks, $always);
    }

    /**
     * Unset, a group reads '' only when a later group is set, or null; a
     * group no match sets always reads what an unset group reads.
     */
    private static function readsUnset(CaptureGroupShape $group, bool $unmatchedAsNull, int $lastAlways, int $lastMaySet): bool
    {
        return match ($group->participation) {
            Participation::Always => false,
            Participation::Never => true,
            Participation::MayBeUnset => $unmatchedAsNull || $group->number <= $lastAlways || $group->number < $lastMaySet,
        };
    }

    /**
     * A name holds the value of its group. A name several groups share (/J)
     * holds the value of the highest-numbered of them that is set, or, when
     * none is set, what an unset one reads: the union of what each holds
     * covers both. When one of them is set by every match, the name always
     * holds the value of a group that is set.
     *
     * @param non-empty-list<CaptureGroupShape> $sharing
     *
     * @return array{0: list<CaptureGroupShape>, 1: bool}
     */
    private static function nameValue(array $sharing, bool $unmatchedAsNull, int $lastAlways, int $lastMaySet): array
    {
        $groups = self::setGroups($sharing);
        $unset = false;
        foreach ($sharing as $group) {
            if (Participation::Always === $group->participation) {
                return [$groups, false];
            }

            $unset = $unset || self::readsUnset($group, $unmatchedAsNull, $lastAlways, $lastMaySet);
        }

        return [$groups, $unset];
    }

    /**
     * @param list<CaptureGroupShape> $groups
     *
     * @return list<CaptureGroupShape>
     */
    private static function setGroups(array $groups): array
    {
        return array_values(array_filter($groups, static fn (CaptureGroupShape $group): bool => Participation::Never !== $group->participation));
    }
}
