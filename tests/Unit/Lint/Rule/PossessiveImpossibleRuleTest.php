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

use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A possessive (or atomic) unbounded repeat over a set X never gives a
 * character back: when the next atom must read a character of X, that
 * character was already taken, and the pattern can never match through
 * there ("/a*+a/"). Proved on single-character atoms only, case folding and
 * x mode followed; a bounded repeat or a possibly-empty next atom never.
 * A warning, like the impossible anchors: no new rule fails CI.
 */
final class PossessiveImpossibleRuleTest extends TestCase
{
    private const ID = 'regex.lint.quantifier.possessiveImpossible';

    /**
     * Subjects the "never matches" rows are replayed on.
     */
    private const SUBJECTS = ['', 'a', 'aa', 'aaa', 'A', 'aA', 'Aa', 'b', 'ab', '5', '55', '15', '_', '__', 'a_', ' a', 'a a', 'aé', 'éé', 'x'];

    #[Test]
    #[DataProvider('provideStarvedAtoms')]
    public function test_an_atom_the_possessive_repeat_starves_is_reported(string $pattern): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: no subject matches.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), \sprintf('%s matches %s', $pattern, json_encode($subject)));
        }

        $violation = $this->violation($pattern);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Warning, $violation->severity);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideStarvedAtoms(): iterable
    {
        yield 'star then the same literal' => ['pattern' => '/a*+a/'];
        yield 'plus then the same literal' => ['pattern' => '/a++a/'];
        yield 'digits then a digit' => ['pattern' => '/\d++5/'];
        yield 'word characters then an underscore' => ['pattern' => '/\w++_/'];
        yield 'caseless flag folds the next atom in' => ['pattern' => '/a*+A/i'];
        yield 'inline caseless flag' => ['pattern' => '/(?i)a*+A/'];
        yield 'caseless repeat, case-sensitive atom inside its set' => ['pattern' => '/(?i:a*+)A/'];
        yield 'extended mode skips the space' => ['pattern' => '/a*+ a/x'];
        yield 'atomic group around the repeat' => ['pattern' => '/(?>a*)a/'];
        yield 'comment before the repeat in the atomic group' => ['pattern' => '/(?>(?#c)a*)a/'];
        yield 'comments around the repeat in the atomic group' => ['pattern' => '/(?>(?#c)a*(?#d))a/'];
        yield 'extended-mode comment after the repeat in the atomic group' => ['pattern' => "/(?>a* # c\n)a/x"];
        yield 'unbounded range' => ['pattern' => '/a{2,}+a/'];
        yield 'unicode word characters take the letter' => ['pattern' => '/\w++é/u'];
    }

    /**
     * The branch never matches, the pattern still does through the other.
     */
    #[Test]
    public function test_a_starved_branch_is_reported_though_another_branch_matches(): void
    {
        $this->assertSame(1, preg_match('/a++a|b/', 'b'));
        $this->assertSame(0, preg_match('/a++a|b/', 'aa'));

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/a++a|b/'));
    }

    #[Test]
    #[DataProvider('provideAtomsThatCanStillMatch')]
    public function test_an_atom_that_can_still_match_is_not_reported(string $pattern, string $subject, string $match): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the pattern matches.
        $this->assertSame(1, preg_match($pattern, $subject, $matches), $pattern);
        $this->assertSame($match, $matches[0]);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, match: string}>
     */
    public static function provideAtomsThatCanStillMatch(): iterable
    {
        yield 'next atom outside the set' => ['pattern' => '/[a-z]*+\d/', 'subject' => 'a1', 'match' => 'a1'];
        yield 'next atom wider than the set' => ['pattern' => '/a*+\w/', 'subject' => 'ab', 'match' => 'ab'];
        yield 'bounded repeat gives up at its bound' => ['pattern' => '/^a{0,3}+a$/', 'subject' => 'aaaa', 'match' => 'aaaa'];
        yield 'bounded optional' => ['pattern' => '/a?+a/', 'subject' => 'aa', 'match' => 'aa'];
        yield 'next atom may be empty' => ['pattern' => '/a*+a?/', 'subject' => 'aa', 'match' => 'aa'];
        yield 'case-sensitive without the flag' => ['pattern' => '/a*+A/', 'subject' => 'A', 'match' => 'A'];
        yield 'caseless next atom wider than the repeat' => ['pattern' => '/a*+(?i)A/', 'subject' => 'A', 'match' => 'A'];
        // Without /u the letter is two bytes \w does not read.
        yield 'byte mode leaves the letter' => ['pattern' => '/\w++é/', 'subject' => 'aé', 'match' => 'aé'];
        // The ASCII options keep "\w" and "\d" to ASCII under /u.
        yield 'ASCII word option leaves the letter' => ['pattern' => '/(?aW)\w*+é/u', 'subject' => 'é', 'match' => 'é'];
        yield 'ASCII digit option leaves the Arabic-Indic digit' => ['pattern' => '/(?aD)\d*+٣/u', 'subject' => '٣', 'match' => '٣'];
        // "(?-aD)" turns the digit option off, the word option stays on.
        yield 'ASCII options turned off in part' => ['pattern' => '/(?a)(?-aD)\w*+é/u', 'subject' => 'é', 'match' => 'é'];
        // Under "(?r)" the Kelvin sign and "k" no longer fold together.
        yield 'caseless restrict keeps the Kelvin sign apart' => ['pattern' => '/(?r)k*+\x{212A}/iu', 'subject' => "\u{212A}", 'match' => "\u{212A}"];
        // Under "(?xx)" the space in the class is layout, not a member.
        yield 'double extended mode drops the space from the class' => ['pattern' => '/(?xx)[a b]*+\ /', 'subject' => 'a ', 'match' => 'a '];
    }

    /**
     * "(?-r)" inside a pattern under /r folds the Kelvin sign with "k"
     * again: "k*+" takes it, and the pattern never matches.
     */
    #[Test]
    public function test_caseless_restrict_turned_off_inside_the_pattern_is_read(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        foreach (["\u{212A}", 'k', 'K', "k\u{212A}", ''] as $subject) {
            $this->assertSame(0, preg_match('/(?-r)k*+\x{212A}/iur', $subject), var_export($subject, true));
        }
        $this->assertSame(1, preg_match('/k*+\x{212A}/iur', "\u{212A}"));

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?-r)k*+\x{212A}/iur'));
        $this->assertNull($this->violation('/k*+\x{212A}/iur'));
    }

    private function violation(string $pattern): ?RuleViolation
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
