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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A possessive quantifier writes what its greedy spelling writes when it
 * never has to give anything back: the match solver reads it as greedy
 * then. Three things the engine does on its own break that reading, and
 * the solver refuses a pattern where one of them may change the matches.
 *
 * PCRE2 makes a character repeated before a group repeated possessively
 * from zero, or an atomic group, possessive as if the group could not be
 * skipped: "/a+(?:b)?+a/" finds nothing in "aa", where "/a+(?:b)?a/" finds
 * "aa", with and without the JIT, from PCRE2 10.34 to 10.49, and alike
 * under "(*NO_AUTO_POSSESS)". The JIT of PCRE2 10.39 to 10.42 leaves the
 * group of a possessive repeat set from an earlier start of the match:
 * "/a(a)*+b/" writes "a" to group 1 on "aacab", where "/a(a)*b/" leaves it
 * unset. Without "u", PHP hands PCRE2 the character tables of the locale a
 * program sets: under en_IE.ISO8859-1 "\w" takes "\xE9", and
 * "/c\xE9++b?\w/" finds nothing in "c\xE9\xE9", where "/c\xE9+b?\w/" finds
 * it whole.
 *
 * Checked on PHP 8.4.28 with PCRE2 10.49, PHP 8.4.28 with PCRE2 10.44,
 * PHP 8.3.37 with PCRE2 10.42, PHP 8.2.36 with PCRE2 10.40, PHP 8.1.34 with
 * PCRE2 10.39 and PHP 7.4.10 with PCRE2 10.34, the JIT on and off.
 */
final class PossessiveProofLimitsTest extends TestCase
{
    use InstalledLocales;

    use PhpUnitForwardCompat;

    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    private const SKIPPED_GROUP = 'as if the group could not be skipped';

    private const POSSESSIVE_CAPTURE = 'Before PCRE2 10.44';

    private const LOCALE_TABLES = 'the character tables of the locale';

    private const LATIN1 = 'en_IE.ISO8859-1';

    /**
     * The engine tells the pair apart on the witness, the JIT on and off,
     * where a row names one.
     */
    #[Test]
    #[DataProvider('provideRepeatsBeforeSkippableGroups')]
    public function test_a_repeat_before_a_possessive_group_that_may_match_nothing_is_refused(string $left, string $right, ?string $witness): void
    {
        if (null !== $witness) {
            $this->assertNotSame(self::matchesOf($left, $witness), self::matchesOf($right, $witness), \sprintf('%s and %s write the same matches on %s.', $left, $right, json_encode($witness)));
            $this->assertNotSame(self::matchesOf(PcreInterpreter::pattern($left), $witness), self::matchesOf(PcreInterpreter::pattern($right), $witness), \sprintf('%s and %s write the same matches on %s without the JIT.', $left, $right, json_encode($witness)));
        }

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains(self::SKIPPED_GROUP);

        (new LanguageSolver())->matchEquivalent($left, $right);
    }

    /**
     * @return iterable<string, array{left: string, right: string, witness: string|null}>
     */
    public static function provideRepeatsBeforeSkippableGroups(): iterable
    {
        yield 'a plus before an optional group' => ['left' => '/a+(?:b)?+a/', 'right' => '/a+(?:b)?a/', 'witness' => 'aa'];
        yield 'a star before a group up to twice' => ['left' => '/a*(?:b){0,2}+a/', 'right' => '/a*(?:b){0,2}a/', 'witness' => 'aa'];
        yield 'an optional letter before an optional group' => ['left' => '/a?(?:b)?+a/', 'right' => '/a?(?:b)?a/', 'witness' => 'a'];
        yield 'a count between two bounds' => ['left' => '/a{2,3}(?:b)?+a/', 'right' => '/a{2,3}(?:b)?a/', 'witness' => 'aaa'];
        yield 'a class' => ['left' => '/[ab]+(?:c)?+a/', 'right' => '/[ab]+(?:c)?a/', 'witness' => 'aa'];
        yield 'a capturing group' => ['left' => '/a+(b)?+a/', 'right' => '/a+(b)?a/', 'witness' => 'aa'];
        yield 'a group of two letters' => ['left' => '/a+(?:bc)?+a/', 'right' => '/a+(?:bc)?a/', 'witness' => 'aa'];
        yield 'a group under its own options' => ['left' => '/a+(?i:b)?+a/', 'right' => '/a+(?i:b)?a/', 'witness' => 'aa'];
        yield 'an optional letter between' => ['left' => '/a+c?(?:b)?+a/', 'right' => '/a+c?(?:b)?a/', 'witness' => 'aa'];
        yield 'the repeat inside a group' => ['left' => '/(a+)(?:b)?+a/', 'right' => '/(a+)(?:b)?a/', 'witness' => 'aa'];
        yield 'the optional group inside a group' => ['left' => '/a+(?:(?:b)?+)a/', 'right' => '/a+(?:(?:b)?)a/', 'witness' => 'aa'];
        yield 'the possessive on the right' => ['left' => '/b+(?:a){0,2}b/', 'right' => '/b+(?:a){0,2}+b/', 'witness' => 'bb'];
        // PCRE2 reads the group repeated without a bound apart, and matches
        // alike: the whole class of groups the engine may skip is refused.
        yield 'a star over a group' => ['left' => '/a+(?:b)*+a/', 'right' => '/a+(?:b)*a/', 'witness' => null];
        // A repeat of a line break, which matches more than one way, is not
        // read as one that gives nothing back.
        yield 'a line break repeated' => ['left' => '/\R+(?:b)?+x/', 'right' => '/\R+(?:b)?x/', 'witness' => null];
    }

    /**
     * A repeat written as a group, "(?:a)+", is not made possessive, a
     * letter repeated possessively from zero is skipped as it should be,
     * and a repeat made possessive that gives nothing back to what follows
     * runs as written: the engine writes the same matches for each pair on
     * every subject.
     */
    #[Test]
    #[DataProvider('provideRepeatsTheEngineReadsAsWritten')]
    public function test_a_possessive_the_engine_reads_as_written_is_still_proven(string $left, string $right, string $letters): void
    {
        foreach (self::subjects($letters, 5) as $subject) {
            $this->assertSame(self::matchesOf($left, $subject), self::matchesOf($right, $subject), \sprintf('%s and %s differ on %s.', $left, $right, json_encode($subject)));
        }

        $this->assertTrue((new LanguageSolver())->matchEquivalent($left, $right)->isEquivalent);
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string}>
     */
    public static function provideRepeatsTheEngineReadsAsWritten(): iterable
    {
        yield 'a repeat written as a group' => ['left' => '/(?:a)+(?:b)?+a/', 'right' => '/(?:a)+(?:b)?a/', 'letters' => 'ab'];
        yield 'an optional letter' => ['left' => '/a+b?+a/', 'right' => '/a+b?a/', 'letters' => 'ab'];
        yield 'a group repeated at least once' => ['left' => '/a+(?:b)++a/', 'right' => '/a+(?:b)+a/', 'letters' => 'ab'];
        yield 'nothing repeated before' => ['left' => '/a(?:b)?+a/', 'right' => '/a(?:b)?a/', 'letters' => 'ab'];
        // Made possessive, the repeat gives nothing back to what follows.
        yield 'a repeat nothing after takes from' => ['left' => '/a+(?:b)?+c/', 'right' => '/a+(?:b)?c/', 'letters' => 'abc'];
        yield 'an optional group at the end' => ['left' => '/[^a]{1,2}(ab)?+/', 'right' => '/[^a]{1,2}(ab)?/', 'letters' => 'abc'];
    }

    /**
     * The JIT of 10.39 to 10.42 keeps what a possessive repeat of a group
     * captured at an earlier start; 10.44 and the interpreter clear it.
     * 10.43, which no PHP release bundles, is not checked.
     */
    #[Test]
    #[DataProvider('providePossessiveCapturingRepeats')]
    public function test_a_possessive_repeat_of_a_capturing_group_is_refused_before_pcre2_10_44(string $left, string $right, string $witness, bool $jitKeepsTheGroup): void
    {
        $running = PcreTarget::runtime();
        $jit = filter_var(\ini_get('pcre.jit'), \FILTER_VALIDATE_BOOL);
        $this->assertSame(self::matchesOf(PcreInterpreter::pattern($left), $witness), self::matchesOf(PcreInterpreter::pattern($right), $witness), 'The interpreter writes the same matches.');
        if (!$jit || !$jitKeepsTheGroup || $running->pcreAtLeast('10.44')) {
            $this->assertSame(self::matchesOf($left, $witness), self::matchesOf($right, $witness), 'The engine writes the same matches.');
        } elseif (!$running->pcreAtLeast('10.43')) {
            $this->assertNotSame(self::matchesOf($left, $witness), self::matchesOf($right, $witness), 'The JIT keeps the group.');
        }

        $this->assertTrue((new LanguageSolver(RegexParser::create(['cache' => null, 'pcre_version' => '10.44'])))->matchEquivalent($left, $right)->isEquivalent);

        foreach (['10.39', '10.40', '10.42', '10.43'] as $release) {
            try {
                (new LanguageSolver(RegexParser::create(['cache' => null, 'pcre_version' => $release])))->matchEquivalent($left, $right);
                $this->fail(\sprintf('%s and %s were proven alike for PCRE2 %s.', $left, $right, $release));
            } catch (ComplexityException $refusal) {
                $this->assertStringContainsString(self::POSSESSIVE_CAPTURE, $refusal->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{left: string, right: string, witness: string, jitKeepsTheGroup: bool}>
     */
    public static function providePossessiveCapturingRepeats(): iterable
    {
        yield 'a letter captured' => ['left' => '/a(a)*+b/', 'right' => '/a(a)*b/', 'witness' => 'aacab', 'jitKeepsTheGroup' => true];
        yield 'two letters captured' => ['left' => '/(ab)*+b/', 'right' => '/(ab)*b/', 'witness' => 'ab', 'jitKeepsTheGroup' => true];
        // The JIT clears a group nested in a group without one of its own,
        // and one a repeat must run once: the whole class is refused.
        yield 'a group inside a group' => ['left' => '/(?:(a))*+b/', 'right' => '/(?:(a))*b/', 'witness' => 'acb', 'jitKeepsTheGroup' => false];
        yield 'a group repeated at least once' => ['left' => '/(a)++b/', 'right' => '/(a)+b/', 'witness' => 'acab', 'jitKeepsTheGroup' => false];
        yield 'a group after a letter in the body' => ['left' => '/(?:x(a))*+b/', 'right' => '/(?:x(a))*b/', 'witness' => 'xaxcb', 'jitKeepsTheGroup' => false];
    }

    /**
     * A possessive repeat that captures nothing is read for every target.
     */
    #[Test]
    public function test_a_possessive_repeat_that_captures_nothing_is_proven_before_pcre2_10_44(): void
    {
        foreach (self::subjects('abc', 5) as $subject) {
            $this->assertSame(self::matchesOf('/(?:ab)++c/', $subject), self::matchesOf('/(?:ab)+c/', $subject), \sprintf('The engine tells them apart on %s.', json_encode($subject)));
        }

        $this->assertTrue((new LanguageSolver(RegexParser::create(['cache' => null, 'pcre_version' => '10.42'])))->matchEquivalent('/(?:ab)++c/', '/(?:ab)+c/')->isEquivalent);
    }

    /**
     * Under C's tables the pair writes the same matches on every subject
     * over its letters; under a Latin-1 locale the witness tells it apart.
     * The target names no other locale, so the solver cannot know the
     * tables are C's, and refuses; one that names the Latin-1 locale reads
     * its tables, where the possessive gives back.
     */
    #[Test]
    #[DataProvider('provideLocaleBoundPossessives')]
    public function test_a_possessive_proven_past_ascii_against_a_class_of_the_locale_is_refused(string $left, string $right, string $letters, string $witness): void
    {
        $this->requiresLocale(self::LATIN1, $left);

        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($left, $right, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($left, $subject) === self::matchesOf($right, $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        $apart = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($left, $right, $witness, $apart): void {
            $apart[] = self::matchesOf($left, $witness) !== self::matchesOf($right, $witness);
        });
        $this->assertSame([true], $apart->getArrayCopy(), 'The Latin-1 tables tell the pair apart.');

        try {
            (new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => CharacterTables::BUILT_IN])))->matchEquivalent($left, $right);
            $this->fail(\sprintf('%s and %s were proven alike under tables the solver cannot know.', $left, $right));
        } catch (ComplexityException $refusal) {
            $this->assertStringContainsString(self::LOCALE_TABLES, $refusal->getMessage());
        }

        $this->expectException(ComplexityException::class);
        (new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::LATIN1])))->matchEquivalent($left, $right);
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string, witness: string}>
     */
    public static function provideLocaleBoundPossessives(): iterable
    {
        yield 'a word character after a byte past ASCII' => ['left' => '/c\xE9++b?\w/', 'right' => '/c\xE9+b?\w/', 'letters' => "cb\xE9", 'witness' => "c\xE9\xE9"];
        yield 'a byte past ASCII after a word character' => ['left' => '/\w++\xE9/', 'right' => '/\w+\xE9/', 'letters' => "a\xE9", 'witness' => "a\xE9\xE9"];
        yield 'two bytes past ASCII, caseless' => ['left' => '/\xC9++\xE9/i', 'right' => '/\xC9+\xE9/i', 'letters' => "\xC9\xE9", 'witness' => "\xC9\xC9"];
        yield 'two bytes past ASCII under an inline caseless option' => ['left' => '/(?i)\xC9++\xE9/', 'right' => '/(?i)\xC9+\xE9/', 'letters' => "\xC9\xE9", 'witness' => "\xC9\xC9"];
        yield 'a class that holds a word character' => ['left' => '/[\w-]++\xE9/', 'right' => '/[\w-]+\xE9/', 'letters' => "a-\xE9", 'witness' => "a\xE9\xE9"];
    }

    /**
     * Where no class meets a byte past ASCII, or "u" reads the Unicode
     * properties, the tables decide nothing the proof reads: the pair is
     * proven under every locale.
     */
    #[Test]
    #[DataProvider('provideLocaleFreePossessives')]
    public function test_a_possessive_no_table_decides_is_still_proven(string $left, string $right, string $letters): void
    {
        $this->requiresLocale(self::LATIN1, $left);

        foreach ([CharacterTables::BUILT_IN, self::LATIN1] as $locale) {
            $alike = new \ArrayObject();
            CharacterTables::under($locale, static function () use ($left, $right, $letters, $alike): void {
                foreach (self::subjects($letters, 4) as $subject) {
                    $alike[] = self::matchesOf($left, $subject) === self::matchesOf($right, $subject);
                }
            });
            $this->assertNotContains(false, $alike->getArrayCopy(), \sprintf('The tables of %s write the same matches.', $locale));
        }

        $solver = new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => CharacterTables::BUILT_IN]));
        $this->assertTrue($solver->matchEquivalent($left, $right)->isEquivalent);

        // Read whole, the strings both match are the same under every
        // locale too.
        $whole = static fn (string $pattern): string => $pattern[0].'^(?:'.substr($pattern, 1, (int) strrpos($pattern, $pattern[0]) - 1).')$'.substr($pattern, (int) strrpos($pattern, $pattern[0]));
        $this->assertTrue($solver->equivalent($whole($left), $whole($right))->isEquivalent);
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string}>
     */
    public static function provideLocaleFreePossessives(): iterable
    {
        yield 'a letter after a byte past ASCII' => ['left' => '/c\xE9++b?x/', 'right' => '/c\xE9+b?x/', 'letters' => "cbx\xE9"];
        yield 'two classes within ASCII' => ['left' => '/\s++\w/', 'right' => '/\s+\w/', 'letters' => " a\xE9"];
        yield 'a code point past ASCII under u' => ['left' => '/c\x{D7}++b?\w/u', 'right' => '/c\x{D7}+b?\w/u', 'letters' => "cb\u{D7}"];
        // The host a route compiles: the letters the option folds stand
        // apart from what the possessive and its follower decide.
        yield 'a host label before a dot, caseless' => ['left' => '/(?P<sub>[^\.]++)\.a\.com/i', 'right' => '/(?P<sub>[^\.]+)\.a\.com/i', 'letters' => "a.c\xE9"];
        yield 'a class of everything but a dot before it, caseless' => ['left' => '/[^.]++\.x/i', 'right' => '/[^.]+\.x/i', 'letters' => ".xX\xE9"];
    }

    /**
     * @return array<int|string, mixed>|false
     */
    private static function matchesOf(string $pattern, string $subject): array|false
    {
        $matches = [];

        return false === preg_match($pattern, $subject, $matches, self::FLAGS) ? false : $matches;
    }

    /**
     * Every string over the letters, up to the length.
     *
     * @return list<string>
     */
    private static function subjects(string $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (mb_str_split($letters, 1, mb_check_encoding($letters, 'UTF-8') ? 'UTF-8' : '8bit') as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $all = [...$all, ...$next];
            $layer = $next;
        }

        return $all;
    }
}
