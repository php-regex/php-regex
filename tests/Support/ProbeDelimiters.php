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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\Hir\ClassSetProvider;

/**
 * The delimiters ClassSetProvider may write its engine probe with, read
 * from the provider itself, and the classes holding every one of them: no
 * probe can be written for those, which the tests of what reads a set
 * through the probe ask about. A delimiter the provider gains is one these
 * classes gain too.
 */
final class ProbeDelimiters
{
    private function __construct() {}

    /**
     * @return non-empty-list<string>
     */
    public static function all(): array
    {
        $delimiters = (new \ReflectionClassConstant(ClassSetProvider::class, 'DELIMITERS'))->getValue();
        \assert(\is_array($delimiters) && array_is_list($delimiters) && [] !== $delimiters && array_filter($delimiters, \is_string(...)) === $delimiters);

        return $delimiters;
    }

    /**
     * A class holding every delimiter, and the characters given after them.
     */
    public static function classOfEveryOne(string $more = ''): string
    {
        return '['.implode('', self::all()).$more.']';
    }

    /**
     * A caseless pattern matching one character of that class, between
     * "/" delimiters.
     */
    public static function caselessPatternOfEveryOne(): string
    {
        return '/^'.str_replace('/', '\\/', self::classOfEveryOne()).'$/i';
    }
}
