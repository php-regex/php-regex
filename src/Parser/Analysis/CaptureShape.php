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
use PHPRegex\Parser\Internal\CaptureKey;
use PHPRegex\Parser\Internal\CaptureLayout;

/**
 * What a successful preg_match() writes into $matches, read from the pattern
 * alone: one record per capturing group, keyed by its number, plus the
 * whole match and the marks a verb may leave.
 *
 * $whole, $groups and $marks merge every match. When the analyzer splits the
 * pattern, $cases holds shapes whose union covers every match, each more
 * precise than the merged view.
 */
final readonly class CaptureShape
{
    /**
     * Mirrors PHPStan's ConstantArrayTypeBuilder::ARRAY_COUNT_LIMIT: past this many value types, nested arrays included, PHPStan generalises a union of array shapes.
     */
    private const PHPSTAN_ARRAY_COUNT_LIMIT = 256;

    /**
     * @internal built by CaptureShapeAnalyzer::analyze()
     *
     * @param array<int<1, max>, CaptureGroupShape> $groups keyed by group number, 1 to the capture count with no gap; branch reset groups sharing a number share a record
     * @param list<string>                          $marks  the names a (*MARK) verb, or a verb that sets one, may leave under "MARK"
     * @param list<self>                            $cases  shapes whose union covers every match, empty when the pattern is not split; a case shares no index with the alternatives of the pattern
     */
    public function __construct(
        public CaptureGroupShape $whole,
        public array $groups,
        public array $marks,
        public array $cases = [],
    ) {}

    /**
     * The array shape of $matches after preg_match() returned 1, written as a
     * PHPStan type, "array{0: 'a'|'ab', 1: 'a', 2?: 'b'}" for /(a)(b)?/, its keys in the order
     * preg_match() writes them, or the union of the shapes of the cases when
     * the pattern is split and PHPStan keeps that union as array shapes (the
     * merged shape otherwise). It honours PREG_UNMATCHED_AS_NULL and
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

        if ([] !== $this->cases) {
            $written = [];
            $count = 0;
            foreach ($this->cases as $case) {
                [$shape, $values] = $case->render($flags);
                $count += isset($written[$shape]) ? 0 : $values;
                $written[$shape] = true;
            }

            // Past the budget PHPStan reads the union as a list without keys: the merged shape says more.
            // PHPStan first merges the shapes that share their keys, and generalises only when what
            // is left still counts past the budget. This count, taken before any merging, is never
            // below PHPStan's: the merged shape may be written where PHPStan would keep the union,
            // which is sound, only less precise.
            if ($count <= self::PHPSTAN_ARRAY_COUNT_LIMIT) {
                return implode('|', array_keys($written));
            }
        }

        return $this->render($flags)[0];
    }

    /**
     * The array shape of $matches after preg_match_all(), written as a PHPStan
     * type. It holds for every call that returns an int, one that finds no
     * match included, so a list it writes may be empty; a caller that knows
     * the count is positive narrows it to non-empty-list. Like
     * preg_match_all(), it ignores a bit above the low byte, under either
     * order.
     *
     * preg_match_all() returns false and leaves [] for an offset past the
     * subject, and for a match that ends before it starts (\K in a
     * lookahead): a caller types such a call only for a pattern without \K
     * and an offset absent or <= 0. A match error (a subject that is not
     * UTF-8 under /u, an exhausted backtrack limit) returns false but stays
     * within the shape: every key, with the matches found before it.
     *
     * Under PREG_PATTERN_ORDER, the default, also read from 0: one array
     * shape, "array{0: list<'a'|'ab'>, 1: list<'a'>, 2: list<''|'b'>}" for
     * /(a)(b)?/. Every group key is written on every call, in the order
     * preg_match() writes them, each holding one value per match: what the
     * group captured, or '' where it was unset (null under
     * PREG_UNMATCHED_AS_NULL, a pair at offset -1 under PREG_OFFSET_CAPTURE).
     * A name several groups share (/J) holds the list of the highest-numbered
     * of them, set or not. The marks a verb leaves sit under "MARK", keyed by
     * the index of each match that set one, the key written only when some
     * match did.
     *
     * Under PREG_SET_ORDER: a list of what preg_match() writes at each match,
     * "list<S>" where S is matchShape() for the same PREG_OFFSET_CAPTURE and
     * PREG_UNMATCHED_AS_NULL flags.
     *
     * @throws InvalidRegexOptionException when the low byte of $flags is neither 0, PREG_PATTERN_ORDER nor PREG_SET_ORDER, as when both orders are set
     */
    public function matchAllShape(int $flags = \PREG_PATTERN_ORDER): string
    {
        $order = $flags & 0xFF;
        if (0 !== $order && \PREG_PATTERN_ORDER !== $order && \PREG_SET_ORDER !== $order) {
            throw new InvalidRegexOptionException(\sprintf('matchAllShape() accepts one of PREG_PATTERN_ORDER and PREG_SET_ORDER, with PREG_OFFSET_CAPTURE and PREG_UNMATCHED_AS_NULL, as preg_match_all() does; got flags %d.', $flags));
        }

        $flags &= \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
        if (\PREG_SET_ORDER === $order) {
            return 'list<'.$this->matchShape($flags).'>';
        }

        return $this->renderPatternOrder($flags);
    }

    /**
     * The one array shape of preg_match_all() under PREG_PATTERN_ORDER, read
     * from the merged view: a list per key, every key always written.
     */
    private function renderPatternOrder(int $flags): string
    {
        $unset = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL) ? 'null' : "''";
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);

        $marks = 'array<int, '.implode('|', $this->markTypes()).'>';
        $items = [];
        foreach (CaptureLayout::ofMatchAll($this)->keys as $key) {
            $items[] = self::keyName($key).': '.($key->holdsMarksOnly()
                ? $marks
                // Once a match sets a mark, PHP writes the marks over the list of a group named MARK.
                : 'list<'.self::entry(self::groupTypes($key, $unset), $key->alwaysSet, $offsets).'>'.($key->marks ? '|'.$marks : ''));
        }

        return 'array{'.implode(', ', $items).'}';
    }

    /**
     * The shape string, and the number of value types PHPStan counts in it:
     * one per key, two more per key written as an offset pair.
     *
     * @return array{0: string, 1: int}
     */
    private function render(int $flags): array
    {
        $asNull = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL);
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);
        $unset = $asNull ? 'null' : "''";

        // Under PREG_OFFSET_CAPTURE every key holds a pair, but a mark verb's own key.
        $pair = $offsets ? 3 : 1;
        $items = [];
        $values = 0;
        foreach (CaptureLayout::ofMatch($this, $asNull)->keys as $key) {
            if ($key->holdsMarksOnly()) {
                $items[] = self::keyName($key).': '.implode('|', $this->markTypes());
                $values++;

                continue;
            }

            $types = self::groupTypes($key, $unset);
            $items[] = self::keyName($key).': '.match (true) {
                !$key->marks => self::entry($types, $key->alwaysSet, $offsets),
                // A group named MARK shares its key with the mark verbs, whose names PHP writes as plain strings over it.
                $offsets => self::entry($types, $key->alwaysSet, true).'|'.implode('|', $this->markTypes()),
                default => self::entry([...$types, ...$this->markTypes()], $key->alwaysSet, false),
            };
            $values += $pair;
        }

        return ['array{'.implode(', ', $items).'}', $values];
    }

    private static function keyName(CaptureKey $key): string
    {
        return $key->key.($key->optional ? '?' : '');
    }

    /**
     * What the key holds from its groups: the value of each, and what an
     * unset group reads when the key may hold it.
     *
     * @return list<string>
     */
    private static function groupTypes(CaptureKey $key, string $unset): array
    {
        $types = array_map(self::valueType(...), $key->groups);
        if ($key->unset) {
            $types[] = $unset;
        }

        return $types;
    }

    /**
     * @return list<string>
     */
    private function markTypes(): array
    {
        if (!CaptureLayout::readsAsLiterals($this->marks)) {
            return ['non-empty-string'];
        }

        return array_map(self::literal(...), $this->marks);
    }

    /**
     * @param list<string> $types
     */
    private static function entry(array $types, bool $always, bool $offsets): string
    {
        $types = array_values(array_unique($types));
        // '' first and null last, as PHPStan prints them.
        usort($types, static fn (string $a, string $b): int => self::rank($a) <=> self::rank($b));
        // PHPStan's type parser reads an intersection inside a union only in parentheses.
        $type = 1 === \count($types) ? $types[0] : implode('|', array_map(static fn (string $type): string => str_contains($type, '&') ? '('.$type.')' : $type, $types));

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

        return match (true) {
            $group->digitsOnly && $group->nonFalsy => 'non-falsy-string&numeric-string',
            $group->digitsOnly => 'numeric-string',
            $group->nonFalsy => 'non-falsy-string',
            $group->minLength > 0 => 'non-empty-string',
            default => 'string',
        };
    }

    /**
     * The values as a union of PHPStan constant strings, or null when there
     * are too many or one cannot be written legibly.
     *
     * @param list<string> $values
     */
    private static function literals(array $values): ?string
    {
        return CaptureLayout::readsAsLiterals($values) ? implode('|', array_map(self::literal(...), $values)) : null;
    }

    private static function literal(string $value): string
    {
        return "'".addcslashes($value, "'\\")."'";
    }
}
