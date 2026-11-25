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
use PHPRegex\Automata\Transform\AutoPossession;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without "u", PCRE2 makes a repeat of the byte 0xFF possessive before
 * "\w", and a repeat of "\w" possessive before that byte, lazy ones too, as
 * if 0xFF were no word character, whatever the character tables say. Under
 * C's tables it is none, and the repeat gives nothing back "\w" could take;
 * under fr_FR.ISO8859-1 it is one, and "/\xFF+\w/" finds nothing in
 * "\xFF\xFF", where "/\xFF+[\w]/", "/(?:\xFF)+\w/" and
 * "/(*NO_AUTO_POSSESS)\xFF+\w/" find it, and "/\w+\xFF/" nothing in
 * "a\xFF\xFF", where "/[\w]+\xFF/" finds it. A class of "\w", a repeat
 * written as a group and "u", under which "\w" reads the Unicode
 * properties, are read as written; so is the byte 0xFE.
 *
 * Checked on PHP 8.4.28 with PCRE2 10.49, the JIT on and off, under C,
 * fr_FR.ISO8859-1, fr_FR.ISO8859-15, tr_TR.ISO8859-9, ru_RU.KOI8-R and
 * el_GR.ISO8859-7.
 */
final class LastBytePossessionTest extends TestCase
{
    use InstalledLocales;
    use PcreGates;

    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    private const LAST_BYTE = 'as if 0xFF were no word character';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * A locale whose "\w" takes 0xFE but not 0xFF.
     */
    private const GREEK = 'el_GR.ISO8859-7';

    /**
     * The tables of fr_FR.ISO8859-1, 0xFF a word character, under a name no
     * machine sets.
     */
    private const GIVEN_LATIN1 = 'xx_XX.LASTBYTEWORD';

    /**
     * The word characters of el_GR.ISO8859-7, 0xFE among them but not 0xFF,
     * under a name no machine sets.
     */
    private const GIVEN_GREEK = 'xx_XX.LASTBYTEGREEK';

    /**
     * Extended classes compile from PCRE2 10.45.
     */
    private const NEWER_RELEASES = ['10.45' => ['/(?[\xFF])+\w/', '/\xFF+(?[\w])/']];

    /**
     * Under Latin-1 the witness tells each pair apart, the JIT on and off;
     * a target that names that locale is read under its tables, where the
     * engine makes the repeat possessive, and both solvers refuse the pair.
     */
    #[Test]
    #[DataProvider('provideRepeatsMadePossessive')]
    public function test_a_repeat_made_possessive_beside_the_last_byte_is_refused_under_latin1(string $left, string $right, string $letters, string $witness): void
    {
        $this->requiresPcre2For($left, self::NEWER_RELEASES);
        $this->requiresLocale(self::LATIN1, $left);

        $apart = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($left, $right, $witness, $apart): void {
            $apart[] = self::matchesOf($left, $witness) !== self::matchesOf($right, $witness);
            $apart[] = self::matchesOf(PcreInterpreter::pattern($left), $witness) !== self::matchesOf(PcreInterpreter::pattern($right), $witness);
        });
        $this->assertSame([true, true], $apart->getArrayCopy(), \sprintf('%s and %s write the same matches on %s under Latin-1.', $left, $right, json_encode(bin2hex($witness))));

        $this->assertRefused(new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::LATIN1])), $left, $right);
    }

    /**
     * Under C's tables each pair writes the same matches on every subject
     * over its letters, yet a target that names no locale but C may run
     * under any, and one whose tables hold 0xFF in "\w" is read under them:
     * both solvers refuse the pair on every machine, and the pair never
     * reads alike. The Latin-1 witness is checked above, where this machine
     * can set that locale.
     */
    #[Test]
    #[DataProvider('provideRepeatsMadePossessive')]
    public function test_a_repeat_made_possessive_beside_the_last_byte_is_refused_where_the_tables_may_hold_it_in_a_word(string $left, string $right, string $letters, string $witness): void
    {
        $this->requiresPcre2For($left, self::NEWER_RELEASES);
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($left, $right, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($left, $subject) === self::matchesOf($right, $subject);
                $alike[] = self::matchesOf(PcreInterpreter::pattern($left), $subject) === self::matchesOf(PcreInterpreter::pattern($right), $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        foreach ([[], ['locale' => CharacterTables::BUILT_IN]] as $target) {
            $solver = new LanguageSolver(RegexParser::create(['cache' => null] + $target));
            $this->assertRefused($solver, $left, $right);

            foreach ([[$left, $right], [$right, $left]] as [$one, $other]) {
                $this->assertFalse($solver->readAlike($one, $other), \sprintf('%s and %s read alike.', $one, $other));
                $this->assertNotSame([], $solver->runsOfEveryCall($one, $other), \sprintf('%s and %s need no run.', $one, $other));
                $this->assertFalse($solver->rewritesAlikeInPlace($one, $other), \sprintf('%s and %s were proven alike in place.', $one, $other));
            }
        }

        // The tables of Latin-1, given under a name no machine sets: the
        // refusal comes before any class is read under them.
        self::giveTables();
        $solver = new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::GIVEN_LATIN1]));
        $this->assertRefused($solver, $left, $right);
        $this->assertFalse($solver->rewritesAlikeInPlace($left, $right));
        $this->assertFalse($solver->rewritesAlikeInPlace($right, $left));
    }

    /**
     * The repeat the engine may make possessive is found in the left
     * pattern of each pair, and none in the right one, where the tables may
     * hold 0xFF in "\w": those of a target of no locale but C, or the
     * given tables of Latin-1. The given word characters of Greek leave
     * 0xFF out: no repeat is found there.
     */
    #[Test]
    #[DataProvider('provideRepeatsMadePossessive')]
    public function test_the_repeat_made_possessive_is_found_where_the_tables_may_hold_the_last_byte_in_a_word(string $left, string $right, string $letters, string $witness): void
    {
        $this->requiresPcre2For($left, self::NEWER_RELEASES);
        self::giveTables();
        $parser = RegexParser::create(['cache' => null]);
        foreach ([CharacterTables::BUILT_IN, self::GIVEN_LATIN1] as $locale) {
            $target = new PcreTarget(\PHP_VERSION_ID, \PCRE_VERSION, $locale);
            $this->assertInstanceOf(QuantifierNode::class, AutoPossession::repeatMadePossessiveBesideLastByte($parser->parse($left), $target), $locale);
            $this->assertNull(AutoPossession::repeatMadePossessiveBesideLastByte($parser->parse($right), $target), $locale);
        }

        $greek = new PcreTarget(\PHP_VERSION_ID, \PCRE_VERSION, self::GIVEN_GREEK);
        $this->assertNull(AutoPossession::repeatMadePossessiveBesideLastByte($parser->parse($left), $greek));
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string, witness: string}>
     */
    public static function provideRepeatsMadePossessive(): iterable
    {
        yield 'the byte repeated before a word character, and before a class of it' => ['left' => '/\xFF+\w/', 'right' => '/\xFF+[\w]/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'the byte repeated, and repeated as a group' => ['left' => '/\xFF+\w/', 'right' => '/(?:\xFF)+\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'a class of the byte repeated' => ['left' => '/[\xFF]+\w/', 'right' => '/(?:[\xFF])+\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'a range of the byte alone repeated' => ['left' => '/[\xFF-\xFF]+\w/', 'right' => '/(?:[\xFF-\xFF])+\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'an extended class of the byte repeated' => ['left' => '/(?[\xFF])+\w/', 'right' => '/(?:(?[\xFF]))+\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'an optional character between' => ['left' => '/\xFF+-*\w/', 'right' => '/(?:\xFF)+-*\w/', 'letters' => "\xFFa-", 'witness' => "\xFF\xFF"];
        yield 'the word character in a group' => ['left' => '/\xFF+(?:\w)/', 'right' => '/\xFF+[\w]/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'the word character in a capturing group' => ['left' => '/\xFF+(\w)/', 'right' => '/(?:\xFF)+(\w)/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'the word character in a branch' => ['left' => '/\xFF+(?:a|\w)/', 'right' => '/(?:\xFF)+(?:a|\w)/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'the repeat in a group' => ['left' => '/(\xFF+)\w/', 'right' => '/((?:\xFF)+)\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'the repeat in an optional group' => ['left' => '/(?:\xFF+)?\w/', 'right' => '/(?:(?:\xFF)+)?\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'a word character after an optional one' => ['left' => '/\xFF+\w?\w/', 'right' => '/(?:\xFF)+\w?\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'a lazy repeat' => ['left' => '/\xFF+?\w/', 'right' => '/(?:\xFF)+?\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'an optional byte' => ['left' => '/\xFF?\w/', 'right' => '/(?:\xFF)?\w/', 'letters' => "\xFFa", 'witness' => "\xFF"];
        yield 'a count between two bounds' => ['left' => '/\xFF{1,3}\w/', 'right' => '/(?:\xFF){1,3}\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'a count from two' => ['left' => '/\xFF{2,}\w/', 'right' => '/\xFF{2,}[\w]/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF\xFF"];
        yield 'a star after the byte' => ['left' => '/\xFF\xFF*\w/', 'right' => '/\xFF(?:\xFF)*\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'caseless' => ['left' => '/\xFF+\w/i', 'right' => '/\xFF+[\w]/i', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'an octal escape' => ['left' => '/\377+\w/', 'right' => '/\377+[\w]/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'the byte written out' => ['left' => "/\xFF+\\w/", 'right' => "/\xFF+[\\w]/", 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'a word character repeated before the byte' => ['left' => '/\w+\xFF/', 'right' => '/[\w]+\xFF/', 'letters' => "a\xFF", 'witness' => "a\xFF\xFF"];
        yield 'a word character repeated lazily before the byte' => ['left' => '/\w+?\xFF/', 'right' => '/(?:\w)+?\xFF/', 'letters' => "a\xFF", 'witness' => "a\xFF\xFF"];
        yield 'a word character repeated before a class of the byte' => ['left' => '/\w+[\xFF]/', 'right' => '/(?:\w)+[\xFF]/', 'letters' => "a\xFF", 'witness' => "a\xFF\xFF"];
        yield 'a word character repeated before the byte repeated' => ['left' => '/\w+\xFF+/', 'right' => '/[\w]+\xFF+/', 'letters' => "a\xFF", 'witness' => "a\xFF\xFF"];
        yield 'a word character repeated before the byte, an optional character between' => ['left' => '/\w+-*\xFF/', 'right' => '/[\w]+-*\xFF/', 'letters' => "a\xFF-", 'witness' => "a\xFF\xFF"];
        yield 'a word character repeated before the byte quoted' => ['left' => "/\\w+\\Q\xFFa\\E/", 'right' => "/[\\w]+\\Q\xFFa\\E/", 'letters' => "a\xFF", 'witness' => "a\xFF\xFFa"];
        yield 'U+00FF under (*UTF) without u' => ['left' => '/(*UTF)\x{FF}+\w/', 'right' => '/(*UTF)\x{FF}+[\w]/', 'letters' => "\u{FF}a", 'witness' => "\u{FF}\u{FF}"];
        yield 'U+00FF written out under (*UTF)' => ['left' => "/(*UTF)\u{FF}+\\w/", 'right' => "/(*UTF)(?:\u{FF})+\\w/", 'letters' => "\u{FF}a", 'witness' => "\u{FF}\u{FF}"];
        yield 'U+0178 caseless under (*UTF)' => ['left' => '/(*UTF)\x{178}+\w/i', 'right' => '/(*UTF)\x{178}+[\w]/i', 'letters' => "\u{FF}a", 'witness' => "\u{FF}\u{FF}"];
        yield 'U+0178 written out, caseless under (*UTF)' => ['left' => "/(*UTF)\\w+\u{178}/i", 'right' => "/(*UTF)[\\w]+\u{178}/i", 'letters' => "a\u{FF}", 'witness' => "a\u{FF}\u{FF}"];
    }

    /**
     * Where the engine reads the repeat as written, the pair is proven
     * under Latin-1, where the subjects over its letters tell nothing
     * apart, the JIT on and off.
     */
    #[Test]
    #[DataProvider('provideRepeatsReadAsWritten')]
    public function test_a_repeat_the_engine_reads_as_written_is_proven_under_latin1(string $left, string $right, string $letters): void
    {
        $this->requiresPcre2For($left, self::NEWER_RELEASES);
        $this->requiresLocale(self::LATIN1, $left);

        $alike = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($left, $right, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($left, $subject) === self::matchesOf($right, $subject);
                $alike[] = self::matchesOf(PcreInterpreter::pattern($left), $subject) === self::matchesOf(PcreInterpreter::pattern($right), $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'Latin-1 writes the same matches.');

        $this->assertProven(new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::LATIN1])), $left, $right);
    }

    /**
     * Those pairs are proven on every machine for a target of no locale,
     * C's tables writing them alike.
     */
    #[Test]
    #[DataProvider('provideRepeatsReadAsWritten')]
    public function test_a_repeat_the_engine_reads_as_written_is_proven_for_a_target_of_no_locale(string $left, string $right, string $letters): void
    {
        $this->requiresPcre2For($left, self::NEWER_RELEASES);
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($left, $right, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($left, $subject) === self::matchesOf($right, $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        $this->assertProven(new LanguageSolver(RegexParser::create(['cache' => null])), $left, $right);
    }

    /**
     * @return iterable<string, array{left: string, right: string, letters: string}>
     */
    public static function provideRepeatsReadAsWritten(): iterable
    {
        yield 'the byte repeated before a class of word characters' => ['left' => '/\xFF+[\w]/', 'right' => '/(?:\xFF)+[\w]/', 'letters' => "\xFFa"];
        yield 'the byte repeated as a group before a word character' => ['left' => '/(?:\xFF)+\w/', 'right' => '/(?:\xFF)+[\w]/', 'letters' => "\xFFa"];
        yield 'a class of word characters repeated before the byte' => ['left' => '/[\w]+\xFF/', 'right' => '/(?:[\w])+\xFF/', 'letters' => "a\xFF"];
        yield 'a word character repeated as a group before the byte' => ['left' => '/(?:\w)+\xFF/', 'right' => '/(?:[\w])+\xFF/', 'letters' => "a\xFF"];
        yield 'the byte repeated before an extended class of word characters' => ['left' => '/\xFF+(?[\w])/', 'right' => '/(?:\xFF)+(?[\w])/', 'letters' => "\xFFa"];
        yield 'the byte below repeated before a word character' => ['left' => '/\xFE+\w/', 'right' => '/\xFE+[\w]/', 'letters' => "\xFEa"];
        yield 'a word character repeated before the byte below' => ['left' => '/\w+\xFE/', 'right' => '/[\w]+\xFE/', 'letters' => "a\xFE"];
        yield 'the byte counted twice before a word character' => ['left' => '/\xFF{2}\w/', 'right' => '/\xFF{2}[\w]/', 'letters' => "\xFFa"];
        yield 'the byte repeated before a character no word holds' => ['left' => '/\xFF+\W/', 'right' => '/\xFF+[\W]/', 'letters' => "\xFF "];
        yield 'the byte repeated before the letter w' => ['left' => '/\xFF+w/', 'right' => '/(?:\xFF)+w/', 'letters' => "\xFFw"];
        yield 'the letter w repeated before the byte' => ['left' => '/w+\xFF/', 'right' => '/(?:w)+\xFF/', 'letters' => "w\xFF"];
        yield 'the byte repeated before a digit' => ['left' => '/\xFF+\d/', 'right' => '/\xFF+[0-9]/', 'letters' => "\xFF1"];
        yield 'a digit repeated before the byte' => ['left' => '/\d+\xFF/', 'right' => '/[0-9]+\xFF/', 'letters' => "1\xFF"];
        yield 'the byte repeated before a word character under u' => ['left' => '/\xFF+\w/u', 'right' => '/\xFF+[\w]/u', 'letters' => "\u{FF}a"];
        yield 'a word character repeated before the byte under u' => ['left' => '/\w+\x{FF}/u', 'right' => '/[\w]+\x{FF}/u', 'letters' => "a\u{FF}"];
    }

    /**
     * Under el_GR.ISO8859-7 "\w" takes 0xFE and leaves 0xFF out: the
     * engine reads each pair alike, the JIT on and off, and a target that
     * names that locale proves it.
     */
    #[Test]
    #[DataProvider('provideRepeatsMadePossessive')]
    public function test_a_repeat_beside_the_last_byte_is_proven_under_tables_that_leave_it_out_of_a_word(string $left, string $right, string $letters, string $witness): void
    {
        $this->requiresPcre2For($left, self::NEWER_RELEASES);
        $this->requiresLocale(self::GREEK, $left);

        $alike = new \ArrayObject();
        CharacterTables::under(self::GREEK, static function () use ($left, $right, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($left, $subject) === self::matchesOf($right, $subject);
                $alike[] = self::matchesOf(PcreInterpreter::pattern($left), $subject) === self::matchesOf(PcreInterpreter::pattern($right), $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'The Greek tables write the same matches.');

        $this->assertProven(new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::GREEK])), $left, $right);
    }

    /**
     * Read alone, each pair runs alike, but spliced into a larger pattern,
     * as a router splices each requirement into a group of its own, PCRE2
     * reads "\w" against what stands around it as if 0xFF were no word
     * character: under Latin-1 the witness tells the two splices apart,
     * the JIT on and off, and a target that names that locale reads the
     * pair apart.
     */
    #[Test]
    #[DataProvider('provideSplicedParts')]
    public function test_a_part_beside_the_last_byte_reads_apart_where_it_is_spliced_under_latin1(string $left, string $right, string $splice, string $witness): void
    {
        $this->requiresLocale(self::LATIN1, $left);

        [$leftSpliced, $rightSpliced] = [\sprintf($splice, substr($left, 1, -1)), \sprintf($splice, substr($right, 1, -1))];
        $apart = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($leftSpliced, $rightSpliced, $witness, $apart): void {
            $apart[] = self::matchesOf($leftSpliced, $witness) !== self::matchesOf($rightSpliced, $witness);
            $apart[] = self::matchesOf(PcreInterpreter::pattern($leftSpliced), $witness) !== self::matchesOf(PcreInterpreter::pattern($rightSpliced), $witness);
        });
        $this->assertSame([true, true], $apart->getArrayCopy(), \sprintf('%s and %s write the same matches on %s under Latin-1.', $leftSpliced, $rightSpliced, json_encode(bin2hex($witness))));

        $this->assertFalse((new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::LATIN1])))->readAlike($left, $right));
    }

    /**
     * Under C's tables the splices write the same matches, yet a target
     * that names no locale but C may run under any: the pair reads apart on
     * every machine, whichever side is read first. Read whole, with nothing
     * around them, the two still run alike in every call. Under "u", where
     * "\w" reads the Unicode properties, the pair reads alike. "\w" is
     * found outside a class where the tables may hold 0xFF in it: those of
     * a target of no locale, or the given tables of Latin-1, and not the
     * given word characters of Greek.
     */
    #[Test]
    #[DataProvider('provideSplicedParts')]
    public function test_a_part_beside_the_last_byte_reads_apart_where_the_tables_may_hold_it_in_a_word(string $left, string $right, string $splice, string $witness): void
    {
        [$leftSpliced, $rightSpliced] = [\sprintf($splice, substr($left, 1, -1)), \sprintf($splice, substr($right, 1, -1))];
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($leftSpliced, $rightSpliced, $witness, $alike): void {
            $alike[] = self::matchesOf($leftSpliced, $witness) === self::matchesOf($rightSpliced, $witness);
            $alike[] = self::matchesOf(PcreInterpreter::pattern($leftSpliced), $witness) === self::matchesOf(PcreInterpreter::pattern($rightSpliced), $witness);
        });
        $this->assertSame([true, true], $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        foreach ([[], ['locale' => CharacterTables::BUILT_IN]] as $target) {
            $solver = new LanguageSolver(RegexParser::create(['cache' => null] + $target));
            $this->assertFalse($solver->readAlike($left, $right), \sprintf('%s and %s read alike.', $left, $right));
            $this->assertFalse($solver->readAlike($right, $left), \sprintf('%s and %s read alike.', $right, $left));
            $this->assertSame([], $solver->runsOfEveryCall($left, $right), \sprintf('%s and %s need a run.', $left, $right));
        }

        $this->assertTrue((new LanguageSolver(RegexParser::create(['cache' => null])))->readAlike($left.'u', $right.'u'));

        self::giveTables();
        $parser = RegexParser::create(['cache' => null]);
        foreach ([CharacterTables::BUILT_IN => true, self::GIVEN_LATIN1 => true, self::GIVEN_GREEK => false] as $locale => $found) {
            $target = new PcreTarget(\PHP_VERSION_ID, \PCRE_VERSION, $locale);
            $this->assertFalse(AutoPossession::meetsLastByteWhereSpliced($parser->parse($left), $target), $locale);
            $this->assertSame($found, AutoPossession::meetsLastByteWhereSpliced($parser->parse($right), $target), $locale);
        }
    }

    /**
     * Under el_GR.ISO8859-7, where "\w" leaves 0xFF out, the splices write
     * the same matches, the JIT on and off, and a target that names that
     * locale reads the pair alike.
     */
    #[Test]
    #[DataProvider('provideSplicedParts')]
    public function test_a_part_beside_the_last_byte_reads_alike_under_tables_that_leave_it_out_of_a_word(string $left, string $right, string $splice, string $witness): void
    {
        $this->requiresLocale(self::GREEK, $left);

        [$leftSpliced, $rightSpliced] = [\sprintf($splice, substr($left, 1, -1)), \sprintf($splice, substr($right, 1, -1))];
        $alike = new \ArrayObject();
        CharacterTables::under(self::GREEK, static function () use ($leftSpliced, $rightSpliced, $witness, $alike): void {
            $alike[] = self::matchesOf($leftSpliced, $witness) === self::matchesOf($rightSpliced, $witness);
            $alike[] = self::matchesOf(PcreInterpreter::pattern($leftSpliced), $witness) === self::matchesOf(PcreInterpreter::pattern($rightSpliced), $witness);
        });
        $this->assertSame([true, true], $alike->getArrayCopy(), 'The Greek tables write the same matches.');

        $this->assertTrue((new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::GREEK])))->readAlike($left, $right));
    }

    /**
     * @return iterable<string, array{left: string, right: string, splice: string, witness: string}>
     */
    public static function provideSplicedParts(): iterable
    {
        yield 'a class of word characters repeated, before the byte' => ['left' => '/[\w]+/', 'right' => '/\w+/', 'splice' => '/(?P<a>%s)(?P<b>\xFF)/', 'witness' => "a\xFF\xFF"];
        yield 'a class of word characters, after the byte repeated' => ['left' => '/[\w]/', 'right' => '/\w/', 'splice' => '/(?P<a>\xFF+)(?P<b>%s)/', 'witness' => "\xFF\xFF"];
    }

    /**
     * The refusal names the repeat nearest what PCRE2 reads it against:
     * the byte repeated twice is made possessive in its second repeat,
     * which the first, then the byte, follows, and "\w" repeated before the
     * byte where "\w" stands before that repeat.
     */
    #[Test]
    #[DataProvider('provideRefusalPositions')]
    public function test_the_refusal_names_the_repeat_made_possessive(string $pattern, int $position): void
    {
        $solver = new LanguageSolver(RegexParser::create(['cache' => null]));
        $proofs = [
            'matches' => static fn (): bool => $solver->matchEquivalent($pattern, '/x/')->isEquivalent,
            'strings' => static fn (): bool => $solver->equivalent($pattern, '/x/')->isEquivalent,
        ];
        foreach ($proofs as $read => $proof) {
            try {
                $proof();
                $this->fail(\sprintf('The %s of %s were read.', $read, $pattern));
            } catch (ComplexityException $refusal) {
                $this->assertStringContainsString(self::LAST_BYTE, $refusal->getMessage());
                $this->assertSame($position, $refusal->position, \sprintf('The %s of %s.', $read, $pattern));
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, position: int}>
     */
    public static function provideRefusalPositions(): iterable
    {
        yield 'the byte repeated twice before a word character' => ['pattern' => '/ab\xFF+\xFF+\w/', 'position' => 7];
        yield 'a word character repeated twice before the byte' => ['pattern' => '/ab\w+\w+\xFF/', 'position' => 5];
        yield 'a word character repeated before the byte repeated before one' => ['pattern' => '/ab\w+\xFF+\w/', 'position' => 2];
    }

    /**
     * A possessive repeat is compiled possessive whatever the tables say:
     * under Latin-1 the engine reads it as written, the witness telling it
     * from its greedy spelling, and a target that names that locale refuses
     * it for what a possessive quantifier does where it may give back, not
     * for the byte.
     */
    #[Test]
    #[DataProvider('providePossessiveRepeats')]
    public function test_a_possessive_repeat_beside_the_last_byte_is_read_as_written_under_latin1(string $left, string $right, string $witness): void
    {
        $this->requiresLocale(self::LATIN1, $left);

        $read = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($left, $witness, $read): void {
            $unpossessed = $left[0].'(*NO_AUTO_POSSESS)'.substr($left, 1);
            $read[] = self::matchesOf($left, $witness) === self::matchesOf($unpossessed, $witness);
            $read[] = self::matchesOf(PcreInterpreter::pattern($left), $witness) === self::matchesOf(PcreInterpreter::pattern($unpossessed), $witness);
            $read[] = self::matchesOf($left, $witness) !== self::matchesOf(str_replace('++', '+', $unpossessed), $witness);
        });
        $this->assertSame([true, true, true], $read->getArrayCopy(), 'The engine reads the possessive repeat as written.');

        $this->assertRefusedNotForTheByte(new LanguageSolver(RegexParser::create(['cache' => null, 'locale' => self::LATIN1])), $left, $right, 'Atomic groups and possessive quantifiers');
    }

    /**
     * On every machine a target of no locale refuses those pairs for the
     * tables of the locale the possessive is proven under, not for the
     * byte.
     */
    #[Test]
    #[DataProvider('providePossessiveRepeats')]
    public function test_a_possessive_repeat_beside_the_last_byte_is_refused_for_the_tables_for_a_target_of_no_locale(string $left, string $right, string $witness): void
    {
        $this->assertSame(preg_match($left, $witness), preg_match($left[0].'(*NO_AUTO_POSSESS)'.substr($left, 1), $witness));

        $this->assertRefusedNotForTheByte(new LanguageSolver(RegexParser::create(['cache' => null])), $left, $right, 'the character tables of the locale');
    }

    /**
     * @return iterable<string, array{left: string, right: string, witness: string}>
     */
    public static function providePossessiveRepeats(): iterable
    {
        yield 'the byte repeated possessively before a word character' => ['left' => '/\xFF++\w/', 'right' => '/\xFF++[\w]/', 'witness' => "\xFF\xFF"];
        yield 'a word character repeated possessively before the byte' => ['left' => '/\w++\xFF/', 'right' => '/[\w]++\xFF/', 'witness' => "a\xFF\xFF"];
    }

    private function assertRefusedNotForTheByte(LanguageSolver $solver, string $left, string $right, string $reason): void
    {
        try {
            $solver->matchEquivalent($left, $right);
            $this->fail(\sprintf('%s and %s were proven alike.', $left, $right));
        } catch (ComplexityException $refusal) {
            $this->assertStringNotContainsString(self::LAST_BYTE, $refusal->getMessage());
            $this->assertStringContainsString($reason, $refusal->getMessage());
        }
    }

    private function assertRefused(LanguageSolver $solver, string $left, string $right): void
    {
        $proofs = [
            'matches' => static fn (): bool => $solver->matchEquivalent($left, $right)->isEquivalent,
            'strings' => static fn (): bool => $solver->equivalent($left, $right)->isEquivalent,
        ];
        foreach ($proofs as $read => $proof) {
            try {
                $proof();
                $this->fail(\sprintf('The %s of %s and %s were proven alike.', $read, $left, $right));
            } catch (ComplexityException $refusal) {
                $this->assertStringContainsString(self::LAST_BYTE, $refusal->getMessage());
            }
        }
    }

    private function assertProven(LanguageSolver $solver, string $left, string $right): void
    {
        $this->assertTrue($solver->matchEquivalent($left, $right)->isEquivalent, 'The matches.');
        $this->assertTrue($solver->equivalent($left, $right)->isEquivalent, 'The strings.');
        $this->assertSame([], $solver->runsOfEveryCall($left, $right), 'The runs.');
    }

    /**
     * Gives the tables of GIVEN_LATIN1, read from fr_FR.ISO8859-1: its word
     * characters and its cases, 0xC0 to 0xDE paired with 0xE0 to 0xFE but
     * for 0xD7, 0xFF alone; and of GIVEN_GREEK, the word characters of
     * el_GR.ISO8859-7 with C's cases.
     */
    private static function giveTables(): void
    {
        $others = [];
        for ($byte = 0xC0; $byte <= 0xDE; $byte++) {
            if (0xD7 !== $byte) {
                $others[$byte] = $byte + 0x20;
                $others[$byte + 0x20] = $byte;
            }
        }

        $latin1 = '';
        $c = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $ascii = ($byte >= 0x41 && $byte <= 0x5A) || ($byte >= 0x61 && $byte <= 0x7A) ? $byte ^ 0x20 : $byte;
            $latin1 .= \chr($others[$byte] ?? $ascii);
            $c .= \chr($ascii);
        }

        CharacterTables::useTables(self::GIVEN_LATIN1, $latin1, [], null, ['w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xBA, 0xBA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]]]);
        CharacterTables::useTables(self::GIVEN_GREEK, $c, [], null, ['w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xB6, 0xB6], [0xB8, 0xBA], [0xBC, 0xBC], [0xBE, 0xD1], [0xD3, 0xFE]]]);
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
