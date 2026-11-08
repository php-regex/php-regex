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

use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, PCRE reads bytes; JavaScript and Python read characters. A
 * multibyte character written whole outside a class is the character held
 * as UTF-8 text, and stays that character. A byte above 0x7F on its own, as
 * an escape or as invalid UTF-8, has no equivalent in a target that reads
 * characters: "\xE9" in JavaScript matches U+00E9, which the UTF-8 "é" never
 * is as a byte. The transpiler refuses it.
 */
final class BytesWithoutUtfTest extends TestCase
{
    use PhpUnitForwardCompat;

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
        $this->expectExceptionMessageIsOrContains('reads characters, not bytes');

        Regex::create()->transpile($pattern, $target);
    }

    #[Test]
    #[DataProvider('provideMultibyteClasses')]
    public function test_byte_mode_multibyte_class_members_are_refused(string $pattern, string $target): void
    {
        // The leading byte of à is also a member of [é] in byte mode.
        $this->assertSame(str_contains($pattern, '[^') ? 0 : 1, preg_match($pattern, 'à'));

        $this->expectException(TranspileException::class);
        $this->expectExceptionMessageIsOrContains('reads characters, not bytes');

        Regex::create()->transpile($pattern, $target);
    }

    /**
     * The advice is the one that works: PCRE takes the pattern under u, and
     * reads there the character the subject holds.
     *
     * @param non-empty-string $advice
     */
    #[Test]
    #[DataProvider('provideAdvice')]
    public function test_the_refusal_gives_advice_pcre_accepts(string $pattern, string $fixed, string $subject, string $advice): void
    {
        // The pattern as written compiles: the refusal is the target's, not PCRE's.
        $this->assertNotFalse(@preg_match($pattern, $subject), $pattern);
        $this->assertSame(1, preg_match($fixed, $subject), $fixed);
        // The advice says whether adding u alone is enough: the engine agrees.
        $refused = !str_ends_with($advice, 'Add u.');
        $this->assertSame($refused ? false : 1, @preg_match($pattern.'u', $subject), $pattern.'u');

        try {
            Regex::create()->transpile($pattern, 'javascript');
            $this->fail($pattern.' must be refused.');
        } catch (TranspileException $exception) {
            $this->assertStringEndsWith($advice, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{pattern: string, fixed: string, subject: string, advice: non-empty-string}>
     */
    public static function provideAdvice(): iterable
    {
        yield 'escape of a byte' => ['pattern' => '/caf\xE9/', 'fixed' => '/caf\xE9/u', 'subject' => 'café', 'advice' => 'Add u.'];
        yield 'character cut by a quantifier' => ['pattern' => '/é+/', 'fixed' => '/é+/u', 'subject' => 'éé', 'advice' => 'Add u.'];
        yield 'raw byte of invalid UTF-8' => ['pattern' => "/caf\xE9/", 'fixed' => '/café/u', 'subject' => 'café', 'advice' => 'refuses a pattern that is not valid UTF-8: write the character as UTF-8, then add u.'];
        yield 'multibyte character in a class' => ['pattern' => '/[é]/', 'fixed' => '/[é]/u', 'subject' => 'é', 'advice' => 'reads each of its bytes as a member of its own. Add u.'];
        // The class is refused first, but u needs the later byte fixed too.
        yield 'class before a raw byte of invalid UTF-8' => ['pattern' => "/(?=[é])\xE9/", 'fixed' => '/(?=[é])é/u', 'subject' => 'é', 'advice' => 'refuses a pattern that is not valid UTF-8: write the character as UTF-8, then add u.'];
        // Valid UTF-8 is not enough: PCRE refuses \C in a lookbehind under u, on every release, so the rest has to change too.
        yield 'class before what u refuses' => ['pattern' => '/[é](?<=\C)/', 'fixed' => '/[é]/u', 'subject' => 'é', 'advice' => 'Add u, after rewriting what PCRE refuses under it: the pattern as written does not compile with u.'];
        // Both at once: the raw byte has to become UTF-8, and \C has to go.
        yield 'raw byte of invalid UTF-8 beside what u refuses' => ['pattern' => "/[\xE9](?<=\\C)/", 'fixed' => '/[é]/u', 'subject' => 'é', 'advice' => 'write the character as UTF-8 and rewrite what PCRE refuses under u, then add u.'];
        // PCRE reads the body alone: a delimiter above 0x7F stays out of u.
        yield 'class between delimiters above 0x7F' => ['pattern' => "\xA7[é]\xA7", 'fixed' => "\xA7[é]\xA7u", 'subject' => 'é', 'advice' => 'reads each of its bytes as a member of its own. Add u.'];
    }

    #[Test]
    #[DataProvider('provideMultibyteClasses')]
    public function test_unicode_multibyte_classes_keep_character_membership(string $pattern, string $target): void
    {
        // Under u the class holds the character: é is in [é], à is not.
        $negated = str_contains($pattern, '[^');
        $this->assertSame($negated ? 0 : 1, preg_match($pattern.'u', 'é'));
        $this->assertSame($negated ? 1 : 0, preg_match($pattern.'u', 'à'));

        $result = Regex::create()->transpile($pattern.'u', $target);

        $this->assertStringContainsString(str_contains($pattern, '[^') ? '[^é]' : substr($pattern, 1, -1), $result->pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, target: string}>
     */
    public static function provideMultibyteClasses(): iterable
    {
        foreach (['javascript', 'python', 'html-pattern'] as $target) {
            foreach (['/[é]/', '/^[^é]+$/', '/[aé]/'] as $pattern) {
                yield $target.': '.$pattern => ['pattern' => $pattern, 'target' => $target];
            }
        }
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
