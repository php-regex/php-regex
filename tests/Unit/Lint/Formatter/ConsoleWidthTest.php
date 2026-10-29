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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\Formatter\ConsoleWidth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The width a console report folds at: COLUMNS when the shell exports it,
 * the terminal's width on a TTY, 120 otherwise, never above 120 nor below
 * 40.
 */
final class ConsoleWidthTest extends TestCase
{
    /**
     * @return iterable<string, array{columns: string|false, expected: ?int}>
     */
    public static function provideColumns(): iterable
    {
        yield 'wider than the cap' => ['columns' => '200', 'expected' => 120];
        yield 'the cap itself' => ['columns' => '120', 'expected' => 120];
        yield 'a usual terminal' => ['columns' => '80', 'expected' => 80];
        yield 'below the floor' => ['columns' => '10', 'expected' => 40];
        yield 'not a number' => ['columns' => 'abc', 'expected' => null];
        yield 'empty' => ['columns' => '', 'expected' => null];
        yield 'zero' => ['columns' => '0', 'expected' => null];
        yield 'negative' => ['columns' => '-5', 'expected' => null];
        yield 'digits then letters' => ['columns' => '12abc', 'expected' => null];
        yield 'leading zeros' => ['columns' => '00080', 'expected' => 80];
        yield 'unset' => ['columns' => false, 'expected' => null];
    }

    #[Test]
    #[DataProvider('provideColumns')]
    public function test_columns_is_read_as_a_width_when_it_is_one(string|false $columns, ?int $expected): void
    {
        $this->assertSame($expected, ConsoleWidth::fromColumns($columns));
    }

    #[Test]
    public function test_the_width_is_clamped_between_the_floor_and_the_cap(): void
    {
        $this->assertSame(120, ConsoleWidth::clamp(200));
        $this->assertSame(40, ConsoleWidth::clamp(1));
        $this->assertSame(100, ConsoleWidth::clamp(100));
        $this->assertSame(ConsoleWidth::DEFAULT, ConsoleWidth::clamp(ConsoleWidth::DEFAULT));
    }

    /**
     * @return iterable<string, array{size: string, expected: ?int}>
     */
    public static function provideSttySizes(): iterable
    {
        yield 'rows and columns' => ['size' => "24 80\n", 'expected' => 80];
        yield 'a wide terminal' => ['size' => "50 200\n", 'expected' => 120];
        yield 'no newline' => ['size' => '40 100', 'expected' => 100];
        yield 'no terminal' => ['size' => '', 'expected' => null];
        yield 'an error message' => ['size' => "stty: stdin isn't a terminal\n", 'expected' => null];
        yield 'columns of zero' => ['size' => "0 0\n", 'expected' => null];
    }

    #[Test]
    #[DataProvider('provideSttySizes')]
    public function test_what_stty_prints_is_read_as_a_width(string $size, ?int $expected): void
    {
        $this->assertSame($expected, ConsoleWidth::fromSttySize($size));
    }

    #[Test]
    public function test_the_terminal_probe_answers_a_width_or_nothing_and_never_throws(): void
    {
        $width = ConsoleWidth::fromTerminal();

        // A pipe has no terminal to ask; a terminal answers within the clamp.
        $this->assertTrue(null === $width || ($width >= 40 && $width <= 120), var_export($width, true));

        // Asked of something that is no terminal, stty answers nothing: no width.
        $this->assertNull(ConsoleWidth::fromTerminal('/dev/null'));
    }

    /**
     * @return iterable<string, array{columns: string|false, terminal: ?int, expected: int}>
     */
    public static function provideResolutions(): iterable
    {
        yield 'the shell wins over the terminal' => ['columns' => '80', 'terminal' => 100, 'expected' => 80];
        yield 'the terminal when the shell says nothing' => ['columns' => false, 'terminal' => 100, 'expected' => 100];
        yield 'the terminal when the shell says nonsense' => ['columns' => 'abc', 'terminal' => 50, 'expected' => 50];
        yield 'the default when nothing is known' => ['columns' => false, 'terminal' => null, 'expected' => 120];
    }

    #[Test]
    #[DataProvider('provideResolutions')]
    public function test_the_width_comes_from_the_shell_then_the_terminal_then_the_default(string|false $columns, ?int $terminal, int $expected): void
    {
        $this->assertSame($expected, ConsoleWidth::resolve($columns, $terminal));
    }

    #[Test]
    public function test_the_probe_reads_the_columns_the_command_prints(): void
    {
        $this->assertSame(100, ConsoleWidth::fromTerminal('/dev/null', ['printf', '24 100\n']));
        $this->assertSame(120, ConsoleWidth::fromTerminal('/dev/null', ['printf', '24 500\n']));
        $this->assertNull(ConsoleWidth::fromTerminal('/dev/null', ['printf', 'no terminal']));
        $this->assertNull(ConsoleWidth::fromTerminal('/dev/null', ['printf', '']));
    }

    #[Test]
    public function test_detect_honours_columns_first(): void
    {
        $columns = getenv('COLUMNS');
        putenv('COLUMNS=90');

        try {
            $this->assertSame(90, ConsoleWidth::detect());
        } finally {
            putenv(false === $columns ? 'COLUMNS' : 'COLUMNS='.$columns);
        }
    }

    #[Test]
    public function test_detect_without_columns_reads_the_terminal_or_falls_to_the_default(): void
    {
        $columns = getenv('COLUMNS');
        putenv('COLUMNS');

        try {
            $width = ConsoleWidth::detect();
        } finally {
            putenv(false === $columns ? 'COLUMNS' : 'COLUMNS='.$columns);
        }

        // Under a terminal the probe reads its width; under a pipe, the
        // only case this suite can pin, the default stands.
        if (\function_exists('posix_isatty') && @posix_isatty(\STDOUT)) {
            $this->assertGreaterThanOrEqual(40, $width);
            $this->assertLessThanOrEqual(120, $width);
        } else {
            $this->assertSame(120, $width);
        }
    }
}
