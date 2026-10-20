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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(?[" opens an extended class from PCRE2 10.45, and is refused on its "["
 * before. Only a "(?" that opens a group does: in "\c(?[a" the "(" is the
 * character "\c" escapes, the "?" repeats it, and "[" opens a plain class.
 *
 * Offsets are preg_match()'s on PHP 7.3 (PCRE2 10.32), 8.0 (10.35), 8.2
 * (10.40), 8.3 (10.42), 8.4 (10.44) and on PCRE2 10.49.
 */
final class ExtendedClassOpenerTest extends TestCase
{
    /**
     * @param array<int, int> $offsets the offset for each PHP version
     */
    #[Test]
    #[DataProvider('provideOpeners')]
    public function test_a_class_opens_an_extended_class_only_after_a_group_opener(string $pattern, array $offsets): void
    {
        foreach ($offsets as $phpVersion => $offset) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s on PHP %d.', $pattern, $phpVersion));
            $this->assertSame($offset, $result->offset, \sprintf('%s on PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
        }
    }

    /**
     * @param array<int, int> $offsets the offset for each PHP version
     */
    #[Test]
    #[DataProvider('provideOpeners')]
    public function test_the_running_engine_agrees(string $pattern, array $offsets): void
    {
        unset($offsets);

        $warning = null;
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        preg_match($pattern, '');
        restore_error_handler();

        $this->assertIsString($warning, \sprintf('%s compiles.', $pattern));
        $this->assertSame(1, preg_match('/at offset (\d+)/', $warning, $match), $warning);

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame((int) $match[1], $result->offset, \sprintf('%s: PCRE says "%s", the library "%s".', $pattern, $warning, (string) $result->error));
    }

    #[Test]
    public function test_the_message_names_the_group_opener(): void
    {
        $result = Regex::create(['cache' => null, 'php_version' => 80200])->validate('/(?[a/');

        $this->assertSame('Invalid group modifier syntax at position 2.', strtok((string) $result->error, "\n"));
    }

    /**
     * @return iterable<string, array{pattern: string, offsets: array<int, int>}>
     */
    public static function provideOpeners(): iterable
    {
        $before1045 = static fn (int $offset): array => [70300 => $offset, 80000 => $offset, 80200 => $offset, 80300 => $offset, 80400 => $offset];

        // "missing terminating ] for character class".
        yield 'a control escape of "("' => ['pattern' => '/\\c(?[a/', 'offsets' => $before1045(6)];
        yield 'a control escape of "(" under x' => ['pattern' => '/\\c(?[a/x', 'offsets' => $before1045(6)];
        yield 'a control escape of "(" after an escaped backslash' => ['pattern' => '/\\\\\\c(?[a/', 'offsets' => $before1045(8)];
        // "character code point value in \x{} or \o{} is too large" from
        // 10.35; 10.32 reads the class to its end first.
        yield 'a code point too large in the class' => ['pattern' => '/a\\c(?[\\x{100}/', 'offsets' => [70300 => 13] + $before1045(12)];
        // "invalid range in character class", "range out of order".
        yield 'a range from a type in the class' => ['pattern' => '/\\c(?[\\d-z/', 'offsets' => $before1045(7)];
        yield 'a range out of order in the class' => ['pattern' => '/\\c(?[z-a/', 'offsets' => $before1045(7)];

        // "unrecognized character after (? or (?-": a group opener.
        yield 'a group opener after an escaped backslash' => ['pattern' => '/\\\\(?[a/', 'offsets' => $before1045(4)];
        yield 'a group opener in a group' => ['pattern' => '/(?:(?[a/', 'offsets' => $before1045(5)];
        // "missing terminating ] for character class": an escaped "(".
        yield 'an escaped "("' => ['pattern' => '/\\(?[a/', 'offsets' => $before1045(5)];
    }
}
