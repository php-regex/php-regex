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

/**
 * What a successful preg_match() writes into $matches, read from the pattern
 * alone: one record per capturing group, in number order, plus the whole
 * match and the marks a verb may leave.
 */
final readonly class CaptureShape
{
    private const MAX_RENDERED_VALUES = 16;

    /**
     * @param list<CaptureGroupShape> $groups one per group number; branch reset groups sharing a number share a record
     * @param list<string>            $marks  the names a (*MARK) verb, or a verb that sets one, may leave under "MARK"
     */
    public function __construct(
        public CaptureGroupShape $whole,
        public array $groups,
        public array $marks,
    ) {}

    /**
     * The array shape of $matches after preg_match() returned 1, written as a
     * PHPStan type: "array{0: 'ab', 1: 'a', 2?: 'b'}". It honours
     * PREG_UNMATCHED_AS_NULL and PREG_OFFSET_CAPTURE.
     */
    public function matchShape(int $flags = 0): string
    {
        $asNull = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL);
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);

        $lastAlways = 0;
        foreach ($this->groups as $group) {
            if (Participation::Always === $group->participation) {
                $lastAlways = $group->number;
            }
        }

        $items = ['0: '.self::entry([self::valueType($this->whole)], true, $offsets)];
        $named = [];
        foreach ($this->groups as $group) {
            $presence = $this->presence($group, $lastAlways, $asNull);
            if (null === $presence) {
                continue;
            }

            $types = $this->types($group, $lastAlways, $asNull);
            $always = Participation::Always === $group->participation;

            if (null !== $group->name && !isset($named[$group->name])) {
                $named[$group->name] = true;
                $items[] = $group->name.$presence.': '.self::entry($this->nameTypes($group->name, $lastAlways, $asNull), $always, $offsets);
            }

            $items[] = $group->number.$presence.': '.self::entry($types, $always, $offsets);
        }

        if ([] !== $this->marks) {
            $items[] = 'MARK?: '.(self::literals($this->marks) ?? 'non-empty-string');
        }

        return 'array{'.implode(', ', $items).'}';
    }

    /**
     * ': ' for a key every match writes, '?: ' for one some matches write,
     * null for one no match writes.
     */
    private function presence(CaptureGroupShape $group, int $lastAlways, bool $asNull): ?string
    {
        if ($asNull || $group->number <= $lastAlways) {
            return '';
        }

        // Without the flag, PHP leaves out the groups past the last one set.
        if (Participation::Never === $group->participation && !$this->laterMaySet($group->number)) {
            return null;
        }

        return '?';
    }

    /**
     * @return list<string>
     */
    private function types(CaptureGroupShape $group, int $lastAlways, bool $asNull): array
    {
        $unset = $asNull ? 'null' : "''";

        return match ($group->participation) {
            Participation::Always => [self::valueType($group)],
            Participation::Never => [$unset],
            // Unset, the group reads '' only when a later group is set, or null.
            Participation::MayBeUnset => $asNull || $group->number <= $lastAlways || $this->laterMaySet($group->number)
                ? [self::valueType($group), $unset]
                : [self::valueType($group)],
        };
    }

    /**
     * A name holds the value of its group; a name several groups share (/J)
     * holds the value of the first one set, or what an unset one reads.
     *
     * @return list<string>
     */
    private function nameTypes(string $name, int $lastAlways, bool $asNull): array
    {
        $types = [];
        foreach ($this->groups as $group) {
            if ($name === $group->name) {
                $types = [...$types, ...$this->types($group, $lastAlways, $asNull)];
            }
        }

        return $types;
    }

    private function laterMaySet(int $number): bool
    {
        foreach ($this->groups as $group) {
            if ($group->number > $number && Participation::Never !== $group->participation) {
                return true;
            }
        }

        return false;
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
            if (!mb_check_encoding($value, 'UTF-8') || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
                return null;
            }

            $literals[] = "'".addcslashes($value, "'\\")."'";
        }

        return implode('|', $literals);
    }
}
