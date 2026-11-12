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

namespace PHPRegex\Tests\Unit\Lint\Command;

use PHPRegex\Linter\Config\TargetSpec;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A bound of a {"min", "max"} range: a PHP_VERSION_ID of PHP 7.0 up to
 * 9.99.99, or a version "x.y" or "x.y.z" of a major 7 to 9, as the schema
 * of regex.json takes it. Every entry point that checks a bound before
 * reading the range asks the same question, and minMax() reads what it
 * accepts.
 */
final class TargetSpecBoundTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideBounds')]
    public function test_a_bound_is_accepted_when_it_names_a_version(int|string $bound): void
    {
        $this->assertTrue(TargetSpec::isBound($bound));
    }

    /**
     * @return iterable<string, array{bound: int|string}>
     */
    public static function provideBounds(): iterable
    {
        yield 'a PHP_VERSION_ID' => ['bound' => 80100];
        yield 'the oldest PHP_VERSION_ID' => ['bound' => 70000];
        yield 'the latest PHP_VERSION_ID' => ['bound' => 99999];
        yield 'a version' => ['bound' => '8.1'];
        yield 'a release' => ['bound' => '8.1.10'];
        yield 'the latest release' => ['bound' => '9.99.99'];
    }

    #[Test]
    #[DataProvider('provideNonBounds')]
    public function test_a_bound_is_refused_when_it_names_no_version(int|float|string|bool|null $bound): void
    {
        $this->assertFalse(TargetSpec::isBound($bound));
    }

    /**
     * @return iterable<string, array{bound: int|float|string|bool|null}>
     */
    public static function provideNonBounds(): iterable
    {
        yield 'PHP 10' => ['bound' => '10.1'];
        yield 'a PHP_VERSION_ID before PHP 7' => ['bound' => 60000];
        yield 'a PHP_VERSION_ID past 9.99.99' => ['bound' => 100000];
        yield 'zero' => ['bound' => 0];
        yield 'a float' => ['bound' => 8.1];
        yield 'a boolean' => ['bound' => true];
        yield 'null' => ['bound' => null];
        yield 'a major alone' => ['bound' => '8'];
        yield 'a leading zero' => ['bound' => '08.1'];
        yield 'a patch past 99' => ['bound' => '8.1.100'];
        yield 'surrounding space' => ['bound' => ' 8.1'];
        yield 'a PHP_VERSION_ID as a string' => ['bound' => '80100'];
    }

    /**
     * What isBound() refuses, minMax() refuses with the object's message.
     */
    #[Test]
    public function test_min_max_refuses_what_is_no_bound(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains(TargetSpec::OBJECT_EXPECTED);

        TargetSpec::minMax('10.1', '10.2');
    }

    /**
     * A PHP_VERSION_ID below PHP 7.0 is no bound either, as min or as max.
     */
    #[Test]
    public function test_min_max_refuses_a_php_version_id_below_php_7(): void
    {
        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains(TargetSpec::OBJECT_EXPECTED);

        TargetSpec::minMax(60000, 80100);
    }

    /**
     * The interval a {min, max} object reads as: min is the first version
     * judged; a max written x.y takes in its whole series, a max of three
     * parts or a PHP_VERSION_ID names one release.
     *
     * @param array{int, int} $interval
     */
    #[Test]
    #[DataProvider('provideMinMaxIntervals')]
    public function test_min_max_reads_its_bounds_into_an_interval(int|string $min, int|string $max, array $interval): void
    {
        $this->assertSame($interval, TargetSpec::minMax($min, $max));
    }

    /**
     * @return iterable<string, array{min: int|string, max: int|string, interval: array{int, int}}>
     */
    public static function provideMinMaxIntervals(): iterable
    {
        yield 'two minors' => ['min' => '8.1', 'max' => '8.6', 'interval' => [80100, 80700]];
        yield 'two releases' => ['min' => '8.1.4', 'max' => '8.4.24', 'interval' => [80104, 80425]];
        yield 'two PHP_VERSION_IDs' => ['min' => 80100, 'max' => 80600, 'interval' => [80100, 80601]];
        yield 'a minor up to a PHP_VERSION_ID' => ['min' => '8.1', 'max' => 80699, 'interval' => [80100, 80700]];
        yield 'one minor' => ['min' => '8.1', 'max' => '8.1', 'interval' => [80100, 80200]];
        yield 'a two-digit minor' => ['min' => '7.4', 'max' => '7.10.3', 'interval' => [70400, 71004]];
    }
}
