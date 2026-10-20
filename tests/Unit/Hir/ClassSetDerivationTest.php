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
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Engine\NoJit;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under /u some atoms are read from others instead of being scanned: a
 * negated type or property is the rest of the alphabet, the dot is the same
 * caseless and is every character under "s", a negated POSIX class is the
 * rest of the POSIX class, and every general category comes from one scan
 * that reads the 30 leaf categories at once. Each answer is held to the
 * running engine's scan of the whole atom, and so is a scan with the JIT to
 * the same scan without it.
 */
final class ClassSetDerivationTest extends TestCase
{
    /**
     * The leaf general categories, each character in exactly one.
     */
    private const LEAF_CATEGORIES = [
        'Lu', 'Ll', 'Lt', 'Lm', 'Lo', 'Mn', 'Mc', 'Me', 'Nd', 'Nl', 'No',
        'Pc', 'Pd', 'Ps', 'Pe', 'Pi', 'Pf', 'Po', 'Sm', 'Sc', 'Sk', 'So',
        'Zs', 'Zl', 'Zp', 'Cc', 'Cf', 'Cs', 'Co', 'Cn',
    ];

    protected function setUp(): void
    {
        StaticCaches::clear();
        // A command, a plugin or a rule built earlier in this process may
        // have left the answers kept on disk, where an earlier query wrote
        // them: these tests ask the engine.
        ClassSetProvider::persistIn(null);
        self::useJit(true);
    }

    protected function tearDown(): void
    {
        self::useJit(true);
        StaticCaches::clear();
    }

    #[Test]
    #[DataProvider('provideDerivedAtoms')]
    public function test_a_derived_atom_is_what_the_engine_scans(string $atom, string $modifiers, string $startVerbs, bool $unicodeFlag): void
    {
        $this->assertEquals(self::scan($atom, $modifiers, $startVerbs, $unicodeFlag), ClassSetProvider::query($atom, true, $modifiers, $startVerbs, $unicodeFlag), $atom);
    }

    /**
     * @return iterable<string, array{atom: string, modifiers: string, startVerbs: string, unicodeFlag: bool}>
     */
    public static function provideDerivedAtoms(): iterable
    {
        $ascii = PcreTarget::runtime()->supports(PcreFeature::AsciiOptions);
        foreach (['\D', '\S', '\W', '\H', '\V'] as $type) {
            foreach (['', 'i', 'x', 's'] as $modifiers) {
                yield \sprintf('%s under "%s"', $type, $modifiers) => ['atom' => $type, 'modifiers' => $modifiers, 'startVerbs' => '', 'unicodeFlag' => true];
            }

            yield $type.' under (*UTF) without UCP' => ['atom' => $type, 'modifiers' => '', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
            if ($ascii) {
                foreach (['aD', 'aS', 'aW', 'a'] as $modifiers) {
                    yield \sprintf('%s under "%s"', $type, $modifiers) => ['atom' => $type, 'modifiers' => $modifiers, 'startVerbs' => '', 'unicodeFlag' => true];
                }
            }
        }

        foreach (['\P{Lu}', '\p{^N}', '\P{Han}', '\p{^Greek}', '\P{Any}', '\P{Xan}', '\P{Cn}'] as $property) {
            yield $property => ['atom' => $property, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        }

        yield '\P{Lu} caseless' => ['atom' => '\P{Lu}', 'modifiers' => 'i', 'startVerbs' => '', 'unicodeFlag' => true];
        yield '\P{Han} caseless' => ['atom' => '\P{Han}', 'modifiers' => 'i', 'startVerbs' => '', 'unicodeFlag' => true];
        foreach (['', 'i', 's', 'is', 'x', 'six'] as $modifiers) {
            foreach (['', '(*CR)', '(*ANY)', '(*ANYCRLF)', '(*NUL)', '(*UCP)'] as $startVerbs) {
                yield \sprintf('. under "%s" and "%s"', $modifiers, $startVerbs) => ['atom' => '.', 'modifiers' => $modifiers, 'startVerbs' => $startVerbs, 'unicodeFlag' => true];
            }
        }

        yield '. under s, (*UTF) without UCP' => ['atom' => '.', 'modifiers' => 's', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
        foreach (self::LEAF_CATEGORIES as $category) {
            yield '\p{'.$category.'}' => ['atom' => '\p{'.$category.'}', 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        }

        foreach (['C', 'L', 'M', 'N', 'P', 'S', 'Z'] as $category) {
            yield '\p{'.$category.'}' => ['atom' => '\p{'.$category.'}', 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
            yield '\P{'.$category.'}' => ['atom' => '\P{'.$category.'}', 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
        }

        foreach (['\p{Lu}', '\p{Ll}', '\p{Lt}', '\p{L}'] as $property) {
            yield $property.' caseless' => ['atom' => $property, 'modifiers' => 'i', 'startVerbs' => '', 'unicodeFlag' => true];
        }

        yield '\p{Lu} under (*UCP)' => ['atom' => '\p{Lu}', 'modifiers' => '', 'startVerbs' => '(*UCP)', 'unicodeFlag' => true];
        yield '\p{Nd} under (*UTF) without UCP' => ['atom' => '\p{Nd}', 'modifiers' => '', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
        foreach (['alpha', 'alnum', 'digit', 'lower', 'upper', 'cntrl', 'space', 'word', 'punct', 'xdigit'] as $name) {
            yield '[[:'.$name.':]]' => ['atom' => '[[:'.$name.':]]', 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
            yield '[^[:'.$name.':]]' => ['atom' => '[^[:'.$name.':]]', 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => true];
            yield '[[:'.$name.':]] caseless' => ['atom' => '[[:'.$name.':]]', 'modifiers' => 'i', 'startVerbs' => '', 'unicodeFlag' => true];
            yield '[^[:'.$name.':]] caseless' => ['atom' => '[^[:'.$name.':]]', 'modifiers' => 'i', 'startVerbs' => '', 'unicodeFlag' => true];
            yield '[[:'.$name.':]] under (*UTF) without UCP' => ['atom' => '[[:'.$name.':]]', 'modifiers' => '', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
            yield '[^[:'.$name.':]] under (*UTF) without UCP' => ['atom' => '[^[:'.$name.':]]', 'modifiers' => '', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
            if ($ascii) {
                yield '[[:'.$name.':]] under aP' => ['atom' => '[[:'.$name.':]]', 'modifiers' => 'aP', 'startVerbs' => '', 'unicodeFlag' => true];
                yield '[[:'.$name.':]] under aT' => ['atom' => '[[:'.$name.':]]', 'modifiers' => 'aT', 'startVerbs' => '', 'unicodeFlag' => true];
            }
        }
    }

    #[Test]
    public function test_a_negated_type_is_the_rest_of_its_type(): void
    {
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\S', true, ''));

        $this->assertSame(['u|:\s', 'u|:\S'], self::keptKeys());
    }

    #[Test]
    public function test_a_negated_property_is_the_rest_of_the_property(): void
    {
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\P{Han}', true, ''));
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\p{^Greek}', true, ''));

        $this->assertSame(['u|:\p{Han}', 'u|:\P{Han}', 'u|:\p{Greek}', 'u|:\p{^Greek}'], self::keptKeys());
    }

    /**
     * Caseless, "\P{Lu}" is no complement the provider knows: PCRE2 10.45
     * reads caseless letter categories otherwise. It is scanned alone.
     */
    #[Test]
    public function test_a_caseless_negated_letter_category_is_scanned_alone(): void
    {
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('\P{Lu}', true, 'i'));

        $this->assertSame(['u|i:\P{Lu}'], self::keptKeys());
    }

    #[Test]
    public function test_the_dot_matches_the_same_caseless(): void
    {
        $this->assertEquals(ClassSetProvider::query('.', true, ''), ClassSetProvider::query('.', true, 'i'));
        $this->assertEquals(ClassSetProvider::query('.', true, 's'), ClassSetProvider::query('.', true, 'is'));

        $this->assertSame(['u|:.', 'u|s:.'], self::keptKeys());
    }

    #[Test]
    public function test_the_dot_under_s_is_every_character(): void
    {
        $this->assertEquals(CharSet::universe(true), self::derived('.', 's', '', true));
        $this->assertEquals(CharSet::universe(true), self::derived('.', 's', '(*CR)', true));
        $this->assertEquals(CharSet::universe(true), self::derived('.', 's', '(*UTF)', false));
        $this->assertNull(self::derived('.', '', '', true));
    }

    #[Test]
    public function test_a_negated_posix_class_is_the_rest_of_the_posix_class(): void
    {
        $this->assertInstanceOf(CharSet::class, ClassSetProvider::query('[^[:punct:]]', true, ''));

        $this->assertSame(['u|:[[:punct:]]', 'u|:[^[:punct:]]'], self::keptKeys());
    }

    /**
     * One scan reads every leaf category; a category asked after it is
     * read from it, a major one as the union of its leaves, and a POSIX
     * class UCP maps onto categories alike.
     */
    #[Test]
    public function test_every_general_category_is_read_from_one_scan(): void
    {
        $this->assertEquals(self::scan('\p{L}', '', '', true), ClassSetProvider::query('\p{L}', true, ''));

        $categories = (new \ReflectionClass(ClassSetProvider::class))->getStaticPropertyValue('categories');
        $this->assertIsArray($categories);
        $this->assertSame(self::LEAF_CATEGORIES, array_keys($categories));
        $this->assertContainsOnlyInstancesOf(CharSet::class, $categories);

        $union = CharSet::empty();
        foreach ($categories as $set) {
            $this->assertTrue($union->intersect($set)->isEmpty(), 'Two leaf categories hold the same character.');
            $union = $union->union($set);
        }

        $this->assertEquals(CharSet::universe(true), $union);
        $this->assertEquals($categories['Nd'], self::derived('[[:digit:]]', '', '', true));
        $this->assertEquals($categories['Lu'], self::derived('\p{Lu}', '', '', true));
        $this->assertNull(self::derived('\p{Lu}', 'i', '', true));
        $this->assertNull(self::derived('[[:digit:]]', '', '(*UTF)', false));
        $this->assertNull(self::derived('\p{Lu}', '', '(*UCP)', true));
    }

    /**
     * Categories that leave characters to no branch are no partition, and
     * a name the engine refuses gives none: the answer is false, kept, and
     * each category is scanned alone. Read without the JIT under a
     * backtrack limit of 2, the engine gives up: nothing is kept, and the
     * next query reads them all.
     */
    #[Test]
    public function test_categories_that_are_no_partition_are_scanned_alone(): void
    {
        $partition = new \ReflectionMethod(ClassSetProvider::class, 'partition');
        $this->assertFalse($partition->invoke(null, ['Lu', 'Ll']));
        $this->assertFalse($partition->invoke(null, ['Nope']));

        self::useJit(false);
        $saved = (string) ini_get('pcre.backtrack_limit');
        LibraryPcre::useIniSetter(static fn (): bool => false);

        try {
            ini_set('pcre.backtrack_limit', '2');
            $gaveUp = $partition->invoke(null, self::LEAF_CATEGORIES);
            $derived = self::derived('\p{Lu}', '', '', true);
        } finally {
            LibraryPcre::useIniSetter(null);
            ini_set('pcre.backtrack_limit', $saved);
        }

        $this->assertNull($gaveUp);
        $this->assertNull($derived);
        $this->assertNull((new \ReflectionClass(ClassSetProvider::class))->getStaticPropertyValue('categories'));
        $this->assertEquals(self::scan('\p{Lu}', '', '', true), self::derived('\p{Lu}', '', '', true));
    }

    #[Test]
    #[DataProvider('provideScannedAtoms')]
    public function test_the_jit_and_the_interpreter_scan_alike(string $atom, bool $unicode, string $modifiers, string $startVerbs, bool $unicodeFlag): void
    {
        $withJit = self::scan($atom, $modifiers, $startVerbs, $unicodeFlag, $unicode);
        self::useJit(false);
        $withoutJit = self::scan($atom, $modifiers, $startVerbs, $unicodeFlag, $unicode);

        $this->assertInstanceOf(CharSet::class, $withJit);
        $this->assertEquals($withoutJit, $withJit, $atom);
    }

    /**
     * @return iterable<string, array{atom: string, unicode: bool, modifiers: string, startVerbs: string, unicodeFlag: bool}>
     */
    public static function provideScannedAtoms(): iterable
    {
        foreach (['\w', '\d', '\s', '\h', '\v', '\W', '.', '\p{L}', '\p{Lu}', '\p{Han}', '\p{Greek}', '[[:alpha:]]', '[[:punct:]]', '[\x{A1}-\x{FFFF}]', '\x{E9}', '\x{3A3}', 'k', '\x{1E9E}'] as $atom) {
            foreach (['', 'i'] as $modifiers) {
                yield \sprintf('%s under "%s"', $atom, $modifiers) => ['atom' => $atom, 'unicode' => true, 'modifiers' => $modifiers, 'startVerbs' => '', 'unicodeFlag' => true];
            }

            yield $atom.' under (*UTF) without UCP' => ['atom' => $atom, 'unicode' => true, 'modifiers' => '', 'startVerbs' => '(*UTF)', 'unicodeFlag' => false];
        }

        foreach (['(*CR)', '(*ANY)', '(*NUL)'] as $startVerbs) {
            yield '. under '.$startVerbs => ['atom' => '.', 'unicode' => true, 'modifiers' => '', 'startVerbs' => $startVerbs, 'unicodeFlag' => true];
        }

        yield '\w bytes' => ['atom' => '\w', 'unicode' => false, 'modifiers' => '', 'startVerbs' => '', 'unicodeFlag' => false];
    }

    /**
     * The JIT runs a Unicode probe whose atom holds no parenthesis: the
     * known JIT crash needs a capture and a backreference, which a probe
     * without one cannot hold. A byte probe runs without it: compiling it
     * costs more than reading 256 bytes.
     */
    #[Test]
    public function test_the_probe_runs_with_the_jit_where_it_pays(): void
    {
        $this->assertStringNotContainsString(NoJit::VERB, self::probe('\w', true, '', 'u', ''));
        $this->assertStringNotContainsString(NoJit::VERB, self::probe('[\x{A1}-\x{FFFF}]', true, 'i', 'u', '(*UCP)'));
        $this->assertStringContainsString(NoJit::VERB, self::probe('[(]', true, '', 'u', ''));
        $this->assertStringContainsString(NoJit::VERB, self::probe('\w', false, '', '', ''));

        self::useJit(false);
        $this->assertStringContainsString(NoJit::VERB, self::probe('\w', true, '', 'u', ''));
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
    private static function scan(string $atom, string $modifiers, string $startVerbs, bool $unicodeFlag, bool $unicode = true): ?CharSet
    {
        $set = (new \ReflectionMethod(ClassSetProvider::class, 'scan'))->invoke(null, $atom, $unicode, $modifiers, $unicodeFlag && $unicode ? 'u' : '', $startVerbs);
        self::assertTrue($set instanceof CharSet || false === $set || null === $set);

        return $set instanceof CharSet ? $set : null;
    }

    private static function derived(string $atom, string $modifiers, string $startVerbs, bool $unicodeFlag): ?CharSet
    {
        $set = (new \ReflectionMethod(ClassSetProvider::class, 'derived'))->invoke(null, $atom, $modifiers, $startVerbs, $unicodeFlag);
        self::assertTrue($set instanceof CharSet || null === $set);

        return $set;
    }

    private static function probe(string $atom, bool $unicode, string $modifiers, string $global, string $startVerbs): string
    {
        $probe = (new \ReflectionMethod(ClassSetProvider::class, 'probe'))->invoke(null, $atom, $unicode, $modifiers, $global, $startVerbs, '/');
        self::assertIsString($probe);

        return $probe;
    }

    private static function useJit(bool $jit): void
    {
        (new \ReflectionClass(ClassSetProvider::class))->setStaticPropertyValue('jit', $jit);
    }
}
