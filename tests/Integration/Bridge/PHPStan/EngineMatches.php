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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * The $matches the engine writes, held against a PHPStan type: the parity
 * corpus, and the reasons a type refuses one value, for preg_match() and
 * for preg_match_all() in either order.
 *
 * An array shape takes extra keys as a subtype, so isSuperTypeOf() alone
 * would accept a $matches holding a key the type does not list; reading that
 * key is then reported as an offset that does not exist. A shape holds the
 * value only when each key the engine writes is one of its keys, each key it
 * requires is written, and each written value is one its key accepts.
 */
final class EngineMatches
{
    public const CORPUS = __DIR__.'/../../../Fixtures/CaptureShapeParity/cases.php';

    /**
     * The flag sets a preg_match() call can pass that change the shape.
     */
    public const FLAG_SETS = [
        0 => '0',
        \PREG_UNMATCHED_AS_NULL => 'PREG_UNMATCHED_AS_NULL',
        \PREG_OFFSET_CAPTURE => 'PREG_OFFSET_CAPTURE',
        \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL => 'PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL',
    ];

    /**
     * The order and flag combinations a preg_match_all() call can pass that
     * change the shape: each order with each preg_match() flag set.
     */
    public const MATCH_ALL_FLAG_SETS = [
        \PREG_PATTERN_ORDER => 'PREG_PATTERN_ORDER',
        \PREG_PATTERN_ORDER | \PREG_UNMATCHED_AS_NULL => 'PREG_PATTERN_ORDER | PREG_UNMATCHED_AS_NULL',
        \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE => 'PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE',
        \PREG_PATTERN_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL => 'PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL',
        \PREG_SET_ORDER => 'PREG_SET_ORDER',
        \PREG_SET_ORDER | \PREG_UNMATCHED_AS_NULL => 'PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL',
        \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE => 'PREG_SET_ORDER | PREG_OFFSET_CAPTURE',
        \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL => 'PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL',
    ];

    /**
     * Subjects tried, in order, when a replay needs one the pattern does not
     * match: preg_match_all() then writes its empty result.
     */
    private const NON_MATCHING_CANDIDATES = ["\x00", '', '#', ' ', "\n", '0', 'Z', '~!~'];

    /**
     * @return list<array{source: string, pattern: string, subjects: non-empty-list<string>}>
     */
    public static function corpus(): array
    {
        /** @var list<array{source: string, pattern: string, subjects: non-empty-list<string>}> $rows */
        $rows = require self::CORPUS;

        return $rows;
    }

    /**
     * @param array<int|string, mixed> $matches
     *
     * @return list<string> why the type refuses the value; empty when it holds it
     */
    public static function refusals(Type $type, array $matches): array
    {
        $value = ConstantTypeHelper::getTypeFromValue($matches);

        if ($type->isConstantArray()->yes()) {
            $best = null;
            foreach ($type->getConstantArrays() as $shape) {
                $refusals = self::shapeRefusals($shape, $matches, $value);
                if ([] === $refusals) {
                    return [];
                }
                if (null === $best || \count($refusals) < \count($best)) {
                    $best = $refusals;
                }
            }

            return $best ?? ['no shape to read'];
        }

        $refusals = [];
        foreach ($matches as $key => $item) {
            $keyType = ConstantTypeHelper::getTypeFromValue($key);
            if ($type->hasOffsetValueType($keyType)->no()) {
                $refusals[] = \sprintf('key %s is written, the type has no such key', self::key($key));

                continue;
            }

            $offsetType = $type->getOffsetValueType($keyType);
            $itemType = ConstantTypeHelper::getTypeFromValue($item);
            if (!$offsetType->isSuperTypeOf($itemType)->yes()) {
                $refusals[] = \sprintf('key %s holds %s, outside %s', self::key($key), $itemType->describe(VerbosityLevel::precise()), $offsetType->describe(VerbosityLevel::precise()));
            }
        }

        if ([] === $refusals && !$type->isSuperTypeOf($value)->yes()) {
            $refusals[] = \sprintf('%s is outside the type', $value->describe(VerbosityLevel::precise()));
        }

        return $refusals;
    }

    /**
     * Why a preg_match_all() shape refuses the $matches the engine writes.
     * Under PREG_SET_ORDER the type is a list: the engine must write a list,
     * and each set is held against the element type as refusals() holds a
     * preg_match() result, extra keys included. Under PREG_PATTERN_ORDER the
     * type is one array shape whose keys hold lists, held as refusals() does.
     *
     * @param array<int|string, mixed> $matches
     *
     * @return list<string> why the type refuses the value; empty when it holds it
     */
    public static function matchAllRefusals(Type $type, array $matches, int $flags): array
    {
        if (0 === ($flags & \PREG_SET_ORDER)) {
            if (!$type->isConstantArray()->yes()) {
                return [\sprintf('%s is not an array shape', $type->describe(VerbosityLevel::precise()))];
            }

            return self::refusals($type, $matches);
        }

        if (!$type->isList()->yes()) {
            return [\sprintf('%s is not a list', $type->describe(VerbosityLevel::precise()))];
        }

        if (!array_is_list($matches)) {
            return ['the engine writes sets that are not a list'];
        }

        $element = $type->getIterableValueType();
        $refusals = [];
        foreach ($matches as $index => $set) {
            if (!\is_array($set)) {
                $refusals[] = \sprintf('set %d is not an array', $index);

                continue;
            }

            foreach (self::refusals($element, $set) as $refusal) {
                $refusals[] = \sprintf('set %d: %s', $index, $refusal);
            }
        }

        $value = ConstantTypeHelper::getTypeFromValue($matches);
        // Each set is checked above. The whole value adds the list itself, but
        // only while PHPStan keeps its sets as constant shapes: past PHPStan's
        // value-type limit it generalises the engine's value, which then says
        // nothing about the shape written.
        if ([] === $refusals && $value->getIterableValueType()->isConstantArray()->yes() && !$type->isSuperTypeOf($value)->yes()) {
            $refusals[] = \sprintf('%s is outside %s', $value->describe(VerbosityLevel::precise()), $type->describe(VerbosityLevel::precise()));
        }

        return $refusals;
    }

    /**
     * The subjects a preg_match_all() replay runs a corpus row on: the
     * concatenation of its subjects (several matches in one call), each
     * subject alone, and a subject the pattern does not match, when one of
     * the candidates is such a subject.
     *
     * @param non-empty-list<string> $subjects
     *
     * @return list<string>
     */
    public static function matchAllSubjects(string $pattern, array $subjects): array
    {
        $replayed = [implode('', $subjects), ...$subjects];
        foreach (self::NON_MATCHING_CANDIDATES as $candidate) {
            if (0 === @preg_match($pattern, $candidate)) {
                $replayed[] = $candidate;

                break;
            }
        }

        return array_values(array_unique($replayed));
    }

    /**
     * @param array<int|string, mixed> $matches
     *
     * @return list<string>
     */
    private static function shapeRefusals(ConstantArrayType $shape, array $matches, Type $value): array
    {
        $refusals = [];
        $known = [];
        foreach ($shape->getKeyTypes() as $index => $keyType) {
            $key = $keyType->getValue();
            $known[$key] = true;
            if (!$shape->isOptionalKey($index) && !\array_key_exists($key, $matches)) {
                $refusals[] = \sprintf('key %s is required, the engine does not write it', self::key($key));
            }
        }

        foreach ($matches as $key => $item) {
            if (!isset($known[$key])) {
                $refusals[] = \sprintf('key %s is written, the type has no such key', self::key($key));

                continue;
            }

            $offsetType = $shape->getOffsetValueType(ConstantTypeHelper::getTypeFromValue($key));
            $itemType = ConstantTypeHelper::getTypeFromValue($item);
            if (!$offsetType->isSuperTypeOf($itemType)->yes()) {
                $refusals[] = \sprintf('key %s holds %s, outside %s', self::key($key), $itemType->describe(VerbosityLevel::precise()), $offsetType->describe(VerbosityLevel::precise()));
            }
        }

        if ([] === $refusals && !$shape->isSuperTypeOf($value)->yes()) {
            $refusals[] = \sprintf('%s is outside %s', $value->describe(VerbosityLevel::precise()), $shape->describe(VerbosityLevel::precise()));
        }

        return $refusals;
    }

    private static function key(int|string $key): string
    {
        return \is_int($key) ? (string) $key : "'".$key."'";
    }
}
