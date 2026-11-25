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
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Hir\CharSet;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A byte scan answers the set every path of the engine reads, and the
 * set of the target's release.
 *
 * Under another locale's tables without u, the interpreter compares a
 * character matched alone by the small letters of the tables
 * (pcre2_match.c, OP_CHARI), where the JIT and the interpreter's repeat of
 * it take the byte and the other case the tables give it, and PCRE2 reads
 * a class of a byte and that other case as the byte, caseless
 * (pcre2_compile.c). Where the paths read an atom apart, its set is not
 * known: under macOS's tr_TR.ISO8859-9 the dotless i "\xFD" takes "I" on
 * the JIT alone, "I" the dotted capital "\xDD" on the interpreter alone;
 * under el_GR.ISO8859-7 the final sigma "\xF2" takes the capital "\xD3" on
 * the JIT alone.
 *
 * Without UTF, (*UCP) gives a byte above ASCII the case of the character
 * of its value from PCRE2 10.35 alone: before it, the tables give the case
 * of every byte, as without (*UCP), none above ASCII under C, while
 * "\w" still reads Unicode's properties.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, the JIT in a process of its
 * own, the interpreter with "(*NO_JIT)"; the rows of PCRE2 10.34 on
 * php:7.4.10, with the JIT and without it, under C.
 */
final class ClassSetEnginePathsTest extends TestCase
{
    use InstalledLocales;

    protected function setUp(): void
    {
        StaticCaches::clear();
        ClassSetProvider::persistIn(null);
    }

    protected function tearDown(): void
    {
        StaticCaches::clear();
    }

    /**
     * @param list<array{int, int}>|null $ranges the bytes every path takes, null where the paths read the atom apart
     */
    #[Test]
    #[DataProvider('provideAtomsUnderLocaleTables')]
    public function test_an_atom_the_paths_of_the_engine_read_apart_is_not_known(string $atom, string $modifiers, string $locale, ?array $ranges): void
    {
        $this->requiresLocale($locale, $atom);
        $label = addcslashes($atom, "\200..\377").' under '.$locale;

        // Oracle: the bytes the atom takes alone on the interpreter, and
        // repeated, which reads them as the JIT does alone, and alone on
        // the JIT where this PHP has one.
        $alone = '/(*NO_START_OPT)^(?'.$modifiers.':'.$atom.')$/';
        $reads = self::bytesTaken([
            'interpreter' => ['/(*NO_JIT)'.substr($alone, 1), false],
            'repeated' => ['/(*NO_JIT)(*NO_START_OPT)^(?'.$modifiers.':'.$atom.'{2})$/', true],
        ], $locale);
        $onJit = self::bytesTakenOnTheJit($alone, $locale);
        if (null !== $onJit) {
            $reads['jit'] = $onJit;
        }

        $this->assertSame(null !== $ranges, 1 === \count(array_unique(array_map(serialize(...), $reads))), 'Oracle: the paths read '.$label.' alike.');
        if (null !== $ranges) {
            $this->assertSame($ranges, $reads['interpreter'], 'Oracle: '.$label);
        }

        $set = ClassSetProvider::query($atom, false, $modifiers, '', false, $locale);
        $this->assertSame($ranges, $set?->ranges, $label);
    }

    /**
     * @return iterable<string, array{atom: string, modifiers: string, locale: string, ranges: list<array{int, int}>|null}>
     */
    public static function provideAtomsUnderLocaleTables(): iterable
    {
        $turkish = 'tr_TR.ISO8859-9';
        $greek = 'el_GR.ISO8859-7';
        yield 'the dotless i, caseless' => ['atom' => '\x{FD}', 'modifiers' => 'i', 'locale' => $turkish, 'ranges' => null];
        yield 'I, caseless' => ['atom' => '\x{49}', 'modifiers' => 'i', 'locale' => $turkish, 'ranges' => null];
        yield 'a class of the dotless i and I' => ['atom' => '[\xFDI]', 'modifiers' => '', 'locale' => $turkish, 'ranges' => null];
        yield 'a class of i and I' => ['atom' => '[Ii]', 'modifiers' => '', 'locale' => $turkish, 'ranges' => null];
        yield 'the small c with cedilla, caseless' => ['atom' => '\x{E7}', 'modifiers' => 'i', 'locale' => $turkish, 'ranges' => [[0xC7, 0xC7], [0xE7, 0xE7]]];
        yield 'a class of the dotless i and the small s with cedilla' => ['atom' => '[\xFD\xFE]', 'modifiers' => '', 'locale' => $turkish, 'ranges' => [[0xFD, 0xFE]]];
        yield 'the final sigma, caseless' => ['atom' => '\x{F2}', 'modifiers' => 'i', 'locale' => $greek, 'ranges' => null];
        yield 'the small sigma, caseless' => ['atom' => '\x{F3}', 'modifiers' => 'i', 'locale' => $greek, 'ranges' => [[0xD3, 0xD3], [0xF3, 0xF3]]];
        yield 'a small e with acute, caseless' => ['atom' => '\x{E9}', 'modifiers' => 'i', 'locale' => 'fr_FR.ISO8859-1', 'ranges' => [[0xC9, 0xC9], [0xE9, 0xE9]]];
    }

    /**
     * @param string|null           $pcre   the release of the target, null for the running one's
     * @param list<array{int, int}> $ranges the bytes the release's engine takes
     */
    #[Test]
    #[DataProvider('provideAtomsUnderUcpByRelease')]
    public function test_under_ucp_a_caseless_byte_takes_the_case_the_release_of_the_target_gives_it(string $atom, string $modifiers, ?string $pcre, array $ranges): void
    {
        $label = $atom.'/'.$modifiers.' under (*UCP) on '.($pcre ?? 'the running release');
        $folds = null === $pcre ? null : (new PcreTarget(\PHP_VERSION_ID, $pcre))->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf);
        // Oracle: the running engine, under C, where it reads (*UCP) as
        // the row's release does.
        if (null === $folds || PcreTarget::runtime()->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf) === $folds) {
            $pattern = '/(*UCP)^(?'.$modifiers.':'.$atom.')$/';
            foreach ([$pattern, '/(*NO_JIT)'.substr($pattern, 1)] as $engine) {
                $this->assertSame($ranges, self::bytesTaken(['taken' => [$engine, false]], CharacterTables::BUILT_IN)['taken'], 'Oracle: '.$engine);
            }
        }

        $this->assertSame($ranges, ClassSetProvider::query($atom, false, $modifiers, '(*UCP)', false, CharacterTables::BUILT_IN, $folds)?->ranges, $label);
    }

    /**
     * @return iterable<string, array{atom: string, modifiers: string, pcre: string|null, ranges: list<array{int, int}>}>
     */
    public static function provideAtomsUnderUcpByRelease(): iterable
    {
        $word = [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xB2, 0xB3], [0xB5, 0xB5], [0xB9, 0xBA], [0xBC, 0xBE], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]];
        foreach ([
            'a Latin-1 capital' => ['\x{C9}', 'i', [[0xC9, 0xC9]], [[0xC9, 0xC9], [0xE9, 0xE9]]],
            'a class leaving a Latin-1 letter out' => ['[^\x{E9}]', 'i', [[0x00, 0xE8], [0xEA, 0xFF]], [[0x00, 0xC8], [0xCA, 0xE8], [0xEA, 0xFF]]],
            'a range of Latin-1 capitals' => ['[\x{C0}-\x{DE}]', 'i', [[0xC0, 0xDE]], [[0xC0, 0xDE], [0xE0, 0xF6], [0xF8, 0xFE]]],
            'the letter k' => ['\x{6B}', 'i', [[0x4B, 0x4B], [0x6B, 0x6B]], [[0x4B, 0x4B], [0x6B, 0x6B]]],
            'the small y with diaeresis' => ['\x{FF}', 'i', [[0xFF, 0xFF]], [[0xFF, 0xFF]]],
            'a sign' => ['\x{D7}', 'i', [[0xD7, 0xD7]], [[0xD7, 0xD7]]],
            'a range across the bound of ASCII' => ['[\x{7F}-\x{80}]', 'i', [[0x7F, 0x80]], [[0x7F, 0x80]]],
            'a word character or a Latin-1 letter' => ['[\w\x{E9}]', 'i', $word, $word],
            'a Latin-1 capital, case-sensitive' => ['\x{C9}', '', [[0xC9, 0xC9]], [[0xC9, 0xC9]]],
        ] as $name => [$atom, $modifiers, $before, $from]) {
            yield $name.', on 10.34' => ['atom' => $atom, 'modifiers' => $modifiers, 'pcre' => '10.34', 'ranges' => $before];
            yield $name.', on 10.35' => ['atom' => $atom, 'modifiers' => $modifiers, 'pcre' => '10.35', 'ranges' => $from];
            yield $name.', on the running release' => ['atom' => $atom, 'modifiers' => $modifiers, 'pcre' => null, 'ranges' => $from];
        }
    }

    /**
     * The bytes each pattern takes under the locale, as ranges: a byte, or
     * the byte twice for a pattern that repeats it.
     *
     * @param array<string, array{string, bool}> $patterns each pattern, and whether it repeats the byte
     *
     * @return array<string, list<array{int, int}>>
     */
    private static function bytesTaken(array $patterns, string $locale): array
    {
        $taken = [];
        CharacterTables::under($locale, static function () use ($patterns, &$taken): void {
            foreach ($patterns as $name => [$pattern, $twice]) {
                $bytes = [];
                for ($byte = 0; $byte <= 0xFF; $byte++) {
                    if (1 === preg_match($pattern, str_repeat(\chr($byte), $twice ? 2 : 1))) {
                        $bytes[] = [$byte, $byte];
                    }
                }

                $taken[$name] = CharSet::fromRanges($bytes)->ranges;
            }
        });

        return $taken;
    }

    /**
     * The bytes the pattern takes on the JIT under the locale, in a process
     * of its own: PHP keeps a pattern compiled with the JIT, or without it,
     * whatever pcre.jit says later. Null where this PHP has no JIT.
     *
     * @return list<array{int, int}>|null
     */
    private static function bytesTakenOnTheJit(string $pattern, string $locale): ?array
    {
        $process = proc_open(
            [\PHP_BINARY, '-n', '-d', 'pcre.jit=1', '-r', 'setlocale(LC_CTYPE, $argv[1]); $t = []; for ($b = 0; $b < 256; $b++) { if (1 === preg_match(base64_decode($argv[2]), chr($b))) { $t[] = $b; } } echo json_encode(PCRE_JIT_SUPPORT ? $t : null);', $locale, base64_encode($pattern)],
            ProcessPipes::descriptors(),
            $pipes,
        );
        self::assertIsResource($process);
        $output = ProcessPipes::drain($pipes)[0];
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        /** @var list<int>|null $bytes */
        $bytes = json_decode($output, true, 512, \JSON_THROW_ON_ERROR);

        return null === $bytes ? null : CharSet::fromRanges(array_map(static fn (int $byte): array => [$byte, $byte], $bytes))->ranges;
    }
}
