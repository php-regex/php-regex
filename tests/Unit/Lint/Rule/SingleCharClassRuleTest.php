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
 * "[a]" is "a": a style rule, off by default. A class holding one
 * metacharacter ("[.]", "[*]", "[ ]" or "[#]" under x) is the clearer
 * escape and stays; so does a negated class, the delimiter, a raw multibyte
 * character without /u (the class reads its bytes), and "[aa]", which is
 * another rule's.
 */
final class SingleCharClassRuleTest extends TestCase
{
    private const ID = 'regex.lint.charclass.single';

    private const ENABLED = ['charclass.single' => true];

    #[Test]
    public function test_the_rule_is_off_by_default(): void
    {
        $this->assertNull($this->violation('/[a]/', []));
        // Off, not missing: the same pattern trips it once enabled.
        $this->assertInstanceOf(RuleViolation::class, $this->violation('/[a]/', self::ENABLED));
    }

    #[Test]
    #[DataProvider('provideSingleCharacterClasses')]
    public function test_a_class_of_one_plain_character_is_reported_when_enabled(string $pattern, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the class and the bare character agree.
        $bare = str_replace(['[', ']'], '', $pattern);
        $this->assertSame(preg_match($pattern, $subject, $withClass), preg_match($bare, $subject, $withoutClass));
        $this->assertSame($withClass, $withoutClass);

        $violation = $this->violation($pattern, self::ENABLED);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Style, $violation->severity);
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string}>
     */
    public static function provideSingleCharacterClasses(): iterable
    {
        yield 'letter' => ['pattern' => '/x[a]y/', 'subject' => 'xay'];
        // A space is a metacharacter only under x.
        yield 'space outside extended mode' => ['pattern' => '/x[ ]y/', 'subject' => 'x y'];
        yield 'multibyte character under u' => ['pattern' => '/x[é]y/u', 'subject' => 'xéy'];
    }

    /**
     * An escape that reads the same in a class and outside it: the tip is
     * the escape without the brackets.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideSingleEscapedCharacterClasses')]
    public function test_a_class_of_one_escaped_character_is_reported_with_the_bare_escape(string $pattern, string $bare, string $escape, array $subjects): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the class and the bare escape agree.
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $withClass), preg_match($bare, $subject, $withoutClass), var_export($subject, true));
            $this->assertSame($withClass, $withoutClass);
        }

        $violation = $this->violation($pattern, self::ENABLED);
        $this->assertInstanceOf(RuleViolation::class, $violation, $pattern);
        $this->assertSame(LintSeverity::Style, $violation->severity);
        $this->assertSame(\sprintf('Write "%s" instead.', $escape), $violation->hint);
    }

    /**
     * @return iterable<string, array{pattern: string, bare: string, escape: string, subjects: list<string>}>
     */
    public static function provideSingleEscapedCharacterClasses(): iterable
    {
        yield 'escaped dot' => ['pattern' => '/x[\.]y/', 'bare' => '/x\.y/', 'escape' => '\.', 'subjects' => ['x.y', 'xay']];
        yield 'escaped closing bracket' => ['pattern' => '/x[\]]y/', 'bare' => '/x\]y/', 'escape' => '\]', 'subjects' => ['x]y', 'x[y']];
        yield 'escaped hyphen' => ['pattern' => '/x[\-]y/', 'bare' => '/x\-y/', 'escape' => '\-', 'subjects' => ['x-y', 'xay']];
        yield 'newline escape' => ['pattern' => '/x[\n]y/', 'bare' => '/x\ny/', 'escape' => '\n', 'subjects' => ["x\ny", 'xny']];
        yield 'escape character' => ['pattern' => '/x[\e]y/', 'bare' => '/x\ey/', 'escape' => '\e', 'subjects' => ["x\x1By", 'xey']];
        // An octal escape in braces is not a reference outside the class.
        yield 'braced octal escape' => ['pattern' => '/x[\o{101}]y/', 'bare' => '/x\o{101}y/', 'escape' => '\o{101}', 'subjects' => ['xAy', 'xay']];
        // Under x an escaped "#" or space is a literal both in a class and out.
        yield 'escaped hash under x' => ['pattern' => '/x[\#]y/x', 'bare' => '/x\#y/x', 'escape' => '\#', 'subjects' => ['x#y', 'xy']];
        yield 'escaped space under x' => ['pattern' => '/x[\ ]y/x', 'bare' => '/x\ y/x', 'escape' => '\ ', 'subjects' => ['x y', 'xy']];
    }

    #[Test]
    #[DataProvider('provideClassesThatStay')]
    public function test_a_class_that_reads_better_than_the_bare_character_is_not_reported(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $this->assertNull($this->violation($pattern, self::ENABLED));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideClassesThatStay(): iterable
    {
        yield 'negated' => ['pattern' => '/[^a]/'];
        yield 'dot' => ['pattern' => '/[.]/'];
        yield 'star' => ['pattern' => '/[*]/'];
        yield 'hash under x' => ['pattern' => '/[#]/x'];
        yield 'space under x' => ['pattern' => '/[ ]/x'];
        yield 'the delimiter' => ['pattern' => '/[\/]/'];
        yield 'raw multibyte character without u' => ['pattern' => '/[é]/'];
        yield 'duplicate, another rule' => ['pattern' => '/[aa]/'];
        yield 'two characters' => ['pattern' => '/[ab]/'];
        yield 'range' => ['pattern' => '/[a-z]/'];
        // "\b" is a backspace in a class, a word boundary outside it.
        yield 'backspace escape' => ['pattern' => '/[\b]/'];
    }

    /**
     * Under x the engine skips every white space character it ignores
     * outside a class, not the space alone: the raw character in a class
     * stays.
     */
    #[Test]
    #[DataProvider('provideWhiteSpaceExtendedModeIgnores')]
    public function test_a_class_of_one_white_space_character_extended_mode_ignores_is_not_reported(string $pattern, string $bare, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the class reads the character,
        // the bare pattern skips it and matches the empty string.
        preg_match($pattern, $subject, $withClass);
        $this->assertSame([$subject], $withClass, $pattern);
        preg_match($bare, $subject, $withoutClass);
        $this->assertSame([''], $withoutClass, $bare);

        $this->assertNull($this->violation($pattern, self::ENABLED), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, bare: string, subject: string}>
     */
    public static function provideWhiteSpaceExtendedModeIgnores(): iterable
    {
        yield 'tab' => ['pattern' => "/[\t]/x", 'bare' => "/\t/x", 'subject' => "\t"];
        yield 'line feed' => ['pattern' => "/[\n]/x", 'bare' => "/\n/x", 'subject' => "\n"];
        yield 'vertical tab' => ['pattern' => "/[\x0B]/x", 'bare' => "/\x0B/x", 'subject' => "\x0B"];
        yield 'form feed' => ['pattern' => "/[\f]/x", 'bare' => "/\f/x", 'subject' => "\f"];
        yield 'carriage return' => ['pattern' => "/[\r]/x", 'bare' => "/\r/x", 'subject' => "\r"];
        // Without u the byte 0x85 is the next-line character.
        yield 'next-line byte' => ['pattern' => "/[\x85]/x", 'bare' => "/\x85/x", 'subject' => "\x85"];
        yield 'next line under u' => ['pattern' => "/[\u{85}]/xu", 'bare' => "/\u{85}/xu", 'subject' => "\u{85}"];
        yield 'left-to-right mark under u' => ['pattern' => "/[\u{200E}]/xu", 'bare' => "/\u{200E}/xu", 'subject' => "\u{200E}"];
        yield 'right-to-left mark under u' => ['pattern' => "/[\u{200F}]/xu", 'bare' => "/\u{200F}/xu", 'subject' => "\u{200F}"];
        yield 'line separator under u' => ['pattern' => "/[\u{2028}]/xu", 'bare' => "/\u{2028}/xu", 'subject' => "\u{2028}"];
        yield 'paragraph separator under u' => ['pattern' => "/[\u{2029}]/xu", 'bare' => "/\u{2029}/xu", 'subject' => "\u{2029}"];
        yield 'tab under an inline x' => ['pattern' => "/(?x)[\t]/", 'bare' => "/(?x)\t/", 'subject' => "\t"];
        yield 'line separator under an inline x' => ['pattern' => "/(?x)[\u{2028}]/u", 'bare' => "/(?x)\u{2028}/u", 'subject' => "\u{2028}"];
    }

    /**
     * Other white space the engine reads under x as any character.
     */
    #[Test]
    public function test_a_class_of_one_white_space_character_extended_mode_keeps_is_reported(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: a no-break space is no pattern white space.
        preg_match("/[\u{A0}]/xu", "\u{A0}", $withClass);
        preg_match("/\u{A0}/xu", "\u{A0}", $withoutClass);
        $this->assertSame(["\u{A0}"], $withClass);
        $this->assertSame($withClass, $withoutClass);

        $this->assertInstanceOf(RuleViolation::class, $this->violation("/[\u{A0}]/xu", self::ENABLED));
        // Outside x a raw tab is a plain character.
        $this->assertInstanceOf(RuleViolation::class, $this->violation("/[\t]/", self::ENABLED));
    }

    /**
     * The bare character or escape reads otherwise where the class stood:
     * "\\1" is a reference outside a class, and "0" after "\\1" makes
     * "\\10".
     */
    #[Test]
    #[DataProvider('provideClassesWhoseMemberReadsOtherwiseBare')]
    public function test_a_class_whose_member_reads_otherwise_bare_is_not_reported(string $pattern, string $bare, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the two patterns disagree on the subject.
        $this->assertNotSame(@preg_match($pattern, $subject), @preg_match($bare, $subject), $pattern);

        $this->assertNull($this->violation($pattern, self::ENABLED));
    }

    /**
     * @return iterable<string, array{pattern: string, bare: string, subject: string}>
     */
    public static function provideClassesWhoseMemberReadsOtherwiseBare(): iterable
    {
        yield 'reference-like escape' => ['pattern' => '/(a)[\1]/', 'bare' => '/(a)\1/', 'subject' => "a\x01"];
        yield 'two-digit escape' => ['pattern' => '/^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)[\12]$/', 'bare' => '/^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)\12$/', 'subject' => "abcdefghijkl\n"];
        yield 'nul escape before a digit' => ['pattern' => '/^[\0]1$/', 'bare' => '/^\01$/', 'subject' => "\x001"];
        yield 'digit after a reference' => ['pattern' => '/^(a)\1[0]$/', 'bare' => '/^(a)\10$/', 'subject' => 'aa0'];
        yield 'digit after an opening brace' => ['pattern' => '/^a{[2]}$/', 'bare' => '/^a{2}$/', 'subject' => 'a{2}'];
    }

    /**
     * The message quotes the class as written.
     */
    #[Test]
    public function test_the_message_quotes_the_class_as_written(): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame(1, preg_match('/^x[\Qa\E]y$/', 'xay'));

        $violation = $this->violation('/x[\Qa\E]y/', self::ENABLED);
        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Character class "[\Qa\E]" holds one character, which reads the same without the class.', $violation->message);
        $this->assertSame('Write "a" instead.', $violation->hint);
    }

    /**
     * Why the metacharacter rows stay: the bare character means something else.
     */
    #[Test]
    public function test_the_engine_reads_a_lone_metacharacter_otherwise(): void
    {
        $this->assertSame(0, preg_match('/^[.]$/', 'x'));
        $this->assertSame(1, preg_match('/^.$/', 'x'));
        // Under x a bare "#" opens a comment and a bare space is skipped:
        // both patterns match the empty string where the class reads one
        // character.
        preg_match('/[#]/x', '#', $matches);
        $this->assertSame(['#'], $matches);
        preg_match('/#/x', '#', $matches);
        $this->assertSame([''], $matches);
        preg_match('/[ ]/x', ' ', $matches);
        $this->assertSame([' '], $matches);
        preg_match('/ /x', ' ', $matches);
        $this->assertSame([''], $matches);
        // "\b" in a class is the backspace character, outside a word boundary.
        $this->assertSame(1, preg_match('/^x[\b]y$/', "x\x08y"));
        $this->assertSame(0, preg_match('/^x\by$/', "x\x08y"));
        // Without u the class matches one byte of the letter on its own.
        $this->assertSame(1, preg_match('/^[é]$/', "\xC3"));
        $this->assertSame(0, preg_match('/^é$/', "\xC3"));
    }

    /**
     * @param array<string, bool> $rules
     */
    private function violation(string $pattern, array $rules): ?RuleViolation
    {
        $linter = new PatternLinter($rules);
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
