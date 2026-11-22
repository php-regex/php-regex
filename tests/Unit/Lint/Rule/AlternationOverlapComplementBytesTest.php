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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without u, where PCRE judges no branch (one of them matches empty), the
 * bytes the branches start with decide: the complement of a class takes
 * the bytes above ASCII it does not hold, so "[^\xE9]" meets a raw 0x90,
 * and "\W" a raw 0xE9 under PCRE2's own tables. Under another locale's,
 * the bytes of "\d", "\s" and "\w" are read from those tables: "\W"
 * leaves 0xE9 out under fr_FR.ISO8859-1, as the engine does, and "\w"
 * takes it. A case is read from the tables of either, a byte read
 * caselessly too: a caseless "[^a]" takes 0xC3 under both, and leaves "A"
 * out, and a caseless 0xC9 takes 0xE9 under the Latin-1 tables alone. A
 * byte written as an escape, "\xE9", "\x{E9}", "\351" or "\o{351}", is
 * the byte written as it is, and a POSIX class takes the bytes the tables
 * give it: "[[:alpha:]]" takes 0xE9 under the Latin-1 tables alone, and
 * "[[:upper:]]" under i, where the engine reads it as "[[:alpha:]]".
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49 on macOS, under C and fr_FR.ISO8859-1:
 * preg_match("/^[^\xE9]$/", "\x90") and preg_match('/^\W$/', "\xE9") are 1
 * under C, and the second 0 under the Latin-1 locale, where
 * preg_match('/^\w$/', "\xE9") and preg_match("/^\xC9$/i", "\xE9") are 1;
 * preg_match("/^[^a]$/i", "\xC3") is 1 under both, and
 * preg_match('/^[[:alpha:]]$/', "\xE9") 0 under C and 1 under the Latin-1
 * locale, with the JIT and without it.
 */
final class AlternationOverlapComplementBytesTest extends TestCase
{
    use InstalledLocales;

    private const ID = 'regex.lint.overlap.charset';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * The locale of the tables a test gives.
     */
    private const GIVEN = 'xx_XX.GIVEN';

    /**
     * @param array{string, string} $branches
     * @param string                $rule     the rule that reports the overlap: two literal
     *                                        branches whose texts fold alike are reported as
     *                                        overlapping text, which the overlap of their
     *                                        characters is then not
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_the_bytes_a_complement_takes_above_ascii_meet_a_byte_written_as_it_is(string $pattern, array $branches, string $byte, string $locale, bool $reported, string $rule = self::ID): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: under the row's locale, both branches take the byte where
        // the row says they overlap.
        $taken = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($branches, $byte, $taken): void {
            foreach ($branches as $branch) {
                $taken[] = preg_match('/^(?:'.$branch.')$/', $byte);
            }
        });
        $this->assertSame($reported, [1, 1] === $taken->getArrayCopy(), \sprintf('Oracle: %s under %s takes %s in %s.', addcslashes($pattern, "\200..\377"), $locale, bin2hex($byte), json_encode($taken->getArrayCopy())));

        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
        $this->assertSame($reported ? [$rule] : [], array_values(array_intersect([OverlappingAlternationRule::ID_OVERLAP, self::ID], $ids)), addcslashes($pattern, "\200..\377").' under '.$locale);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: array{string, string}, byte: string, locale: string, reported: bool, rule?: string}>
     */
    public static function provideRows(): iterable
    {
        foreach ([CharacterTables::BUILT_IN, self::LATIN1] as $locale) {
            yield 'a negated byte, then a byte it takes, '.$locale => ['pattern' => "/(?:[^\xE9]|\x90|)+/", 'branches' => ["[^\xE9]", "\x90"], 'byte' => "\x90", 'locale' => $locale, 'reported' => true];
            yield 'a byte, then a negated byte that takes it, '.$locale => ['pattern' => "/(?:\x90|[^\xE9]|)+/", 'branches' => ["\x90", "[^\xE9]"], 'byte' => "\x90", 'locale' => $locale, 'reported' => true];
            yield 'a negated letter of two bytes, then a byte it takes, '.$locale => ['pattern' => "/(?:[^é]|\x90|)+/", 'branches' => ['[^é]', "\x90"], 'byte' => "\x90", 'locale' => $locale, 'reported' => true];
            yield 'a negated ASCII letter, then a byte above ASCII, '.$locale => ['pattern' => "/(?:[^a]|\xC3|)+/", 'branches' => ['[^a]', "\xC3"], 'byte' => "\xC3", 'locale' => $locale, 'reported' => true];
            yield 'a negated byte, then that byte, '.$locale => ['pattern' => "/(?:[^\xC3]|\xC3|)+/", 'branches' => ["[^\xC3]", "\xC3"], 'byte' => "\xC3", 'locale' => $locale, 'reported' => false];
        }

        yield 'no word character, then a Latin-1 letter, C' => ['pattern' => "/(?:\\W|\xE9|)+/", 'branches' => ['\W', "\xE9"], 'byte' => "\xE9", 'locale' => CharacterTables::BUILT_IN, 'reported' => true];
        yield 'no word character, then a Latin-1 letter, '.self::LATIN1 => ['pattern' => "/(?:\\W|\xE9|)+/", 'branches' => ['\W', "\xE9"], 'byte' => "\xE9", 'locale' => self::LATIN1, 'reported' => false];
        foreach ([CharacterTables::BUILT_IN, self::LATIN1] as $locale) {
            yield 'a negated ASCII letter read caselessly, then a byte above ASCII, '.$locale => ['pattern' => "/(?:(?i:[^a])|\xC3|)+/", 'branches' => ['(?i:[^a])', "\xC3"], 'byte' => "\xC3", 'locale' => $locale, 'reported' => true];
            yield 'a negated ASCII letter read caselessly, then its capital, '.$locale => ['pattern' => '/(?:(?i:[^a])|A|)+/', 'branches' => ['(?i:[^a])', 'A'], 'byte' => 'A', 'locale' => $locale, 'reported' => false];
        }

        // What the Latin-1 tables decide, which PCRE2's own do otherwise.
        foreach ([CharacterTables::BUILT_IN => false, self::LATIN1 => true] as $locale => $latin1) {
            yield 'a word character, then a Latin-1 letter, '.$locale => ['pattern' => "/(?:\\w|\xE9|)+/", 'branches' => ['\w', "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            yield 'a Latin-1 letter, then a word character, '.$locale => ['pattern' => "/(?:\xE9|\\w|)+/", 'branches' => ["\xE9", '\w'], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            yield 'a Latin-1 letter, then a class of word characters, '.$locale => ['pattern' => "/(?:\xE9|[\\w]|)+/", 'branches' => ["\xE9", '[\w]'], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            yield 'a Latin-1 capital read caselessly, then its small letter, '.$locale => ['pattern' => "/(?:\xC9|\xE9|)+/i", 'branches' => ["(?i:\xC9)", "(?i:\xE9)"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1, 'rule' => OverlappingAlternationRule::ID_OVERLAP];
            yield 'a group reading a Latin-1 capital caselessly, then its small letter, '.$locale => ['pattern' => "/(?:(?i:\xC9)|\xE9|)+/", 'branches' => ["(?i:\xC9)", "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1, 'rule' => OverlappingAlternationRule::ID_OVERLAP];
            yield 'a Latin-1 small letter, then a class of its capital, caseless, '.$locale => ['pattern' => "/(?:\xE9|[\xC9]|)+/i", 'branches' => ["(?i:\xE9)", "(?i:[\xC9])"], 'byte' => "\xC9", 'locale' => $locale, 'reported' => $latin1];
        }

        // The same bytes written as escapes, which the ASCII read leaves
        // unknown, and the POSIX classes, which the tables fill as well.
        foreach ([CharacterTables::BUILT_IN => false, self::LATIN1 => true] as $locale => $latin1) {
            foreach (['\xE9', '\x{E9}', '\351', '\o{351}'] as $escape) {
                yield 'no word character, then a Latin-1 letter written '.$escape.', '.$locale => ['pattern' => '/(?:\W|'.$escape.'|)+/', 'branches' => ['\W', $escape], 'byte' => "\xE9", 'locale' => $locale, 'reported' => !$latin1];
                yield 'a word character, then a Latin-1 letter written '.$escape.', '.$locale => ['pattern' => '/(?:\w|'.$escape.'|)+/', 'branches' => ['\w', $escape], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
                yield 'a POSIX class of letters, then a Latin-1 letter written '.$escape.', '.$locale => ['pattern' => '/(?:[[:alpha:]]|'.$escape.'|)+/', 'branches' => ['[[:alpha:]]', $escape], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            }

            foreach ([['\xC9', '\xE9'], ['\x{C9}', '\x{E9}'], ['\311', '\351'], ['\o{311}', '\o{351}'], ['\xC9', "\xE9"], ["\xC9", '\o{351}']] as [$capital, $small]) {
                $name = 'a Latin-1 capital written '.addcslashes($capital, "\200..\377").' read caselessly, then its small letter written '.addcslashes($small, "\200..\377").', '.$locale;
                yield $name => ['pattern' => '/(?:'.$capital.'|'.$small.'|)+/i', 'branches' => ['(?i:'.$capital.')', '(?i:'.$small.')'], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            }

            yield 'a Latin-1 capital written as an escape read caselessly, then another small letter, '.$locale => ['pattern' => '/(?:\xC9|\xEA|)+/i', 'branches' => ['(?i:\xC9)', '(?i:\xEA)'], 'byte' => "\xEA", 'locale' => $locale, 'reported' => false];
            yield 'a POSIX class of letters, then a Latin-1 letter, '.$locale => ['pattern' => "/(?:[[:alpha:]]|\xE9|)+/", 'branches' => ['[[:alpha:]]', "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            yield 'a Latin-1 letter, then a POSIX class of letters, '.$locale => ['pattern' => "/(?:\xE9|[[:alpha:]]|)+/", 'branches' => ["\xE9", '[[:alpha:]]'], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            yield 'a negated POSIX class of letters, then a Latin-1 letter, '.$locale => ['pattern' => "/(?:[[:^alpha:]]|\xE9|)+/", 'branches' => ['[[:^alpha:]]', "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => !$latin1];
            yield 'a negated class of a POSIX class, then a Latin-1 letter, '.$locale => ['pattern' => "/(?:[^[:alpha:]]|\xE9|)+/", 'branches' => ['[^[:alpha:]]', "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => !$latin1];
            yield 'a POSIX class in a larger class, then a Latin-1 letter, '.$locale => ['pattern' => "/(?:[0-9[:alpha:]]|\xE9|)+/", 'branches' => ['[0-9[:alpha:]]', "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            yield 'a POSIX class of small letters, then a Latin-1 small letter, '.$locale => ['pattern' => "/(?:[[:lower:]]|\xE9|)+/", 'branches' => ['[[:lower:]]', "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
            yield 'a POSIX class of capitals, then a Latin-1 small letter, '.$locale => ['pattern' => "/(?:[[:upper:]]|\xE9|)+/", 'branches' => ['[[:upper:]]', "\xE9"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => false];
            yield 'a POSIX class of capitals read caselessly, then a Latin-1 small letter, '.$locale => ['pattern' => "/(?:[[:upper:]]|\xE9|)+/i", 'branches' => ['(?i:[[:upper:]])', "(?i:\xE9)"], 'byte' => "\xE9", 'locale' => $locale, 'reported' => $latin1];
        }

        // What both tables decide alike.
        foreach ([CharacterTables::BUILT_IN, self::LATIN1] as $locale) {
            yield 'no word character, then a sign, '.$locale => ['pattern' => "/(?:\\W|\xD7|)+/", 'branches' => ['\W', "\xD7"], 'byte' => "\xD7", 'locale' => $locale, 'reported' => true];
            yield 'a Latin-1 letter, then no digit, '.$locale => ['pattern' => "/(?:\xE9|\\D|)+/", 'branches' => ["\xE9", '\D'], 'byte' => "\xE9", 'locale' => $locale, 'reported' => true];
            yield 'no space, then a no-break space, '.$locale => ['pattern' => "/(?:\\S|\xA0|)+/", 'branches' => ['\S', "\xA0"], 'byte' => "\xA0", 'locale' => $locale, 'reported' => true];
            yield 'a space, then a no-break space, '.$locale => ['pattern' => "/(?:\\s|\xA0|)+/", 'branches' => ['\s', "\xA0"], 'byte' => "\xA0", 'locale' => $locale, 'reported' => false];
            yield 'a digit, then a superscript two, '.$locale => ['pattern' => "/(?:\\d|\xB2|)+/", 'branches' => ['\d', "\xB2"], 'byte' => "\xB2", 'locale' => $locale, 'reported' => false];
            yield 'a Latin-1 capital read caselessly, then a class holding it, '.$locale => ['pattern' => "/(?:\xC9|[\xC9\xCA]|)+/i", 'branches' => ["(?i:\xC9)", "(?i:[\xC9\xCA])"], 'byte' => "\xC9", 'locale' => $locale, 'reported' => true];
            yield 'a Latin-1 capital read caselessly, then another small letter, '.$locale => ['pattern' => "/(?:\xC9|\xEA|)+/i", 'branches' => ["(?i:\xC9)", "(?i:\xEA)"], 'byte' => "\xEA", 'locale' => $locale, 'reported' => false];
            yield 'two signs without a case, read caselessly, '.$locale => ['pattern' => "/(?:\xD7|\xF7|)+/i", 'branches' => ["(?i:\xD7)", "(?i:\xF7)"], 'byte' => "\xF7", 'locale' => $locale, 'reported' => false];
            yield 'no word character, then a sign written as an escape, '.$locale => ['pattern' => '/(?:\W|\xD7|)+/', 'branches' => ['\W', '\xD7'], 'byte' => "\xD7", 'locale' => $locale, 'reported' => true];
            yield 'a range above ASCII, then a byte in it written as an escape, '.$locale => ['pattern' => '/(?:[\x80-\xFF]|\xE9|)+/', 'branches' => ['[\x80-\xFF]', '\xE9'], 'byte' => "\xE9", 'locale' => $locale, 'reported' => true];
            yield 'a negated POSIX class of letters, then a sign, '.$locale => ['pattern' => "/(?:[[:^alpha:]]|\xD7|)+/", 'branches' => ['[[:^alpha:]]', "\xD7"], 'byte' => "\xD7", 'locale' => $locale, 'reported' => true];
            yield 'a negated POSIX class of letters, then a sign written as an escape, '.$locale => ['pattern' => '/(?:[[:^alpha:]]|\xD7|)+/', 'branches' => ['[[:^alpha:]]', '\xD7'], 'byte' => "\xD7", 'locale' => $locale, 'reported' => true];
            yield 'a POSIX class of letters, then a sign, '.$locale => ['pattern' => "/(?:[[:alpha:]]|\xD7|)+/", 'branches' => ['[[:alpha:]]', "\xD7"], 'byte' => "\xD7", 'locale' => $locale, 'reported' => false];
            yield 'a POSIX class of digits, then a superscript two, '.$locale => ['pattern' => "/(?:[[:digit:]]|\xB2|)+/", 'branches' => ['[[:digit:]]', "\xB2"], 'byte' => "\xB2", 'locale' => $locale, 'reported' => false];
        }
    }

    /**
     * Under tables a test gives, as no machine here builds them, the bytes
     * read where PCRE judges no branch, an empty one first, hold what those
     * tables decide: the bytes of "\d", "\s" and "\w", and the other case
     * of a byte. A caseless byte whose case the engine's paths read
     * otherwise leaves the branches unjudged, and a caseless ASCII letter
     * the case the ASCII read gives it. The tables pair 0xE9 with 0xC9, put
     * the Latin-1 letters in "\w", 0xB2 in "\d" and 0xA0 in "\s", and 0xB5
     * and "i" are read otherwise by the interpreter. Two literal branches
     * whose texts fold alike are reported as overlapping text.
     *
     * @param string $rule the rule that reports the overlap
     */
    #[Test]
    #[DataProvider('provideRowsUnderGivenTables')]
    public function test_read_under_given_tables_the_bytes_hold_what_they_decide(string $pattern, bool $reported, string $rule = self::ID): void
    {
        $others = [0xE9 => 0xC9, 0xC9 => 0xE9];
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        CharacterTables::useTables(self::GIVEN, $cases, [0xB5, 0x69], bytes: [
            'd' => [[0x30, 0x39], [0xB2, 0xB2]],
            's' => [[0x09, 0x0D], [0x20, 0x20], [0xA0, 0xA0]],
            'w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]],
            'alpha' => [[0x41, 0x5A], [0x61, 0x7A], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]],
            'upper' => [[0x41, 0x5A], [0xC0, 0xD6], [0xD8, 0xDE]],
            'lower' => [[0x61, 0x7A], [0xDF, 0xF6], [0xF8, 0xFF]],
        ]);

        $this->assertSame($reported ? [$rule] : [], self::overlapRules($pattern, self::GIVEN), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, reported: bool, rule?: string}>
     */
    public static function provideRowsUnderGivenTables(): iterable
    {
        yield 'a word character, then a Latin-1 letter' => ['pattern' => "/(?:|\\w|\xE9)+/", 'reported' => true];
        yield 'no word character, then a Latin-1 letter' => ['pattern' => "/(?:|\\W|\xE9)+/", 'reported' => false];
        yield 'no word character, then a sign' => ['pattern' => "/(?:|\\W|\xD7)+/", 'reported' => true];
        yield 'a digit, then a superscript two' => ['pattern' => "/(?:|\\d|\xB2)+/", 'reported' => true];
        yield 'no digit, then a superscript two' => ['pattern' => "/(?:|\\D|\xB2)+/", 'reported' => false];
        yield 'a space, then a no-break space' => ['pattern' => "/(?:|\\s|\xA0)+/", 'reported' => true];
        yield 'no space, then a no-break space' => ['pattern' => "/(?:|\\S|\xA0)+/", 'reported' => false];
        yield 'a Latin-1 capital read caselessly, then its small letter' => ['pattern' => "/(?:|\xC9|\xE9)+/i", 'reported' => true, 'rule' => OverlappingAlternationRule::ID_OVERLAP];
        yield 'a Latin-1 capital read caselessly, then another small letter' => ['pattern' => "/(?:|\xC9|\xEA)+/i", 'reported' => false];
        yield 'a caseless byte the interpreter reads otherwise' => ['pattern' => "/(?:|\xB5|\xC9)+/i", 'reported' => false];
        yield 'a caseless class of an ASCII letter the interpreter reads otherwise, then its capital' => ['pattern' => "/(?:|(?i:[i])|I|\xE9)+/", 'reported' => true];
        yield 'a caseless class of an ASCII letter the interpreter reads otherwise, then another capital' => ['pattern' => "/(?:|(?i:[i])|J|\xE9)+/", 'reported' => false];
        foreach (['\xE9', '\x{E9}', '\351', '\o{351}'] as $escape) {
            yield 'a word character, then a Latin-1 letter written '.$escape => ['pattern' => '/(?:|\w|'.$escape.')+/', 'reported' => true];
            yield 'no word character, then a Latin-1 letter written '.$escape => ['pattern' => '/(?:|\W|'.$escape.')+/', 'reported' => false];
            yield 'a POSIX class of letters, then a Latin-1 letter written '.$escape => ['pattern' => '/(?:|[[:alpha:]]|'.$escape.')+/', 'reported' => true];
        }
        yield 'no word character, then a sign written as an escape' => ['pattern' => '/(?:|\W|\xD7)+/', 'reported' => true];
        yield 'a digit, then a superscript two written as an escape' => ['pattern' => '/(?:|\d|\xB2)+/', 'reported' => true];
        yield 'a space, then a no-break space written as an escape' => ['pattern' => '/(?:|\s|\xA0)+/', 'reported' => true];
        yield 'a Latin-1 capital written as an escape read caselessly, then its small letter' => ['pattern' => '/(?:|\xC9|\xE9)+/i', 'reported' => true];
        yield 'a Latin-1 capital written as an escape read caselessly, then another small letter' => ['pattern' => '/(?:|\xC9|\xEA)+/i', 'reported' => false];
        yield 'a caseless escape the interpreter reads otherwise' => ['pattern' => '/(?:|\xB5|\xC9)+/i', 'reported' => false];
        yield 'a POSIX class of letters, then a Latin-1 letter' => ['pattern' => "/(?:|[[:alpha:]]|\xE9)+/", 'reported' => true];
        yield 'a negated POSIX class of letters, then a Latin-1 letter' => ['pattern' => "/(?:|[[:^alpha:]]|\xE9)+/", 'reported' => false];
        yield 'a negated POSIX class of letters, then a sign' => ['pattern' => "/(?:|[[:^alpha:]]|\xD7)+/", 'reported' => true];
        yield 'a negated class of a POSIX class, then a Latin-1 letter' => ['pattern' => "/(?:|[^[:alpha:]]|\xE9)+/", 'reported' => false];
        yield 'a POSIX class in a larger class, then a Latin-1 letter' => ['pattern' => "/(?:|[0-9[:alpha:]]|\xE9)+/", 'reported' => true];
        yield 'a POSIX class of capitals, then a Latin-1 small letter' => ['pattern' => "/(?:|[[:upper:]]|\xE9)+/", 'reported' => false];
        yield 'a POSIX class of capitals read caselessly, then a Latin-1 small letter' => ['pattern' => "/(?:|[[:upper:]]|\xE9)+/i", 'reported' => true];
        yield 'a POSIX class of small letters, then a Latin-1 capital' => ['pattern' => "/(?:|[[:lower:]]|\xC9)+/", 'reported' => false];
    }

    /**
     * The rules of overlapping branches that report the pattern under the
     * locale: the overlap of their texts or of their characters.
     *
     * @return list<string>
     */
    private static function overlapRules(string $pattern, string $locale): array
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        return array_values(array_intersect([OverlappingAlternationRule::ID_OVERLAP, self::ID], array_map(static fn ($issue): string => $issue->id, $linter->getIssues())));
    }
}
