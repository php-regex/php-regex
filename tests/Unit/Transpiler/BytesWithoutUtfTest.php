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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, PCRE reads bytes; JavaScript and Python read characters. A
 * multibyte character written whole is the character the PHP string holds
 * as UTF-8 text, and stays that character. A byte above 0x7F on its own, as
 * an escape or as invalid UTF-8, has no equivalent in a target that reads
 * characters: "\xE9" in JavaScript matches U+00E9, which the UTF-8 "é" never
 * is as a byte. The transpiler refuses it.
 */
final class BytesWithoutUtfTest extends TestCase
{
    #[Test]
    #[DataProvider('provideTargets')]
    public function test_a_multibyte_character_stays_that_character(string $target): void
    {
        $result = Regex::create()->transpile('/café/', $target);

        $this->assertSame('café', $result->pattern);
    }

    #[Test]
    #[DataProvider('provideBytes')]
    public function test_a_byte_above_ascii_is_refused(string $pattern, string $target): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('reads characters, not bytes');

        Regex::create()->transpile($pattern, $target);
    }

    /**
     * @return iterable<string, array{target: string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'JavaScript' => ['target' => 'javascript'];
        yield 'Python' => ['target' => 'python'];
    }

    /**
     * @return iterable<string, array{pattern: string, target: string}>
     */
    public static function provideBytes(): iterable
    {
        foreach (['javascript', 'python'] as $target) {
            yield $target.': hex escapes of a character' => ['pattern' => '/caf\xC3\xA9/', 'target' => $target];
            yield $target.': Latin-1 byte escape' => ['pattern' => '/caf\xE9/', 'target' => $target];
            yield $target.': raw byte of invalid UTF-8' => ['pattern' => "/caf\xE9/", 'target' => $target];
            yield $target.': range of bytes' => ['pattern' => '/[\x80-\xFF]/', 'target' => $target];
            yield $target.': octal escape of a byte' => ['pattern' => '/\351/', 'target' => $target];
            // The quantifier repeats the last byte of the character.
            yield $target.': quantified multibyte character' => ['pattern' => '/é+/', 'target' => $target];
        }
    }

    #[Test]
    public function test_a_control_character_beside_a_multibyte_one_is_still_escaped(): void
    {
        $this->assertSame('caf\\té', Regex::create()->transpile("/caf\té/", 'javascript')->pattern);
    }

    #[Test]
    #[DataProvider('provideTargets')]
    public function test_under_u_a_code_point_escape_is_a_character(string $target): void
    {
        $result = Regex::create()->transpile('/caf\xE9/u', $target);

        $this->assertStringContainsString('caf', $result->pattern);
    }
}
