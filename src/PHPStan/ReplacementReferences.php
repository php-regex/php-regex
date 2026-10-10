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

namespace PHPRegex\PHPStan;

use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * The group references of a preg_replace() replacement, read as PHP reads
 * it: "$n", "${n}" and "\n" with one or two digits ("$10" is group 10, never
 * group 1 then "0"); a "\" or "$" right after a backslash written as is
 * ("\\$1" is a backslash then group 1, "\$1" the text "$1"). "${name}" is
 * no reference, PHP leaves it in the result as written: listed with its name.
 *
 * @phpstan-type GroupReference array{raw: string, group: int|null, name: string|null}
 *
 * @internal
 */
final class ReplacementReferences
{
    /**
     * @return list<GroupReference> in the order they are written
     */
    public static function of(string $replacement): array
    {
        $references = [];
        $previous = '';
        $offset = 0;
        $length = \strlen($replacement);
        while ($offset < $length) {
            $char = $replacement[$offset];
            if ('\\' === $char || '$' === $char) {
                // The backslash before it is dropped, the character kept.
                if ('\\' === $previous) {
                    $previous = '';
                    $offset++;

                    continue;
                }

                if (1 === LibraryPcre::match('/\G(?:\$\{([0-9]{1,2})\}|[\\\\$]([0-9]{1,2}))/', $replacement, $match, 0, $offset)) {
                    $references[] = ['raw' => $match[0], 'group' => (int) (($match[1] ?? '').($match[2] ?? '')), 'name' => null];
                    $offset += \strlen($match[0]);

                    continue;
                }

                if ('$' === $char && 1 === LibraryPcre::match('/\G\$\{([_A-Za-z\x80-\xFF][_A-Za-z0-9\x80-\xFF]*)\}/', $replacement, $match, 0, $offset)) {
                    $references[] = ['raw' => $match[0], 'group' => null, 'name' => $match[1]];
                }
            }

            $previous = $char;
            $offset++;
        }

        return $references;
    }
}
