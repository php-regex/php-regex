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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\PHPStan\PatternChecker;
use PHPRegex\PHPStan\RegexPatternArgumentRule;
use PHPRegex\PHPStan\RegexPatternRule;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * PHPStan's "phpVersion" parameter is one version id, a {min, max} range,
 * or unset (its schema refuses anything else): the rules take nothing
 * else either.
 */
final class RegexPatternRulePhpVersionRangeTest extends PHPStanTestCase
{
    #[Test]
    #[DataProvider('provideAcceptedRanges')]
    public function test_an_accepted_range_constructs_every_rule(mixed $range): void
    {
        foreach (self::constructors() as $class => $arguments) {
            $this->assertInstanceOf($class, new $class(...$arguments, ...[$range]));
        }
    }

    #[Test]
    #[DataProvider('provideRefusedRanges')]
    public function test_a_value_phpstan_never_passes_is_refused_by_every_rule(mixed $range): void
    {
        foreach (self::constructors() as $class => $arguments) {
            try {
                new $class(...$arguments, ...[$range]);
                $this->fail(\sprintf('%s accepted a %s range.', $class, get_debug_type($range)));
            } catch (\TypeError $error) {
                $this->assertStringContainsString('$phpVersionRange', $error->getMessage(), $class);
            }
        }
    }

    /**
     * @return iterable<string, array{range: mixed}>
     */
    public static function provideAcceptedRanges(): iterable
    {
        yield 'unset' => ['range' => null];
        yield 'one version' => ['range' => 80200];
        yield 'a range' => ['range' => ['min' => 80200, 'max' => 80599]];
    }

    /**
     * @return iterable<string, array{range: mixed}>
     */
    public static function provideRefusedRanges(): iterable
    {
        yield 'a string' => ['range' => 'garbage'];
        yield 'a float' => ['range' => 3.14];
        yield 'a boolean' => ['range' => true];
    }

    /**
     * The arguments each constructor takes before the range.
     *
     * @return array<class-string, list<mixed>>
     */
    private static function constructors(): array
    {
        return [
            PatternChecker::class => [[], null],
            RegexPatternRule::class => [[], null],
            RegexPatternArgumentRule::class => [self::getContainer()->getByType(ReflectionProvider::class), [], null],
        ];
    }
}
