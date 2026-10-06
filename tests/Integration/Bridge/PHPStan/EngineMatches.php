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
 * corpus, and the reasons a type refuses one value.
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
