<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * The classes the twin writes in place of the enums, and the way back to the
 * shared instance of a case a cache restored: unserialize() builds a new
 * object where PHP 8.1 gives the enum case itself, and a comparison by
 * identity tells them apart.
 *
 * @internal
 */
final class EnumCases
{
    /**
     * @var list<class-string<UnitEnum>>
     */
    public const CLASSES = [
        Analysis\LiteralConfidence::class,
        Analysis\Participation::class,
        BsrConvention::class,
        ErrorCode::class,
        Hir\AssertionKind::class,
        Hir\Greed::class,
        Hir\LookKind::class,
        NewlineConvention::class,
        Node\CharLiteralType::class,
        Node\ClassSetOperator::class,
        Node\GroupType::class,
        Node\QuantifierType::class,
        PcreFeature::class,
        Printer\FormatRefusal::class,
        Resolution\ArgumentState::class,
        Token\TokenType::class,
        TraversalAction::class,
        Validation\ValidationErrorCategory::class,
    ];

    /**
     * The value with each case it holds, at any depth, replaced by the
     * shared instance of that case.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function shared($value)
    {
        return self::walk($value, new \SplObjectStorage());
    }

    /**
     * @param mixed                            $value
     * @param \SplObjectStorage<object, mixed> $seen
     *
     * @return mixed
     */
    private static function walk($value, \SplObjectStorage $seen)
    {
        if ($value instanceof UnitEnum) {
            foreach ($value::cases() as $case) {
                if ($case->name === $value->name) {
                    return $case;
                }
            }

            return $value;
        }

        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::walk($item, $seen);
            }

            return $value;
        }

        // array access, not contains() and attach(): PHP 8.5 deprecates them
        if (!\is_object($value) || isset($seen[$value])) {
            return $value;
        }

        $seen[$value] = true;
        for ($class = new \ReflectionObject($value); false !== $class; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $class->getName()) {
                    continue;
                }

                // needed before PHP 8.1, deprecated from 8.5
                if (\PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                if (!$property->isInitialized($value)) {
                    continue;
                }

                $item = $property->getValue($value);
                $shared = self::walk($item, $seen);
                if ($shared !== $item) {
                    $property->setValue($value, $shared);
                }
            }
        }

        return $value;
    }
}
