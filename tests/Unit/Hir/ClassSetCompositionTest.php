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

use PHPRegex\Parser\Cache\StaticCaches;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A class that is not caseless is the union of its members: each type,
 * property or POSIX class is scanned once for the process, whatever class
 * holds it, the characters and ranges are read as written, and a negated
 * class is the rest of the alphabet. A caseless ASCII character is itself
 * and its other case, but "k" and "s", which Unicode folds with a third
 * character. Every answer is held to the scan of the whole atom by the
 * running engine.
 */
final class ClassSetCompositionTest extends TestCase
{
    protected function setUp(): void
    {
        StaticCaches::clear();
    }

    protected function tearDown(): void
    {
        StaticCaches::clear();
    }

    #[Test]
    public function test_a_member_is_scanned_once_for_every_class_that_holds_it(): void
    {
        foreach (['[\w-]', '[\w.]', '[\w\-]', '[^\w@]'] as $atom) {
            $this->assertInstanceOf(CharSet::class, ClassSetProvider::query($atom, true, ''));
        }

        $kept = self::keptKeys();
        $this->assertContains('u|:\w', $kept);
        $this->assertCount(5, $kept);
    }

    #[Test]
    #[DataProvider('provideClasses')]
    public function test_a_class_is_what_the_engine_scans(string $atom, bool $unicode, string $modifiers, string $startVerbs, bool $unicodeFlag): void
    {
        $composed = ClassSetProvider::query($atom, $unicode, $modifiers, $startVerbs, $unicodeFlag);

        $this->assertEquals(self::scan($atom, $unicode, $modifiers, $startVerbs, $unicodeFlag), $composed, $atom);
    }

    /**
     * @return iterable<string, array{atom: string, unicode: bool, modifiers: string, startVerbs: string, unicodeFlag: bool}>
     */
    public static function provideClasses(): iterable
    {
        $classes = ['[\w-]', '[^\w.]', '[\d\s]', '[[:alpha:]_]', '[^[:^digit:]x]', '[a-z\d]', '[\w\W]', '[^\s\S]', '[\h\v]', '[\Qa-z\E]', '[\x00-\x1F\x7F]', '[^a-c]', '[]a-]'];
        foreach ($classes as $class) {
            yield $class.' bytes' => ['atom' => $class, 'unicode' => false, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => false];
        }

        yield '[[:^alpha:]x] under u' => ['atom' => '[[:^alpha:]x]', 'unicode' => true, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        yield '[\w-] under u' => ['atom' => '[\w-]', 'unicode' => true, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        yield '[^\pL\d] under u' => ['atom' => '[^\pL\d]', 'unicode' => true, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        yield '[\p{L}\p{N}_] under u' => ['atom' => '[\p{L}\p{N}_]', 'unicode' => true, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        yield '[a-z\x{100}-\x{2FF}] under u' => ['atom' => '[a-z\x{100}-\x{2FF}]', 'unicode' => true, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        yield '[\d\s] under (*UTF), no UCP' => ['atom' => '[\d\s]', 'unicode' => true, 'modifiers' => '', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
        yield '[\w.] under (*UCP) without u' => ['atom' => '[\w.]', 'unicode' => false, 'modifiers' => '', 'startVerbs' => '(*UCP)', 'unicodeFlag' => false];
        yield '[a b] under xx' => ['atom' => '[a b]', 'unicode' => false, 'modifiers' => 'xx', 'startVerbs' => '', 'unicodeFlag' => false];
        yield '[a b] under x' => ['atom' => '[a b]', 'unicode' => false, 'modifiers' => 'x', 'startVerbs' => '', 'unicodeFlag' => false];
        yield '[\w.] under s' => ['atom' => '[\w.]', 'unicode' => false, 'modifiers' => 's', 'startVerbs' => '', 'unicodeFlag' => false];
        yield 'bytes of a character without u' => ['atom' => "[\u{E9}a]", 'unicode' => false, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => false];
        foreach (['[a-zA-Z0-9]', '[k-s]', '[\x{430}-\x{44F}x]', '[^a-z]', '[\p{Lu}x]', '[\w\s.]', "[\u{E9}_]", '[[:^alpha:]x]', '[\pL\d]', '[\D]'] as $class) {
            yield $class.' caseless under u' => ['atom' => $class, 'unicode' => true, 'modifiers' => 'i', 'startVerbs' => '', 'unicodeFlag' => true];
        }

        foreach (['[a-z_]', '[[:alpha:]-]', '[^A-F0-9]'] as $class) {
            yield $class.' caseless bytes' => ['atom' => $class, 'unicode' => false, 'modifiers' => 'i', 'startVerbs' => '', 'unicodeFlag' => false];
        }

        yield '[a-z_] caseless under (*UTF), no UCP' => ['atom' => '[a-z_]', 'unicode' => true, 'modifiers' => 'i', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
        if (PcreTarget::runtime()->supports(PcreFeature::AsciiOptions)) {
            yield '[\d\w] under aD' => ['atom' => '[\d\w]', 'unicode' => true, 'modifiers' => 'aD', 'startVerbs' => '', 'unicodeFlag' => true];
            yield '[[:alpha:]\d] under aP' => ['atom' => '[[:alpha:]\d]', 'unicode' => true, 'modifiers' => 'aP', 'startVerbs' => '', 'unicodeFlag' => true];
        }
    }

    /**
     * "\pL" and "\p{L}" are one atom, "\p{^L}" and "\P{L}" another; a
     * category caseless matching leaves alone is asked without "i", a type
     * too. "\p" with no name is no category: the engine refuses it.
     */
    #[Test]
    public function test_one_spelling_and_one_scan_per_category(): void
    {
        $this->assertEquals(ClassSetProvider::query('\p{L}', true, ''), ClassSetProvider::query('\pL', true, 'i'));
        $this->assertEquals(ClassSetProvider::query('\P{N}', true, ''), ClassSetProvider::query('\p{^N}', true, 's'));
        $this->assertEquals(ClassSetProvider::query('\d', true, ''), ClassSetProvider::query('\d', true, 'ix'));
        $this->assertNull(ClassSetProvider::query('\p', true, ''));

        $this->assertSame(['u|:\p{L}', 'u|:\P{N}', 'u|:\d', 'u|:\p'], self::keptKeys());
    }

    #[Test]
    #[DataProvider('provideCaselessCharacters')]
    public function test_a_caseless_ascii_character_is_what_the_engine_scans(int $codePoint, bool $unicode, string $modifiers): void
    {
        $atom = \sprintf('\x{%X}', $codePoint);

        $this->assertEquals(self::scan($atom, $unicode, $modifiers, '', $unicode), ClassSetProvider::query($atom, $unicode, $modifiers, '', $unicode), $atom);
    }

    /**
     * @return iterable<string, array{codePoint: int, unicode: bool, modifiers: string}>
     */
    public static function provideCaselessCharacters(): iterable
    {
        $restrict = PcreTarget::runtime()->supports(PcreFeature::CaselessRestrictModifier);
        for ($codePoint = 0; $codePoint < 0x80; $codePoint++) {
            yield \sprintf('U+%04X bytes', $codePoint) => ['codePoint' => $codePoint, 'unicode' => false, 'modifiers' => 'i'];
        }

        foreach (str_split('aAkKsSzZ09:._ -~') as $character) {
            yield \sprintf('"%s" under u', $character) => ['codePoint' => \ord($character), 'unicode' => true, 'modifiers' => 'i'];
            if ($restrict) {
                yield \sprintf('"%s" under u and r', $character) => ['codePoint' => \ord($character), 'unicode' => true, 'modifiers' => 'ir'];
            }
        }
    }

    /**
     * The keys of the sets the provider keeps.
     *
     * @return list<string>
     */
    private static function keptKeys(): array
    {
        $sets = (new \ReflectionClass(ClassSetProvider::class))->getStaticPropertyValue('sets');
        self::assertIsArray($sets);

        return array_map(strval(...), array_keys($sets));
    }

    /**
     * What the engine answers for the whole atom, as the provider asks it.
     */
    private static function scan(string $atom, bool $unicode, string $modifiers, string $startVerbs, bool $unicodeFlag): ?CharSet
    {
        $set = (new \ReflectionMethod(ClassSetProvider::class, 'scan'))->invoke(null, $atom, $unicode, $modifiers, $unicodeFlag && $unicode ? 'u' : '', $startVerbs);
        self::assertTrue($set instanceof CharSet || false === $set || null === $set);

        return $set instanceof CharSet ? $set : null;
    }
}
