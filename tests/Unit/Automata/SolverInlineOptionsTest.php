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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Hir\ClassHir;
use PHPRegex\Parser\Hir\ConditionalHir;
use PHPRegex\Parser\Hir\Hir;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Hir\LiteralHir;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The solver reads the inline options as PCRE does: r per scope, the ASCII
 * options a, aD, aS, aW, aP and aT per scope and carried across
 * alternatives, "(?^)" clearing r and keeping U and the ASCII options,
 * "(?-a)" clearing every ASCII option, and an option set in a conditional's
 * yes branch holding in its no branch.
 *
 * The oracle for every answer: full-match membership on the running engine
 * (pcre.jit 0) of every string of one or two characters over an alphabet
 * that holds the characters each option moves: k, K, the Kelvin sign,
 * U+0663, U+00A0, U+00E9, U+00AA, U+0085, ASCII digits, letters and spaces.
 * Engine: PHP 8.4.26, PCRE2 10.49. PCRE2 before 10.43 refuses "(?r)" and
 * the ASCII options: a row it cannot compile says so and stops.
 */
final class SolverInlineOptionsTest extends TestCase
{
    private const ALPHABET = [
        'a', 'A', 'b', 'B', 'c', 'k', 'K', "\u{212A}", 'x', 'y', '_', '0', '3', "\u{0663}", "\u{0660}",
        ' ', "\t", "\n", "\u{A0}", "\u{85}", "\u{E9}", "\u{AA}", '!',
    ];

    private string|false $jit = false;

    protected function setUp(): void
    {
        $this->jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
    }

    protected function tearDown(): void
    {
        if (false !== $this->jit) {
            ini_set('pcre.jit', $this->jit);
        }
    }

    /**
     * A solver blind to the ASCII options answers "not equivalent" on the
     * ASCII rows with a non-ASCII witness (U+0660, U+0085, U+00AA) the
     * engine refuses on both sides, and "equivalent" for "(?aD)\d" against
     * "\d".
     */
    #[Test]
    #[DataProvider('provideEquivalenceRows')]
    public function test_inline_options_change_equivalence_as_the_engine_does(string $left, string $right, bool $equivalent): void
    {
        if (!self::compilesHere($left) || !self::compilesHere($right)) {
            return;
        }

        $this->assertSame($equivalent, $this->engineEquivalent($left, $right), 'Oracle disagrees with the row.');

        $result = (new LanguageSolver())->equivalent($left, $right, self::fullMatch());

        $this->assertSame($equivalent, $result->isEquivalent, $left.' <=> '.$right.', witnesses '.json_encode([$result->leftOnlyExample, $result->rightOnlyExample]));
        if (!$equivalent) {
            $witness = $result->leftOnlyExample ?? $result->rightOnlyExample;
            $this->assertNotNull($witness);
            $this->assertNotSame(self::matchesFull($left, $witness), self::matchesFull($right, $witness), 'The witness does not tell the patterns apart on the engine.');
        }
    }

    /**
     * @return iterable<string, array{left: string, right: string, equivalent: bool}>
     */
    public static function provideEquivalenceRows(): iterable
    {
        // The ASCII options, set for the rest of the pattern or scoped.
        yield 'aD makes \d ascii' => ['left' => '/(?aD)\d/u', 'right' => '/[0-9]/u', 'equivalent' => true];
        yield 'a makes \d ascii' => ['left' => '/(?a)\d/u', 'right' => '/[0-9]/u', 'equivalent' => true];
        yield 'aS makes \s ascii' => ['left' => '/(?aS)\s/u', 'right' => '/[\t\n\x0B\f\r ]/u', 'equivalent' => true];
        yield 'aW makes \w ascii' => ['left' => '/(?aW)\w/u', 'right' => '/[A-Za-z0-9_]/u', 'equivalent' => true];
        yield 'aP makes a posix class ascii' => ['left' => '/(?aP)[[:alpha:]]/u', 'right' => '/[A-Za-z]/u', 'equivalent' => true];
        yield 'aT makes the posix digit class ascii' => ['left' => '/(?aT)[[:digit:]]/u', 'right' => '/[0-9]/u', 'equivalent' => true];
        yield 'scoped aD' => ['left' => '/(?aD:\d)/u', 'right' => '/[0-9]/u', 'equivalent' => true];
        // Carried into the next alternative: "/x(?aD)|^\d$/u" refuses U+0663.
        yield 'aD carried into the next alternative' => ['left' => '/x(?aD)|\d/u', 'right' => '/x|[0-9]/u', 'equivalent' => true];
        // The caret keeps the ASCII options: "/^(?aD)(?^)\d$/u" refuses U+0663.
        yield 'caret keeps aD' => ['left' => '/(?aD)(?^)\d/u', 'right' => '/[0-9]/u', 'equivalent' => true];
        yield 'caret with i keeps aW' => ['left' => '/(?aW)(?^i)\w/u', 'right' => '/[A-Za-z0-9_]/u', 'equivalent' => true];
        // Kept as guards: each option moves its own classes only.
        yield 'aD leaves \w alone' => ['left' => '/(?aD)\w/u', 'right' => '/\w/u', 'equivalent' => true];
        yield 'aW leaves the posix letter class alone' => ['left' => '/(?aW)[[:alpha:]]/u', 'right' => '/[[:alpha:]]/u', 'equivalent' => true];
        yield 'minus a clears aD' => ['left' => '/(?aD)(?-a)\d/u', 'right' => '/\d/u', 'equivalent' => true];
        yield 'aD restored at the group end' => ['left' => '/(?:x(?aD)|y)\d/u', 'right' => '/[xy]\d/u', 'equivalent' => true];
        yield 'aD is not \d' => ['left' => '/(?aD)\d/u', 'right' => '/\d/u', 'equivalent' => false];
        // r per scope, the caret clearing it, carried across alternatives.
        yield 'caret clears an inline r' => ['left' => '/(?ir)(?^i)k/u', 'right' => '/[kK\x{212A}]/u', 'equivalent' => true];
        yield 'i-r clears an inline r' => ['left' => '/(?r)(?i-r)k/u', 'right' => '/[kK\x{212A}]/u', 'equivalent' => true];
        yield 'scoped i-r clears an inline r' => ['left' => '/(?r)(?i-r:k)/u', 'right' => '/[kK\x{212A}]/u', 'equivalent' => true];
        yield 'r carried into the next alternative' => ['left' => '/x(?r)|(?i)k/u', 'right' => '/x|[kK]/u', 'equivalent' => true];
        yield 'r restored at the group end' => ['left' => '/(?:x(?r)|y)(?i)k/u', 'right' => '/[xy][kK\x{212A}]/u', 'equivalent' => true];
        // Under "xx" a class skips its spaces and tabs, wherever they stand:
        // "/^(?xx)[a b]$/" and "/^(?xx:[a b])$/" refuse " ". An escaped space
        // stays: "/^(?xx)[\ a]$/" matches " ".
        yield 'xx skips a space between class members' => ['left' => '/(?xx)[a b]/', 'right' => '/[ab]/', 'equivalent' => true];
        yield 'scoped xx skips a space between class members' => ['left' => '/(?xx:[a b])c/', 'right' => '/[ab]c/', 'equivalent' => true];
        yield 'xx skips a space in a negated class' => ['left' => '/(?xx)[^a b]/', 'right' => '/[^ab]/', 'equivalent' => true];
        yield 'xx skips a space after a range' => ['left' => '/(?xx)[a-c ]/', 'right' => '/[a-c]/', 'equivalent' => true];
        yield 'xx skips a tab between class members' => ['left' => "/(?xx)[a\tb]/", 'right' => '/[ab]/', 'equivalent' => true];
        // Kept as guards.
        yield 'xx keeps an escaped space' => ['left' => '/(?xx)[\ a]/', 'right' => '/[ a]/', 'equivalent' => true];
        yield 'xx keeps an escaped space between class members' => ['left' => '/(?xx)[a\ b]/', 'right' => '/[ ab]/', 'equivalent' => true];
        yield 'xx skips a leading space in a negated class' => ['left' => '/(?xx)[^ a]/', 'right' => '/[^a]/', 'equivalent' => true];
        yield 'caseless xx skips a space' => ['left' => '/(?ixx)[a b]/', 'right' => '/[abAB]/', 'equivalent' => true];
        yield 'caseless without xx keeps a space' => ['left' => '/(?i)[a b]/', 'right' => '/[ abAB]/', 'equivalent' => true];
        // "(?x)" after "(?xx)" turns xx off: "/^(?xx)(?x)[ a]$/" matches " ".
        yield 'x after xx keeps a caseless class space' => ['left' => '/(?ixx)(?x)[a b]/', 'right' => '/[ abAB]/', 'equivalent' => true];
        yield 'x after xx keeps a leading caseless class space' => ['left' => '/(?ixx)(?x)[ a]/', 'right' => '/[ aA]/', 'equivalent' => true];
        yield 'ix after xx keeps a class space' => ['left' => '/(?xx)(?ix)[a b]/', 'right' => '/[ abAB]/', 'equivalent' => true];
        yield 'scoped x after xx keeps a caseless class space' => ['left' => '/(?ixx)(?x:[a b])/', 'right' => '/[ abAB]/', 'equivalent' => true];
        yield 'x inside a caseless xx scope keeps a class space' => ['left' => '/(?ixx:(?x)[a b])/', 'right' => '/[ abAB]/', 'equivalent' => true];
        yield 'x after xx keeps a class space' => ['left' => '/(?xx)(?x)[a b]/', 'right' => '/[ ab]/', 'equivalent' => true];
        yield 'x after xx keeps a leading class space' => ['left' => '/(?xx)(?x)[ a]/', 'right' => '/[ a]/', 'equivalent' => true];
        yield 'minus x after xx keeps a caseless class space' => ['left' => '/(?ixx)(?-x)[a b]/', 'right' => '/[ abAB]/', 'equivalent' => true];
        yield 'xx after x after xx skips a class space' => ['left' => '/(?ixx)(?x)(?xx)[a b]/', 'right' => '/[abAB]/', 'equivalent' => true];
        // The ASCII options with i, and "-aD" or "-aW" taking one option off "a".
        yield 'aT with i folds the Kelvin sign' => ['left' => '/(?iaT)k/u', 'right' => '/[kK\x{212A}]/u', 'equivalent' => true];
        yield 'aT with i: ascii posix digits, folded letters' => ['left' => '/(?aTi)[[:digit:]k]/u', 'right' => '/[0-9kK\x{212A}]/u', 'equivalent' => true];
        yield 'minus aD after a keeps aS' => ['left' => '/(?a)(?-aD)\d\s/u', 'right' => '/\d[\t\n\x0B\f\r ]/u', 'equivalent' => true];
        yield 'a minus aD in one setting keeps aS' => ['left' => '/(?a-aD)\d\s/u', 'right' => '/\d[\t\n\x0B\f\r ]/u', 'equivalent' => true];
        yield 'minus aW after a keeps aD' => ['left' => '/(?a)(?-aW)\w\d/u', 'right' => '/\w[0-9]/u', 'equivalent' => true];
        // Every letter after "-" is taken off, whatever comes after it.
        yield 'minus i then another letter clears i' => ['left' => '/(?i)(?-im)a/', 'right' => '/a/', 'equivalent' => true];
        yield 'minus i then n clears i' => ['left' => '/(?i)(?-in)a/', 'right' => '/a/', 'equivalent' => true];
        yield 'x set and i cleared in one setting' => ['left' => '/(?i)(?x-i)a/', 'right' => '/a/', 'equivalent' => true];
        // PCRE2 10.45+: an extended class is a set like any other class.
        yield 'extended class difference' => ['left' => '/(?[ [a-c] - [b] ])/', 'right' => '/[ac]/', 'equivalent' => true];
    }

    /**
     * A solver reading "(?aD)\d" as "\d" calls "/\d/u" a subset
     * of "/(?aD)\d/u", which U+0663 refutes on the engine, and refuses
     * "/(?aD)\d+/u" within "/[0-9]+/u" with U+0660, which the engine
     * matches with neither.
     */
    #[Test]
    #[DataProvider('provideSubsetRows')]
    public function test_inline_options_change_subset_answers_as_the_engine_does(string $left, string $right, bool $subset): void
    {
        if (!self::compilesHere($left) || !self::compilesHere($right)) {
            return;
        }

        $engineSubset = true;
        foreach (self::subjects() as $subject) {
            if (self::matchesFull($left, $subject) && !self::matchesFull($right, $subject)) {
                $engineSubset = false;

                break;
            }
        }
        $this->assertSame($subset, $engineSubset, 'Oracle disagrees with the row.');

        $result = (new LanguageSolver())->subsetOf($left, $right, self::fullMatch());

        $this->assertSame($subset, $result->isSubset, $left.' within '.$right.', counterexample '.json_encode($result->counterExample));
        if (!$subset) {
            $this->assertNotNull($result->counterExample);
            $this->assertTrue(self::matchesFull($left, $result->counterExample));
            $this->assertFalse(self::matchesFull($right, $result->counterExample));
        }
    }

    /**
     * @return iterable<string, array{left: string, right: string, subset: bool}>
     */
    public static function provideSubsetRows(): iterable
    {
        yield 'ascii digits under aD' => ['left' => '/(?aD)\d+/u', 'right' => '/[0-9]+/u', 'subset' => true];
        yield 'aD carried after an alternative' => ['left' => '/x(?aD)|\d+/u', 'right' => '/x|[0-9]+/u', 'subset' => true];
        yield 'unicode digits are not within aD' => ['left' => '/\d/u', 'right' => '/(?aD)\d/u', 'subset' => false];
        yield 'caret keeps aS' => ['left' => '/(?aS)(?^)\s/u', 'right' => '/[\t\n\x0B\f\r ]/u', 'subset' => true];
    }

    /**
     * The caret keeps U: "/^(?U)(?^)a+/" matches "a" on "aaa", the same as
     * "/^a+?/", where "/^a+/" matches "aaa". Kept as a guard.
     */
    #[Test]
    public function test_caret_reset_keeps_ungreedy_for_the_first_match(): void
    {
        $this->assertSame(1, preg_match('/^(?U)(?^)a+/', 'aaa', $caret));
        $this->assertSame(1, preg_match('/^a+?/', 'aaa', $lazy));
        $this->assertSame(1, preg_match('/^a+/', 'aaa', $greedy));
        $this->assertSame($lazy, $caret);
        $this->assertNotSame($greedy, $caret);

        $solver = new LanguageSolver();

        $this->assertTrue($solver->matchEquivalent('/(?U)(?^)a+/', '/a+?/')->isEquivalent);
        $this->assertFalse($solver->matchEquivalent('/(?U)(?^)a+/', '/a+/')->isEquivalent);
    }

    /**
     * An option set in the yes branch of a conditional holds in its no
     * branch: the engine reads the pattern left to right. The solver
     * refuses every conditional as a language, so the answer shows in the
     * normalized form it reads: the set each no branch matches.
     *
     * Engine: "/^(?(?=x)x(?i)|A)$/" on "a" is 1, "/^(?(?=x)x(?r)|(?i)k)$/u"
     * on the Kelvin sign 0, "/^(?(?=x)x(?aD)|\d)$/u" on U+0663 0.
     *
     * @param list<int> $inNoBranch
     * @param list<int> $notInNoBranch
     */
    #[Test]
    #[DataProvider('provideConditionalCarryRows')]
    public function test_an_option_set_in_the_yes_branch_holds_in_the_no_branch(string $pattern, array $inNoBranch, array $notInNoBranch): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $anchored = $pattern[0].'^(?:'.substr($pattern, 1, (int) strrpos($pattern, $pattern[0]) - 1).')$'.substr($pattern, (int) strrpos($pattern, $pattern[0]));
        foreach ($inNoBranch as $codePoint) {
            $this->assertSame(1, preg_match($anchored, mb_chr($codePoint, 'UTF-8')), \sprintf('Oracle: %s on U+%04X', $anchored, $codePoint));
        }
        foreach ($notInNoBranch as $codePoint) {
            $this->assertSame(0, preg_match($anchored, mb_chr($codePoint, 'UTF-8')), \sprintf('Oracle: %s on U+%04X', $anchored, $codePoint));
        }

        $hir = (new HirTranslator())->translate(RegexParser::create()->parse($pattern));
        $conditional = self::find($hir);
        $this->assertInstanceOf(ConditionalHir::class, $conditional, 'No conditional in the normalized form of '.$pattern);

        $codePoints = self::codePointsIn($conditional->no);
        foreach ($inNoBranch as $codePoint) {
            $this->assertContains($codePoint, $codePoints, \sprintf('The no branch of %s does not read U+%04X', $pattern, $codePoint));
        }
        foreach ($notInNoBranch as $codePoint) {
            $this->assertNotContains($codePoint, $codePoints, \sprintf('The no branch of %s reads U+%04X', $pattern, $codePoint));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, inNoBranch: list<int>, notInNoBranch: list<int>}>
     */
    public static function provideConditionalCarryRows(): iterable
    {
        yield 'i set in the yes branch' => ['pattern' => '/(?(?=x)x(?i)|A)/', 'inNoBranch' => [0x41, 0x61], 'notInNoBranch' => []];
        yield 'r set in the yes branch' => ['pattern' => '/(?(?=x)x(?r)|(?i)k)/u', 'inNoBranch' => [0x6B, 0x4B], 'notInNoBranch' => [0x212A]];
        yield 'aD set in the yes branch' => ['pattern' => '/(?(?=x)x(?aD)|\d)/u', 'inNoBranch' => [0x30, 0x33], 'notInNoBranch' => [0x0663, 0x0660]];
        // Kept as guards: an option set in the no branch stays there.
        yield 'i set in the no branch only' => ['pattern' => '/(?(?=x)x|(?i)A)/', 'inNoBranch' => [0x41, 0x61], 'notInNoBranch' => []];
    }

    private function engineEquivalent(string $left, string $right): bool
    {
        foreach (self::subjects() as $subject) {
            if (self::matchesFull($left, $subject) !== self::matchesFull($right, $subject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every string of one or two characters over the alphabet.
     *
     * @return list<string>
     */
    private static function subjects(): array
    {
        $subjects = self::ALPHABET;
        foreach (self::ALPHABET as $first) {
            foreach (self::ALPHABET as $second) {
                $subjects[] = $first.$second;
            }
        }

        return $subjects;
    }

    /**
     * Whether the engine matches the whole subject: the pattern wrapped in
     * "\A(?:...)\z", an option set inside it ending with the group.
     */
    private static function matchesFull(string $pattern, string $subject): bool
    {
        $delimiter = $pattern[0];
        $end = (int) strrpos($pattern, $delimiter);
        $anchored = $delimiter.'\A(?:'.substr($pattern, 1, $end - 1).')\z'.substr($pattern, $end);

        return 1 === @preg_match($anchored, $subject);
    }

    private static function find(Hir $hir): ?ConditionalHir
    {
        if ($hir instanceof ConditionalHir) {
            return $hir;
        }

        foreach ($hir->children() as $child) {
            $found = self::find($child);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The code points the leaves of a branch read, among those the rows ask about.
     *
     * @return list<int>
     */
    private static function codePointsIn(Hir $hir): array
    {
        $codePoints = [];
        if ($hir instanceof LiteralHir) {
            $codePoints = $hir->codePoints;
        } elseif ($hir instanceof ClassHir) {
            foreach ([0x30, 0x33, 0x41, 0x4B, 0x61, 0x6B, 0x0660, 0x0663, 0x212A] as $codePoint) {
                if ($hir->set->contains($codePoint)) {
                    $codePoints[] = $codePoint;
                }
            }
        }

        foreach ($hir->children() as $child) {
            $codePoints = [...$codePoints, ...self::codePointsIn($child)];
        }

        return array_values(array_unique($codePoints));
    }

    /**
     * Whether this PCRE2 compiles the row: "(?r)" and the ASCII options
     * need 10.43 or later, and nothing else may be refused.
     */
    private static function compilesHere(string $pattern): bool
    {
        if (false !== @preg_match($pattern, '')) {
            return true;
        }

        self::assertTrue(version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '<'), $pattern.' does not compile on PCRE2 '.\PCRE_VERSION);

        return false;
    }

    private static function fullMatch(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
