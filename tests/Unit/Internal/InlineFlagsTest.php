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

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\Internal\InlineFlags;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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

        // "^" turns off what it does not list among i, m, n, r, s and x; U
        // and J stay as they are (see test_a_caret_turns_off_imnrsx_only).
        yield 'resetting the others' => ['text' => '^im', 'set' => 'im', 'unset' => 'sxn'];
        yield 'resetting everything' => ['text' => '^', 'set' => '', 'unset' => 'imsxn'];
    }

    /**
     * PCRE2: "(?^)" unsets the imnrsx options. U and J are no such option:
     * "(?U)(?^)a+" still matches "a" of "aaa", and "(?^)" leaves two groups
     * of the same name allowed under J. n and r are: "(?n)(?^)(a)" captures
     * again, and "(?ri)(?^i)k" matches the Kelvin sign again.
     */
    #[Test]
    public function test_a_caret_turns_off_imnrsx_only(): void
    {
        $this->assertSame(1, preg_match('/(?U)(?^)a+/', 'aaa', $lazy));
        $this->assertSame('a', $lazy[0], 'Oracle: U survives.');
        $this->assertSame(1, preg_match('/(?^)(?<m>a)(?<m>b)/J', 'ab'), 'Oracle: J survives.');
        $this->assertSame(1, preg_match('/(?n)(?^)(a)/', 'a', $captured));
        $this->assertCount(2, $captured, 'Oracle: n is reset.');
        if (version_compare(implode('.', \array_slice(explode('.', explode(' ', \PCRE_VERSION)[0]), 0, 2)), '10.43', '>=')) {
            $this->assertSame([0, 1], [preg_match('/(?ri)k/u', "\u{212A}"), preg_match('/(?ri)(?^i)k/u', "\u{212A}")], 'Oracle: r is reset.');
        }

        $flags = InlineFlags::read('^', InlineFlags::LETTERS.'r');

        $this->assertInstanceOf(InlineFlags::class, $flags);
        foreach (str_split('imnrsx') as $letter) {
            $this->assertTrue($flags->turnsOff($letter), $letter.' is reset.');
        }
        foreach (['U', 'J'] as $letter) {
            $this->assertFalse($flags->turnsOff($letter), $letter.' is not reset.');
            $this->assertTrue($flags->inForce($letter, true), $letter.' stays in force.');
        }
        $this->assertSame('JU', $flags->applyTo('imnrsxJU'));
        $this->assertSame('UJi', InlineFlags::read('^i')?->applyTo('UJsx'));
    }

    /**
     * The library reads "a+" after "(?U)(?^)" as PCRE does, ungreedy: the
     * printed pattern matches what the original matches, and the lazy end
     * is found, as for "(?U)a+".
     */
    #[Test]
    public function test_a_caret_leaves_ungreedy_in_force_for_the_library(): void
    {
        $pattern = '/(?U)(?^)a+/';
        $this->assertSame(1, preg_match($pattern, 'aaa', $match));
        $this->assertSame('a', $match[0], 'Oracle.');

        $tree = Regex::create(['cache' => null])->parse($pattern);

        $printed = $tree->accept(new PatternPrinter());
        $this->assertSame(1, preg_match($printed, 'aaa', $printedMatch), $printed);
        $this->assertSame('a', $printedMatch[0] ?? null, $printed);

        $linter = new PatternLinter();
        $tree->accept($linter);
        $this->assertContains('regex.lint.quantifier.lazyEnd', array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));
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

    /**
     * Under "(?xx)" PCRE skips a space before the first member of a class,
     * so "[ ]" is a class that never closes: the oracle pattern of each row
     * compiles exactly when "xx" is not in force where its class stands.
     */
    #[Test]
    #[DataProvider('provideExtendedMoreSettings')]
    public function test_extended_more_in_force_follows_what_the_group_says_of_x(string $text, bool $wasInForce, bool $inForce, string $oracle): void
    {
        $this->assertSame(!$inForce, false !== @preg_match($oracle, ''), \sprintf('Oracle: %s.', $oracle));

        $flags = InlineFlags::read($text);

        $this->assertInstanceOf(InlineFlags::class, $flags);
        $this->assertSame($inForce, $flags->extendedMoreInForce($wasInForce));
        $this->assertSame(!$inForce, Regex::create(['cache' => null])->validate($oracle)->isValid, $oracle);
    }

    /**
     * @return iterable<string, array{text: string, wasInForce: bool, inForce: bool, oracle: string}>
     */
    public static function provideExtendedMoreSettings(): iterable
    {
        yield 'a group silent on x keeps xx on' => ['text' => 'i', 'wasInForce' => true, 'inForce' => true, 'oracle' => '/(?xx)(?i)[ ]/'];
        yield 'a group silent on x keeps xx on in its body' => ['text' => 'i', 'wasInForce' => true, 'inForce' => true, 'oracle' => '/(?xx)(?i:[ ])/'];
        yield 'a group silent on x keeps xx off' => ['text' => 'i', 'wasInForce' => false, 'inForce' => false, 'oracle' => '/(?i)[ ]/'];
        yield 'xx turns it on' => ['text' => 'xx', 'wasInForce' => false, 'inForce' => true, 'oracle' => '/(?xx)[ ]/'];
        yield 'xx turns it on, whatever else is turned off' => ['text' => 'xx-i', 'wasInForce' => false, 'inForce' => true, 'oracle' => '/(?x)(?xx-i)[ ]/'];
        yield 'a single x turns it off' => ['text' => 'x', 'wasInForce' => true, 'inForce' => false, 'oracle' => '/(?xx)(?x)[ ]/'];
        yield 'turning x off turns it off' => ['text' => '-x', 'wasInForce' => true, 'inForce' => false, 'oracle' => '/(?xx)(?-x)[ ]/'];
        yield 'a caret turns it off' => ['text' => '^', 'wasInForce' => true, 'inForce' => false, 'oracle' => '/(?xx)(?^)[ ]/'];
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
