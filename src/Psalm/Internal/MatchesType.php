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

namespace PHPRegex\Psalm\Internal;

use PHPRegex\Parser\Analysis\CaptureGroupShape;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Internal\CaptureKey;
use PHPRegex\Parser\Internal\CaptureLayout;
use Psalm\Config;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TNonFalsyString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * The Psalm type of $matches, built as Psalm atomics from a capture shape:
 * the facts CaptureShape::matchShape() and matchAllShape() write for
 * PHPStan, key for key, from the same layout, but numeric-string. Psalm
 * 6.19's type combiner reads numeric-string|'a' as numeric-string, in the
 * plugin's unions and in the user's code alike (preg_match(…) ? $m[1] :
 * 'none'): a group of digits is non-falsy-string where every value is
 * truthy, else non-empty-string. The cases of a split pattern are merged key
 * by key into one array shape: a key some case leaves out is optional.
 *
 * Building a literal string needs Psalm's configuration: this runs inside a
 * Psalm analysis.
 *
 * @internal
 */
final class MatchesType
{
    /**
     * What preg_match() writes on a match: a sealed array shape, an offset
     * pair being list{string, int}. It honours PREG_OFFSET_CAPTURE and
     * PREG_UNMATCHED_AS_NULL, and, like preg_match(), ignores a bit above
     * the low byte.
     *
     * @throws InvalidRegexOptionException when $flags has a bit of the low byte set, as PREG_SET_ORDER does
     */
    public static function ofMatch(CaptureShape $shape, int $flags = 0): Union
    {
        if (0 !== ($flags & 0xFF)) {
            throw new InvalidRegexOptionException(\sprintf('A $matches type of preg_match() takes PREG_OFFSET_CAPTURE and PREG_UNMATCHED_AS_NULL only, as preg_match() does; got flags %d.', $flags));
        }

        $flags &= \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
        if ([] === $shape->cases) {
            return new Union([TKeyedArray::make(self::matchProperties($shape, $flags))]);
        }

        // Merged key by key: each key holds what it holds in any case, and is optional where a case leaves it out.
        $properties = [];
        $present = [];
        foreach ($shape->cases as $case) {
            foreach (self::matchProperties($case, $flags) as $key => $value) {
                $properties[$key] = self::union($properties[$key] ?? null, $value);
                $present[$key] = ($present[$key] ?? 0) + 1;
            }
        }

        foreach ($properties as $key => $value) {
            if ($present[$key] < \count($shape->cases)) {
                $properties[$key] = $value->setPossiblyUndefined(true);
            }
        }

        return new Union([TKeyedArray::make($properties)]);
    }

    /**
     * What preg_match_all() writes on every call that compiles, one that
     * finds no match included: under PREG_PATTERN_ORDER, the default, one
     * list per key; under PREG_SET_ORDER, a list of what preg_match() writes.
     *
     * @throws InvalidRegexOptionException when the low byte of $flags is neither 0, PREG_PATTERN_ORDER nor PREG_SET_ORDER
     */
    public static function ofMatchAll(CaptureShape $shape, int $flags = \PREG_PATTERN_ORDER): Union
    {
        $order = $flags & 0xFF;
        if (0 !== $order && \PREG_PATTERN_ORDER !== $order && \PREG_SET_ORDER !== $order) {
            throw new InvalidRegexOptionException(\sprintf('A $matches type of preg_match_all() takes one of PREG_PATTERN_ORDER and PREG_SET_ORDER, with PREG_OFFSET_CAPTURE and PREG_UNMATCHED_AS_NULL, as preg_match_all() does; got flags %d.', $flags));
        }

        $flags &= \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;
        if (\PREG_SET_ORDER === $order) {
            return Type::getList(self::ofMatch($shape, $flags));
        }

        return new Union([self::patternOrderShape($shape, $flags)]);
    }

    /**
     * The most keys one array shape of the type holds: Psalm keeps no shape
     * past its maxShapedArraySize. The flags are read as ofMatch() or
     * ofMatchAll() reads them.
     */
    public static function keyCount(CaptureShape $shape, int $flags, bool $matchAll): int
    {
        if ($matchAll && \PREG_SET_ORDER !== ($flags & 0xFF)) {
            return \count(CaptureLayout::ofMatchAll($shape)->keys);
        }

        $count = 0;
        foreach ([] === $shape->cases ? [$shape] : $shape->cases as $case) {
            $count = max($count, \count(CaptureLayout::ofMatch($case, 0 !== ($flags & \PREG_UNMATCHED_AS_NULL))->keys));
        }

        return $count;
    }

    /**
     * @return non-empty-array<int|string, Union>
     */
    private static function matchProperties(CaptureShape $shape, int $flags): array
    {
        $asNull = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL);
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);
        $marks = self::markType($shape);

        $properties = [];
        foreach (CaptureLayout::ofMatch($shape, $asNull)->keys as $key) {
            if ($key->holdsMarksOnly()) {
                // A mark verb's own key holds the name, never an offset pair.
                $value = $marks;
            } else {
                $value = self::entry(self::groupType($key, $asNull), $key->alwaysSet, $offsets);
                // A group named MARK shares its key with the mark verbs, whose names PHP writes as plain strings over it.
                if ($key->marks) {
                    $value = self::union($value, $marks);
                }
            }

            $properties[$key->key] = $value->setPossiblyUndefined($key->optional);
        }

        return $properties ?: throw new \LogicException('A layout holds the whole match under 0, always.');
    }

    private static function patternOrderShape(CaptureShape $shape, int $flags): TKeyedArray
    {
        $asNull = 0 !== ($flags & \PREG_UNMATCHED_AS_NULL);
        $offsets = 0 !== ($flags & \PREG_OFFSET_CAPTURE);
        // The marks sit under "MARK", keyed by the index of each match that set one.
        $marks = new Union([new TArray([Type::getInt(), self::markType($shape)])]);

        $properties = [];
        foreach (CaptureLayout::ofMatchAll($shape)->keys as $key) {
            if ($key->holdsMarksOnly()) {
                $value = $marks;
            } else {
                $value = Type::getList(self::entry(self::groupType($key, $asNull), $key->alwaysSet, $offsets));
                // Once a match sets a mark, PHP writes the marks over the list of a group named MARK.
                if ($key->marks) {
                    $value = self::union($value, $marks);
                }
            }

            $properties[$key->key] = $value->setPossiblyUndefined($key->optional);
        }

        return TKeyedArray::make($properties ?: throw new \LogicException('A layout holds the whole match under 0, always.'));
    }

    /**
     * A value, or the offset pair holding it: its offset is -1 when the
     * group may be unset.
     */
    private static function entry(Union $value, bool $alwaysSet, bool $offsets): Union
    {
        if (!$offsets) {
            return $value;
        }

        return new Union([TKeyedArray::make([$value, Type::getIntRange($alwaysSet ? 0 : -1, null)], null, null, true)]);
    }

    /**
     * What the key holds from its groups: the value of each, and what an
     * unset group reads when the key may hold it.
     */
    private static function groupType(CaptureKey $key, bool $asNull): Union
    {
        $type = null;
        foreach ($key->groups as $group) {
            $type = self::union($type, self::valueType($group));
        }

        if ($key->unset) {
            $type = self::union($type, new Union([$asNull ? new TNull() : TLiteralString::make('')]));
        }

        // A key with no group and no unset value holds marks only: the caller does not ask.
        return $type ?? Type::getNever();
    }

    /**
     * Every value of $a and of $b, optional when either is. Offset pairs
     * merge into one pair, element by element.
     */
    private static function union(?Union $a, Union $b): Union
    {
        if (null === $a) {
            return $b;
        }

        $pair = null;
        $atomics = [];
        foreach ([...array_values($a->getAtomicTypes()), ...array_values($b->getAtomicTypes())] as $atomic) {
            if ($atomic instanceof TKeyedArray && $atomic->is_list && isset($atomic->properties[0], $atomic->properties[1]) && 2 === \count($atomic->properties)) {
                $pair = null === $pair ? $atomic : TKeyedArray::make([
                    self::union($pair->properties[0], $atomic->properties[0]),
                    Type::combineUnionTypes($pair->properties[1], $atomic->properties[1]),
                ], null, null, true);

                continue;
            }

            $atomics[] = $atomic;
        }

        $type = null === $pair ? null : new Union([$pair]);
        foreach ($atomics as $atomic) {
            $type = Type::combineUnionTypes($type, new Union([$atomic]));
        }

        return ($type ?? Type::getNever())->setPossiblyUndefined($a->possibly_undefined || $b->possibly_undefined);
    }

    private static function valueType(CaptureGroupShape $group): Union
    {
        if (null !== $group->values && null !== $literals = self::literals($group->values)) {
            return $literals;
        }

        if (0 === $group->maxLength) {
            return new Union([TLiteralString::make('')]);
        }

        // No numeric-string: Psalm 6.19's combiner reads numeric-string|'a' as numeric-string.
        return new Union([match (true) {
            $group->nonFalsy => new TNonFalsyString(),
            $group->digitsOnly || $group->minLength > 0 => new TNonEmptyString(),
            default => new TString(),
        }]);
    }

    private static function markType(CaptureShape $shape): Union
    {
        return self::literals($shape->marks) ?? new Union([new TNonEmptyString()]);
    }

    /**
     * The values as a union of literal strings, or null when there are too
     * many, when one cannot be read legibly in an issue, or when one is past
     * the length Psalm keeps a literal for.
     *
     * @param list<string> $values
     */
    private static function literals(array $values): ?Union
    {
        if (!CaptureLayout::readsAsLiterals($values)) {
            return null;
        }

        $maxLength = Config::getInstance()->max_string_length;
        $literals = [];
        foreach ($values as $value) {
            if (\strlen($value) >= $maxLength) {
                return null;
            }

            $literals[] = TLiteralString::make($value);
        }

        return new Union($literals);
    }
}
