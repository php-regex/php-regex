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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Modernizer;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without "u", PCRE2 makes a repeat of the byte 0xFF possessive before
 * "\w", and a repeat of "\w" possessive before that byte, lazy ones too, as
 * if 0xFF were no word character, but never a repeat written as a group:
 * under fr_FR.ISO8859-1, where "\w" takes 0xFF, "/(?:\xFF)+\w/" finds
 * "\xFF\xFF" in "\xFF\xFF" where "/\xFF+\w/" finds nothing. The optimizer
 * drops such a group only where the tables of the target leave 0xFF out of
 * "\w", whether or not the options ask the automata to check the others.
 * The Modernizer, which reads no tables, keeps it, and keeps an empty group
 * a lazy repeat beside that byte stops at.
 *
 * Checked on PHP 8.4.28 with PCRE2 10.49, the JIT on and off, under C,
 * fr_FR.ISO8859-1 and el_GR.ISO8859-7.
 */
final class LastBytePossessionRewriteTest extends TestCase
{
    use InstalledLocales;

    private const FLAGS = \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * A locale whose "\w" takes 0xFE but not 0xFF.
     */
    private const GREEK = 'el_GR.ISO8859-7';

    /**
     * Under Latin-1 the rewrite tells the witness apart from the pattern,
     * the JIT on and off; a target that names that locale keeps the group.
     */
    #[Test]
    #[DataProvider('provideGroupsTheEngineReads')]
    public function test_a_group_around_a_repeat_beside_the_last_byte_stays_under_latin1(string $pattern, string $unchecked, string $letters, string $witness): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        $apart = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($pattern, $unchecked, $witness, $apart): void {
            $apart[] = self::matchesOf($pattern, $witness) !== self::matchesOf($unchecked, $witness);
            $apart[] = self::matchesOf(PcreInterpreter::pattern($pattern), $witness) !== self::matchesOf(PcreInterpreter::pattern($unchecked), $witness);
        });
        $this->assertSame([true, true], $apart->getArrayCopy(), \sprintf('%s and %s write the same matches on %s under Latin-1.', $pattern, $unchecked, json_encode(bin2hex($witness))));

        $optimizer = new Optimizer(RegexParser::create(['cache' => null, 'locale' => self::LATIN1]));
        foreach ([true, false] as $verify) {
            $this->assertSame($pattern, $optimizer->optimize($pattern, new OptimizerOptions(verifyWithAutomata: $verify))->optimized);
        }
    }

    /**
     * C's tables write the rewrite alike on every subject over its
     * letters, yet a target that names no locale but C may run under any:
     * the group stays, whether or not the options ask for every check. The
     * Latin-1 witness is checked above, where this machine can set that
     * locale.
     */
    #[Test]
    #[DataProvider('provideGroupsTheEngineReads')]
    public function test_a_group_around_a_repeat_beside_the_last_byte_stays_for_a_target_of_no_locale(string $pattern, string $unchecked, string $letters, string $witness): void
    {
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($pattern, $unchecked, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($pattern, $subject) === self::matchesOf($unchecked, $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        foreach ([[], ['locale' => CharacterTables::BUILT_IN]] as $target) {
            $optimizer = new Optimizer(RegexParser::create(['cache' => null] + $target));
            foreach ([true, false] as $verify) {
                $this->assertSame($pattern, $optimizer->optimize($pattern, new OptimizerOptions(verifyWithAutomata: $verify))->optimized);
            }
        }
    }

    /**
     * Under el_GR.ISO8859-7 "\w" leaves 0xFF out: the engine reads the
     * rewrite as it reads the pattern, the JIT on and off, and a target
     * that names that locale takes the group out.
     */
    #[Test]
    #[DataProvider('provideGroupsTheEngineReads')]
    public function test_a_group_around_a_repeat_beside_the_last_byte_goes_under_tables_that_leave_it_out_of_a_word(string $pattern, string $unchecked, string $letters, string $witness): void
    {
        $this->requiresLocale(self::GREEK, $pattern);

        $alike = new \ArrayObject();
        CharacterTables::under(self::GREEK, static function () use ($pattern, $unchecked, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($pattern, $subject) === self::matchesOf($unchecked, $subject);
                $alike[] = self::matchesOf(PcreInterpreter::pattern($pattern), $subject) === self::matchesOf(PcreInterpreter::pattern($unchecked), $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'The Greek tables write the same matches.');

        $optimizer = new Optimizer(RegexParser::create(['cache' => null, 'locale' => self::GREEK]));
        foreach ([true, false] as $verify) {
            $this->assertSame($unchecked, $optimizer->optimize($pattern, new OptimizerOptions(verifyWithAutomata: $verify))->optimized);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, unchecked: string, letters: string, witness: string}>
     */
    public static function provideGroupsTheEngineReads(): iterable
    {
        yield 'the byte repeated before a word character' => ['pattern' => '/(?:\xFF)+\w/', 'unchecked' => '/\xFF+\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'the byte repeated lazily before a word character' => ['pattern' => '/(?:\xFF)+?\w/', 'unchecked' => '/\xFF+?\w/', 'letters' => "\xFFa", 'witness' => "\xFF\xFF"];
        yield 'an optional byte before a word character' => ['pattern' => '/(?:\xFF)?\w/', 'unchecked' => '/\xFF?\w/', 'letters' => "\xFFa", 'witness' => "\xFF"];
        yield 'a word character repeated before the byte' => ['pattern' => '/(?:\w)+\xFF/', 'unchecked' => '/\w+\xFF/', 'letters' => "a\xFF", 'witness' => "a\xFF\xFF"];
        yield 'a word character repeated lazily before the byte' => ['pattern' => '/(?:\w)+?\xFF/', 'unchecked' => '/\w+?\xFF/', 'letters' => "a\xFF", 'witness' => "a\xFF\xFF"];
    }

    /**
     * A rewrite of a pattern that holds such a repeat is left to the
     * automata whatever the options say, and they refuse the pattern, read
     * wider than the engine reads it: "\w{0}" reads nothing, yet the
     * pattern stays as written, though the engine reads it as the rewrite
     * under C's tables, and under fr_FR.ISO8859-1 on PHP 8.4.28 with PCRE2
     * 10.49.
     */
    #[Test]
    #[DataProvider('provideRepeatsBeforeNothing')]
    public function test_a_rewrite_of_a_pattern_holding_a_repeat_beside_the_last_byte_is_checked_whatever_the_options(string $pattern, string $unchecked, string $letters): void
    {
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($pattern, $unchecked, $letters, $alike): void {
            foreach (self::subjects($letters, 4) as $subject) {
                $alike[] = self::matchesOf($pattern, $subject) === self::matchesOf($unchecked, $subject);
            }
        });
        $this->assertNotContains(false, $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        $this->assertSame($pattern, (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(verifyWithAutomata: false))->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, unchecked: string, letters: string}>
     */
    public static function provideRepeatsBeforeNothing(): iterable
    {
        yield 'the byte repeated before a word character read no time' => ['pattern' => '/\xFF+\w{0}/', 'unchecked' => '/\xFF+/', 'letters' => "\xFFa"];
        yield 'a word character repeated before the byte read no time' => ['pattern' => '/\w+\xFF{0}/', 'unchecked' => '/\w+/', 'letters' => "a\xFF"];
    }

    /**
     * The Modernizer takes a group out only where its brackets decide
     * nothing: under Latin-1 the witness tells each pattern apart from the
     * one without the group, the JIT on and off, and the group stays.
     */
    #[Test]
    #[DataProvider('provideGroupsTheModernizerKeeps')]
    public function test_the_modernizer_keeps_a_group_whose_brackets_decide_a_repeat_beside_the_last_byte_under_latin1(string $pattern, string $without, string $witness): void
    {
        $this->requiresLocale(self::LATIN1, $pattern);

        $apart = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($pattern, $without, $witness, $apart): void {
            $apart[] = self::matchesOf($pattern, $witness) !== self::matchesOf($without, $witness);
            $apart[] = self::matchesOf(PcreInterpreter::pattern($pattern), $witness) !== self::matchesOf(PcreInterpreter::pattern($without), $witness);
        });
        $this->assertSame([true, true], $apart->getArrayCopy(), \sprintf('%s and %s write the same matches on %s under Latin-1.', $pattern, $without, json_encode(bin2hex($witness))));

        $this->assertSame($pattern, self::modernized($pattern, self::LATIN1));
    }

    /**
     * C's tables write each pattern as the one without the group, yet the
     * pattern may run under any tables where the locale is not known: the
     * group stays on every machine. The Latin-1 witness is checked above,
     * where this machine can set that locale.
     */
    #[Test]
    #[DataProvider('provideGroupsTheModernizerKeeps')]
    public function test_the_modernizer_keeps_a_group_whose_brackets_decide_a_repeat_beside_the_last_byte_where_the_locale_is_not_known(string $pattern, string $without, string $witness): void
    {
        $alike = new \ArrayObject();
        CharacterTables::under(CharacterTables::BUILT_IN, static function () use ($pattern, $without, $witness, $alike): void {
            $alike[] = self::matchesOf($pattern, $witness) === self::matchesOf($without, $witness);
            $alike[] = self::matchesOf(PcreInterpreter::pattern($pattern), $witness) === self::matchesOf(PcreInterpreter::pattern($without), $witness);
        });
        $this->assertSame([true, true], $alike->getArrayCopy(), 'C\'s tables write the same matches.');

        $this->assertSame($pattern, self::modernized($pattern, null));
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, witness: string}>
     */
    public static function provideGroupsTheModernizerKeeps(): iterable
    {
        yield 'the byte repeated before a word character' => ['pattern' => '/(?:\xFF)+\w/', 'without' => '/\xFF+\w/', 'witness' => "\xFF\xFF"];
        yield 'a word character repeated before the byte' => ['pattern' => '/(?:\w)+\xFF/', 'without' => '/\w+\xFF/', 'witness' => "a\xFF\xFF"];
        yield 'an empty group between a lazy repeat and a word character' => ['pattern' => '/\xFF+?(?:)\w/', 'without' => '/\xFF+?\w/', 'witness' => "\xFF\xFF"];
    }

    /**
     * Where the engine reads the group as its body, on every subject over
     * its letters, under C's tables and under Latin-1 where this machine
     * can set it, the JIT on and off, the Modernizer takes it out.
     */
    #[Test]
    #[DataProvider('provideGroupsTheModernizerTakesOut')]
    public function test_the_modernizer_takes_out_a_group_the_engine_reads_as_its_body(string $pattern, string $without, string $letters): void
    {
        $locales = [CharacterTables::BUILT_IN];

        try {
            CharacterTables::forLocale(self::LATIN1);
            $locales[] = self::LATIN1;
        } catch (InvalidRegexOptionException) {
            // This machine cannot set Latin-1: C's tables are read alone.
        }

        $alike = new \ArrayObject();
        foreach ($locales as $locale) {
            CharacterTables::under($locale, static function () use ($pattern, $without, $letters, $alike): void {
                foreach (self::subjects($letters, 4) as $subject) {
                    $alike[] = self::matchesOf($pattern, $subject) === self::matchesOf($without, $subject);
                    $alike[] = self::matchesOf(PcreInterpreter::pattern($pattern), $subject) === self::matchesOf(PcreInterpreter::pattern($without), $subject);
                }
            });
        }
        $this->assertNotContains(false, $alike->getArrayCopy(), 'The tables write the same matches.');

        $this->assertSame($without, self::modernized($pattern, null));
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, letters: string}>
     */
    public static function provideGroupsTheModernizerTakesOut(): iterable
    {
        yield 'the byte below repeated before a word character' => ['pattern' => '/(?:\xFE)+\w/', 'without' => '/\xFE+\w/', 'letters' => "\xFEa"];
        yield 'the byte repeated before a word character under u' => ['pattern' => '/(?:\x{FF})+\w/u', 'without' => '/\x{FF}+\w/u', 'letters' => "\u{FF}a"];
        yield 'an empty group between a lazy repeat of the byte below and a word character' => ['pattern' => '/\xFE+?(?:)\w/', 'without' => '/\xFE+?\w/', 'letters' => "\xFEa"];
    }

    private static function modernized(string $pattern, ?string $locale): string
    {
        return RegexParser::create(['cache' => null])->parse($pattern)->accept(new Modernizer(null === $locale ? null : PcreTarget::runtime()->withLocale($locale)))->accept(PatternPrinter::asWritten());
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
