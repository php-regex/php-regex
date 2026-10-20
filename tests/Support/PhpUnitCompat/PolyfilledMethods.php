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

namespace PHPRegex\Tests\Support\PhpUnitCompat;

use PHPUnit\Framework\TestCase;

/**
 * The list of the TestCase methods this layer provides to the PHPUnit
 * releases that lack them, and the one place that picks which trait stands
 * behind each feature.
 *
 * Every feature trait has two implementations: Native\ holds an empty
 * trait, picked when the running PHPUnit has the method (PHPUnit declares
 * most of them final, so the native one must never be redeclared), and
 * Polyfill\ holds the method built on the older API. The file named after
 * the feature, beside this one, only calls load(), which loads the one the
 * running PHPUnit calls for and gives it the feature's name: the other one
 * never loads, and no name is ever declared twice.
 */
final class PolyfilledMethods
{
    /**
     * Each feature trait, the method it provides, the PHPUnit release that
     * first declares that method, and the methods it replaces: the ones
     * PHPUnit deprecates in its favour and the tests no longer call.
     */
    public const FEATURES = [
        // https://github.com/sebastianbergmann/phpunit/issues/6560
        ExpectExceptionMessageIsOrContains::class => [
            'method' => 'expectExceptionMessageIsOrContains',
            'since' => '13.2.0',
            'replaces' => ['expectExceptionMessage'],
        ],
    ];

    /**
     * Where no PHPUnit is installed, as Rector reads the tests, the method
     * is built on the older API.
     *
     * @param trait-string $trait
     */
    public static function isNative(string $trait): bool
    {
        return class_exists(TestCase::class) && (new \ReflectionClass(TestCase::class))->hasMethod(self::FEATURES[$trait]['method']);
    }

    /**
     * The implementation that stands behind the feature trait, Native\ or
     * Polyfill\.
     *
     * @param trait-string $trait
     */
    public static function implementation(string $trait, bool $native): string
    {
        $at = (int) strrpos($trait, '\\');

        return substr($trait, 0, $at).($native ? '\\Native' : '\\Polyfill').substr($trait, $at);
    }

    /**
     * @param trait-string $trait
     */
    public static function file(string $trait, bool $native): string
    {
        $name = substr($trait, (int) strrpos($trait, '\\') + 1);

        return __DIR__.($native ? '/Native/' : '/Polyfill/').$name.'.php';
    }

    /**
     * Loads the implementation the running PHPUnit calls for under the
     * feature's name. It requires the file itself, for the static analysis
     * loads the traits with no Composer autoloader; and requires it again
     * while the trait is missing, for the analysis inside Rector looks a
     * class up by including its file with every read trapped, which leaves
     * the file counted as included though nothing in it ran.
     *
     * @param trait-string $trait
     */
    public static function load(string $trait): void
    {
        $native = self::isNative($trait);
        $implementation = self::implementation($trait, $native);
        if (!trait_exists($implementation, false)) {
            require self::file($trait, $native);
        }

        class_alias($implementation, $trait, false);
    }
}
