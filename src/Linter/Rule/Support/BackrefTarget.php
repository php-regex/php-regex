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

namespace PHPRegex\Linter\Rule\Support;

use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Parses backreference syntax into a numeric or named target.
 *
 * It reads the target as written, not the groups it resolves to: the rules
 * report a reference to a missing group by its number or name, and read
 * "\11" as an octal escape when no group bears 11, where the group index
 * finds no group either way. A condition and a relative reference are left
 * out on purpose.
 *
 * @internal
 */
final class BackrefTarget
{
    private function __construct() {}

    /**
     * @return array{type: 'number', value: int}|array{type: 'name', value: string}|null
     */
    public static function parse(string $ref): ?array
    {
        if (LibraryPcre::match('/^\\\\g\{?[+-]\d+\\}?$/', $ref) > 0) {
            return null;
        }

        if (LibraryPcre::match('/^\\\\(\d+)$/', $ref, $matches) || LibraryPcre::match('/^\\\\g\{?(\d+)\\}?$/', $ref, $matches)) {
            return ['type' => 'number', 'value' => (int) $matches[1]];
        }

        if (LibraryPcre::match('/^\\\\k[<{\'](?<name>\w+)[>}\']$/', $ref, $matches)) {
            return ['type' => 'name', 'value' => $matches['name']];
        }

        return null;
    }
}
