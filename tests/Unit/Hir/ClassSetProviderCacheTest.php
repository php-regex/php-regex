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

use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Parser\Internal\StaticCaches;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the class scan keeps for the process: a probe PCRE refuses, or an
 * atom that leaves no delimiter free, is answered once and for all; a scan
 * the engine gave up on under its limits is not, the next query scans
 * again.
 *
 * Whether the engine is asked is seen through the raise of the limits: with
 * the caller's backtrack limit below the floor, each library regex run
 * outside a window asks for the raise first. The work runs in a closure
 * that sets everything back before any assertion: PHPUnit runs regexes of
 * its own while it reports.
 */
final class ClassSetProviderCacheTest extends TestCase
{
    /**
     * @var array<string, string|false>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['pcre.backtrack_limit', 'pcre.recursion_limit'] as $key) {
            $this->saved[$key] = ini_get($key);
        }
        StaticCaches::clear();
        LibraryPcre::useIniSetter(null);
    }

    protected function tearDown(): void
    {
        LibraryPcre::useIniSetter(null);
        foreach ($this->saved as $key => $value) {
            if (false !== $value) {
                ini_set($key, $value);
            }
        }
        StaticCaches::clear();
    }

    /**
     * Under a backtrack limit of 2, the raise refused, the probe "[a-z]"
     * gives up on the first block (the probe runs without the JIT, so the
     * limit bites whatever ran before). Nothing is kept: the second query
     * asks the engine again, and once the limit is back the third one gets
     * the set.
     */
    #[Test]
    public function test_query_scans_again_after_the_engine_gave_up(): void
    {
        $raises = 0;
        $work = static function () use (&$raises): array {
            LibraryPcre::useIniSetter(static function () use (&$raises): false {
                $raises++;

                return false;
            });

            $first = ClassSetProvider::query('[a-z]', true, '');
            $askedFirst = $raises;
            $second = ClassSetProvider::query('[a-z]', true, '');

            return [$first, $askedFirst, $second, $raises - $askedFirst];
        };
        [$first, $askedFirst, $second, $askedSecond] = self::underBacktrackLimit('2', $work);
        $third = ClassSetProvider::query('[a-z]', true, '');

        $this->assertNull($first);
        $this->assertGreaterThan(0, $askedFirst);
        $this->assertNull($second);
        $this->assertGreaterThan(0, $askedSecond, 'The failed scan was kept: the engine was not asked again.');
        $this->assertInstanceOf(CharSet::class, $third);
        $this->assertSame([[0x61, 0x7A]], $third->ranges);
    }

    /**
     * An atom PCRE refuses to compile: the first query asks the engine, the
     * second does not, and the warning the engine raises reaches no handler
     * and is not left as PHP's last error.
     */
    #[Test]
    #[DataProvider('provideRefusedAtoms')]
    public function test_query_keeps_a_probe_pcre_refuses(string $atom): void
    {
        $this->assertFalse(@preg_match('/'.$atom.'/u', ''), 'Oracle: PCRE refuses the atom.');

        $raises = 0;
        $work = static function () use ($atom, &$raises): array {
            LibraryPcre::useIniSetter(static function (string $key, string $value) use (&$raises): string|false {
                $raises++;

                return ini_set($key, $value);
            });

            // The oracle above left its own warning there.
            error_clear_last();
            $first = ClassSetProvider::query($atom, true, '');
            $askedFirst = $raises;
            $second = ClassSetProvider::query($atom, true, '');

            return [$first, $askedFirst, $second, $raises - $askedFirst, error_get_last()];
        };
        [$first, $askedFirst, $second, $askedSecond, $lastError] = self::underBacktrackLimit('2', $work);

        $this->assertNull($first);
        $this->assertGreaterThan(0, $askedFirst);
        $this->assertNull($second);
        $this->assertSame(0, $askedSecond, 'The refusal was not kept: the engine was asked again.');
        $this->assertNull($lastError, 'The refused probe left a warning behind.');
    }

    /**
     * @return iterable<string, array{atom: string}>
     */
    public static function provideRefusedAtoms(): iterable
    {
        yield 'range out of order' => ['atom' => '[z-a]'];
        yield 'unknown POSIX class' => ['atom' => '[[:foo:]]'];
        yield 'unknown property' => ['atom' => '\\p{Nope}'];
    }

    /**
     * Every delimiter the probe can use stands in the atom, a class PCRE
     * reads well with brace delimiters: no probe can be written, the engine
     * is never asked, and the answer is null.
     */
    #[Test]
    public function test_query_answers_null_for_an_atom_holding_every_delimiter(): void
    {
        $atom = "[/#~%!@;\x01]";
        $this->assertSame(1, preg_match('{'.$atom.'}', "\x01"), 'Oracle: PCRE reads the atom.');
        $this->assertSame(0, preg_match('{'.$atom.'}', 'a'));

        $raises = 0;
        [$first, $second] = self::underBacktrackLimit('2', static function () use ($atom, &$raises): array {
            LibraryPcre::useIniSetter(static function (string $key, string $value) use (&$raises): string|false {
                $raises++;

                return ini_set($key, $value);
            });

            return [ClassSetProvider::query($atom, true, ''), ClassSetProvider::query($atom, true, '')];
        });

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertSame(0, $raises, 'The engine was asked for an atom no probe can hold.');
    }

    /**
     * "r" travels with the other options of the scope, in any order. Under
     * "r" a caseless "k" stops matching the Kelvin sign U+212A ("/(?i)k/ur"
     * on U+212A: 0; without r: 1). A PCRE2 older than 10.43 refuses the
     * probe: the answer is null.
     */
    #[Test]
    public function test_query_reads_caseless_restrict_from_the_scope(): void
    {
        $restricted = ClassSetProvider::query('k', true, 'ir');
        $alreadyThere = ClassSetProvider::query('k', true, 'ri');
        $unrestricted = ClassSetProvider::query('k', true, 'i');

        $this->assertInstanceOf(CharSet::class, $unrestricted);
        $this->assertSame(1, preg_match('/(?i)k/u', "\u{212A}"));
        $this->assertTrue($unrestricted->contains(0x212A));

        if (!PcreTarget::runtime()->supports(PcreFeature::CaselessRestrictModifier)) {
            $this->assertNull($restricted);
            $this->assertNull($alreadyThere);

            return;
        }

        // The inline "(?r)" works on every PHP; the /r flag only from 8.4.
        $this->assertSame(0, preg_match('/(?ir)k/u', "\u{212A}"), 'Oracle: r keeps the Kelvin sign out.');
        $this->assertSame(1, preg_match('/(?ir)k/u', 'K'));
        $this->assertInstanceOf(CharSet::class, $restricted);
        $this->assertSame([[0x4B, 0x4B], [0x6B, 0x6B]], $restricted->ranges);
        $this->assertInstanceOf(CharSet::class, $alreadyThere);
        $this->assertSame($restricted->ranges, $alreadyThere->ranges);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private static function underBacktrackLimit(string $limit, \Closure $work): mixed
    {
        $saved = (string) ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.backtrack_limit', $limit);

            return $work();
        } finally {
            LibraryPcre::useIniSetter(null);
            ini_set('pcre.backtrack_limit', $saved);
        }
    }
}
