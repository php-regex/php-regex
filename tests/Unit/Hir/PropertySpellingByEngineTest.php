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

namespace PHPRegex\Tests\Unit\Hir;

use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The sets of a pattern are read from the running engine, which may not read
 * a property name as the target does. PCRE2 10.39, which PHP 8.1 bundles,
 * knows one exact spelling of each name, and a bare script name is the
 * script alone; from 10.40 names are matched loosely, "sc=", "script=" and
 * the four-letter script codes are read, and a bare script name is its
 * script extensions. Each atom is spelled so the running engine reads what
 * the target means, or not at all when it has no such spelling: on PHP 8.1,
 * preg_match('/\p{Greek}/u', "\u{342}") is 0 and on PHP 8.2 (10.40) it is 1,
 * and 10.39 has no name for the script extensions of Greek.
 */
final class PropertySpellingByEngineTest extends TestCase
{
    #[Test]
    #[DataProvider('provideNewerTargetOlderEngine')]
    public function test_a_property_of_a_newer_target_is_spelled_as_an_older_engine_reads_it(string $atom, ?string $expected): void
    {
        $this->assertSame($expected, ClassSetProvider::spelledFor($atom, new PcreTarget(80400, '10.44'), new PcreTarget(80100, '10.39')), $atom);
    }

    /**
     * @return iterable<string, array{atom: string, expected: ?string}>
     */
    public static function provideNewerTargetOlderEngine(): iterable
    {
        yield 'a script by its code' => ['atom' => '\p{sc=Grek}', 'expected' => '\p{Greek}'];
        yield 'a script by its loose name' => ['atom' => '\p{Script = greek}', 'expected' => '\p{Greek}'];
        yield 'a script by "sc:"' => ['atom' => '\p{sc:Greek}', 'expected' => '\p{Greek}'];
        yield 'a script of several words by its code' => ['atom' => '\p{sc=Hmnp}', 'expected' => '\p{Nyiakeng_Puachue_Hmong}'];
        yield 'a script of several words, loosely' => ['atom' => '\p{sc=old-italic}', 'expected' => '\p{Old_Italic}'];
        yield 'a script, negated by the letter' => ['atom' => '\P{sc=Grek}', 'expected' => '\P{Greek}'];
        yield 'a script, negated by a caret' => ['atom' => '\p{^sc=grek}', 'expected' => '\p{^Greek}'];
        yield 'a script, negated twice' => ['atom' => '\P{^sc=Grek}', 'expected' => '\P{^Greek}'];
        yield 'a script in a class' => ['atom' => '[\p{sc=Hani}a]', 'expected' => '[\p{Han}a]'];
        yield 'Inherited by its private code' => ['atom' => '\p{sc=Qaai}', 'expected' => '\p{Inherited}'];
        yield 'a category, loosely' => ['atom' => '\p{lu}', 'expected' => '\p{Lu}'];
        yield 'a category, spaced' => ['atom' => '\p{ L u }', 'expected' => '\p{Lu}'];
        yield 'the cased letters by their other name' => ['atom' => '\p{lc}', 'expected' => '\p{L&}'];
        yield 'a category of one lower-case letter' => ['atom' => '\pl', 'expected' => '\p{L}'];
        yield 'a property of PCRE2, loosely' => ['atom' => '\p{xan}', 'expected' => '\p{Xan}'];
        yield 'any character, loosely' => ['atom' => '\p{any}', 'expected' => '\p{Any}'];
        yield 'a category as written' => ['atom' => '\p{Lu}', 'expected' => '\p{Lu}'];
        yield 'a category of one letter as written' => ['atom' => '\pN', 'expected' => '\pN'];
        yield 'an escaped backslash before a p' => ['atom' => '[\\\\p{greek}]', 'expected' => '[\\\\p{greek}]'];
        yield 'no property' => ['atom' => '[a-z]', 'expected' => '[a-z]'];
        yield 'a bare script: its extensions' => ['atom' => '\p{Greek}', 'expected' => null];
        yield 'a bare script, loosely: its extensions' => ['atom' => '\p{greek}', 'expected' => null];
        yield 'a bare script code: its extensions' => ['atom' => '\p{Grek}', 'expected' => null];
        yield 'the extensions by "scx="' => ['atom' => '\p{scx=Grek}', 'expected' => null];
        yield 'a bare script in a class' => ['atom' => '[\p{Greek}a]', 'expected' => null];
        yield 'a Bidi class' => ['atom' => '\p{bc=L}', 'expected' => null];
        yield 'a binary property' => ['atom' => '\p{alpha}', 'expected' => null];
        yield 'a script PCRE2 10.39 does not know' => ['atom' => '\p{sc=Kawi}', 'expected' => null];
        yield 'a name the target does not know' => ['atom' => '\p{sc=Sunu}', 'expected' => null];
        yield 'a property in a quoted run' => ['atom' => '[\Q\p{lu}\E]', 'expected' => null];
    }

    #[Test]
    #[DataProvider('provideOlderTargetNewerEngine')]
    public function test_a_property_of_an_older_target_is_spelled_as_a_newer_engine_reads_it(string $atom, ?string $expected): void
    {
        $this->assertSame($expected, ClassSetProvider::spelledFor($atom, new PcreTarget(80100, '10.39'), new PcreTarget(80400, '10.49')), $atom);
    }

    /**
     * @return iterable<string, array{atom: string, expected: ?string}>
     */
    public static function provideOlderTargetNewerEngine(): iterable
    {
        yield 'a script: the script alone' => ['atom' => '\p{Han}', 'expected' => '\p{sc:Han}'];
        yield 'a script, negated twice' => ['atom' => '\P{^Han}', 'expected' => '\P{^sc:Han}'];
        yield 'a script in a class' => ['atom' => '[\p{Han}a]', 'expected' => '[\p{sc:Han}a]'];
        yield 'a category' => ['atom' => '\p{Lu}', 'expected' => '\p{Lu}'];
        yield 'a category of one letter' => ['atom' => '\pL', 'expected' => '\pL'];
        yield 'the cased letters' => ['atom' => '\p{L&}', 'expected' => '\p{L&}'];
        yield 'a name the target spells otherwise' => ['atom' => '\p{greek}', 'expected' => null];
        yield 'a script the target does not know' => ['atom' => '\p{Kawi}', 'expected' => null];
    }

    #[Test]
    public function test_an_atom_is_read_as_written_where_both_engines_read_names_alike(): void
    {
        $this->assertSame('\p{greek}', ClassSetProvider::spelledFor('\p{greek}', new PcreTarget(80400, '10.44'), new PcreTarget(80400, '10.49')));
        $this->assertSame('\p{greek}', ClassSetProvider::spelledFor('\p{greek}', new PcreTarget(80400, '10.49'), new PcreTarget(80400, '10.44')));
        $this->assertSame('\p{Han}', ClassSetProvider::spelledFor('\p{Han}', new PcreTarget(80100, '10.39'), new PcreTarget(80100, '10.35')));
        $this->assertSame('\p{greek}', ClassSetProvider::spelledFor('\p{greek}', null, new PcreTarget(80100, '10.39')));
    }

    /**
     * PCRE2 10.45 added Sunuwar, 10.34 Elymaic: an older engine refuses
     * them, and has no other name for them.
     */
    #[Test]
    public function test_a_name_a_later_release_added_is_unknown_to_an_older_engine(): void
    {
        $this->assertNull(ClassSetProvider::spelledFor('\p{sunuwar}', new PcreTarget(80400, '10.49'), new PcreTarget(80400, '10.44')));
        $this->assertNull(ClassSetProvider::spelledFor('[\p{sc=Sunu}a]', new PcreTarget(80400, '10.49'), new PcreTarget(80400, '10.44')));
        $this->assertNull(ClassSetProvider::spelledFor('\p{Elymaic}', new PcreTarget(80100, '10.39'), new PcreTarget(70400, '10.33')));
        $this->assertSame('\p{Elymaic}', ClassSetProvider::spelledFor('\p{Elymaic}', new PcreTarget(80100, '10.39'), new PcreTarget(80000, '10.35')));
    }
}
