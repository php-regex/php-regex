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
use PHPRegex\Linter\Rule\OverlappingAlternationRule;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two branches of a loop overlap when one input starts both. The text a
 * branch starts with is read through an atomic group and a script run, and
 * two texts one of which starts the other are told apart by what follows
 * the shorter one: in "(?:a\d|aa)+" the second "a" is no digit, and no
 * input starts both branches. Two literal branches written alike are the
 * same branch twice, whatever their characters.
 *
 * Every verdict is the engine's (PCRE2 10.49, the interpreter, start
 * optimizations off): the branches are run alone on every subject of a
 * small alphabet, and the steps of the loop are the smallest backtrack
 * limit a failing attempt runs under. Doubling the subject doubles a linear
 * count, 23 -> 43 steps for "/^(?:a\d|aa)+$/" on "aa" 10 then 20 times and
 * "!", where the branches overlap it grows by more than a thousand,
 * 4,095 -> 4,194,303 for "/^(?:a\d|a1)+$/" on "a1".
 */
final class AlternationOverlapSharedStartTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * The locale of the tables a test gives.
     */
    private const GIVEN = 'xx_XX.FOLLOWS';

    /**
     * @param list<string> $branches the branches, each read alone under the pattern's flags
     * @param list<string> $alphabet the characters the subjects are written with
     */
    #[Test]
    #[DataProvider('provideBranchesNoInputStartsAlike')]
    public function test_branches_no_input_starts_alike_are_not_reported(string $pattern, array $branches, string $flags, array $alphabet): void
    {
        $this->assertBranchesAsTheEngineReadsThem(false, $pattern, $branches, $flags, $alphabet, CharacterTables::BUILT_IN);

        $this->assertSame([], self::findings($pattern, CharacterTables::BUILT_IN), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: list<string>, flags: string, alphabet: list<string>}>
     */
    public static function provideBranchesNoInputStartsAlike(): iterable
    {
        yield 'a text in an atomic group' => ['pattern' => '/^(?:(?>ab)|ac)+$/', 'branches' => ['(?>ab)', 'ac'], 'flags' => '', 'alphabet' => ['a', 'b', 'c']];
        yield 'a text in an atomic group, in a capture' => ['pattern' => '/^(?:((?>ab))|ac)+$/', 'branches' => ['((?>ab))', 'ac'], 'flags' => '', 'alphabet' => ['a', 'b', 'c']];
        yield 'a text in a script run' => ['pattern' => '/^(?:(*sr:öa)|öb)+$/u', 'branches' => ['(*sr:öa)', 'öb'], 'flags' => 'u', 'alphabet' => ['ö', 'a', 'b']];
        yield 'a text in an atomic script run' => ['pattern' => '/^(?:(*asr:öa)|öb)+$/u', 'branches' => ['(*asr:öa)', 'öb'], 'flags' => 'u', 'alphabet' => ['ö', 'a', 'b']];
        yield 'a text in an atomic group, in a script run' => ['pattern' => '/^(?:(*sr:(?>öa))|öb)+$/u', 'branches' => ['(*sr:(?>öa))', 'öb'], 'flags' => 'u', 'alphabet' => ['ö', 'a', 'b']];
        yield 'a text then a digit, beside a longer text' => ['pattern' => '/^(?:a\d|aa)+$/', 'branches' => ['a\d', 'aa'], 'flags' => '', 'alphabet' => ['a', '1']];
        yield 'a longer text, beside a text then a digit' => ['pattern' => '/^(?:aa|a\d)+$/', 'branches' => ['aa', 'a\d'], 'flags' => '', 'alphabet' => ['a', '1']];
        yield 'one text going on apart' => ['pattern' => '/^(?:a\d|a[a-z])+$/', 'branches' => ['a\d', 'a[a-z]'], 'flags' => '', 'alphabet' => ['a', '1']];
        yield 'a text then a repeated letter' => ['pattern' => '/^(?:ab+|ac)+$/', 'branches' => ['ab+', 'ac'], 'flags' => '', 'alphabet' => ['a', 'b', 'c']];
        yield 'a text then a group of letters' => ['pattern' => '/^(?:a(?:b|c)x|ad)+$/', 'branches' => ['a(?:b|c)x', 'ad'], 'flags' => '', 'alphabet' => ['a', 'b', 'd', 'x']];
        yield 'a text of two letters then a digit' => ['pattern' => '/^(?:ab\d|abc)+$/', 'branches' => ['ab\d', 'abc'], 'flags' => '', 'alphabet' => ['a', 'b', 'c', '1']];
        yield 'a text in an atomic group then a digit' => ['pattern' => '/^(?:(?>a)\d|aa)+$/', 'branches' => ['(?>a)\d', 'aa'], 'flags' => '', 'alphabet' => ['a', '1']];
        yield 'a text then a digit, beside a byte above ASCII' => ['pattern' => "/^(?:a\\d|a\xE9)+\$/", 'branches' => ['a\d', "a\xE9"], 'flags' => '', 'alphabet' => ['a', '1', "\xE9"]];
        // A negated class takes the characters above ASCII, and no "b".
        yield 'a text then anything but a letter, beside that letter' => ['pattern' => '/^(?:a[^b]|ab)+$/', 'branches' => ['a[^b]', 'ab'], 'flags' => '', 'alphabet' => ['a', 'b', 'c', "\xE9"]];
        yield 'a text then anything but the last character of ASCII, beside that character' => ['pattern' => '/^(?:a[^\x7F]|a\x7F)+$/', 'branches' => ['a[^\x7F]', 'a\x7F'], 'flags' => '', 'alphabet' => ['a', "\x7F", "\x80"]];
        yield 'a text then a letter of ASCII, beside a character above it, under u' => ['pattern' => '/^(?:a[b-d]|aé)+$/u', 'branches' => ['a[b-d]', 'aé'], 'flags' => 'u', 'alphabet' => ['a', 'b', 'é']];
        yield 'a text above ASCII then a letter, under u' => ['pattern' => '/^(?:é[b-d]|éa)+$/u', 'branches' => ['é[b-d]', 'éa'], 'flags' => 'u', 'alphabet' => ['é', 'a', 'b']];
    }

    /**
     * @param list<string> $branches the branches, each read alone under the pattern's flags
     * @param list<string> $alphabet the characters the subjects are written with
     */
    #[Test]
    #[DataProvider('provideBranchesAnInputStartsAlike')]
    public function test_branches_an_input_starts_alike_are_reported(string $pattern, array $branches, string $flags, array $alphabet, string $quoted): void
    {
        $this->assertBranchesAsTheEngineReadsThem(true, $pattern, $branches, $flags, $alphabet, CharacterTables::BUILT_IN);

        $findings = self::findings($pattern, CharacterTables::BUILT_IN);
        $this->assertSame([OverlappingAlternationRule::ID_CHARSET], array_keys($findings), $pattern);
        $this->assertStringContainsString($quoted, (string) reset($findings));
    }

    /**
     * @return iterable<string, array{pattern: string, branches: list<string>, flags: string, alphabet: list<string>, quoted: string}>
     */
    public static function provideBranchesAnInputStartsAlike(): iterable
    {
        yield 'a text then a digit, beside the text and a digit' => ['pattern' => '/^(?:a\d|a1)+$/', 'branches' => ['a\d', 'a1'], 'flags' => '', 'alphabet' => ['a', '1'], 'quoted' => '"a\d" and "a1"'];
        yield 'a text then a word character, beside a longer text' => ['pattern' => '/^(?:a\w|ab)+$/', 'branches' => ['a\w', 'ab'], 'flags' => '', 'alphabet' => ['a', 'b'], 'quoted' => '"a\w" and "ab"'];
        yield 'one text going on alike' => ['pattern' => '/^(?:a\d|a[1-3])+$/', 'branches' => ['a\d', 'a[1-3]'], 'flags' => '', 'alphabet' => ['a', '1'], 'quoted' => '"a\d" and "a[1-3]"'];
        yield 'a text then a letter that may be left out' => ['pattern' => '/^(?:ab?c|ac)+$/', 'branches' => ['ab?c', 'ac'], 'flags' => '', 'alphabet' => ['a', 'b', 'c'], 'quoted' => '"ab?c" and "ac"'];
        yield 'a text then a lookahead' => ['pattern' => '/^(?:a(?=c)c|ac)+$/', 'branches' => ['a(?=c)c', 'ac'], 'flags' => '', 'alphabet' => ['a', 'c'], 'quoted' => '"a(?=c)c" and "ac"'];
        yield 'a text in an atomic group, beside the same text' => ['pattern' => '/^(?:(?>ab)c|abc)+$/', 'branches' => ['(?>ab)c', 'abc'], 'flags' => '', 'alphabet' => ['a', 'b', 'c'], 'quoted' => '"(?>ab)c" and "abc"'];
        yield 'a text in a script run, beside the same text' => ['pattern' => '/^(?:(*sr:öa)|öa)+$/u', 'branches' => ['(*sr:öa)', 'öa'], 'flags' => 'u', 'alphabet' => ['ö', 'a'], 'quoted' => '"(*sr:öa)" and "öa"'];
        yield 'a text then anything but a letter, beside a byte above ASCII' => ['pattern' => "/^(?:a[^b]|a\xE9)+\$/", 'branches' => ['a[^b]', "a\xE9"], 'flags' => '', 'alphabet' => ['a', "\xE9"], 'quoted' => '"a[^b]" and "a\xE9"'];
        yield 'a text then anything but a letter, beside the first byte above ASCII' => ['pattern' => "/^(?:a[^b]|a\x80)+\$/", 'branches' => ['a[^b]', "a\x80"], 'flags' => '', 'alphabet' => ['a', "\x80"], 'quoted' => '"a[^b]" and "a\x80"'];
        yield 'a text then anything but a letter, beside the last byte' => ['pattern' => "/^(?:a[^b]|a\xFF)+\$/", 'branches' => ['a[^b]', "a\xFF"], 'flags' => '', 'alphabet' => ['a', "\xFF"], 'quoted' => '"a[^b]" and "a\xFF"'];
        yield 'a text then anything but a letter, beside a character above ASCII, under u' => ['pattern' => '/^(?:a[^b]|aé)+$/u', 'branches' => ['a[^b]', 'aé'], 'flags' => 'u', 'alphabet' => ['a', 'é'], 'quoted' => '"a[^b]" and "aé"'];
        // Read caselessly under u, "k" takes the Kelvin sign, which the
        // bytes of the class do not hold: what follows the text is not read.
        yield 'a text then a letter read caselessly, beside the text and the Kelvin sign, under u' => ['pattern' => "/^(?:a\u{212A}|a(?i)[k])+\$/u", 'branches' => ["a\u{212A}", 'a(?i)[k]'], 'flags' => 'u', 'alphabet' => ['a', "\u{212A}"], 'quoted' => "\"a\u{212A}\" and \"a(?i)[k]\""];
        yield 'a text then a digit, beside the text and a digit above ASCII, under u' => ['pattern' => '/^(?:a\d|a٣)+$/u', 'branches' => ['a\d', 'a٣'], 'flags' => 'u', 'alphabet' => ['a', '٣'], 'quoted' => '"a\d" and "a٣"'];
        yield 'a text then a class above ASCII, beside the text and one of its characters, under u' => ['pattern' => '/^(?:a[é-ë]|aê)+$/u', 'branches' => ['a[é-ë]', 'aê'], 'flags' => 'u', 'alphabet' => ['a', 'ê'], 'quoted' => '"a[é-ë]" and "aê"'];
    }

    /**
     * What follows a text is read under the tables of the target's locale:
     * under fr_FR.ISO8859-1 "\w" takes 0xE9, which it leaves under C.
     */
    #[Test]
    #[DataProvider('provideLocales')]
    public function test_what_follows_a_text_is_read_under_the_tables_of_the_target_locale(string $locale, bool $overlap): void
    {
        if (CharacterTables::BUILT_IN !== $locale) {
            $this->requiresLocale($locale, '/^(?:a\w|a\xE9)+$/');
        }

        $pattern = "/^(?:a\\w|a\xE9)+\$/";
        $this->assertBranchesAsTheEngineReadsThem($overlap, $pattern, ['a\w', "a\xE9"], '', ['a', "\xE9"], $locale);

        $this->assertSame($overlap ? [OverlappingAlternationRule::ID_CHARSET] : [], array_keys(self::findings($pattern, $locale)), $locale);
    }

    /**
     * @return iterable<string, array{locale: string, overlap: bool}>
     */
    public static function provideLocales(): iterable
    {
        yield 'PCRE2\'s own tables' => ['locale' => CharacterTables::BUILT_IN, 'overlap' => false];
        yield 'the tables of a Latin-1 locale' => ['locale' => self::LATIN1, 'overlap' => true];
    }

    /**
     * Under tables a test gives, whose "\w" takes 0xE9 as the Latin-1
     * tables' does: CI machines often hold no Latin-1 locale. The other
     * rules, which may ask an engine no machine runs under those tables,
     * are not run.
     */
    #[Test]
    #[DataProvider('provideBranchesUnderGivenTables')]
    public function test_what_follows_a_text_is_read_under_given_tables(string $pattern, bool $overlap): void
    {
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr(Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte);
        }

        CharacterTables::useTables(self::GIVEN, $cases, [], null, ['w' => [[0x30, 0x39], [0x41, 0x5A], [0x5F, 0x5F], [0x61, 0x7A], [0xE9, 0xE9]]]);

        $this->assertSame($overlap ? [OverlappingAlternationRule::ID_CHARSET] : [], array_keys(self::findings($pattern, self::GIVEN, true)), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, overlap: bool}>
     */
    public static function provideBranchesUnderGivenTables(): iterable
    {
        yield 'a text then a word character, beside a byte the tables give it' => ['pattern' => "/^(?:a\\w|a\xE9)+\$/", 'overlap' => true];
        yield 'a text then a word character, beside a byte the tables leave out' => ['pattern' => "/^(?:a\\w|a\xEA)+\$/", 'overlap' => false];
        yield 'a text then a word character, beside a letter of ASCII' => ['pattern' => '/^(?:a\w|ab)+$/', 'overlap' => true];
        yield 'a text then a digit, beside a letter of ASCII' => ['pattern' => '/^(?:a\d|ab)+$/', 'overlap' => false];
    }

    /**
     * Read caselessly, the texts alone tell two branches apart, and so they
     * do where the bytes of what follows a text are not known, as those of
     * "\d" under u, which holds digits above ASCII: what follows is not
     * read, and the report is a conservative one.
     *
     * @param list<string> $branches
     * @param list<string> $alphabet
     */
    #[Test]
    #[DataProvider('provideBranchesComparedByTheirTextsAlone')]
    public function test_branches_are_compared_by_their_texts_alone_where_what_follows_is_not_read(string $pattern, array $branches, string $flags, array $alphabet): void
    {
        $this->assertBranchesAsTheEngineReadsThem(false, $pattern, $branches, $flags, $alphabet, CharacterTables::BUILT_IN);

        $this->assertSame([OverlappingAlternationRule::ID_CHARSET], array_keys(self::findings($pattern, CharacterTables::BUILT_IN)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: list<string>, flags: string, alphabet: list<string>}>
     */
    public static function provideBranchesComparedByTheirTextsAlone(): iterable
    {
        yield 'a digit under u' => ['pattern' => '/^(?:a\d|aa)+$/u', 'branches' => ['a\d', 'aa'], 'flags' => 'u', 'alphabet' => ['a', '1', '٣']];
        yield 'under i' => ['pattern' => '/^(?:a\d|aa)+$/i', 'branches' => ['a\d', 'aa'], 'flags' => 'i', 'alphabet' => ['a', 'A', '1']];
        yield 'the shorter text read caselessly' => ['pattern' => '/^(?:(?i:a)\d|aa)+$/', 'branches' => ['(?i:a)\d', 'aa'], 'flags' => '', 'alphabet' => ['a', 'A', '1']];
        yield 'the longer text read caselessly' => ['pattern' => '/^(?:a\d|(?i:aa))+$/', 'branches' => ['a\d', '(?i:aa)'], 'flags' => '', 'alphabet' => ['a', 'A', '1']];
        yield 'what follows the text read caselessly' => ['pattern' => '/^(?:a(?i:[b-d])|aa)+$/', 'branches' => ['a(?i:[b-d])', 'aa'], 'flags' => '', 'alphabet' => ['a', 'b', 'B']];
        yield 'what follows the text read under an option setting' => ['pattern' => '/^(?:a(?i)[b-d]|aa)+$/', 'branches' => ['a(?i)[b-d]', '(?i)aa'], 'flags' => '', 'alphabet' => ['a', 'b', 'B']];
    }

    /**
     * Two literal branches written alike are one branch twice: every input
     * one takes, the other takes. Past ASCII under u, beside a branch that
     * may match nothing, the engine is not asked what the branches start
     * with and their first bytes tell nothing: the texts say it.
     */
    #[Test]
    #[DataProvider('provideLiteralBranchesWrittenTwice')]
    public function test_a_literal_branch_written_twice_is_reported(string $pattern, string $unit, string $quoted): void
    {
        $short = self::steps($pattern, str_repeat($unit, 5).'!');
        $long = self::steps($pattern, str_repeat($unit, 10).'!');
        $this->assertGreaterThan(8 * $short, $long, \sprintf('Oracle: %s takes %d then %d steps.', $pattern, $short, $long));

        $findings = self::findings($pattern, CharacterTables::BUILT_IN);
        $this->assertSame([OverlappingAlternationRule::ID_CHARSET], array_keys($findings), $pattern);
        $this->assertSame(\sprintf('Alternation branches %s have overlapping character sets, which may cause unnecessary backtracking.', $quoted), reset($findings));
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, quoted: string}>
     */
    public static function provideLiteralBranchesWrittenTwice(): iterable
    {
        yield 'a character above ASCII beside an empty branch, under u' => ['pattern' => '/^(?:é|é|)+$/u', 'unit' => 'é', 'quoted' => '"é" and "é"'];
        yield 'a capital above ASCII beside an empty branch, under iu' => ['pattern' => '/^(?:É|É|)+$/iu', 'unit' => 'é', 'quoted' => '"É" and "É"'];
        yield 'a text above ASCII beside an empty branch, under u' => ['pattern' => '/^(?:éa|éa|)+$/u', 'unit' => 'éa', 'quoted' => '"éa" and "éa"'];
        yield 'a character above ASCII and its escape beside an empty branch, under u' => ['pattern' => '/^(?:é|\x{E9}|)+$/u', 'unit' => 'é', 'quoted' => '"é" and "\x{E9}"'];
        yield 'a character above ASCII and its atomic group beside an empty branch, under u' => ['pattern' => '/^(?:(?>é)|é|)+$/u', 'unit' => 'é', 'quoted' => '"(?>é)" and "é"'];
        yield 'the first of the branches written twice, with the next one written as it is' => ['pattern' => '/^(?:é|è|è|é|é|)+$/u', 'unit' => 'é', 'quoted' => '"é" and "é"'];
        yield 'the first of the branches written twice, whatever comes between' => ['pattern' => '/^(?:é|è|x|è|ê|é|)+$/u', 'unit' => 'é', 'quoted' => '"é" and "é"'];
        yield 'a letter of ASCII beside an empty branch' => ['pattern' => '/^(?:a|a|)+$/', 'unit' => 'a', 'quoted' => '"a" and "a"'];
        yield 'a character above ASCII behind a lookahead that is not read, under u' => ['pattern' => '/^(?:(?=\X)x|é|é)+$/u', 'unit' => 'é', 'quoted' => '"é" and "é"'];
    }

    /**
     * The second pair written twice is quoted with its own places: "è" at
     * the second and the fourth place, not the first "é".
     */
    #[Test]
    public function test_the_branch_written_twice_is_quoted_from_its_first_place(): void
    {
        $pattern = '/^(?:éa|è|x|è|)+$/u';
        $short = self::steps($pattern, str_repeat('è', 5).'!');
        $long = self::steps($pattern, str_repeat('è', 10).'!');
        $this->assertGreaterThan(8 * $short, $long);

        $this->assertSame([OverlappingAlternationRule::ID_CHARSET => 'Alternation branches "è" and "è" have overlapping character sets, which may cause unnecessary backtracking.'], self::findings($pattern, CharacterTables::BUILT_IN));
    }

    /**
     * @param list<string> $branches
     * @param list<string> $alphabet
     */
    #[Test]
    #[DataProvider('provideLiteralBranchesWrittenApart')]
    public function test_literal_branches_past_ascii_written_apart_are_not_reported(string $pattern, array $branches, string $flags, array $alphabet): void
    {
        $this->assertBranchesAsTheEngineReadsThem(false, $pattern, $branches, $flags, $alphabet, CharacterTables::BUILT_IN);

        $this->assertSame([], self::findings($pattern, CharacterTables::BUILT_IN), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, branches: list<string>, flags: string, alphabet: list<string>}>
     */
    public static function provideLiteralBranchesWrittenApart(): iterable
    {
        // "é" is C3 A9 and "è" C3 A8: one lead byte, two characters.
        yield 'two characters sharing a lead byte beside an empty branch' => ['pattern' => '/^(?:é|è|)+$/u', 'branches' => ['é', 'è'], 'flags' => 'u', 'alphabet' => ['é', 'è']];
        yield 'two texts told apart beside an empty branch' => ['pattern' => '/^(?:éa|éb|)+$/u', 'branches' => ['éa', 'éb'], 'flags' => 'u', 'alphabet' => ['é', 'a', 'b']];
        yield 'a character and its capital, each in its own case, beside an empty branch' => ['pattern' => '/^(?:é|É|)+$/u', 'branches' => ['é', 'É'], 'flags' => 'u', 'alphabet' => ['é', 'É']];
    }

    /**
     * The oracle: whether one subject written with the alphabet starts two
     * of the branches, each read alone, on the JIT where it is on and on
     * the interpreter; every branch starts one. Then the loop itself: on
     * each subject one turn of it reads whole, repeated 6 then 12 times
     * before a character no branch takes, its steps double where no input
     * starts two branches and grow much faster where one does.
     *
     * @param list<string> $branches
     * @param list<string> $alphabet
     */
    private function assertBranchesAsTheEngineReadsThem(bool $overlap, string $pattern, array $branches, string $flags, array $alphabet, string $locale): void
    {
        $label = \sprintf('%s under %s', addcslashes($pattern, "\200..\377"), $locale);
        $subjects = $longest = [''];
        for ($length = 1; $length <= 4; $length++) {
            $longer = [];
            foreach ($longest as $subject) {
                foreach ($alphabet as $character) {
                    $longer[] = $subject.$character;
                }
            }

            $subjects = [...$subjects, ...$longer];
            $longest = $longer;
        }

        $alternation = '(?:'.implode('|', $branches).')';
        $shared = new \ArrayObject();
        $started = new \ArrayObject(array_fill(0, \count($branches), false));
        $steps = new \ArrayObject();
        CharacterTables::under($locale, static function () use ($pattern, $alternation, $branches, $flags, $subjects, $shared, $started, $steps): void {
            foreach ($subjects as $subject) {
                foreach ([static fn (string $regex): string => $regex, PcreInterpreter::pattern(...)] as $engine) {
                    $starting = 0;
                    foreach ($branches as $index => $branch) {
                        if (1 === preg_match($engine('/^(?:'.$branch.')/'.$flags), $subject)) {
                            $starting++;
                            $started[$index] = true;
                        }
                    }

                    if ($starting > 1 && !isset($shared[0])) {
                        $shared[] = $subject;
                    }
                }

                // Each subject one turn of the loop reads whole, repeated.
                if (1 === preg_match('/^'.$alternation.'$/D'.$flags, $subject)) {
                    $steps[] = [self::steps($pattern, str_repeat($subject, 6).'!'), self::steps($pattern, str_repeat($subject, 12).'!')];
                }
            }
        });

        $this->assertNotContains(false, $started->getArrayCopy(), 'Oracle: every branch of '.$label.' starts a subject.');
        $this->assertNotSame([], $steps->getArrayCopy(), 'Oracle: one turn of the loop of '.$label.' reads a subject whole.');
        $both = $shared->getArrayCopy();
        $this->assertSame($overlap, [] !== $both, \sprintf('Oracle: %s, whose branches %s.', $label, [] === $both ? 'start no subject alike' : 'both start '.addcslashes(var_export($both, true), "\200..\377")));

        $growth = 0.0;
        foreach ($steps as [$short, $long]) {
            $growth = max($growth, $long / $short);
        }

        $this->assertSame($overlap, $growth > 8, \sprintf('Oracle: %s takes %s steps.', $label, json_encode($steps->getArrayCopy())));
    }

    /**
     * The smallest backtrack limit the match attempt runs under: the
     * engine's count of steps for one start, in the interpreter, start
     * optimizations off.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $limit = ini_get('pcre.backtrack_limit');
        $unoptimized = PcreInterpreter::pattern($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));

        try {
            $low = 1;
            $high = 1 << 24;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($unoptimized, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
    }

    /**
     * The findings of the rules of overlapping branches, keyed by rule,
     * those rules run alone where $alone asks.
     *
     * @return array<string, string>
     */
    private static function findings(string $pattern, string $locale, bool $alone = false): array
    {
        $others = [];
        foreach ($alone ? (new LintRuleRegistry())->all() : [] as $rule) {
            foreach (array_diff($rule->getRuleIds(), [OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET]) as $id) {
                $others[$id] = false;
            }
        }

        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter($regex->target(), $others);
        $regex->parse($pattern)->accept($linter);

        $findings = [];
        foreach ($linter->getIssues() as $issue) {
            if (\in_array($issue->id, [OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET], true)) {
                $findings[$issue->id] = $issue->message;
            }
        }

        return $findings;
    }
}
