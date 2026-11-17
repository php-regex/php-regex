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

namespace PHPRegex\Tests\Unit\Cli;

use PHPRegex\Cli\Command\RedosArguments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A subject, a prefix, a suffix or a pattern is free text: one that starts
 * with "-" is read after the space as after "=", "-" being a delimiter PHP
 * takes ("-a-" compiles).
 */
final class RedosArgumentsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFreeTextOptions')]
    public function test_a_free_text_value_may_start_with_a_dash(string $option, string $property): void
    {
        $spaced = RedosArguments::parse(['/a/', $option, '-x-']);
        $attached = RedosArguments::parse(['/a/', $option.'=-x-']);

        $this->assertInstanceOf(RedosArguments::class, $spaced);
        $this->assertInstanceOf(RedosArguments::class, $attached);
        $this->assertSame('-x-', $spaced->{$property});
        $this->assertSame($attached->{$property}, $spaced->{$property});
    }

    /**
     * @return iterable<string, array{option: string, property: string}>
     */
    public static function provideFreeTextOptions(): iterable
    {
        yield 'the input' => ['option' => '--input', 'property' => 'input'];
        yield 'the prefix' => ['option' => '--prefix', 'property' => 'prefix'];
        yield 'the suffix' => ['option' => '--suffix', 'property' => 'suffix'];
        yield 'the safe pattern' => ['option' => '--safe', 'property' => 'safePattern'];
    }

    /**
     * A count is written in digits alone and read back as written: one past
     * the integer range was read as PHP_INT_MAX, and --repeat then asked
     * for more memory than PHP gives.
     */
    #[Test]
    #[DataProvider('provideCountsThatDoNotReadBack')]
    public function test_a_count_that_does_not_read_back_as_written_is_refused(string $option, string $value): void
    {
        $this->assertSame('Invalid value for '.$option.'.', RedosArguments::parse(['/a/', $option.'='.$value]));
    }

    /**
     * @return iterable<string, array{option: string, value: string}>
     */
    public static function provideCountsThatDoNotReadBack(): iterable
    {
        yield 'a repeat past the integer range' => ['option' => '--repeat', 'value' => '99999999999999999999'];
        yield 'iterations past the integer range' => ['option' => '--iterations', 'value' => '99999999999999999999'];
        yield 'a warmup with a leading zero' => ['option' => '--warmup', 'value' => '01'];
        yield 'a backtrack limit past the integer range' => ['option' => '--backtrack-limit', 'value' => '18446744073709551616'];
    }

    #[Test]
    public function test_a_count_in_range_is_read(): void
    {
        $arguments = RedosArguments::parse(['/a/', '--repeat=3', '--warmup=0', '--backtrack-limit='.\PHP_INT_MAX]);

        $this->assertInstanceOf(RedosArguments::class, $arguments);
        $this->assertSame([3, 0, \PHP_INT_MAX], [$arguments->repeat, $arguments->warmup, $arguments->backtrackLimit]);
    }
}
