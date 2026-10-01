<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Linter\Rule\Support;

/**
 * Parses backreference syntax into a numeric or named target.
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
        if (preg_match('/^\\\\g\{?[+-]\d+\\}?$/', $ref) > 0) {
            return null;
        }

        if (preg_match('/^\\\\(\d+)$/', $ref, $matches) || preg_match('/^\\\\g\{?(\d+)\\}?$/', $ref, $matches)) {
            return ['type' => 'number', 'value' => (int) $matches[1]];
        }

        if (preg_match('/^\\\\k[<{\'](?<name>\w+)[>}\']$/', $ref, $matches)) {
            return ['type' => 'name', 'value' => $matches['name']];
        }

        return null;
    }
}
