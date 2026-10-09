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

namespace PHPRegex\Tests\Unit\Transpiler;

use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\TranspileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The HTML pattern attribute matches the whole value under the v flag:
 * "^(?:" . pattern . ")$" (WHATWG HTML, the pattern attribute). Each value
 * below was checked with Node 24, new RegExp("^(?:" + value + ")$", "v"),
 * on the subjects listed: it accepts exactly those preg_match() matches.
 */
final class HtmlPatternTargetTest extends TestCase
{
    /**
     * @param array<int|string, bool> $verdicts subject => whether preg_match() and the attribute accept it
     */
    #[Test]
    #[DataProvider('providePatterns')]
    #[DataProvider('provideCaselessPatterns')]
    public function test_the_attribute_accepts_what_preg_match_matches(string $pattern, string $attribute, array $verdicts): void
    {
        $result = Regex::create(['cache' => null])->transpile($pattern, 'html-pattern');

        $this->assertSame($attribute, $result->pattern);
        $this->assertSame($attribute, $result->literal);
        $this->assertSame('v', $result->flags);
        foreach ($verdicts as $subject => $accepted) {
            $this->assertSame($accepted ? 1 : 0, preg_match($pattern, (string) $subject), $pattern.' on '.$subject);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, attribute: string, verdicts: array<int|string, bool>}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a search' => ['pattern' => '/\d+/', 'attribute' => '[\s\S]*(?:\d+)[\s\S]*', 'verdicts' => ['a1b' => true, 'ab' => false]];
        yield 'anchored both ends' => ['pattern' => '/^\d+$/', 'attribute' => '^\d+$', 'verdicts' => ['12' => true, '1a' => false]];
        yield 'subject anchors' => ['pattern' => '/\A[a-z]+\z/u', 'attribute' => '^[a-z]+$', 'verdicts' => ['abc' => true, 'ab1' => false]];
        yield 'anchored at the start only' => ['pattern' => '/^ab/', 'attribute' => '(?:^ab)[\s\S]*', 'verdicts' => ['abc' => true, 'cab' => false]];
        yield 'parentheses in a class' => ['pattern' => '/^[(a)]+$/', 'attribute' => '^[\(a\)]+$', 'verdicts' => ['(a)' => true, 'b' => false]];
        yield 'a hyphen and a dot in a class' => ['pattern' => '/^[\w.-]+@[\w-]+\.com$/', 'attribute' => '^[\w\.\-]+@[\w\-]+\.com$', 'verdicts' => ['a.b-c@d-e.com' => true, 'a@b.org' => false]];
        yield 'an alternation anchored on one side each' => ['pattern' => '/^a|b$/', 'attribute' => '[\s\S]*(?:^a|b$)[\s\S]*', 'verdicts' => ['ax' => true, 'xb' => true, 'xa' => false]];
        yield 'brackets in a class' => ['pattern' => '/^[\w\[\]]+$/', 'attribute' => '^[\w\[\]]+$', 'verdicts' => ['a[b]' => true, 'a(b)' => false]];
        yield 'an opening bracket alone in a class' => ['pattern' => '/^[^[]+$/', 'attribute' => '^[^\[]+$', 'verdicts' => ['ab' => true, 'a[b' => false]];
        yield 'a script' => ['pattern' => '/^\p{Han}+$/u', 'attribute' => '^\p{Script_Extensions=Han}+$', 'verdicts' => ['中、' => true, '中a' => false]];
        yield 'a property' => ['pattern' => '/^\p{L}+$/u', 'attribute' => '^\p{L}+$', 'verdicts' => ['été' => true, 'é1' => false]];
    }

    /**
     * The attribute takes no flag: each letter under /i, or under "(?i)",
     * is written with every character preg_match() takes for it, as the
     * running PCRE says. Under /u, "k" also takes the Kelvin sign and "s"
     * the long s; without /u a letter takes its ASCII pair only.
     *
     * @return iterable<string, array{pattern: string, attribute: string, verdicts: array<int|string, bool>}>
     */
    public static function provideCaselessPatterns(): iterable
    {
        yield 'caseless letters' => ['pattern' => '/^abc$/i', 'attribute' => '^[aA][bB][cC]$', 'verdicts' => ['ABC' => true, 'aBc' => true, 'abd' => false]];
        yield 'a caseless class' => ['pattern' => '/^[a-z]+$/i', 'attribute' => '^[a-zA-Z]+$', 'verdicts' => ['AbC' => true, 'a1' => false, "\u{17F}" => false, "\u{212A}" => false]];
        yield 'a caseless class under u' => ['pattern' => '/^[a-z]+$/iu', 'attribute' => '^[a-zA-Z\u017F\u212A]+$', 'verdicts' => ['AbC' => true, "\u{17F}" => true, "\u{212A}" => true, 'é' => false]];
        yield 'the Kelvin sign under u' => ['pattern' => '/^k$/iu', 'attribute' => '^[kK\u212A]$', 'verdicts' => ['K' => true, "\u{212A}" => true, 'x' => false]];
        yield 'no Kelvin sign without u' => ['pattern' => '/^k$/i', 'attribute' => '^[kK]$', 'verdicts' => ['K' => true, "\u{212A}" => false]];
        yield 'a negated caseless class' => ['pattern' => '/^[^a-z]+$/i', 'attribute' => '^[^a-zA-Z]+$', 'verdicts' => ['A' => false, '1-2' => true]];
        yield 'a negated caseless class under u' => ['pattern' => '/^[^a-z]$/iu', 'attribute' => '^[^a-zA-Z\u017F\u212A]$', 'verdicts' => ['A' => false, "\u{17F}" => false, "\u{212A}" => false, 'é' => true]];
        yield 'an inline switch' => ['pattern' => '/^a(?i)b$/', 'attribute' => '^a[bB]$', 'verdicts' => ['aB' => true, 'AB' => false]];
        yield 'a scoped group' => ['pattern' => '/^(?i:a)b$/', 'attribute' => '^(?:[aA])b$', 'verdicts' => ['Ab' => true, 'AB' => false]];
        yield 'a scoped group turning it off' => ['pattern' => '/^(?-i:a)b$/i', 'attribute' => '^(?:a)[bB]$', 'verdicts' => ['aB' => true, 'AB' => false]];
        yield 'a switch carried into the next branches' => ['pattern' => '/^(?:a(?i)b|c)$/', 'attribute' => '^(?:a[bB]|[cC])$', 'verdicts' => ['aB' => true, 'C' => true, 'Ab' => false]];
        yield 'a switch ending with its group' => ['pattern' => '/^(?:(a(?i)b)|c)$/', 'attribute' => '^(?:(a[bB])|c)$', 'verdicts' => ['aB' => true, 'C' => false]];
        yield 'a switch before the anchor' => ['pattern' => '/(?i)^ab$/', 'attribute' => '^[aA][bB]$', 'verdicts' => ['AB' => true, 'xab' => false]];
        yield 'a caseless search' => ['pattern' => '/\d+x/i', 'attribute' => '[\s\S]*(?:\d+[xX])[\s\S]*', 'verdicts' => ['a1X' => true, 'x1' => false]];
        yield 'a reference outside the caseless group' => ['pattern' => '/(?i:(a))\1/', 'attribute' => '[\s\S]*(?:(?:([aA]))\1)[\s\S]*', 'verdicts' => ['AA' => true, 'Aa' => false]];
        yield 'a hex escape' => ['pattern' => '/^\x41$/i', 'attribute' => '^[\x41a]$', 'verdicts' => ['a' => true, 'b' => false]];
        yield 'a quantified letter' => ['pattern' => '/^ab{2}$/i', 'attribute' => '^[aA][bB]{2}$', 'verdicts' => ['ABb' => true, 'abbb' => false]];
        yield 'a class of escapes' => ['pattern' => '/^[\w.-]+$/i', 'attribute' => '^[\w\.\-]+$', 'verdicts' => ['A.b-c' => true, 'a b' => false]];
        yield 'a byte letter stays as written' => ['pattern' => '/^é$/i', 'attribute' => '^é$', 'verdicts' => ['é' => true, 'É' => false]];
        yield 'a letter under u' => ['pattern' => '/^é$/iu', 'attribute' => '^[é\xC9]$', 'verdicts' => ['É' => true, 'e' => false]];
        yield 'a dash between letters' => ['pattern' => '/^a-1$/i', 'attribute' => '^[aA]-1$', 'verdicts' => ['A-1' => true, 'a1' => false]];
        yield 'a caret resetting the options' => ['pattern' => '/^(?i)a(?^)b$/', 'attribute' => '^[aA]b$', 'verdicts' => ['Ab' => true, 'AB' => false]];
        yield 'an ungreedy pattern' => ['pattern' => '/^a+?b*$/U', 'attribute' => '^a+b*?$', 'verdicts' => ['aab' => true, 'ac' => false]];
        yield 'an inline ungreedy switch' => ['pattern' => '/^a(?Ui)b+$/', 'attribute' => '^a[bB]+?$', 'verdicts' => ['aBb' => true, 'Ab' => false]];
        yield 'a dot-all group' => ['pattern' => '/^(?s:a.)$/', 'attribute' => '^(?:a.)$', 'verdicts' => ['ab' => true, 'a' => false]];
        yield 'a hex escape with no other case' => ['pattern' => '/^\x31$/i', 'attribute' => '^\x31$', 'verdicts' => ['1' => true, '2' => false]];
        yield 'a property closed under case' => ['pattern' => '/^\p{L}+$/iu', 'attribute' => '^\p{L}+$', 'verdicts' => ['ÉtÉ' => true, 'a1' => false]];
        yield 'a Greek sigma' => ['pattern' => '/^σ$/iu', 'attribute' => '^[σ\u03A3\u03C2]$', 'verdicts' => ['Σ' => true, 'ς' => true, 'o' => false]];

        // From PCRE2 10.45, "\p{Lu}", "\p{Ll}" and "\p{Lt}" under /i take
        // every cased letter, "\P{Lu}" none: what a property loses is
        // subtracted, a v class operation. Before, they are left as written.
        if (version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '>=')) {
            yield 'a caseless property' => ['pattern' => '/^\p{Lu}$/i', 'attribute' => '^[\p{Lu}a-z\xB5\xDF-\xF6\xF8-\xFF]$', 'verdicts' => ['a' => true, '1' => false]];
            yield 'a caseless negated property' => ['pattern' => '/^\P{Lu}$/i', 'attribute' => '^[\P{Lu}--[a-z\xB5\xDF-\xF6\xF8-\xFF]]$', 'verdicts' => ['a' => false, '1' => true]];
            yield 'a class gaining and losing characters' => ['pattern' => '/^[\P{Lu}k]$/i', 'attribute' => '^[[\P{Lu}kK]--[a-jl-z\xB5\xDF-\xF6\xF8-\xFF]]$', 'verdicts' => ['K' => true, 'a' => false, '_' => true]];
            yield 'a negated class gaining characters' => ['pattern' => '/^[^\P{Lu}]$/i', 'attribute' => '^[[^\P{Lu}]a-z\xB5\xDF-\xF6\xF8-\xFF]$', 'verdicts' => ['a' => true, '1' => false]];
            yield 'a negated class losing characters' => ['pattern' => '/^[^\p{Lu}]$/i', 'attribute' => '^[^\p{Lu}a-z\xB5\xDF-\xF6\xF8-\xFF]$', 'verdicts' => ['a' => false, '1' => true]];
        }
    }

    #[Test]
    public function test_an_atom_the_engine_cannot_probe_is_refused(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('PCRE cannot tell which characters');

        // The class holds every delimiter the probe could be written with.
        Regex::create(['cache' => null])->transpile("/^[\\/#~%!@;\x01]$/i", 'html-pattern');
    }

    #[Test]
    public function test_spelling_the_caseless_flag_out_is_noted(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^abc$/i', 'html-pattern');

        $this->assertContains('Spelled /i out: each letter is written with every case it matches, as [aA].', $result->notes);
    }

    #[Test]
    public function test_a_reference_under_the_caseless_flag_cannot_be_carried(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('A backreference under /i cannot be carried into the HTML pattern attribute: it would match its group\'s text in one case only.');

        Regex::create(['cache' => null])->transpile('/(a)\1/i', 'html-pattern');
    }

    #[Test]
    public function test_an_inline_flag_the_attribute_cannot_take_is_refused(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('The HTML pattern attribute cannot carry the inline flags (?n).');

        Regex::create(['cache' => null])->transpile('/a(?n)(b)+/', 'html-pattern');
    }

    #[Test]
    public function test_a_flag_the_attribute_cannot_take_is_refused(): void
    {
        $this->expectException(TranspileException::class);
        $this->expectExceptionMessage('The HTML pattern attribute takes no flags: /A cannot be carried.');

        Regex::create(['cache' => null])->transpile('/ab+/iA', 'html-pattern');
    }

    #[Test]
    public function test_the_line_flags_change_nothing_in_a_field_value(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^a.b$/sm', 'html-pattern');

        $this->assertSame('^a.b$', $result->pattern);
        $this->assertContains('A field value holds no line break: /s and /m change nothing there.', $result->notes);
    }

    #[Test]
    public function test_the_study_flag_is_dropped(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^ab+c$/S', 'html-pattern');

        $this->assertSame('^ab+c$', $result->pattern);
        $this->assertContains('Dropped /S: PHP has ignored it since 7.3, under PCRE2.', $result->notes);
    }

    #[Test]
    public function test_extended_mode_is_applied_and_noted(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^ a b $/x', 'html-pattern');

        $this->assertSame('^ab$', $result->pattern);
        $this->assertContains('Applied /x (extended mode): whitespace and comments were removed during compilation.', $result->notes);
    }

    #[Test]
    public function test_the_constructor_is_the_regexp_the_browser_builds(): void
    {
        $result = Regex::create(['cache' => null])->transpile('/^\d+$/', 'html');

        $this->assertSame('html-pattern', $result->target);
        $this->assertSame('new RegExp("^(?:^\\\\d+$)$", "v")', $result->constructor);
    }
}
