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

namespace PHPRegex\Tests\Unit\Parser\Engine;

use PHPRegex\Parser\Engine\IniFlag;
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
        // A C long is as wide as a PHP int: a magnitude past PHP_INT_MAX
        // saturates, which keeps the bits a 32-bit mask selects (LONG_MAX's
        // low bits, LONG_MIN's sign bit) and so reads as on, where a 64-bit
        // long is read whole and its low 32 bits decide.
        $wide = \PHP_INT_SIZE > 4;
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
        yield '2^32 truncates to zero' => ['setting' => '4294967296', 'expected' => !$wide];
        yield '2^32 + 1 truncates to one' => ['setting' => '4294967297', 'expected' => true];
        yield '2^32 with trailing text' => ['setting' => '4294967296abc', 'expected' => !$wide];
        yield '2^33 + 1 truncates to one' => ['setting' => '8589934593', 'expected' => true];
        yield 'minus 2^32 truncates to zero' => ['setting' => '-4294967296', 'expected' => !$wide];
        yield '2^32 - 1' => ['setting' => '4294967295', 'expected' => true];
        yield '2^31, past INT_MAX' => ['setting' => '2147483648', 'expected' => true];
        yield 'INT_MAX' => ['setting' => '2147483647', 'expected' => true];
        yield 'INT_MIN' => ['setting' => '-2147483648', 'expected' => true];
        yield 'padded signed 2^32 + 1' => ['setting' => '  +4294967297', 'expected' => true];
        // Past a C long, strtol() saturates: LONG_MAX keeps 32 bits set,
        // LONG_MIN none.
        // Below LONG_MAX, the value is read whole: its own low bits decide,
        // not the saturation rule, whatever its digit count or spelling.
        yield '2^62, nineteen digits, low bits clear' => ['setting' => '4611686018427387904', 'expected' => !$wide];
        yield 'minus LONG_MAX, read whole, low bits set' => ['setting' => '-9223372036854775807', 'expected' => true];
        yield '22 times 2^32, spelled above LONG_MAX letter by letter' => ['setting' => '94489280512', 'expected' => !$wide];
        yield 'LONG_MAX' => ['setting' => '9223372036854775807', 'expected' => true];
        yield 'past LONG_MAX' => ['setting' => '9223372036854775808', 'expected' => true];
        yield 'far past LONG_MAX' => ['setting' => '99999999999999999999', 'expected' => true];
        yield '2^64' => ['setting' => '18446744073709551616', 'expected' => true];
        yield 'LONG_MIN' => ['setting' => '-9223372036854775808', 'expected' => !$wide];
        yield 'far past LONG_MIN' => ['setting' => '-99999999999999999999', 'expected' => !$wide];
    }

    /**
     * The masks stand for C types narrower than a PHP int on a 64-bit PHP
     * and as wide as one on a 32-bit PHP (php-wasm): a 0xFFFFFFFF literal is
     * a float there, which a typed int parameter refuses and a bitwise
     * operator reads with a deprecation. The constant must be an int on
     * every width.
     */
    #[Test]
    public function test_the_int_mask_is_an_int_on_this_php(): void
    {
        $this->assertIsInt(IniFlag::INT_MASK);
        $this->assertSame(IniFlag::INT_MASK, \PHP_INT_SIZE > 4 ? 0xFFFFFFFF : -1);
    }

    /**
     * A PHP int read as the C unsigned 32-bit int the engine receives: on a
     * 64-bit PHP its low 32 bits; on a 32-bit PHP a negative value stands
     * past PHP_INT_MAX, and PHP_INT_MAX is the answer that compares right
     * against every limit the library knows.
     */
    #[Test]
    #[DataProvider('provideUnsignedValues')]
    public function test_unsigned_int_reads_the_value_as_the_engine_does(int $value, int $expected): void
    {
        $this->assertSame($expected, IniFlag::unsignedInt($value));
    }

    /**
     * @return iterable<string, array{value: int, expected: int}>
     */
    public static function provideUnsignedValues(): iterable
    {
        yield 'zero' => ['value' => 0, 'expected' => 0];
        yield 'one' => ['value' => 1, 'expected' => 1];
        yield "PHP's default backtrack limit" => ['value' => 1_000_000, 'expected' => 1_000_000];
        yield 'INT_MAX' => ['value' => 2147483647, 'expected' => 2147483647];
        yield 'minus one, unlimited' => ['value' => -1, 'expected' => \PHP_INT_SIZE > 4 ? 4294967295 : \PHP_INT_MAX];
        yield 'INT_MIN' => ['value' => -2147483648, 'expected' => \PHP_INT_SIZE > 4 ? 2147483648 : \PHP_INT_MAX];
        if (\PHP_INT_SIZE > 4) {
            yield '2^32 - 1' => ['value' => 4294967295, 'expected' => 4294967295];
            yield '2^32 truncates to zero' => ['value' => 4294967296, 'expected' => 0];
            yield '2^32 + 1 truncates to one' => ['value' => 4294967297, 'expected' => 1];
            yield 'LONG_MIN truncates to zero' => ['value' => \PHP_INT_MIN, 'expected' => 0];
            yield 'LONG_MAX keeps 32 bits' => ['value' => \PHP_INT_MAX, 'expected' => 4294967295];
        }
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
        // Past PHP_INT_MAX a magnitude saturates to LONG_MAX, whose low byte
        // is set, where a 64-bit long is read whole.
        $wide = \PHP_INT_SIZE > 4;
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
        yield '2^32 truncates to zero' => ['setting' => '4294967296', 'expected' => !$wide];
        yield 'past LONG_MAX saturates, low bits set' => ['setting' => '9223372036854775808', 'expected' => true];
        yield 'LONG_MIN, low bits clear' => ['setting' => '-9223372036854775808', 'expected' => false];
    }
}
