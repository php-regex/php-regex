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
use PHPRegex\Linter\Rule\LintRuleRegistry;
use PHPRegex\Linter\Rule\NestedQuantifierRule;
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Linter\Rule\QuantifierConcatenationRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without UTF, the rules that compare the characters of two runs read them
 * as the tables of the target's locale give them, every byte: the other
 * case of a letter read caselessly, and the bytes of "\w". Under glibc's
 * tr_TR.ISO-8859-9 "i" pairs with 0xDD and "I" with 0xFD, so a caseless
 * "i" and a caseless "I" take no character in common: "(?:i|I)+$",
 * "(?:i+I)+$" and "i+I+$" are no overlap, no nested loop and no pair of
 * runs one of which holds the other, and "i+\xDD+$" is, read caselessly.
 * "\w" takes 0xDD there, so "(?:\w+\xDD)+$" is a nested loop. Under C every
 * verdict stays as PCRE2's own tables give it.
 *
 * Engine, glibc's tr_TR.ISO-8859-9 (Debian, localedef): PHP 8.2.36 with
 * PCRE2 10.40, 8.4.28 and 8.6.0 with 10.44, the JIT on and off, each
 * setting in a process of its own: "/^i$/i" matches "i" and 0xDD alone,
 * "/^I$/i" "I" and 0xFD alone; "(?:i|I)+$", "(?:i+I)+$" and "i+I+$" return
 * 0 at once on "iI" 15 times then "!", "(?:i|\xDD)+$" and "(?:i+\xDD)+$"
 * exhaust the backtrack limit, "i+\xDD+$" takes 3 s on 3,000 "i" then "!"
 * without the JIT, and "(?:\w+\xDD)+$" exhausts the backtrack limit on 30
 * 0xDD then "!", where "(?:\w+\xD7)+$" returns 0 at once.
 */
final class RunsUnderLocaleTablesTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * glibc's name of its Latin-5 Turkish locale; macOS names its own,
     * whose tables pair the letters otherwise, tr_TR.ISO8859-9, and sets
     * no locale under this name.
     */
    private const GLIBC_TURKISH = 'tr_TR.ISO-8859-9';

    /**
     * The tables of glibc's tr_TR.ISO-8859-9, given where no such locale is
     * installed.
     */
    private const GIVEN_GLIBC = 'xx_XX.LATIN5';

    /**
     * The tables of macOS's tr_TR.ISO8859-9, given: "i" and "I" pair with
     * each other, 0xDD lowers to "i" and 0xFD uppers to "I", and the
     * interpreter matches 0xDD with "I": those three bytes are read
     * otherwise by the engine's paths.
     */
    private const GIVEN_MACOS = 'xx_XX.TURKISH';

    private const RULES = [
        OverlappingAlternationRule::ID_OVERLAP,
        OverlappingAlternationRule::ID_CHARSET,
        NestedQuantifierRule::ID,
        QuantifierConcatenationRule::ID,
    ];

    /**
     * @param array{string, string} $atoms  the atoms the rule compares, each read alone under the row's flags
     * @param bool                  $subset whether the rule asks that one atom take no character the other does not,
     *                                      rather than that they take one in common
     * @param bool                  $holds  whether the engine answers yes, on the JIT and on the interpreter
     * @param string|null           $rule   the rule that reports the pattern, none for null
     */
    #[Test]
    #[DataProvider('provideRows')]
    public function test_runs_read_their_characters_from_the_tables_of_the_target_locale(string $pattern, array $atoms, string $flags, bool $subset, bool $holds, string $locale, ?string $rule): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: the bytes each atom takes alone under the locale, by the
        // JIT where it is on and by the interpreter.
        $taken = ['' => [[], []], '(*NO_JIT)' => [[], []]];
        CharacterTables::under($locale, static function () use ($atoms, $flags, &$taken): void {
            foreach (array_keys($taken) as $path) {
                foreach ($atoms as $index => $atom) {
                    for ($byte = 0; $byte < 256; $byte++) {
                        if (1 === preg_match('/'.$path.'^(?:'.$atom.')$/'.$flags, \chr($byte))) {
                            $taken[$path][$index][] = $byte;
                        }
                    }
                }
            }
        });
        $label = \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale);
        foreach ($taken as $path => [$first, $second]) {
            $answer = $subset ? [] === array_diff($first, $second) || [] === array_diff($second, $first) : [] !== array_intersect($first, $second);
            $this->assertSame($holds, $answer, \sprintf('Oracle%s: %s, whose atoms take %s and %s.', '' === $path ? '' : ' '.$path, $label, json_encode(array_map(dechex(...), $first)), json_encode(array_map(dechex(...), $second))));
        }

        $this->assertSame(null === $rule ? [] : [$rule], self::reported($pattern, $locale), $label);
    }

    /**
     * @return iterable<string, array{pattern: string, atoms: array{string, string}, flags: string, subset: bool, holds: bool, locale: string, rule: string|null}>
     */
    public static function provideRows(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;
        $nested = NestedQuantifierRule::ID;
        $concatenation = QuantifierConcatenationRule::ID;

        foreach ([CharacterTables::BUILT_IN => false, self::GLIBC_TURKISH => true] as $locale => $turkish) {
            yield 'a small and a capital i, as branches, '.$locale => ['pattern' => '/(?:i|I)+$/i', 'atoms' => ['i', 'I'], 'flags' => 'i', 'subset' => false, 'holds' => !$turkish, 'locale' => $locale, 'rule' => $turkish ? null : $text];
            yield 'a run of small i, then a capital, in a loop, '.$locale => ['pattern' => '/(?:i+I)+$/i', 'atoms' => ['i', 'I'], 'flags' => 'i', 'subset' => false, 'holds' => !$turkish, 'locale' => $locale, 'rule' => $turkish ? null : $nested];
            yield 'a run of small i, then a run of capitals, '.$locale => ['pattern' => '/i+I+$/i', 'atoms' => ['i', 'I'], 'flags' => 'i', 'subset' => true, 'holds' => !$turkish, 'locale' => $locale, 'rule' => $turkish ? null : $concatenation];
            yield 'a run of small i, then a run of 0xDD, '.$locale => ['pattern' => '/i+\xDD+$/i', 'atoms' => ['i', '\xDD'], 'flags' => 'i', 'subset' => true, 'holds' => $turkish, 'locale' => $locale, 'rule' => $turkish ? $concatenation : null];
            yield 'a run of word characters, then a raw 0xDD, in a loop, '.$locale => ['pattern' => "/(?:\\w+\xDD)+\$/", 'atoms' => ['\w', "\xDD"], 'flags' => '', 'subset' => false, 'holds' => $turkish, 'locale' => $locale, 'rule' => $turkish ? $nested : null];
            yield 'a run of word characters, then a raw sign, in a loop, '.$locale => ['pattern' => "/(?:\\w+\xD7)+\$/", 'atoms' => ['\w', "\xD7"], 'flags' => '', 'subset' => false, 'holds' => false, 'locale' => $locale, 'rule' => null];
            yield 'a run of letters, then a comma, in a loop, '.$locale => ['pattern' => '/(?:[a-z]+,)+$/i', 'atoms' => ['[a-z]', ','], 'flags' => 'i', 'subset' => false, 'holds' => false, 'locale' => $locale, 'rule' => null];
            yield 'a run of anything but a comma, then a comma, in a loop, '.$locale => ['pattern' => '/(?:[^,]+,)+$/i', 'atoms' => ['[^,]', ','], 'flags' => 'i', 'subset' => false, 'holds' => false, 'locale' => $locale, 'rule' => null];
            yield 'a run of small i, then a group reading a capital I caselessly or a J, in a loop, '.$locale => ['pattern' => '/(?:i+(?:(?i)I|J))+$/', 'atoms' => ['i', '(?:(?i)I|J)'], 'flags' => '', 'subset' => false, 'holds' => !$turkish, 'locale' => $locale, 'rule' => $turkish ? null : $nested];
            yield 'a class of small letters and a capital I, as branches, '.$locale => ['pattern' => '/(?:[a-z]|I)+$/i', 'atoms' => ['[a-z]', 'I'], 'flags' => 'i', 'subset' => false, 'holds' => !$turkish, 'locale' => $locale, 'rule' => $turkish ? null : $charset];
            yield 'a class of small letters and a capital J, as branches, '.$locale => ['pattern' => '/(?:[a-z]|J)+$/i', 'atoms' => ['[a-z]', 'J'], 'flags' => 'i', 'subset' => false, 'holds' => true, 'locale' => $locale, 'rule' => $charset];
            yield 'the unrolled loop of a quoted text, '.$locale => ['pattern' => '/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/', 'atoms' => ['[^"\\\\]', '\\\\'], 'flags' => '', 'subset' => false, 'holds' => false, 'locale' => $locale, 'rule' => null];
        }

        yield 'a small i and 0xDD, as branches, '.self::GLIBC_TURKISH => ['pattern' => '/(?:i|\xDD)+$/i', 'atoms' => ['i', '\xDD'], 'flags' => 'i', 'subset' => false, 'holds' => true, 'locale' => self::GLIBC_TURKISH, 'rule' => $charset];
        yield 'a run of small i, then 0xDD, in a loop, '.self::GLIBC_TURKISH => ['pattern' => '/(?:i+\xDD)+$/i', 'atoms' => ['i', '\xDD'], 'flags' => 'i', 'subset' => false, 'holds' => true, 'locale' => self::GLIBC_TURKISH, 'rule' => $nested];

        // The Latin-1 tables pair 0xC9 with 0xE9, and 0xCA with 0xEA.
        foreach ([CharacterTables::BUILT_IN => false, self::LATIN1 => true] as $locale => $latin1) {
            yield 'a run of a Latin-1 capital, then a run of its small letter, '.$locale => ['pattern' => "/\xC9+\xE9+\$/i", 'atoms' => ["\xC9", "\xE9"], 'flags' => 'i', 'subset' => true, 'holds' => $latin1, 'locale' => $locale, 'rule' => $latin1 ? $concatenation : null];
            yield 'a run of a Latin-1 capital, then another small letter, '.$locale => ['pattern' => "/\xC9+\xEA+\$/i", 'atoms' => ["\xC9", "\xEA"], 'flags' => 'i', 'subset' => true, 'holds' => false, 'locale' => $locale, 'rule' => null];
        }

        yield 'a run of a Latin-1 capital, then another small letter, in a loop, '.self::LATIN1 => ['pattern' => "/(?:\xC9+\xEA)+\$/i", 'atoms' => ["\xC9", "\xEA"], 'flags' => 'i', 'subset' => false, 'holds' => false, 'locale' => self::LATIN1, 'rule' => null];
        yield 'a run of a Latin-1 capital, then its small letter, in a loop, '.self::LATIN1 => ['pattern' => "/(?:\xC9+\xE9)+\$/i", 'atoms' => ["\xC9", "\xE9"], 'flags' => 'i', 'subset' => false, 'holds' => true, 'locale' => self::LATIN1, 'rule' => $nested];
    }

    /**
     * Under tables a test gives, as Linux machines seldom have the locale:
     * glibc's tr_TR.ISO-8859-9, which pairs "i" with 0xDD and "I" with
     * 0xFD, and macOS's tr_TR.ISO8859-9, whose paths read "i", "I" and
     * 0xDD otherwise. Where the tables leave the case of a byte unsettled,
     * any caseless set holding it would not be known, a caseless "[a-z]"
     * among them: a pattern read caselessly is read in ASCII there, as
     * under C, where the JIT and the interpreter pair "i" with "I" alike,
     * and one read in its own case every byte. A branch the tables do not
     * decide, a byte written as it is or as an escape, opens on an empty
     * branch, which leaves the branches to the bytes the tables give
     * rather than to the engine, which no machine runs under them; the
     * other rules, which may ask it, are not run.
     *
     * @param list<string> $rules the rules that report the pattern
     */
    #[Test]
    #[DataProvider('provideRowsUnderGivenTables')]
    public function test_runs_read_their_characters_from_given_tables(string $pattern, string $locale, array $rules): void
    {
        self::giveTables();

        $this->assertSame($rules, self::reported($pattern, $locale, true), \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale));
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string, rules: list<string>}>
     */
    public static function provideRowsUnderGivenTables(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;
        $nested = NestedQuantifierRule::ID;
        $concatenation = QuantifierConcatenationRule::ID;

        $glibc = self::GIVEN_GLIBC;
        yield 'a small and a capital i, as branches, glibc' => ['pattern' => '/(?:i|I)+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a small and a capital i beside an empty branch, glibc' => ['pattern' => '/(?:|i|I)+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a small and a capital a, as branches, glibc' => ['pattern' => '/(?:a|A)+$/i', 'locale' => $glibc, 'rules' => [$text]];
        yield 'a class of small letters and a capital I, as branches, glibc' => ['pattern' => '/(?:[a-z]|I)+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a class of small letters and a capital J, as branches, glibc' => ['pattern' => '/(?:[a-z]|J)+$/i', 'locale' => $glibc, 'rules' => [$charset]];
        yield 'a small i and 0xDD, as branches, glibc' => ['pattern' => '/(?:|i|\xDD)+$/i', 'locale' => $glibc, 'rules' => [$charset]];
        yield 'a small i and a raw 0xDD, as branches, glibc' => ['pattern' => "/(?:|i|\xDD)+\$/i", 'locale' => $glibc, 'rules' => [$text]];
        yield 'a capital I and 0xFD, as branches, glibc' => ['pattern' => '/(?:|I|\xFD)+$/i', 'locale' => $glibc, 'rules' => [$charset]];
        yield 'a run of small i, then a capital, in a loop, glibc' => ['pattern' => '/(?:i+I)+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a run of small i, then 0xDD, in a loop, glibc' => ['pattern' => '/(?:i+\xDD)+$/i', 'locale' => $glibc, 'rules' => [$nested]];
        yield 'a run of small i, then a capital read caselessly, in a loop, glibc' => ['pattern' => '/(?:i+(?i:I))+$/', 'locale' => $glibc, 'rules' => []];
        yield 'a run of small i read caselessly, then 0xDD, in a loop, glibc' => ['pattern' => '/(?:(?i:i)+\xDD)+$/', 'locale' => $glibc, 'rules' => [$nested]];
        yield 'a run of small i, then a run of capitals, glibc' => ['pattern' => '/i+I+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a run of small i, then a run of 0xDD, glibc' => ['pattern' => '/i+\xDD+$/i', 'locale' => $glibc, 'rules' => [$concatenation]];
        yield 'a run of 0xFD, then a run of capital I, glibc' => ['pattern' => '/\xFD+I+$/i', 'locale' => $glibc, 'rules' => [$concatenation]];
        yield 'a run of small i, then a run of letters, glibc' => ['pattern' => '/i+[a-z\xDD]+$/i', 'locale' => $glibc, 'rules' => [$concatenation]];
        yield 'a run of small i, then a run of ASCII letters alone, glibc' => ['pattern' => '/i+[a-z]+$/i', 'locale' => $glibc, 'rules' => [$concatenation]];
        yield 'a run of capital I, then a run of small letters, glibc' => ['pattern' => '/I+[a-z]+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a run of 0xDD, then a run of word characters, glibc' => ['pattern' => '/\xDD+\w+$/', 'locale' => $glibc, 'rules' => [$concatenation]];
        yield 'a run of word characters, then a raw 0xDD, in a loop, glibc' => ['pattern' => "/(?:\\w+\xDD)+\$/", 'locale' => $glibc, 'rules' => [$nested]];
        yield 'a run of word characters, then a raw sign, in a loop, glibc' => ['pattern' => "/(?:\\w+\xD7)+\$/", 'locale' => $glibc, 'rules' => []];
        yield 'a run of anything but a comma, then a comma, in a loop, glibc' => ['pattern' => '/(?:[^,]+,)+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a run of anything but a small i, then 0xDD, in a loop, glibc' => ['pattern' => '/(?:[^i]+\xDD)+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a run of anything but a small i, then 0xFD, in a loop, glibc' => ['pattern' => '/(?:[^i]+\xFD)+$/i', 'locale' => $glibc, 'rules' => [$nested]];
        yield 'a comma, then a run of small i and a capital, in a loop, glibc' => ['pattern' => '/(?:,i+I)+$/i', 'locale' => $glibc, 'rules' => []];
        yield 'a run of small i, then a run of capitals read caselessly, in a loop, glibc' => ['pattern' => '/(?:i+(?i:I)+)+$/', 'locale' => $glibc, 'rules' => []];
        yield 'a run of small i under an option setting, then 0xDD, in a loop, glibc' => ['pattern' => '/(?:(?i)i+\xDD)+$/', 'locale' => $glibc, 'rules' => [$nested]];
        yield 'a run of word characters, then a sign written as an escape, in a loop, glibc' => ['pattern' => '/(?:\w+\xD7)+$/', 'locale' => $glibc, 'rules' => []];
        yield 'a run of 0xFF, then a run of the bytes below it, glibc' => ['pattern' => '/\xFF+[\xF0-\xFE]+$/', 'locale' => $glibc, 'rules' => []];
        yield 'a run of letters each guarded against 0xDD, then a run of a, glibc' => ['pattern' => '/(?:[a-z](?!\xDD))*a+$/', 'locale' => $glibc, 'rules' => [$concatenation]];

        $macos = self::GIVEN_MACOS;
        yield 'a small and a capital i, as branches, macOS' => ['pattern' => '/(?:|i|I)+$/i', 'locale' => $macos, 'rules' => [$text]];
        yield 'a run of small i, then a capital, in a loop, macOS' => ['pattern' => '/(?:i+I)+$/i', 'locale' => $macos, 'rules' => [$nested]];
        yield 'a run of small i, then a run of capitals, macOS' => ['pattern' => '/i+I+$/i', 'locale' => $macos, 'rules' => [$concatenation]];
        yield 'a run of letters, then a comma, in a loop, macOS' => ['pattern' => '/(?:[a-z]+,)+$/i', 'locale' => $macos, 'rules' => []];
        yield 'a run of word characters, then a raw 0xDD, in a loop, macOS' => ['pattern' => "/(?:\\w+\xDD)+\$/", 'locale' => $macos, 'rules' => [$nested]];
        yield 'a run of word characters, then a raw sign, in a loop, macOS' => ['pattern' => "/(?:\\w+\xD7)+\$/", 'locale' => $macos, 'rules' => []];
        yield 'a run of a, then a run of capital A, macOS' => ['pattern' => '/a+A+$/i', 'locale' => $macos, 'rules' => [$concatenation]];
        yield 'a run of a, then a capital B, in a loop, macOS' => ['pattern' => '/(?:a+B)+$/i', 'locale' => $macos, 'rules' => []];

        // Under UTF a byte above ASCII is part of a character, whose case
        // the tables do not give: the rules read as under C.
        yield 'a run of a, then a capital A, in a loop, under u, glibc' => ['pattern' => '/(?:a+A)+$/iu', 'locale' => $glibc, 'rules' => [$nested]];
        yield 'a run of a, then a run of capital A, under u, glibc' => ['pattern' => '/a+A+$/iu', 'locale' => $glibc, 'rules' => [$concatenation]];
    }

    /**
     * The rules of the comparison of runs that report the pattern under
     * the locale, run alone where $alone asks: the others may ask the
     * engine under tables no machine builds.
     *
     * @return list<string>
     */
    private static function reported(string $pattern, string $locale, bool $alone = false): array
    {
        $others = [];
        foreach ($alone ? (new LintRuleRegistry())->all() : [] as $rule) {
            foreach (array_diff($rule->getRuleIds(), self::RULES) as $id) {
                $others[$id] = false;
            }
        }

        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter($others, $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        return array_values(array_intersect(self::RULES, $ids));
    }

    /**
     * Gives the tables of GIVEN_GLIBC, read from glibc's
     * tr_TR.ISO-8859-9: ASCII's cases but for "i", paired with 0xDD, and
     * "I" with 0xFD; the Latin-5 capitals 0xC0 to 0xDE but 0xD7 and 0xDD
     * with the small letters 0x20 above; "\w" as that locale's. And the
     * tables of GIVEN_MACOS, read from macOS's tr_TR.ISO8859-9: ASCII's
     * cases, 0xDD lowered to "i" and 0xFD uppered to "I", the interpreter
     * reading "i", "I" and 0xDD otherwise.
     */
    private static function giveTables(): void
    {
        $glibc = [0x49 => 0xFD, 0xFD => 0x49, 0x69 => 0xDD, 0xDD => 0x69];
        $macos = [0xDD => 0x69, 0xFD => 0x49];
        for ($byte = 0xC0; $byte <= 0xDE; $byte++) {
            if (0xD7 !== $byte && 0xDD !== $byte) {
                $glibc[$byte] = $byte + 0x20;
                $glibc[$byte + 0x20] = $byte;
                $macos[$byte] = $byte + 0x20;
                $macos[$byte + 0x20] = $byte;
            }
        }

        $glibcCases = '';
        $macosCases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $ascii = Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte;
            $glibcCases .= \chr($glibc[$byte] ?? $ascii);
            $macosCases .= \chr($macos[$byte] ?? $ascii);
        }

        $words = ['w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xAA, 0xAA], [0xB5, 0xB5], [0xBA, 0xBA], [0xC0, 0xD6], [0xD8, 0xF6], [0xF8, 0xFF]]];
        CharacterTables::useTables(self::GIVEN_GLIBC, $glibcCases, [], null, $words);
        CharacterTables::useTables(self::GIVEN_MACOS, $macosCases, [0x49, 0x69, 0xDD], null, $words);
    }
}
