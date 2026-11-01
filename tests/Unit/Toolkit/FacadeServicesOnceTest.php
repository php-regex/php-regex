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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Validation\CompatibilityChecker;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The facade lives as long as the application that holds it, in a container
 * or a singleton: the stateless services it answers through are built on
 * their first call and kept, not rebuilt on every call. A facade for another
 * target is another facade, with services of its own.
 */
final class FacadeServicesOnceTest extends TestCase
{
    /**
     * @param class-string           $class
     * @param \Closure(Regex): mixed $call
     */
    #[Test]
    #[DataProvider('services')]
    public function test_a_stateless_service_is_built_once_per_facade(string $property, string $class, \Closure $call): void
    {
        $regex = Regex::create();

        $this->assertNull(self::serviceOf($regex, $property), 'Nothing is built before the first call.');
        $call($regex);
        $first = self::serviceOf($regex, $property);
        $call($regex);

        $this->assertInstanceOf($class, $first);
        $this->assertSame($first, self::serviceOf($regex, $property), 'The second call answers through the service the first one built.');
    }

    #[Test]
    public function test_a_facade_for_another_target_builds_services_of_its_own(): void
    {
        $regex = Regex::create();
        $regex->redos('/(a+)+b/');
        $other = $regex->withTarget(PcreTarget::bundledWith(80200));
        $other->redos('/(a+)+b/');

        $this->assertNotSame($regex, $other);
        $this->assertNotSame(self::serviceOf($regex, 'redosAnalyzer'), self::serviceOf($other, 'redosAnalyzer'));
    }

    /**
     * @return iterable<string, array{property: string, class: class-string, call: \Closure(Regex): mixed}>
     */
    public static function services(): iterable
    {
        yield 'redos analyzer' => ['property' => 'redosAnalyzer', 'class' => RedosAnalyzer::class, 'call' => static fn (Regex $regex): mixed => $regex->redos('/(a+)+b/')];
        yield 'optimizer' => ['property' => 'optimizer', 'class' => Optimizer::class, 'call' => static fn (Regex $regex): mixed => $regex->optimize('/[0-9]+/')];
        yield 'transpiler' => ['property' => 'transpiler', 'class' => Transpiler::class, 'call' => static fn (Regex $regex): mixed => $regex->transpile('/a+/', 'javascript')];
        yield 'compatibility checker' => ['property' => 'compatibilityChecker', 'class' => CompatibilityChecker::class, 'call' => static fn (Regex $regex): mixed => $regex->compatibility('/a+/')];
    }

    private static function serviceOf(Regex $regex, string $property): ?object
    {
        $value = (new \ReflectionProperty($regex, $property))->getValue($regex);

        return \is_object($value) ? $value : null;
    }
}
