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
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A non-capturing group around one atom goes, unless taking it out
 * changes the pattern: it keeps braces from reading as a quantifier, or a
 * quantifier repeats it and would then repeat something else than one
 * character.
 */
final class RedundantGroupRuleTest extends TestCase
{
    use PcreGates;

    private const ID = 'regex.lint.group.redundant';

    #[Test]
    public function test_a_group_around_one_atom_is_reported(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the group changes nothing.
        $this->assertSame(1, preg_match('/^x(?:a)y$/', 'xay'));
        $this->assertSame(1, preg_match('/^xay$/', 'xay'));

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/x(?:a)y/'));
    }

    /**
     * A quantifier repeats one character with or without the group around
     * it: "(?:a)+" is "a+".
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideQuantifiedGroupsAroundOneCharacter')]
    public function test_a_quantified_group_around_one_character_is_reported(string $pattern, string $without, array $subjects): void
    {
        // "{,2}" repeats only from PCRE2 10.43.
        if ('/^(?:\N){,2}$/' === $pattern) {
            $this->requiresPcre2('10.43', $pattern);
        }

        // Oracle, PHP 8.4.26 / PCRE2 10.49: the two patterns match the same text.
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $withGroup), preg_match($without, $subject, $withoutGroup), var_export($subject, true));
            $this->assertSame($withGroup, $withoutGroup);
        }

        $this->assertInstanceOf(RuleViolation::class, $this->violation($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, subjects: list<string>}>
     */
    public static function provideQuantifiedGroupsAroundOneCharacter(): iterable
    {
        yield 'letter under a plus' => ['pattern' => '/x(?:a)+/', 'without' => '/xa+/', 'subjects' => ['x', 'xa', 'xaa']];
        yield 'escaped dollar, optional' => ['pattern' => '/(?:\$)?1/', 'without' => '/\$?1/', 'subjects' => ['1', '$1', '$$1']];
        yield 'lazy dot' => ['pattern' => '/<(?:.)*?>/', 'without' => '/<.*?>/', 'subjects' => ['<a><b>', '<>']];
        yield 'shorthand count' => ['pattern' => '/(?:\d){2}/', 'without' => '/\d{2}/', 'subjects' => ['1', '12', '123']];
        yield 'multibyte letter under u' => ['pattern' => '/^(?:é)+$/u', 'without' => '/^é+$/u', 'subjects' => ['é', 'éé']];
        // A count after "\N" repeats it: "\N{2}" is "(?:\N){2}".
        yield 'match-anything escape under a count' => ['pattern' => '/^(?:\N){2}$/', 'without' => '/^\N{2}$/', 'subjects' => ['a', 'ab', 'abc', "a\n"]];
        yield 'match-anything escape under a count with no lower bound' => ['pattern' => '/^(?:\N){,2}$/', 'without' => '/^\N{,2}$/', 'subjects' => ['', 'a', 'ab', 'abc']];
    }

    /**
     * @param string $without the pattern with the group taken out
     */
    #[Test]
    #[DataProvider('provideGroupsThatCannotGo')]
    public function test_a_group_whose_removal_changes_the_pattern_is_not_reported(string $pattern, string $without, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the two patterns disagree on the subject.
        $this->assertNotSame(@preg_match($pattern, $subject), @preg_match($without, $subject), $pattern);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, without: string, subject: string}>
     */
    public static function provideGroupsThatCannotGo(): iterable
    {
        // The group keeps the braces from reading as a quantifier.
        yield 'digit after an opening brace' => ['pattern' => '/^a{(?:2)}$/', 'without' => '/^a{2}$/', 'subject' => 'a{2}'];
        yield 'opening brace before a digit' => ['pattern' => '/^a(?:{)2}$/', 'without' => '/^a{2}$/', 'subject' => 'a{2}'];
        yield 'closing brace after a count' => ['pattern' => '/^a{2(?:})$/', 'without' => '/^a{2}$/', 'subject' => 'a{2}'];
        // "\N{U+41}" is the code point under u, and does not compile
        // without it, nor does "\N{x}": the group keeps "\N" apart from the
        // braces, which read as literal text.
        yield 'code point braces after the match-anything escape under u' => ['pattern' => '/^(?:\N){U+41}$/u', 'without' => '/^\N{U+41}$/u', 'subject' => 'A'];
        yield 'code point braces after the match-anything escape without u' => ['pattern' => '/^(?:\N){U+41}$/', 'without' => '/^\N{U+41}$/', 'subject' => 'B{U+41}'];
        yield 'name braces after the match-anything escape' => ['pattern' => '/^(?:\N){x}$/', 'without' => '/^\N{x}$/', 'subject' => 'B{x}'];
        // Under a quantifier the group is the operand: without it the
        // quantifier repeats the last quoted character or byte, or an item
        // it cannot repeat at all.
        yield 'quoted run under a plus' => ['pattern' => '/^(?:\Qab\E)+$/', 'without' => '/^\Qab\E+$/', 'subject' => 'abb'];
        yield 'comment under a plus' => ['pattern' => '/^x(?:(?#c))+$/', 'without' => '/^x(?#c)+$/', 'subject' => 'xx'];
        yield 'anchor under a plus' => ['pattern' => '/(?:^)+a/', 'without' => '/^+a/', 'subject' => 'a'];
        yield 'keep under a plus' => ['pattern' => '/(?:\K)+a/', 'without' => '/\K+a/', 'subject' => 'a'];
        // Without u the letter is two bytes, and the plus would repeat the second.
        yield 'multibyte letter without u' => ['pattern' => '/^(?:é)+$/', 'without' => '/^é+$/', 'subject' => 'éé'];
    }

    /**
     * PCRE2 makes a character repeated before a possessive or atomic group
     * that may match nothing possessive, as if the group could not be
     * skipped, and the brackets decide which: a repeat written as a group is
     * never made possessive, a letter repeated possessively is skipped as it
     * should be. The engine tells each pattern apart from the one without
     * the group on "aa", with and without the JIT, and no group is reported.
     */
    #[Test]
    #[DataProvider('provideBracketsTheEngineReads')]
    public function test_a_group_whose_brackets_decide_a_possessive_is_not_reported(string $pattern, string $group, string $without): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, and 10.34 and 10.39.
        $this->assertNotSame(preg_match($pattern, 'aa'), preg_match($without, 'aa'));
        $this->assertNotSame(preg_match(PcreInterpreter::pattern($pattern), 'aa'), preg_match(PcreInterpreter::pattern($without), 'aa'));

        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);
        $reported = array_filter($linter->getIssues(), static fn (RuleViolation $violation): bool => self::ID === $violation->id && str_contains($violation->message, '"'.$group.'"'));

        $this->assertSame([], $reported);
    }

    /**
     * @return iterable<string, array{pattern: string, group: string, without: string}>
     */
    public static function provideBracketsTheEngineReads(): iterable
    {
        yield 'an optional group repeated possessively' => ['pattern' => '/a+(?:b)?+a/', 'group' => '(?:b)', 'without' => '/a+b?+a/'];
        yield 'an optional group in an atomic group' => ['pattern' => '/a+(?>(?:b)?)a/', 'group' => '(?:b)', 'without' => '/a+(?>b?)a/'];
        yield 'an optional group before an optional letter in an atomic group' => ['pattern' => '/a+(?>(?:b)?c?)a/', 'group' => '(?:b)', 'without' => '/a+(?>b?c?)a/'];
        yield 'a repeat written as a group' => ['pattern' => '/(?:a)+(?:b)?+a/', 'group' => '(?:a)', 'without' => '/a+(?:b)?+a/'];
    }

    /**
     * The group around the optional letter goes there: the repeat before it
     * is written as a group, which PCRE2 never makes possessive, and the
     * engine finds "aa" with or without it.
     */
    #[Test]
    public function test_a_group_after_a_repeat_written_as_a_group_is_still_reported(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49.
        $this->assertSame([1, 1], [preg_match('/(?:a)+(?:b)?+a/', 'aa'), preg_match('/(?:a)+b?+a/', 'aa')]);

        $linter = new PatternLinter();
        Regex::create()->parse('/(?:a)+(?:b)?+a/')->accept($linter);
        $messages = array_map(static fn (RuleViolation $violation): string => $violation->message, array_filter($linter->getIssues(), static fn (RuleViolation $violation): bool => self::ID === $violation->id));

        $this->assertSame(['Redundant non-capturing group "(?:b)" at offset 6; it can be removed without changing behavior.'], array_values($messages));
    }

    /**
     * Where no possessive or atomic group that may match nothing stands,
     * the brackets decide nothing, and the group is reported.
     */
    #[Test]
    public function test_a_group_before_a_greedy_optional_group_is_still_reported(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the two match alike.
        $this->assertSame([1, 1], [preg_match('/(?:a)+(?:b)?a/', 'aa'), preg_match('/a+(?:b)?a/', 'aa')]);

        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(?:a)+(?:b)?a/'));
    }

    /**
     * Each pattern is read on its own: one linted after a pattern whose
     * brackets decide a possessive, by the same facade, is judged by its
     * own.
     */
    #[Test]
    public function test_the_brackets_of_one_pattern_decide_nothing_for_the_next(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49.
        $this->assertSame([0, 1], [preg_match('/a+(?:b)?+a/', 'aa'), preg_match('/a+b?+a/', 'aa')]);
        $this->assertSame([1, 1], [preg_match('/a+(?:b)?a/', 'aa'), preg_match('/a+b?a/', 'aa')]);

        $regex = Regex::create(['cache' => null]);
        $reported = [];
        foreach (['/a+(?:b)?+a/', '/a+(?:b)?a/', '/a+(?:b)?+a/'] as $pattern) {
            $reported[] = \count(array_filter($regex->lint($pattern), static fn (RuleViolation $violation): bool => self::ID === $violation->id));
        }

        $this->assertSame([0, 1, 0], $reported);
    }

    #[Test]
    public function test_two_redundant_groups_produce_distinct_messages(): void
    {
        $pattern = "/^(?:['\"])?[a-zA-Z0-9_.]+(?:['\"])?$/";

        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        $violations = array_values(array_filter(
            $linter->getIssues(),
            static fn (RuleViolation $v): bool => self::ID === $v->id,
        ));

        $this->assertCount(2, $violations, 'Expected two redundant-group violations for '.$pattern);
        $this->assertNotSame($violations[0]->message, $violations[1]->message, 'The two warnings should have distinct messages so the reader knows which group each one refers to.');
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
