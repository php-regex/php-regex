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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Tests\Support\PublicSurface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Result objects are built by the library: the class, its methods and its
 * properties are public, its constructor is @internal, so a minor release may
 * give it a new parameter.
 */
final class ResultConstructorTest extends TestCase
{
    #[Test]
    #[DataProvider('provideResults')]
    public function test_result_constructors_are_internal(string $class, string $file): void
    {
        $this->assertTrue(class_exists($class), $class.' does not exist.');
        $constructor = (new \ReflectionClass($class))->getConstructor();

        $this->assertInstanceOf(\ReflectionMethod::class, $constructor, $class.' declares no constructor ('.$file.').');
        $this->assertSame($class, $constructor->getDeclaringClass()->getName(), $class.' must declare its own constructor ('.$file.').');
        $this->assertMatchesRegularExpression(
            '~^\s*\*\s*@internal\b~m',
            (string) $constructor->getDocComment(),
            $class.'::__construct() is built by the library: its docblock says "@internal built by <producer>" ('.$file.').',
        );
    }

    /**
     * @return iterable<string, array{class: string, file: string}>
     */
    public static function provideResults(): iterable
    {
        foreach (PublicSurface::RESULTS as $package => $classes) {
            foreach ($classes as $class) {
                yield $package.'/'.$class => [
                    'class' => 'PHPRegex\\'.$package.'\\'.str_replace('/', '\\', $class),
                    'file' => 'src/'.$package.'/'.$class.'.php',
                ];
            }
        }
    }
}
