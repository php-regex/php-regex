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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\IniFlag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IniFlagTest extends TestCase
{
    /**
     * A boolean ini string as ini_get() gives it, read the way PHP reads a
     * boolean setting. Each row was checked against the engine: under
     * pcre.jit set to the value, preg_match('/^(a|b)*$/') on a long subject
     * stops on the JIT stack limit (on) or on the backtrack limit (off).
     */
    #[Test]
    #[DataProvider('provideSettings')]
    public function test_is_on_reads_the_value_as_php_reads_an_ini_bool(string $setting, bool $expected): void
    {
        $this->assertSame($expected, IniFlag::isOn($setting));
    }

    /**
     * @return iterable<string, array{setting: string, expected: bool}>
     */
    public static function provideSettings(): iterable
    {
        yield 'zero' => ['setting' => '0', 'expected' => false];
        yield 'one' => ['setting' => '1', 'expected' => true];
        yield 'empty, as php -d pcre.jit=off leaves it' => ['setting' => '', 'expected' => false];
        yield 'off' => ['setting' => 'off', 'expected' => false];
        yield 'Off' => ['setting' => 'Off', 'expected' => false];
        yield 'on' => ['setting' => 'on', 'expected' => true];
        yield 'ON' => ['setting' => 'ON', 'expected' => true];
        yield 'yes' => ['setting' => 'yes', 'expected' => true];
        yield 'TRUE' => ['setting' => 'TRUE', 'expected' => true];
        yield 'false' => ['setting' => 'false', 'expected' => false];
        yield 'on with a leading space' => ['setting' => ' on', 'expected' => false];
        yield 'on with a trailing space' => ['setting' => 'on ', 'expected' => false];
        yield 'two' => ['setting' => '2', 'expected' => true];
        yield 'minus one' => ['setting' => '-1', 'expected' => true];
        yield 'leading space' => ['setting' => ' 1', 'expected' => true];
        yield 'leading tab' => ['setting' => "\t1", 'expected' => true];
        yield 'leading vertical tab' => ['setting' => "\v1", 'expected' => true];
        yield 'leading digit' => ['setting' => '1abc', 'expected' => true];
        yield 'word' => ['setting' => 'abc', 'expected' => false];
        yield 'exponent, only its leading digits read' => ['setting' => '1e-3', 'expected' => true];
        yield 'a fraction below one' => ['setting' => '0.9', 'expected' => false];
        yield 'signed one' => ['setting' => '+1', 'expected' => true];
        yield 'negative zero' => ['setting' => '-0', 'expected' => false];
        yield 'two signs' => ['setting' => '+-1', 'expected' => false];
        yield 'a space after the sign' => ['setting' => '- 1', 'expected' => false];
        yield 'padded one' => ['setting' => ' 01', 'expected' => true];
        yield 'many leading zeros' => ['setting' => '00000000000000000000000001', 'expected' => true];
        yield 'hexadecimal' => ['setting' => '0x1', 'expected' => false];
        // atoi() returns a C int: the value keeps its low 32 bits.
        yield '2^32 truncates to zero' => ['setting' => '4294967296', 'expected' => false];
        yield '2^32 + 1 truncates to one' => ['setting' => '4294967297', 'expected' => true];
        yield '2^32 with trailing text' => ['setting' => '4294967296abc', 'expected' => false];
        yield '2^33 + 1 truncates to one' => ['setting' => '8589934593', 'expected' => true];
        yield 'minus 2^32 truncates to zero' => ['setting' => '-4294967296', 'expected' => false];
        yield '2^32 - 1' => ['setting' => '4294967295', 'expected' => true];
        yield '2^31, past INT_MAX' => ['setting' => '2147483648', 'expected' => true];
        yield 'INT_MAX' => ['setting' => '2147483647', 'expected' => true];
        yield 'INT_MIN' => ['setting' => '-2147483648', 'expected' => true];
        yield 'padded signed 2^32 + 1' => ['setting' => '  +4294967297', 'expected' => true];
        // Past a C long, strtol() saturates: LONG_MAX keeps 32 bits set,
        // LONG_MIN none.
        yield 'LONG_MAX' => ['setting' => '9223372036854775807', 'expected' => true];
        yield 'past LONG_MAX' => ['setting' => '9223372036854775808', 'expected' => true];
        yield 'far past LONG_MAX' => ['setting' => '99999999999999999999', 'expected' => true];
        yield '2^64' => ['setting' => '18446744073709551616', 'expected' => true];
        yield 'LONG_MIN' => ['setting' => '-9223372036854775808', 'expected' => false];
        yield 'far past LONG_MIN' => ['setting' => '-99999999999999999999', 'expected' => false];
    }

    /**
     * display_errors as ini_get() gives it, read the way PHP reads it
     * (php_get_display_errors_mode()): "on", "yes", "true", "stderr" or
     * "stdout" in any case, else the C long of the value cast to an
     * unsigned char. Each row was checked against the engine: under
     * ini_set('display_errors', value), reading an undefined variable
     * prints the warning (on) or not (off).
     */
    #[Test]
    #[DataProvider('provideDisplayErrorsSettings')]
    public function test_displays_errors_reads_the_value_as_php_reads_display_errors(string $setting, bool $expected): void
    {
        $this->assertSame($expected, IniFlag::displaysErrors($setting));
    }

    /**
     * @return iterable<string, array{setting: string, expected: bool}>
     */
    public static function provideDisplayErrorsSettings(): iterable
    {
        yield 'zero' => ['setting' => '0', 'expected' => false];
        yield 'one' => ['setting' => '1', 'expected' => true];
        yield 'empty, as php -d display_errors=off leaves it' => ['setting' => '', 'expected' => false];
        yield 'On' => ['setting' => 'On', 'expected' => true];
        yield 'yes' => ['setting' => 'yes', 'expected' => true];
        yield 'TRUE' => ['setting' => 'TRUE', 'expected' => true];
        yield 'false' => ['setting' => 'false', 'expected' => false];
        yield 'stderr' => ['setting' => 'stderr', 'expected' => true];
        yield 'STDOUT' => ['setting' => 'STDOUT', 'expected' => true];
        yield 'on with a trailing space' => ['setting' => 'on ', 'expected' => false];
        yield 'word' => ['setting' => 'abc', 'expected' => false];
        yield 'two' => ['setting' => '2', 'expected' => true];
        yield 'minus one' => ['setting' => '-1', 'expected' => true];
        yield 'leading space' => ['setting' => ' 1', 'expected' => true];
        yield 'leading digit' => ['setting' => '1abc', 'expected' => true];
        yield 'hexadecimal' => ['setting' => '0x1', 'expected' => false];
        // The mode is an unsigned char: the value keeps its low 8 bits.
        yield '255, every low bit set' => ['setting' => '255', 'expected' => true];
        yield '256 truncates to zero' => ['setting' => '256', 'expected' => false];
        yield '257 truncates to one' => ['setting' => '257', 'expected' => true];
        yield '512 truncates to zero' => ['setting' => '512', 'expected' => false];
        yield '2^32 truncates to zero' => ['setting' => '4294967296', 'expected' => false];
        yield 'past LONG_MAX saturates, low bits set' => ['setting' => '9223372036854775808', 'expected' => true];
        yield 'LONG_MIN, low bits clear' => ['setting' => '-9223372036854775808', 'expected' => false];
    }
}
