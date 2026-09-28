<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Internal\InlineFlags;

/**
 * What a "(?...)" group turns on, what it turns off, and what it refuses.
 */
final class InlineFlagsTest extends TestCase
{
    #[Test]
    #[DataProvider('provideModifierStrings')]
    public function test_a_modifier_string_says_what_it_turns_on_and_off(string $text, string $set, string $unset): void
    {
        $flags = InlineFlags::read($text);

        $this->assertInstanceOf(InlineFlags::class, $flags);
        $this->assertSame($set, $flags->set);
        $this->assertSame($unset, $flags->unset);
    }

    /**
     * @return iterable<string, array{text: string, set: string, unset: string}>
     */
    public static function provideModifierStrings(): iterable
    {
        yield 'turning some on' => ['text' => 'im', 'set' => 'im', 'unset' => ''];
        yield 'turning some off' => ['text' => '-sx', 'set' => '', 'unset' => 'sx'];
        yield 'both at once' => ['text' => 'im-sx', 'set' => 'im', 'unset' => 'sx'];

        // "^" turns off everything it does not list.
        yield 'resetting the others' => ['text' => '^im', 'set' => 'im', 'unset' => 'sxUJnud'];
        yield 'resetting everything' => ['text' => '^', 'set' => '', 'unset' => 'imsxUJnud'];
    }

    #[Test]
    #[DataProvider('provideNonModifierStrings')]
    public function test_what_is_not_a_modifier_string_is_refused(string $text): void
    {
        $this->assertNotInstanceOf(InlineFlags::class, InlineFlags::read($text));
    }

    /**
     * @return iterable<string, array{text: string}>
     */
    public static function provideNonModifierStrings(): iterable
    {
        yield 'nothing at all' => ['text' => ''];
        yield 'a letter PCRE does not know' => ['text' => 'zz'];
        yield 'a name, not modifiers' => ['text' => 'name'];
        yield 'an unknown letter among known ones' => ['text' => 'im-zz'];
    }

    #[Test]
    public function test_a_letter_the_php_version_allows_is_read(): void
    {
        $this->assertNotInstanceOf(InlineFlags::class, InlineFlags::read('r'));

        $flags = InlineFlags::read('r', InlineFlags::LETTERS.'r');
        $this->assertInstanceOf(InlineFlags::class, $flags);
        $this->assertSame('r', $flags->set);
    }

    #[Test]
    public function test_a_modifier_turned_on_and_off_ends_off(): void
    {
        // PCRE reads the letters in order: preg_match('/(?i-i)a/', 'A') === 0.
        $flags = InlineFlags::read('is-si');

        $this->assertInstanceOf(InlineFlags::class, $flags);
        $this->assertFalse($flags->inForce('i', true));

        $other = InlineFlags::read('im-sx');
        $this->assertInstanceOf(InlineFlags::class, $other);
        $this->assertTrue($other->inForce('i', false));
    }

    #[Test]
    public function test_a_modifier_in_force_inside_the_group(): void
    {
        $flags = InlineFlags::read('x-i');

        $this->assertInstanceOf(InlineFlags::class, $flags);
        $this->assertTrue($flags->inForce('x', false), 'The group turns it on.');
        $this->assertFalse($flags->inForce('i', true), 'The group turns it off.');
        $this->assertTrue($flags->inForce('s', true), 'The group says nothing about it.');
        $this->assertFalse($flags->inForce('s', false));
    }

    #[Test]
    #[DataProvider('provideApplications')]
    public function test_applying_a_modifier_string_to_the_ones_already_in_force(string $text, string $current, string $result): void
    {
        $this->assertSame($result, InlineFlags::read($text)?->applyTo($current));
    }

    /**
     * @return iterable<string, array{text: string, current: string, result: string}>
     */
    public static function provideApplications(): iterable
    {
        yield 'adding' => ['text' => 'x', 'current' => 'i', 'result' => 'ix'];
        yield 'adding what is already there' => ['text' => 'i', 'current' => 'i', 'result' => 'i'];
        yield 'removing' => ['text' => '-i', 'current' => 'is', 'result' => 's'];
        yield 'resetting the others' => ['text' => '^m', 'current' => 'isx', 'result' => 'm'];
    }
}
