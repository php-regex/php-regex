<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * What \PHPRegex\Parser\UnitEnum is to an enum, for the classes the twin writes in place of
 * the enums: PHP lets no class implement \PHPRegex\Parser\UnitEnum.
 *
 * @property string $name
 *
 * @internal
 */
interface UnitEnum
{
    /**
     * @return list<static>
     */
    public static function cases(): array;
}
