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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Hir\Utf8;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Backtrack\LastCodeUnit;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first code unit PCRE2's compiler sets for a pattern: the interpreter
 * starts an attempt only where the subject holds it, the attempt pinned by
 * the A modifier included. Every row is pcre2test 10.49's "First code
 * unit" for the pattern (utf and ucp for u, "locale=" for the locale rows),
 * "(caseless)" where it looks for either case.
 *
 * Without u nor "(*UCP)", PCRE2 flags a first code unit caseless below 255
 * only, as it does the last one it requires: it looks for the byte 0xFF in
 * its own case, whatever other case the tables of the locale give it. Under
 * ru_RU.KOI8-R, whose tables pair 0xFF with 0xDF, the interpreter finds no
 * match of "/\xff+!/i" in "\xdf!", where the JIT, whose scan takes either
 * case, and "(*NO_START_OPT)" find one; 0xFE keeps its other case 0xDE, and
 * 0xDF its other case 0xFF. Under fr_FR.ISO8859-15 0xFF is paired with
 * 0xBE, and looked for alone too.
 *
 * Checked on PHP 8.4.28 with PCRE2 10.49, the JIT on and off.
 */
final class FirstCodeUnitTest extends TestCase
{
    use InstalledLocales;

    private const KOI8_R = 'ru_RU.KOI8-R';

    private const LATIN9 = 'fr_FR.ISO8859-15';

    /**
     * The cases of each locale, given under a name no machine sets.
     */
    private const GIVEN = [self::KOI8_R => 'xx_XX.FIRSTBYTEKOI8R', self::LATIN9 => 'xx_XX.FIRSTBYTELATIN9'];

    /**
     * The interpreter starts an attempt on a subject that begins with the
     * code unit, and with its other case where the row gives one; it
     * starts none on a subject that holds neither: with a match limit of
     * zero the search fails on the first attempt it starts, and returns 0
     * where it starts none. Where the compiler sets no first code unit,
     * the row lists characters an attempt starts at all the same.
     *
     * @param array{string, ?string}|null $expected the character, and its other case when PCRE2 looks for either
     * @param string                      $tail     what follows the first character in the subject
     * @param list<string>                $starts   characters an attempt starts at
     * @param list<string>                $unread   characters no attempt starts at
     */
    #[Test]
    #[DataProvider('provideFirstCodeUnits')]
    public function test_first_code_unit_is_the_one_pcre2_looks_for(string $pattern, ?array $expected, string $tail, array $starts, array $unread): void
    {
        $interpreted = PcreInterpreter::pattern($pattern);
        foreach ($starts as $character) {
            $this->assertFalse(PcreAttemptSteps::searchUnderLimit($interpreted, $character.$tail, 0), \sprintf('Oracle: %s starts no attempt on %s.', $pattern, bin2hex($character.$tail)));
        }

        foreach ($unread as $character) {
            $this->assertSame(0, PcreAttemptSteps::searchUnderLimit($interpreted, $character.$tail, 0), \sprintf('Oracle: %s starts an attempt on %s.', $pattern, bin2hex($character.$tail)));
        }

        // A first code unit is one of the characters the row starts at.
        if (null !== $expected) {
            $this->assertSame($starts, array_values(array_filter($expected, static fn (?string $character): bool => null !== $character)), $pattern);
        }

        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $first = LastCodeUnit::first($regex);

        $this->assertSame($expected, null === $first ? null : array_map(
            static fn (?int $character): ?string => null === $character ? null : Utf8::character($character, $regex->isUnicode()),
            $first,
        ), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: array{string, ?string}|null, tail: string, starts: list<string>, unread: list<string>}>
     */
    public static function provideFirstCodeUnits(): iterable
    {
        yield 'a repeated literal' => ['pattern' => '/a+b/', 'expected' => ['a', null], 'tail' => 'b', 'starts' => ['a'], 'unread' => ['A', 'x']];
        yield 'the same literal in each alternative' => ['pattern' => '/a+b|ac/', 'expected' => ['a', null], 'tail' => 'b', 'starts' => ['a'], 'unread' => ['x']];
        yield 'a literal after a start of subject' => ['pattern' => '/^a/', 'expected' => ['a', null], 'tail' => '', 'starts' => ['a'], 'unread' => ['x']];
        yield 'a literal after a word boundary' => ['pattern' => '/\ba/', 'expected' => ['a', null], 'tail' => '', 'starts' => ['a'], 'unread' => ['x']];
        yield 'a literal after a lookbehind' => ['pattern' => '/(?<=x)a/', 'expected' => ['a', null], 'tail' => '', 'starts' => ['a'], 'unread' => ['x']];
        yield 'a literal in a repeated group' => ['pattern' => '/(a)+b/', 'expected' => ['a', null], 'tail' => 'b', 'starts' => ['a'], 'unread' => ['x']];
        yield 'a literal before an accepting verb' => ['pattern' => '/a(*ACCEPT)b/', 'expected' => ['a', null], 'tail' => '', 'starts' => ['a'], 'unread' => ['b']];
        yield 'a caseless letter' => ['pattern' => '/A+b/i', 'expected' => ['A', 'a'], 'tail' => 'b', 'starts' => ['A', 'a'], 'unread' => ['x']];
        yield 'a caseless letter repeated twice' => ['pattern' => '/a{2,}b/i', 'expected' => ['a', 'A'], 'tail' => 'ab', 'starts' => ['a', 'A'], 'unread' => []];
        yield 'a letter after an option setting' => ['pattern' => '/(?i)a+b/', 'expected' => ['a', 'A'], 'tail' => 'b', 'starts' => ['a', 'A'], 'unread' => ['x']];
        yield 'a class of a letter and its other case' => ['pattern' => '/[aA]+b/', 'expected' => ['a', 'A'], 'tail' => 'b', 'starts' => ['a', 'A'], 'unread' => ['x']];
        yield 'a caseless k' => ['pattern' => '/k+b/i', 'expected' => ['k', 'K'], 'tail' => 'b', 'starts' => ['k', 'K'], 'unread' => ['x']];
        yield 'a caseless sign' => ['pattern' => '/=+b/i', 'expected' => ['=', null], 'tail' => 'b', 'starts' => ['='], 'unread' => ['x']];
        yield 'a two-byte character under u' => ['pattern' => '/é+b/u', 'expected' => ['é', null], 'tail' => 'b', 'starts' => ['é'], 'unread' => ['x']];
        yield 'a byte past ASCII under (*UCP)' => ['pattern' => '/(*UCP)\xe9+!/i', 'expected' => ["\xe9", "\xc9"], 'tail' => '!', 'starts' => ["\xe9", "\xc9"], 'unread' => ['x']];
        // None the compiler sets. The start-up study may still find the
        // code units a match starts with, in either case, and a lookahead
        // may assert one.
        yield 'alternatives that start apart' => ['pattern' => '/a+b|cb/', 'expected' => null, 'tail' => 'b', 'starts' => ['a', 'c'], 'unread' => ['x']];
        yield 'alternatives of a letter in each case' => ['pattern' => '/a|A/i', 'expected' => null, 'tail' => '', 'starts' => ['a', 'A'], 'unread' => ['x']];
        yield 'a literal after a repeat of none' => ['pattern' => '/a{0}b/', 'expected' => null, 'tail' => '', 'starts' => ['b'], 'unread' => ['a']];
        yield 'an optional first literal' => ['pattern' => '/a?b/', 'expected' => null, 'tail' => 'b', 'starts' => ['a', 'b'], 'unread' => []];
        yield 'a class' => ['pattern' => '/\d+b/', 'expected' => null, 'tail' => 'b', 'starts' => ['0', '9'], 'unread' => ['x']];
        yield 'a start of line under m' => ['pattern' => '/^a/m', 'expected' => null, 'tail' => 'a', 'starts' => ['a', 'x'], 'unread' => []];
        yield 'a lookahead' => ['pattern' => '/(?=a)\w/', 'expected' => null, 'tail' => '', 'starts' => ['a'], 'unread' => ['x']];
        yield 'an accepting verb first' => ['pattern' => '/(*ACCEPT)a/', 'expected' => null, 'tail' => '', 'starts' => ['a', 'x'], 'unread' => []];
        yield 'a subroutine call first' => ['pattern' => '/(?1)(a)/', 'expected' => null, 'tail' => 'a', 'starts' => ['a', 'x'], 'unread' => []];
        yield 'a caseless two-byte character under u' => ['pattern' => '/é+b/iu', 'expected' => null, 'tail' => 'b', 'starts' => ['é', 'É'], 'unread' => ['x']];
        yield 'a caseless k under u' => ['pattern' => '/k+b/iu', 'expected' => null, 'tail' => 'b', 'starts' => ['k', 'K', "\u{212A}"], 'unread' => ['x']];
    }

    /**
     * Under the locale the interpreter starts an attempt on the subject
     * that begins with the code unit of the row, and with the other case
     * the tables give it only where the row gives that case; the JIT, in a
     * process of its own, takes either.
     *
     * @param array{int, ?int}|null $expected
     */
    #[Test]
    #[DataProvider('provideFirstBytes')]
    public function test_the_first_byte_is_looked_for_as_the_interpreter_reads_it_under_the_locale(string $locale, string $pattern, string $subject, int $found, ?array $expected): void
    {
        $this->requiresLocale($locale, $pattern);

        $read = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($pattern, $subject, $read): void {
            $read[] = preg_match(PcreInterpreter::pattern($pattern), $subject);
            $read[] = preg_match(PcreInterpreter::pattern(self::withoutStartOptimizations($pattern)), $subject);
        });
        $read[] = JitInAFreshProcess::matched($pattern, $subject, 0, $locale);
        $this->assertSame([$found, 1, 1], $read->getArrayCopy(), \sprintf('%s on %s under %s', $pattern, bin2hex($subject), $locale));

        $this->assertFirst($expected, $pattern, $locale);
    }

    /**
     * The same rows on every machine, under the cases of each locale given
     * under a name no machine sets.
     *
     * @param array{int, ?int}|null $expected
     */
    #[Test]
    #[DataProvider('provideFirstBytes')]
    public function test_the_first_byte_is_looked_for_as_the_interpreter_reads_it_under_tables_that_pair_it(string $locale, string $pattern, string $subject, int $found, ?array $expected): void
    {
        self::giveTables();

        $this->assertFirst($expected, $pattern, self::GIVEN[$locale]);
    }

    /**
     * @return iterable<string, array{locale: string, pattern: string, subject: string, found: int, expected: array{int, ?int}|null}>
     */
    public static function provideFirstBytes(): iterable
    {
        $koi8r = self::KOI8_R;

        yield 'the last byte, caseless' => ['locale' => $koi8r, 'pattern' => '/\xff+!/i', 'subject' => "\xdf!", 'found' => 0, 'expected' => [0xFF, null]];
        yield 'the last byte, caseless, Latin-9' => ['locale' => self::LATIN9, 'pattern' => '/\xff+!/i', 'subject' => "\xbe!", 'found' => 0, 'expected' => [0xFF, null]];
        yield 'the byte below, caseless' => ['locale' => $koi8r, 'pattern' => '/\xfe+!/i', 'subject' => "\xde!", 'found' => 1, 'expected' => [0xFE, 0xDE]];
        yield 'the other case of the last byte, caseless' => ['locale' => $koi8r, 'pattern' => '/\xdf+!/i', 'subject' => "\xff!", 'found' => 1, 'expected' => [0xDF, 0xFF]];
        // PCRE2 reads a class of a byte and its other case as the byte
        // first written, caseless.
        yield 'a class of the last byte and its other case' => ['locale' => $koi8r, 'pattern' => '/[\xff\xdf]+!/', 'subject' => "\xdf!", 'found' => 0, 'expected' => [0xFF, null]];
        yield 'a class of the other case and the last byte' => ['locale' => $koi8r, 'pattern' => '/[\xdf\xff]+!/', 'subject' => "\xff!", 'found' => 1, 'expected' => [0xDF, 0xFF]];
        yield 'the last byte in a group under i' => ['locale' => $koi8r, 'pattern' => '/(?i:\xff)+!/', 'subject' => "\xdf!", 'found' => 0, 'expected' => [0xFF, null]];
        yield 'the last byte first in each alternative' => ['locale' => $koi8r, 'pattern' => '/\xff+!|\xffx/i', 'subject' => "\xdf!", 'found' => 0, 'expected' => [0xFF, null]];
        // The alternatives start apart: the compiler sets none, and the
        // start-up study finds both bytes.
        yield 'the last byte and its other case in two alternatives' => ['locale' => $koi8r, 'pattern' => '/\xff+!|\xdfx/i', 'subject' => "\xdf!", 'found' => 1, 'expected' => null];
        // Under "(*UCP)" 0xFF is paired with U+0178, never with the byte of
        // the tables: "\xdf!" holds no match at all.
        yield 'the last byte under (*UCP)' => ['locale' => $koi8r, 'pattern' => '/(*UCP)\xff+!/i', 'subject' => "\xff!", 'found' => 1, 'expected' => [0xFF, 0x178]];
    }

    /**
     * PCRE2 10.34 flags a code unit caseless below 255 only, as later
     * releases do, and reads the case of a byte from the tables under
     * "(*UCP)" too: 0xFF is looked for alone there, 0xFE in either case.
     *
     * @param array{int, ?int} $expected
     */
    #[Test]
    #[DataProvider('provideFirstBytesOfPcre2With1034')]
    public function test_the_first_byte_is_looked_for_alone_under_ucp_before_pcre2_10_35(string $pattern, array $expected): void
    {
        self::giveTables();
        $regex = RegexParser::create(['cache' => null])->parse($pattern);

        $this->assertSame($expected, LastCodeUnit::first($regex, new PcreTarget(70400, '10.34', self::GIVEN[self::KOI8_R])), $pattern.' on PCRE2 10.34');
    }

    /**
     * @return iterable<string, array{pattern: string, expected: array{int, ?int}}>
     */
    public static function provideFirstBytesOfPcre2With1034(): iterable
    {
        yield 'the last byte' => ['pattern' => '/(*UCP)\xff+!/i', 'expected' => [0xFF, null]];
        yield 'the byte below' => ['pattern' => '/(*UCP)\xfe+!/i', 'expected' => [0xFE, 0xDE]];
    }

    /**
     * The run of a search-cost witness holds a character the interpreter
     * starts an attempt at: 0xFF itself where PCRE2 looks for it alone,
     * though the repeat reads its other case too. On the run of that other
     * case the interpreter starts no attempt, and the cost is none; on the
     * run of the witness an attempt started further from the end takes
     * more steps, and confirmed mode replays it.
     *
     * @param string|null $unread the run of the other case, on which the interpreter starts no attempt;
     *                            null when it starts one there too
     */
    #[Test]
    #[DataProvider('provideSearchesStartingWithTheLastByte')]
    public function test_the_search_cost_run_holds_a_character_the_interpreter_starts_at(string $pattern, bool $everyMatch, string $run, ?string $unread): void
    {
        $this->requiresLocale(self::KOI8_R, $pattern);

        $analysis = Regex::create(['cache' => null, 'locale' => self::KOI8_R])->redos($pattern, new RedosOptions(RedosMode::Confirmed, RedosSeverity::Low));
        $cost = $everyMatch ? $analysis->everyMatchSearchCost : $analysis->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $analysis->headline());

        $far = $near = 0;
        /** @var list<int|false> $started */
        $started = [];
        $breaker = $cost->breaker;
        CharacterTables::under(self::KOI8_R, static function () use ($pattern, $run, $unread, $breaker, &$far, &$near, &$started): void {
            $pinned = PcreAttemptSteps::pinned($pattern);
            $subject = str_repeat($run, 1000).$breaker;
            $far = PcreAttemptSteps::steps($pinned, $subject, 250 * \strlen($run));
            $near = PcreAttemptSteps::steps($pinned, $subject, 500 * \strlen($run));
            $started[] = PcreAttemptSteps::searchUnderLimit(PcreInterpreter::pattern($pattern), $subject, 0);
            if (null !== $unread) {
                $started[] = PcreAttemptSteps::searchUnderLimit(PcreInterpreter::pattern($pattern), str_repeat($unread, 1000).$breaker, 0);
            }
        });
        $this->assertGreaterThanOrEqual(250, $far - $near, \sprintf('Oracle: the attempts of %s on a run of %s take %d then %d steps.', $pattern, bin2hex($run), $far, $near));
        $this->assertSame(null === $unread ? [false] : [false, 0], $started, \sprintf('Oracle: the attempts %s starts on a run of %s, then of its other case.', $pattern, bin2hex($run)));

        $this->assertSame($run, $cost->run, $pattern);
        $this->assertTrue($cost->replayed, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, everyMatch: bool, run: string, unread: string|null}>
     */
    public static function provideSearchesStartingWithTheLastByte(): iterable
    {
        yield 'the last byte repeated, caseless' => ['pattern' => '/\xff+!/i', 'everyMatch' => false, 'run' => "\xff", 'unread' => "\xdf"];
        yield 'a class of the last byte and its other case' => ['pattern' => '/[\xff\xdf]+!/', 'everyMatch' => false, 'run' => "\xff", 'unread' => "\xdf"];
        // The other case is a character of its own for the model, which the
        // optional item reads alone: no run of it is one the search tries.
        yield 'the last byte before its other case alone' => ['pattern' => '/\xff+(?-i:\xdf)?!/i', 'everyMatch' => false, 'run' => "\xff", 'unread' => "\xdf"];
        yield 'short matches that start with the last byte' => ['pattern' => '/\xff(?:\xff+\d)?/i', 'everyMatch' => true, 'run' => "\xff", 'unread' => "\xdf"];
        // PCRE2 looks for 0xDF in either case, and for no first code unit
        // under "(*NO_START_OPT)": the run stays the one the model gives.
        yield 'the other case of the last byte repeated' => ['pattern' => '/\xdf+!/i', 'everyMatch' => false, 'run' => "\xdf", 'unread' => null];
        yield 'the last byte without start-up optimizations' => ['pattern' => '/(*NO_START_OPT)\xff+!/i', 'everyMatch' => false, 'run' => "\xdf", 'unread' => null];
    }

    /**
     * Where the interpreter looks for the first code unit in either case,
     * or for none, the run keeps the character the model gives its class;
     * a run word no attempt starts at is passed over for the next one. On
     * the run of the witness the search starts an attempt, and one started
     * further from the end takes more steps.
     */
    #[Test]
    #[DataProvider('provideSearchesStartingWithAFirstCodeUnit')]
    public function test_the_search_cost_run_starts_with_the_first_code_unit(string $pattern, bool $everyMatch, string $run): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $cost = $everyMatch ? $analysis->everyMatchSearchCost : $analysis->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $analysis->headline());

        $subject = $cost->build(1000);
        $pinned = PcreAttemptSteps::pinned($pattern);
        $far = PcreAttemptSteps::steps($pinned, $subject, 250 * \strlen($run));
        $near = PcreAttemptSteps::steps($pinned, $subject, 500 * \strlen($run));
        $this->assertGreaterThanOrEqual(250, $far - $near, \sprintf('Oracle: the attempts of %s on a run of %s take %d then %d steps.', $pattern, $run, $far, $near));
        $this->assertFalse(PcreAttemptSteps::searchUnderLimit(PcreInterpreter::pattern($pattern), $subject, 0), 'Oracle: '.$pattern.' starts no attempt on its witness.');

        $this->assertSame($run, $cost->run, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, everyMatch: bool, run: string}>
     */
    public static function provideSearchesStartingWithAFirstCodeUnit(): iterable
    {
        yield 'a letter looked for in either case' => ['pattern' => '/a+!/i', 'everyMatch' => false, 'run' => 'A'];
        yield 'a two-byte character under u' => ['pattern' => '/é+!/u', 'everyMatch' => false, 'run' => 'é'];
        yield 'no first code unit' => ['pattern' => '/[ab]+!/', 'everyMatch' => false, 'run' => 'a'];
        // The loop of "a" and "b" gives a run word first, at which no
        // attempt starts: every match starts with "x".
        yield 'a run word no attempt starts at, then one' => ['pattern' => '/x(?:[ab]+c|x+y)/', 'everyMatch' => false, 'run' => 'x'];
        yield 'short matches after a run word no call starts at' => ['pattern' => '/x(?:[ab]+c|x+\d)?/', 'everyMatch' => true, 'run' => 'x'];
    }

    /**
     * @param array{int, ?int}|null $expected
     */
    private function assertFirst(?array $expected, string $pattern, string $locale): void
    {
        $regex = RegexParser::create(['cache' => null])->parse($pattern);

        $this->assertSame($expected, LastCodeUnit::first($regex, new PcreTarget(\PHP_VERSION_ID, \PCRE_VERSION, $locale)), $pattern.' under '.$locale);
    }

    /**
     * The pattern with "(*NO_START_OPT)" after its opening delimiter.
     */
    private static function withoutStartOptimizations(string $pattern): string
    {
        return $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);
    }

    /**
     * Gives the cases of ru_RU.KOI8-R, 0xC0 to 0xDF paired with 0xE0 to
     * 0xFF and 0xA3 with 0xB3, and those of fr_FR.ISO8859-15, 0xC0 to 0xDE
     * paired with 0xE0 to 0xFE but for 0xD7, and 0xFF with 0xBE, as macOS
     * builds them, under names no machine sets.
     */
    private static function giveTables(): void
    {
        $koi8r = [0xA3 => 0xB3];
        for ($byte = 0xC0; $byte <= 0xDF; $byte++) {
            $koi8r[$byte] = $byte + 0x20;
        }

        $latin9 = [0xA6 => 0xA8, 0xB4 => 0xB8, 0xBC => 0xBD, 0xBE => 0xFF];
        for ($byte = 0xC0; $byte <= 0xDE; $byte++) {
            if (0xD7 !== $byte) {
                $latin9[$byte] = $byte + 0x20;
            }
        }

        foreach ([self::KOI8_R => $koi8r, self::LATIN9 => $latin9] as $locale => $pairs) {
            $others = [];
            foreach ($pairs as $byte => $other) {
                $others[$byte] = $other;
                $others[$other] = $byte;
            }

            $cases = '';
            for ($byte = 0; $byte < 256; $byte++) {
                $ascii = ($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A) ? $byte ^ 0x20 : $byte;
                $cases .= \chr($others[$byte] ?? $ascii);
            }

            CharacterTables::useTables(self::GIVEN[$locale], $cases);
        }
    }
}
