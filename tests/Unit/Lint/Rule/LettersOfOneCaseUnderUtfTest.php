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
use PHPRegex\Linter\Rule\GroupIndex;
use PHPRegex\Linter\Rule\LintContext;
use PHPRegex\Linter\Rule\NestedQuantifierRule;
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Linter\Rule\PatternInfo;
use PHPRegex\Linter\Rule\QuantifierConcatenationRule;
use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Under UTF, PCRE2 compares a letter of ASCII written alone, "i", "\x69",
 * "[i]" or "[^i]", by the tables of the locale it compiles under, where a
 * class of several characters and a back-reference read Unicode's cases.
 * Under glibc's tr_TR.UTF-8, whose tables give "i" and "I" no other case,
 * "/^I$/iu" does not match "i" and "/^[IJ]$/iu" does: "(?:i|I)+$",
 * "(?:i+I)+$" and "i+I+$" are no overlap, no nested loop and no pair of
 * runs one of which holds the other, while "(?:[ij]|I)+$" and
 * "(?:[ij]+I)+$" are, and "(?:[^i]+I)+$", whose class takes "I" there, is
 * a nested loop it is not under C. "k" and "s" keep both cases, Unicode
 * pairing them with the Kelvin sign and the long s whatever the tables say.
 *
 * Engine, glibc's tr_TR.UTF-8 (Debian, localedef): PHP 8.2.36 with PCRE2
 * 10.40, 8.3.37 with 10.42, 8.4.28, 8.5.13 and 8.6.0 with 10.44, the JIT
 * on and off; and macOS's kk_KZ.PT154, whose tables pair no letter, PHP
 * 8.4.28 with PCRE2 10.49. On each, read alone under iu, "i" and "I" match
 * themselves alone and "k" matches "K"; "[ij]" takes "I", "[^i]" takes
 * "I", "(i)\1" matches "iI". On "i" 8 then 16 times and "!", the
 * interpreter takes 19 then 35 steps for "/^(?:i|I)+$/iu" (1,023 then
 * 262,143 under C) and 2 then 2 for "/^(?:i+I)+$/iu" (68 then 3,194 under
 * C); on "I", 89 then 4,181 for "/^(?:[^i]+I)+$/iu" (2 then 2 under C).
 * "/^i+I+$/iu" matches "iiI", which "/^iI+$/iu" does not: the run cannot
 * be tightened there.
 */
final class LettersOfOneCaseUnderUtfTest extends TestCase
{
    use InstalledLocales;

    /**
     * The locales whose tables may give a letter no other case: glibc's
     * tr_TR.UTF-8 leaves "i" and "I" alone, macOS's pairs them; macOS's
     * kk_KZ.PT154 pairs no letter. The engine says which, row by row.
     */
    private const LOCALES = [CharacterTables::BUILT_IN, 'tr_TR.UTF-8', 'kk_KZ.PT154'];

    /**
     * The tables a test gives, ASCII's cases but for "i" and "I", which
     * have no other case, as under glibc's tr_TR.UTF-8.
     */
    private const GIVEN = 'xx_XX.UTF8TR';

    private const RULES = [
        OverlappingAlternationRule::ID_OVERLAP,
        OverlappingAlternationRule::ID_CHARSET,
        NestedQuantifierRule::ID,
        QuantifierConcatenationRule::ID,
    ];

    /**
     * The characters the atoms are tried on: the letters, the characters
     * above ASCII that Unicode or Turkish pair them with, and others.
     */
    private const CANDIDATES = ['i', 'I', 'j', 'J', 'k', 'K', 'x', 'X', "\u{212A}", "\u{17F}", "\u{130}", "\u{131}", 'é', '1', ' '];

    /**
     * @param array{string, string} $atoms the atoms the rule compares, each read alone under iu
     * @param string                $asks  what the rule asks of them: "meet" where it reports two atoms that take a
     *                                     character in common, "held" where it reports a first atom that takes no
     *                                     character the second does not
     * @param string                $rule  the rule that reports the pattern where the engine answers yes
     */
    #[Test]
    #[DataProvider('provideRowsTheEngineDecides')]
    public function test_a_letter_written_alone_is_read_as_the_engine_reads_it_under_the_locale(string $pattern, array $atoms, string $asks, string $rule, string $locale): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        // Oracle: the characters each atom takes alone under the locale,
        // by the JIT where it is on and by the interpreter.
        $taken = ['' => [[], []], '(*NO_JIT)' => [[], []]];
        CharacterTables::under($locale, static function () use ($atoms, &$taken): void {
            foreach (array_keys($taken) as $path) {
                foreach ($atoms as $index => $atom) {
                    foreach (self::CANDIDATES as $character) {
                        if (1 === preg_match('/'.$path.'^(?:'.$atom.')$/iu', $character)) {
                            $taken[$path][$index][] = $character;
                        }
                    }
                }
            }
        });

        $label = \sprintf('%s under %s', $pattern, $locale);
        $answers = [];
        foreach ($taken as [$first, $second]) {
            $answers[] = 'meet' === $asks ? [] !== array_intersect($first, $second) : [] === array_diff($first, $second);
        }

        $this->assertCount(1, array_unique($answers), \sprintf('Oracle: both paths of the engine read %s alike, taking %s.', $label, json_encode($taken)));

        $this->assertSame($answers[0] ? [$rule] : [], self::reported($pattern, $locale), $label);
    }

    /**
     * @return iterable<string, array{pattern: string, atoms: array{string, string}, asks: string, rule: string, locale: string}>
     */
    public static function provideRowsTheEngineDecides(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;
        $nested = NestedQuantifierRule::ID;
        $runs = QuantifierConcatenationRule::ID;

        $rows = [
            'a small and a capital i, as branches' => ['/(?:i|I)+$/iu', ['i', 'I'], 'meet', $text],
            'a small and a capital j, as branches' => ['/(?:j|J)+$/iu', ['j', 'J'], 'meet', $text],
            'a small and a capital k, as branches' => ['/(?:k|K)+$/iu', ['k', 'K'], 'meet', $text],
            'texts a small and a capital i start, as branches' => ['/(?:ix\d|Ix\w)+$/iu', ['i', 'I'], 'meet', $charset],
            'a class of two letters and a capital I, as branches' => ['/(?:[ij]|I)+$/iu', ['[ij]', 'I'], 'meet', $charset],
            'a class of a small i alone and a capital I, as branches' => ['/(?:[i]|I)+$/iu', ['[i]', 'I'], 'meet', $charset],
            'an escaped small i and a capital I, as branches' => ['/(?:\x69\d|I)+$/iu', ['\x69', 'I'], 'meet', $charset],
            'anything but a small i and a capital I, as branches' => ['/(?:[^i]|I)+$/iu', ['[^i]', 'I'], 'meet', $charset],
            'anything but a small i and a small i, as branches' => ['/(?:[^i]|i)+$/iu', ['[^i]', 'i'], 'meet', $charset],
            'a run of small i, then a capital, in a loop' => ['/(?:i+I)+$/iu', ['i', 'I'], 'meet', $nested],
            'a run of capital I, then a small i, in a loop' => ['/(?:I+i)+$/iu', ['I', 'i'], 'meet', $nested],
            'a run of small k, then a capital, in a loop' => ['/(?:k+K)+$/iu', ['k', 'K'], 'meet', $nested],
            'a run of small i, then a class of two capitals, in a loop' => ['/(?:i+[IJ])+$/iu', ['i', '[IJ]'], 'meet', $nested],
            'a run of two letters, then a capital I, in a loop' => ['/(?:[ij]+I)+$/iu', ['[ij]', 'I'], 'meet', $nested],
            'a run of small i, then a class of a capital I alone, in a loop' => ['/(?:i+[I])+$/iu', ['i', '[I]'], 'meet', $nested],
            'a run of small i, then an escaped capital I, in a loop' => ['/(?:i+\x{49})+$/iu', ['i', '\x{49}'], 'meet', $nested],
            'a run of small i in a group, then a capital, in a loop' => ['/(?:(?:i)+I)+$/iu', ['(?:i)', 'I'], 'meet', $nested],
            'a run of anything but a small i, then a capital I, in a loop' => ['/(?:[^i]+I)+$/iu', ['[^i]', 'I'], 'meet', $nested],
            'a run of capital I, then anything but a small i, in a loop' => ['/(?:I+[^i])+$/iu', ['I', '[^i]'], 'meet', $nested],
            'a run of small i, then a run of capitals' => ['/i+I+$/iu', ['i', 'I'], 'held', $runs],
            'a run of capital I, then a run of small i' => ['/I+i+$/iu', ['I', 'i'], 'held', $runs],
            'a run of small k, then a run of capitals' => ['/k+K+$/iu', ['k', 'K'], 'held', $runs],
            'a run of small i, then a run of two letters' => ['/i+[ij]+$/iu', ['i', '[ij]'], 'held', $runs],
            'a run of capital I, then a run of two small letters' => ['/I+[ij]+$/iu', ['I', '[ij]'], 'held', $runs],
            'a run of small i, then a run of a class of a capital I alone' => ['/i+[I]+$/iu', ['i', '[I]'], 'held', $runs],
            'a run of an escaped small i, then a run of capitals' => ['/\x69+I+$/iu', ['\x69', 'I'], 'held', $runs],
            'a run of small i in a group, then a run of capitals' => ['/(?:i)+I+$/iu', ['(?:i)', 'I'], 'held', $runs],
            'a run of anything but a capital I, then a run of anything but a small i' => ['/[^I]+[^i]+$/iu', ['[^I]', '[^i]'], 'held', $runs],
        ];

        foreach (self::LOCALES as $locale) {
            foreach ($rows as $name => [$pattern, $atoms, $asks, $rule]) {
                yield $name.', '.$locale => ['pattern' => $pattern, 'atoms' => $atoms, 'asks' => $asks, 'rule' => $rule, 'locale' => $locale];
            }
        }
    }

    /**
     * Under tables a test gives, as few machines hold a locale that gives
     * a letter no other case: "i" and "I" match themselves alone there,
     * every other letter both its cases.
     *
     * @param list<string> $rules the rules that report the pattern
     */
    #[Test]
    #[DataProvider('provideRowsUnderGivenTables')]
    public function test_a_letter_written_alone_is_read_from_given_tables(string $pattern, array $rules): void
    {
        self::giveTables(self::GIVEN, [0x49 => 0x49, 0x69 => 0x69]);

        $this->assertSame($rules, self::reported($pattern, self::GIVEN), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, rules: list<string>}>
     */
    public static function provideRowsUnderGivenTables(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;
        $nested = NestedQuantifierRule::ID;
        $runs = QuantifierConcatenationRule::ID;

        yield 'a small and a capital i, as branches' => ['pattern' => '/(?:i|I)+$/iu', 'rules' => []];
        yield 'a small and a capital i beside an empty branch' => ['pattern' => '/(?:|i|I)+$/iu', 'rules' => []];
        yield 'a small and a capital j, as branches' => ['pattern' => '/(?:j|J)+$/iu', 'rules' => [$text]];
        yield 'a small and a capital k, as branches' => ['pattern' => '/(?:k|K)+$/iu', 'rules' => [$text]];
        yield 'a small and a capital s, as branches' => ['pattern' => '/(?:s|S)+$/iu', 'rules' => [$text]];
        yield 'texts a small and a capital i start, as branches' => ['pattern' => '/(?:ix\d|Ix\w)+$/iu', 'rules' => []];
        yield 'texts a small and a capital j start, as branches' => ['pattern' => '/(?:jx\d|Jx\w)+$/iu', 'rules' => [$charset]];
        yield 'texts whose second letters are a small and a capital i, as branches' => ['pattern' => '/(?:xi\d|xI\w)+$/iu', 'rules' => []];
        yield 'a text the Kelvin sign starts and one a small k starts, as branches' => ['pattern' => "/(?:\u{212A}i\\d|ki\\w)+\$/iu", 'rules' => [$charset]];
        yield 'a text read in its own case, then caselessly, beside its capital' => ['pattern' => '/(?:i(?i)i\d|iI\w)+$/u', 'rules' => []];
        yield 'a small i in its own case and a capital read caselessly, as branches' => ['pattern' => '/(?:i|(?i:I))+$/u', 'rules' => []];
        yield 'a class of two letters and a capital I, as branches' => ['pattern' => '/(?:[ij]|I)+$/iu', 'rules' => [$charset]];
        yield 'a class of a small i alone and a capital I, as branches' => ['pattern' => '/(?:[i]|I)+$/iu', 'rules' => []];
        yield 'an escaped small i and a capital I, as branches' => ['pattern' => '/(?:\x69\d|I)+$/iu', 'rules' => []];
        yield 'anything but a small i and a capital I, as branches' => ['pattern' => '/(?:[^i]|I)+$/iu', 'rules' => [$charset]];
        yield 'anything but a small i and a small i, as branches' => ['pattern' => '/(?:[^i]|i)+$/iu', 'rules' => []];
        yield 'anything but a small j and a capital J, as branches' => ['pattern' => '/(?:[^j]|J)+$/iu', 'rules' => []];
        yield 'anything but two letters and a capital I, as branches' => ['pattern' => '/(?:[^ij]|I)+$/iu', 'rules' => []];
        yield 'a small i beside an empty branch and a class holding its capital' => ['pattern' => '/(?:|i|[IJ])+$/iu', 'rules' => [$charset]];
        yield 'a run of small i, then a capital, in a loop' => ['pattern' => '/(?:i+I)+$/iu', 'rules' => []];
        yield 'a run of capital I, then a small i, in a loop' => ['pattern' => '/(?:I+i)+$/iu', 'rules' => []];
        yield 'a run of small j, then a capital, in a loop' => ['pattern' => '/(?:j+J)+$/iu', 'rules' => [$nested]];
        yield 'a run of small k, then a capital, in a loop' => ['pattern' => '/(?:k+K)+$/iu', 'rules' => [$nested]];
        yield 'a run of small i, then a class of two capitals, in a loop' => ['pattern' => '/(?:i+[IJ])+$/iu', 'rules' => [$nested]];
        yield 'a run of two letters, then a capital I, in a loop' => ['pattern' => '/(?:[ij]+I)+$/iu', 'rules' => [$nested]];
        yield 'a run of small i, then a class of a capital I alone, in a loop' => ['pattern' => '/(?:i+[I])+$/iu', 'rules' => []];
        yield 'a run of small i, then an escaped capital I, in a loop' => ['pattern' => '/(?:i+\x{49})+$/iu', 'rules' => []];
        yield 'a run of small i in a group, then a capital, in a loop' => ['pattern' => '/(?:(?:i)+I)+$/iu', 'rules' => []];
        yield 'a run of small i read caselessly, then a capital, in a loop' => ['pattern' => '/(?:(?i:i)+I)+$/u', 'rules' => []];
        yield 'a run of small i, then a capital read caselessly, in a loop' => ['pattern' => '/(?:i+(?i:I))+$/u', 'rules' => []];
        yield 'a run of anything but a small i, then a capital I, in a loop' => ['pattern' => '/(?:[^i]+I)+$/iu', 'rules' => [$nested]];
        yield 'a run of anything but a small j, then a capital J, in a loop' => ['pattern' => '/(?:[^j]+J)+$/iu', 'rules' => []];
        yield 'a run of capital I, then anything but a small i, in a loop' => ['pattern' => '/(?:I+[^i])+$/iu', 'rules' => [$nested]];
        yield 'a run of anything but two letters, then a capital I, in a loop' => ['pattern' => '/(?:[^ij]+I)+$/iu', 'rules' => []];
        yield 'a run of small i, then a run of capitals' => ['pattern' => '/i+I+$/iu', 'rules' => []];
        yield 'a run of capital I, then a run of small i' => ['pattern' => '/I+i+$/iu', 'rules' => []];
        yield 'a run of small j, then a run of capitals' => ['pattern' => '/j+J+$/iu', 'rules' => [$runs]];
        yield 'a run of small i, then a run of two letters' => ['pattern' => '/i+[ij]+$/iu', 'rules' => [$runs]];
        yield 'a run of capital I, then a run of two small letters' => ['pattern' => '/I+[ij]+$/iu', 'rules' => [$runs]];
        yield 'a run of small i, then a run of a class of a capital I alone' => ['pattern' => '/i+[I]+$/iu', 'rules' => []];
        yield 'a run of an escaped small i, then a run of capitals' => ['pattern' => '/\x69+I+$/iu', 'rules' => []];
        yield 'a run of small i in a group, then a run of capitals' => ['pattern' => '/(?:i)+I+$/iu', 'rules' => []];
        yield 'a run of anything but a capital I, then a run of anything but a small i' => ['pattern' => '/[^I]+[^i]+$/iu', 'rules' => []];
        yield 'a run of anything but a capital J, then a run of anything but a small j' => ['pattern' => '/[^J]+[^j]+$/iu', 'rules' => [$runs]];
        // Without UTF the rules read every byte under the tables.
        yield 'a small and a capital i, as branches, without u' => ['pattern' => '/(?:i|I)+$/i', 'rules' => []];
        yield 'a run of small i, then a run of capitals, without u' => ['pattern' => '/i+I+$/i', 'rules' => []];
    }

    /**
     * A letter matches itself alone where the tables settle its case and
     * its capital's, and pair neither with the other nor both with one
     * byte: the tables given pair each of five letters otherwise.
     */
    #[Test]
    public function test_the_letters_of_one_case_are_those_the_tables_pair_with_no_case_of_theirs(): void
    {
        self::giveTables('xx_XX.PAIRS', [
            // "i" and "I" have no other case; "h" and "H" have another byte each.
            0x49 => 0x49, 0x69 => 0x69, 0x48 => 0xC8, 0xC8 => 0x48, 0x68 => 0xE8, 0xE8 => 0x68,
            // "l" has its capital, which has another byte; "m" another byte, whose capital has it.
            0x4C => 0xCC, 0xCC => 0x4C, 0x6D => 0xED, 0xED => 0x6D,
            // "n" and "N" have one byte both.
            0x4E => 0xD1, 0x6E => 0xD1,
            // "k" and "s" have no other case, which Unicode gives them.
            0x4B => 0x4B, 0x6B => 0x6B, 0x53 => 0x53, 0x73 => 0x73,
            // "o" and "p" have none either, but the engine's paths read
            // the capital of one and the small letter of the other apart.
            0x4F => 0x4F, 0x6F => 0x6F, 0x50 => 0x50, 0x70 => 0x70,
        ], [0x4F, 0x70]);

        $letters = self::context('/x/iu', 'xx_XX.PAIRS')->lettersOfOneCase();
        $this->assertInstanceOf(ByteCharSet::class, $letters);
        $this->assertSame([[0x48, 0x49], [0x68, 0x69]], $letters->ranges());

        $this->assertNull(self::context('/x/i', 'xx_XX.PAIRS')->lettersOfOneCase());
        $this->assertNull(self::context('/x/iu', CharacterTables::BUILT_IN)->lettersOfOneCase());

        self::giveTables('xx_XX.ASCII', []);
        $this->assertNull(self::context('/x/iu', 'xx_XX.ASCII')->lettersOfOneCase());
    }

    /**
     * A (*UTF) the pattern opens with reads the letters as u does.
     */
    #[Test]
    public function test_a_pattern_that_turns_utf_on_reads_the_letters_as_under_u(): void
    {
        self::giveTables(self::GIVEN, [0x49 => 0x49, 0x69 => 0x69]);

        $this->assertSame([], self::reported('/(*UTF)(?:i+I)+$/i', self::GIVEN));
        $this->assertSame([NestedQuantifierRule::ID], self::reported('/(*UTF)(?:j+J)+$/i', self::GIVEN));
    }

    /**
     * The rules of the comparison of runs and branches that report the
     * pattern under the locale.
     *
     * @return list<string>
     */
    private static function reported(string $pattern, string $locale): array
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter($regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        return array_values(array_intersect(self::RULES, $ids));
    }

    private static function context(string $pattern, string $locale): LintContext
    {
        $tree = RegexParser::create(['cache' => null])->parse($pattern);

        return new LintContext(
            new PatternInfo($tree->flags, $tree->delimiter, '', $tree->isUnicode()),
            new GroupIndex(0, [], [], [], false),
            CharSetAnalyzer::forRegex($tree),
            null,
            null,
            Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale])->target(),
        );
    }

    /**
     * Gives tables under a name: ASCII's cases but for the bytes given.
     *
     * @param array<int, int> $cases     the other case of a byte, by byte
     * @param list<int>       $unsettled the bytes whose case the engine's paths read otherwise
     */
    private static function giveTables(string $locale, array $cases, array $unsettled = []): void
    {
        $table = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $table .= \chr($cases[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        CharacterTables::useTables($locale, $table, $unsettled);
    }
}
