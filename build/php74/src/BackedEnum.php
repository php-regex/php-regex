<?php

declare(strict_types=1);

namespace PHPRegex\Parser;

/**
 * What \PHPRegex\Parser\BackedEnum is to a backed enum, for the classes the twin writes in
 * place of the enums.
 *
 * @property int|string $value
 *
 * @method static static      from(int|string $value)
 * @method static static|null tryFrom(int|string $value)
 *
 * @internal
 */
interface BackedEnum extends UnitEnum
{
}
