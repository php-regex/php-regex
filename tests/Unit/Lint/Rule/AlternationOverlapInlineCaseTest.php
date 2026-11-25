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
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The texts of the branches are read character by character in the case
 * the options in force there give: an option setting at the start of a
 * branch, "(?-i)", or inside it, "a(?-i)B" or "(?i:a)b", changes the case
 * of what follows it, and of the branches after its own, as PCRE reads it.
 * Two characters meet when they are the same, or when either is read
 * caselessly and they are one letter in two cases: "/(?:(?-i)Ax|(?-i)ax)+/i"
 * is not reported, as no input starts both branches, and
 * "/(?:(?i:A)x|ax)+/" is.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, with and without the JIT:
 * preg_match('/^(?:(?-i)Ax)$/i', 'ax') is 0, preg_match('/^(?:(?-i)Ax|ax)$/i',
 * 'aX') is 0, and under fr_FR.ISO8859-1 preg_match("/^(?:(?-i)\xC9x)$/i",
 * "\xE9x") is 0.
 */
final class AlternationOverlapInlineCaseTest extends TestCase
{
    use InstalledLocales;

    private const LATIN1 = 'fr_FR.ISO8859-1';

    /**
     * The locale of the tables a test gives.
     */
    private const GIVEN = 'xx_XX.INLINE';

    /**
     * @param list<string> $branches the branches of the alternation, each written as the pattern holds it
     * @param string|null  $rule     the rule that reports the alternation, none for null
     */
    #[Test]
    #[DataProvider('provideBranches')]
    public function test_each_character_of_a_text_is_read_in_the_case_in_force_there(array $branches, string $flags, ?string $rule): void
    {
        $pattern = '/(?:'.implode('|', $branches).')+/'.$flags;

        // The oracle: every input a branch may start with, each letter in
        // either case, is run through each branch in its place, the others
        // failing first, so that an option setting one carries into the
        // next still holds there.
        $started = [];
        foreach (self::caseVariants($branches) as $subject) {
            $started[$subject] = self::branchesStarting($branches, $flags, $subject);
        }

        $this->assertSame(null !== $rule, \in_array(2, $started, true), \sprintf('Oracle: %s, whose branches start %s.', $pattern, json_encode($started)));

        $this->assertSame(null === $rule ? [] : [$rule], self::overlapRules($pattern, CharacterTables::BUILT_IN), $pattern);
    }

    /**
     * @return iterable<string, array{branches: list<string>, flags: string, rule: string|null}>
     */
    public static function provideBranches(): iterable
    {
        $text = OverlappingAlternationRule::ID_OVERLAP;
        $charset = OverlappingAlternationRule::ID_CHARSET;

        // An option setting at the start of a branch.
        yield 'each branch read in its own case from its start' => ['branches' => ['(?-i)Ax', '(?-i)ax'], 'flags' => 'i', 'rule' => null];
        yield 'a branch read in its own case, carried into the next' => ['branches' => ['(?-i)Ax', 'ax'], 'flags' => 'i', 'rule' => null];
        yield 'each branch read in its own case, a text that starts the other' => ['branches' => ['(?-i)Ax', '(?-i)Axy'], 'flags' => 'i', 'rule' => $text];
        yield 'each branch read in its own case, then items that meet' => ['branches' => ['(?-i)A\d', '(?-i)a\w'], 'flags' => 'i', 'rule' => null];

        // A group setting options around the branch.
        yield 'a branch read in its own case by a group around it' => ['branches' => ['(?-i:AA)', 'AB'], 'flags' => 'i', 'rule' => null];
        yield 'a branch read in its own case by a group around it, then items that meet' => ['branches' => ['(?-i:AB)', 'ab\d'], 'flags' => 'i', 'rule' => $charset];

        // An option setting inside a branch.
        yield 'a letter read caselessly, then one in its own case' => ['branches' => ['a(?-i)B', 'A(?-i)b'], 'flags' => 'i', 'rule' => null];
        yield 'a letter read caselessly, then one in its own case alike' => ['branches' => ['a(?-i)B', 'A(?-i)By'], 'flags' => 'i', 'rule' => $text];
        yield 'texts in two cases, then items that meet' => ['branches' => ['a(?-i)B\d', 'A(?-i)b\w'], 'flags' => 'i', 'rule' => null];
        yield 'texts in two cases alike, then items that meet' => ['branches' => ['a(?-i)B\d', 'A(?-i)B\w'], 'flags' => 'i', 'rule' => $charset];
        yield 'a group reading a letter caselessly, then a letter in its own case' => ['branches' => ['(?i:A)x', 'aX'], 'flags' => '', 'rule' => null];
        yield 'a group reading a letter caselessly, then the same letter' => ['branches' => ['(?i:A)x', 'ax'], 'flags' => '', 'rule' => $text];
        yield 'a group reading a letter caselessly, then a capital' => ['branches' => ['(?i:a)b', 'AB'], 'flags' => '', 'rule' => null];
        yield 'a setting carried into the next branch' => ['branches' => ['A(?i)x', 'aX'], 'flags' => '', 'rule' => $text];
        yield 'texts read caselessly past their first letter, then items that meet' => ['branches' => ['a(?i)b\d', 'a(?i)c\w'], 'flags' => '', 'rule' => null];
        yield 'texts read caselessly past their first letter alike, then items that meet' => ['branches' => ['a(?i)b\d', 'a(?i)B\w'], 'flags' => '', 'rule' => $charset];
        yield 'a letter in its own case, then one read caselessly' => ['branches' => ['(?-i)A(?i)x', '(?-i)AX'], 'flags' => 'i', 'rule' => $text];
    }

    /**
     * Under fr_FR.ISO8859-1, where the Latin-1 tables pair 0xC9 with 0xE9,
     * a byte read in its own case meets itself alone, written as it is or
     * as an escape.
     *
     * @param list<string> $branches
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideLatin1Branches')]
    public function test_each_character_of_a_text_is_read_in_the_case_in_force_there_under_a_latin1_locale(array $branches, string $flags, array $subjects, ?string $rule): void
    {
        $pattern = '/(?:'.implode('|', $branches).')+/'.$flags;
        $this->requiresLocale(self::LATIN1, $pattern);

        $started = new \ArrayObject();
        CharacterTables::under(self::LATIN1, static function () use ($branches, $flags, $subjects, $started): void {
            foreach ($subjects as $subject) {
                $started[bin2hex($subject)] = self::branchesStarting($branches, $flags, $subject);
            }
        });

        $label = addcslashes($pattern, "\200..\377");
        $this->assertSame(null !== $rule, \in_array(2, $started->getArrayCopy(), true), \sprintf('Oracle: %s, whose branches start %s.', $label, json_encode($started->getArrayCopy())));

        $this->assertSame(null === $rule ? [] : [$rule], self::overlapRules($pattern, self::LATIN1), $label);
    }

    /**
     * @return iterable<string, array{branches: list<string>, flags: string, subjects: list<string>, rule: string|null}>
     */
    public static function provideLatin1Branches(): iterable
    {
        $subjects = ["\xC9x", "\xE9x", "\xC9X", "\xE9X"];
        yield 'each branch read in its own case from its start, raw bytes' => ['branches' => ["(?-i)\xC9x", "(?-i)\xE9x"], 'flags' => 'i', 'subjects' => $subjects, 'rule' => null];
        yield 'each branch read in its own case from its start, escapes' => ['branches' => ['(?-i)\xC9x', '(?-i)\xE9x'], 'flags' => 'i', 'subjects' => $subjects, 'rule' => null];
        yield 'a byte read caselessly, then a letter in its own case' => ['branches' => ["\xC9(?-i)x", "\xE9(?-i)X"], 'flags' => 'i', 'subjects' => $subjects, 'rule' => null];
        yield 'a byte read caselessly, then the same letter' => ['branches' => ["\xC9(?-i)x", "\xE9(?-i)x"], 'flags' => 'i', 'subjects' => $subjects, 'rule' => OverlappingAlternationRule::ID_OVERLAP];
    }

    /**
     * Under tables a test gives, which pair 0xC9 with 0xE9 as the Latin-1
     * tables do: CI machines often hold no Latin-1 locale. Each pattern
     * opens with an empty branch, which leaves the branches the text does
     * not join to their bytes.
     */
    #[Test]
    #[DataProvider('provideBranchesUnderGivenTables')]
    public function test_each_character_of_a_text_is_read_in_the_case_in_force_there_under_given_tables(string $pattern, ?string $rule): void
    {
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr(match ($byte) {
                0xC9 => 0xE9,
                0xE9 => 0xC9,
                default => Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte,
            });
        }

        CharacterTables::useTables(self::GIVEN, $cases);

        $this->assertSame(null === $rule ? [] : [$rule], self::overlapRules($pattern, self::GIVEN), addcslashes($pattern, "\200..\377"));
    }

    /**
     * @return iterable<string, array{pattern: string, rule: string|null}>
     */
    public static function provideBranchesUnderGivenTables(): iterable
    {
        yield 'each branch read in its own case from its start, raw bytes' => ['pattern' => "/(?:|(?-i)\xC9x|(?-i)\xE9x)+/i", 'rule' => null];
        yield 'each branch read in its own case from its start, escapes' => ['pattern' => '/(?:|(?-i)\xC9x|(?-i)\xE9x)+/i', 'rule' => null];
        yield 'a byte read caselessly, then a letter in its own case' => ['pattern' => "/(?:|\xC9(?-i)x|\xE9(?-i)X)+/i", 'rule' => null];
        yield 'a byte read caselessly, then the same letter' => ['pattern' => "/(?:|\xC9(?-i)x|\xE9(?-i)x)+/i", 'rule' => OverlappingAlternationRule::ID_OVERLAP];
    }

    /**
     * How many branches start the subject, each run in its place in the
     * alternation, the JIT and the interpreter agreeing.
     *
     * @param list<string> $branches
     */
    private static function branchesStarting(array $branches, string $flags, string $subject): int
    {
        $starting = 0;
        foreach (array_keys($branches) as $index) {
            $alone = [];
            foreach ($branches as $other => $branch) {
                $alone[] = $other === $index ? $branch : '(*FAIL)'.$branch;
            }

            $pattern = '/^(?:'.implode('|', $alone).')/'.$flags;
            $jit = preg_match($pattern, $subject);
            self::assertSame($jit, preg_match(PcreInterpreter::pattern($pattern), $subject), $pattern.' on '.$subject);
            $starting += $jit;
        }

        return $starting;
    }

    /**
     * Every text the branches may start with, each ASCII letter of their
     * literal characters in either case: the letters of the texts, read up
     * to the first escape.
     *
     * @param list<string> $branches
     *
     * @return list<string>
     */
    private static function caseVariants(array $branches): array
    {
        $variants = [];
        foreach ($branches as $branch) {
            $letters = preg_replace('/\(\?[a-z-]*:?|\)|\\\\.*$/', '', $branch);
            $texts = [''];
            foreach (str_split((string) $letters) as $letter) {
                $next = [];
                foreach ($texts as $text) {
                    $next[] = $text.Ascii::lower($letter);
                    $next[] = $text.Ascii::upper($letter);
                }

                $texts = $next;
            }

            // A branch that goes on past its text takes a digit there.
            $suffix = str_contains($branch, '\\') ? '1' : '';
            foreach ($texts as $text) {
                $variants[] = $text.$suffix;
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * The rules of overlapping branches that report the pattern.
     *
     * @return list<string>
     */
    private static function overlapRules(string $pattern, string $locale): array
    {
        $regex = Regex::create(['cache' => null, 'php_version' => '8.4', 'locale' => $locale]);
        $linter = new PatternLinter([], $regex->target());
        $regex->parse($pattern)->accept($linter);

        $ids = array_map(static fn ($issue): string => $issue->id, $linter->getIssues());

        return array_values(array_intersect([OverlappingAlternationRule::ID_OVERLAP, OverlappingAlternationRule::ID_CHARSET], $ids));
    }
}
