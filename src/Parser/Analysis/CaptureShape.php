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

namespace PHPRegex\Parser\Analysis;

use PHPRegex\Parser\Exception\InvalidRegexOptionException;

/**
 * What a successful preg_match() writes into $matches, read from the pattern
 * alone: one record per capturing group, keyed by its number, plus the
 * whole match and the marks a verb may leave.
 */
final readonly class CaptureShape
{
    private const MAX_RENDERED_VALUES = 16;

    private const CONTROL_CHARACTERS = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F";

    /**
     * @internal built by CaptureShapeAnalyzer::analyze()
     *
     * @param array<int<1, max>, CaptureGroupShape> $groups keyed by group number, 1 to the capture count with no gap; branch reset groups sharing a number share a record
     * @param list<string>                          $marks  the names a (*MARK) verb, or a verb that sets one, may leave under "MARK"
     */
    public function __construct(
        public CaptureGroupShape $whole,
        public array $groups,
        public array $marks,
    ) {}

    /**
     * The array shape of $matches after preg_match() returned 1, written as a
     * PHPStan type: "array{0: 'ab', 1: 'a', 2?: 'b'}", its keys in the order
     * preg_match() writes them. It honours PREG_UNMATCHED_AS_NULL and
     * PREG_OFFSET_CAPTURE; like preg_match(), it ignores a bit above the low
     * byte and refuses any other flag.
     *
     * @throws InvalidRegexOptionException when $flags has a bit of the low byte set, as PREG_SET_ORDER does
     */
    public function matchShape(int $flags = 0): string
    {
        if (0 !== ($flags & 0xFF)) {
            throw new InvalidRegexOptionException(\sprintf('matchShape() accepts PREG_OFFSET_CAPTURE and PREG_UNMATCHED_AS_NULL only, as preg_match() does; got flags %d.', $flags));
        }

        $asNull = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL);
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);

        // Read once, so each key below costs no scan of the other groups.
        $lastAlways = 0;
        $lastMaySet = 0;
        $byName = [];
        foreach ($this->groups as $group) {
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

        $items = ['0: '.self::entry([self::valueType($this->whole)], true, $offsets)];
        $marked = false;
        $named = [];
        foreach ($this->groups as $group) {
            $presence = self::presence($group, $lastAlways, $lastMaySet, $asNull);
            if (null === $presence) {
                continue;
            }

            $types = self::types($group, $lastAlways, $lastMaySet, $asNull);
            $always = Participation::Always === $group->participation;

            if (null !== $group->name && !isset($named[$group->name])) {
                $named[$group->name] = true;
                $items[] = $group->name.$presence.': '.$this->nameEntry($group->name, $byName[$group->name], $always, $offsets, $lastAlways, $lastMaySet, $asNull);
                $marked = $marked || 'MARK' === $group->name;
            }

            $items[] = $group->number.$presence.': '.self::entry($types, $always, $offsets);
        }

        if ([] !== $this->marks && !$marked) {
            $items[] = 'MARK?: '.implode('|', $this->markTypes());
        }

        return 'array{'.implode(', ', $items).'}';
    }

    /**
     * ': ' for a key every match writes, '?: ' for one some matches write,
     * null for one no match writes. $lastAlways is the highest group number
     * every match sets, $lastMaySet the highest some match may set, 0 for none.
     */
    private static function presence(CaptureGroupShape $group, int $lastAlways, int $lastMaySet, bool $asNull): ?string
    {
        if ($asNull || $group->number <= $lastAlways) {
            return '';
        }

        // Without the flag, PHP leaves out the groups past the last one set.
        if (Participation::Never === $group->participation && $group->number >= $lastMaySet) {
            return null;
        }

        return '?';
    }

    /**
     * @return list<string>
     */
    private static function types(CaptureGroupShape $group, int $lastAlways, int $lastMaySet, bool $asNull): array
    {
        $unset = $asNull ? 'null' : "''";

        return match ($group->participation) {
            Participation::Always => [self::valueType($group)],
            Participation::Never => [$unset],
            // Unset, the group reads '' only when a later group is set, or null.
            Participation::MayBeUnset => $asNull || $group->number <= $lastAlways || $group->number < $lastMaySet
                ? [self::valueType($group), $unset]
                : [self::valueType($group)],
        };
    }

    /**
     * What the key of a name holds. A group named MARK shares that key with
     * the mark verbs, whose names PHP writes as plain strings over it.
     *
     * @param non-empty-list<CaptureGroupShape> $sharing the groups that bear the name
     */
    private function nameEntry(string $name, array $sharing, bool $always, bool $offsets, int $lastAlways, int $lastMaySet, bool $asNull): string
    {
        $types = self::nameTypes($sharing, $lastAlways, $lastMaySet, $asNull);
        if ('MARK' !== $name || [] === $this->marks) {
            return self::entry($types, $always, $offsets);
        }

        if ($offsets) {
            return self::entry($types, $always, true).'|'.implode('|', $this->markTypes());
        }

        return self::entry([...$types, ...$this->markTypes()], $always, false);
    }

    /**
     * @return list<string>
     */
    private function markTypes(): array
    {
        if (null === self::literals($this->marks)) {
            return ['non-empty-string'];
        }

        return array_map(self::literal(...), $this->marks);
    }

    /**
     * A name holds the value of its group. A name several groups share (/J)
     * holds the value of the highest-numbered of them that is set, or, when
     * none is set, what an unset one reads: the union of what each holds
     * covers both.
     *
     * @param non-empty-list<CaptureGroupShape> $sharing
     *
     * @return list<string>
     */
    private static function nameTypes(array $sharing, int $lastAlways, int $lastMaySet, bool $asNull): array
    {
        $types = [];
        foreach ($sharing as $group) {
            $types = [...$types, ...self::types($group, $lastAlways, $lastMaySet, $asNull)];
        }

        return $types;
    }

    /**
     * @param list<string> $types
     */
    private static function entry(array $types, bool $always, bool $offsets): string
    {
        $types = array_values(array_unique($types));
        // '' first and null last, as PHPStan prints them.
        usort($types, static fn (string $a, string $b): int => self::rank($a) <=> self::rank($b));
        $type = implode('|', $types);

        return $offsets ? \sprintf('array{%s, int<%d, max>}', $type, $always ? 0 : -1) : $type;
    }

    private static function rank(string $type): int
    {
        return match ($type) {
            "''" => 0,
            'null' => 2,
            default => 1,
        };
    }

    private static function valueType(CaptureGroupShape $group): string
    {
        if (null !== $group->values && null !== $literals = self::literals($group->values)) {
            return $literals;
        }

        if (0 === $group->maxLength) {
            return "''";
        }

        return $group->minLength > 0 ? 'non-empty-string' : 'string';
    }

    /**
     * The values as a union of PHPStan constant strings, or null when there
     * are too many or one cannot be written legibly.
     *
     * @param list<string> $values
     */
    private static function literals(array $values): ?string
    {
        if ([] === $values || \count($values) > self::MAX_RENDERED_VALUES) {
            return null;
        }

        $literals = [];
        foreach ($values as $value) {
            if (!mb_check_encoding($value, 'UTF-8') || false !== strpbrk($value, self::CONTROL_CHARACTERS)) {
                return null;
            }

            $literals[] = self::literal($value);
        }

        return implode('|', $literals);
    }

    private static function literal(string $value): string
    {
        return "'".addcslashes($value, "'\\")."'";
    }
}
