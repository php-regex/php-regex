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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A group name, a subroutine name or the ")" after it holds no backslash
 * and no "(": PCRE refuses either where it stands, before it reads the
 * escape or the group it opens. When that escape or group is one PCRE
 * would refuse too, a "\c" ending the pattern, an empty "\x{}", a "(?*"
 * older than PCRE2 10.35, the error on the name still comes first.
 *
 * Offsets are preg_match()'s on PHP 7.3 (PCRE2 10.32), 8.0 (10.35), 8.2
 * (10.40), 8.3 (10.42), 8.4 (10.44) and on PCRE2 10.49.
 */
final class NameCutByARefusedTokenTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNamesCutByAnEscape')]
    #[DataProvider('provideItemsBeforeAnEscape')]
    public function test_validate_meets_the_running_engine_on_a_refused_escape_ending_the_pattern(string $pattern, int $offset): void
    {
        $result = Regex::create(['cache' => null])->validate($pattern);
        $said = \sprintf('%s: the library says "%s" at %s.', $pattern, (string) $result->error, var_export($result->offset, true));

        $this->assertFalse($result->isValid, $said);
        $this->assertSame($this->engineOffset($pattern), $result->offset, $said);
        // The same offset on every release from PCRE2 10.35 to 10.49.
        $this->assertSame($offset, $result->offset, $said);
    }

    #[Test]
    #[DataProvider('provideNamesCutByAnEscape')]
    #[DataProvider('provideItemsBeforeAnEscape')]
    public function test_validate_meets_every_target_from_pcre2_10_35_on_a_refused_escape_ending_the_pattern(string $pattern, int $offset): void
    {
        foreach ([80000, 80200, 80300, 80400] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s on PHP %d.', $pattern, $phpVersion));
            $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideNamesCutByAnEscape(): iterable
    {
        // "subpattern name expected": the name starts with the backslash.
        yield 'a subroutine name' => ['pattern' => '/(?&\\c/', 'offset' => 3];
        yield 'a Python subroutine name' => ['pattern' => '/(?P>\\c/', 'offset' => 4];
        yield 'a group name' => ['pattern' => '/(?<\\c/', 'offset' => 3];
        yield 'a quoted group name' => ['pattern' => "/(?'\\c/", 'offset' => 3];
        yield 'a condition name in angle brackets' => ['pattern' => '/(?(<\\c/', 'offset' => 4];
        yield 'a bare condition name' => ['pattern' => '/(?(\\c/', 'offset' => 3];
        yield 'a recursion condition name' => ['pattern' => '/(?(R&\\c/', 'offset' => 5];
        yield 'a subroutine name before an empty code point' => ['pattern' => '/(?&\\x{}/', 'offset' => 3];
        // "syntax error in subpattern name (missing terminator?)": the
        // backslash ends the name.
        yield 'a group name ended' => ['pattern' => '/(?<a\\c/', 'offset' => 4];
        yield 'a Python group name ended' => ['pattern' => '/(?P<a\\c/', 'offset' => 5];
        yield 'a quoted group name ended' => ['pattern' => "/(?'a\\c/", 'offset' => 4];
        yield 'a Python back reference name ended' => ['pattern' => '/(?P=a\\c/', 'offset' => 5];
        yield 'a condition name ended' => ['pattern' => '/(?(<a\\c/', 'offset' => 5];
        yield 'a quoted condition name ended' => ['pattern' => "/(?('a\\c/", 'offset' => 5];
        yield 'a recursion condition name ended' => ['pattern' => '/(?(R&a\\c/', 'offset' => 6];
        yield 'a condition name ended after a group' => ['pattern' => '/(?<a>x)(?(<a\\c/', 'offset' => 12];
        yield 'a group name ended before an empty code point' => ['pattern' => '/(?<a\\x{}/', 'offset' => 4];
        // "missing closing parenthesis": a subroutine name ends at its ")".
        yield 'a subroutine call left open' => ['pattern' => '/(?&a\\c/', 'offset' => 4];
        yield 'a subroutine call left open before an empty code point' => ['pattern' => '/(?&a\\x{}/', 'offset' => 4];
    }

    /**
     * Where an item may stand, PCRE reads the escape, and refuses it: "\c at
     * end of pattern", past the "c".
     *
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideItemsBeforeAnEscape(): iterable
    {
        yield 'a group left open' => ['pattern' => '/(a\\c/', 'offset' => 4];
        yield 'a non-capturing group left open' => ['pattern' => '/(?:\\c/', 'offset' => 5];
        yield 'a group name closed' => ['pattern' => '/(?<a>\\c/', 'offset' => 7];
        yield 'a subroutine call closed' => ['pattern' => '/(?&a)\\c/', 'offset' => 7];
        yield 'a class range' => ['pattern' => '/[z-\\c/', 'offset' => 5];
        yield 'a brace that opens no count' => ['pattern' => '/a{\\c/', 'offset' => 4];
    }

    /**
     * After "(?" PCRE wants an option letter or a group kind, and refuses
     * the backslash there: on it up to PCRE2 10.44, past it from 10.47.
     */
    #[Test]
    public function test_the_character_after_a_group_opener_comes_before_the_escape(): void
    {
        $pattern = '/(?\\c/';

        $this->assertSame($this->engineOffset($pattern), Regex::create(['cache' => null])->validate($pattern)->offset);

        foreach ([80000, 80200, 80300, 80400] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('PHP %d.', $phpVersion));
            $this->assertSame(2, $result->offset, \sprintf('PHP %d: %s', $phpVersion, (string) $result->error));
        }
    }

    /**
     * PCRE2 10.35 added "(?*" for "(*napla:": PHP 7.3, on 10.32, refuses it,
     * but the name or condition before it first.
     */
    #[Test]
    #[DataProvider('provideNamesCutByAGroupOnPcre2Ten32')]
    public function test_the_name_error_comes_before_a_group_pcre2_10_32_refuses(string $pattern, int $offset): void
    {
        $result = Regex::create(['cache' => null, 'php_version' => 70300])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, \sprintf('%s: %s', $pattern, (string) $result->error));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideNamesCutByAGroupOnPcre2Ten32(): iterable
    {
        // "subpattern name expected".
        yield 'a subroutine name' => ['pattern' => '/(?&(?*a/', 'offset' => 3];
        yield 'a condition name' => ['pattern' => '/(?((?*a/', 'offset' => 3];
        // "syntax error in subpattern name (missing terminator)".
        yield 'a group name ended' => ['pattern' => '/(?<a(?*a/', 'offset' => 4];
        // "assertion expected after (?( or (?(?C)".
        yield 'a condition assertion' => ['pattern' => '/(?(?*a/', 'offset' => 2];
    }

    /**
     * Where the running engine refuses the pattern.
     */
    private function engineOffset(string $pattern): int
    {
        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        preg_match($pattern, '');
        restore_error_handler();

        $this->assertIsString($warning, \sprintf('%s compiles.', $pattern));
        $this->assertSame(1, preg_match('/at offset (\d+)/', $warning, $match), $warning);

        return (int) $match[1];
    }
}
