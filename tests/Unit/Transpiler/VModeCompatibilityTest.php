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

use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Target\JavaScript\VModeCompatibility;
use PHPRegex\Transpiler\Transpiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a browser makes of a pattern written for PCRE, read as is under the
 * v flag, as the HTML pattern attribute reads it. Whether each value
 * compiles was checked with Node 22, new RegExp(value, "v"): a value
 * compiles exactly when no finding says the browser refuses it.
 */
final class VModeCompatibilityTest extends TestCase
{
    /**
     * @param list<array{string, int, bool}> $expected construct, offset, and whether the browser refuses it
     */
    #[Test]
    #[DataProvider('provideValues')]
    public function test_findings_match_what_the_browser_compiles(string $value, bool $compiles, array $expected): void
    {
        $findings = self::check($value);

        $this->assertSame($expected, array_map(static fn (array $finding): array => [$finding['construct'], $finding['offset'], $finding['refused']], $findings));
        $this->assertSame(!$compiles, \in_array(true, array_column($findings, 'refused'), true));
    }

    /**
     * @return iterable<string, array{string, bool, list<array{string, int, bool}>}>
     */
    public static function provideValues(): iterable
    {
        yield '[a-z0-9-]+' => ['[a-z0-9-]+', false, [['class_syntax', 7, true]]];
        yield '[a-z0-9\\-]+' => ['[a-z0-9\\-]+', true, []];
        yield '[-a]' => ['[-a]', false, [['class_syntax', 1, true]]];
        yield '[a-]' => ['[a-]', false, [['class_syntax', 2, true]]];
        yield '[(]' => ['[(]', false, [['class_syntax', 1, true]]];
        yield '[\\(]' => ['[\\(]', true, []];
        yield '[)]' => ['[)]', false, [['class_syntax', 1, true]]];
        yield '[{]' => ['[{]', false, [['class_syntax', 1, true]]];
        yield '[}]' => ['[}]', false, [['class_syntax', 1, true]]];
        yield '[/]' => ['[/]', false, [['class_syntax', 1, true]]];
        yield '[|]' => ['[|]', false, [['class_syntax', 1, true]]];
        yield '[&&]' => ['[&&]', false, [['class_double_punctuator', 1, true]]];
        yield '[a&&b]' => ['[a&&b]', true, [['class_double_punctuator', 2, false]]];
        yield '[!!]' => ['[!!]', false, [['class_double_punctuator', 1, true]]];
        yield '[a!b]' => ['[a!b]', true, []];
        yield '[##]' => ['[##]', false, [['class_double_punctuator', 1, true]]];
        yield '[a#]' => ['[a#]', true, []];
        yield '[.]' => ['[.]', true, []];
        yield '[..]' => ['[..]', false, [['class_double_punctuator', 1, true]]];
        yield '[$]' => ['[$]', true, []];
        yield '[$$]' => ['[$$]', false, [['class_double_punctuator', 1, true]]];
        yield '[*]' => ['[*]', true, []];
        yield '[**]' => ['[**]', false, [['class_double_punctuator', 1, true]]];
        yield '[+]' => ['[+]', true, []];
        yield '[,,]' => ['[,,]', false, [['class_double_punctuator', 1, true]]];
        yield '[::]' => ['[::]', false, [['class_double_punctuator', 1, true]]];
        yield '[;;]' => ['[;;]', false, [['class_double_punctuator', 1, true]]];
        yield '[<<]' => ['[<<]', false, [['class_double_punctuator', 1, true]]];
        yield '[==]' => ['[==]', false, [['class_double_punctuator', 1, true]]];
        yield '[>>]' => ['[>>]', false, [['class_double_punctuator', 1, true]]];
        yield '[??]' => ['[??]', false, [['class_double_punctuator', 1, true]]];
        yield '[@@]' => ['[@@]', false, [['class_double_punctuator', 1, true]]];
        yield '[``]' => ['[``]', false, [['class_double_punctuator', 1, true]]];
        yield '[~~]' => ['[~~]', false, [['class_double_punctuator', 1, true]]];
        yield '[^^]' => ['[^^]', true, []];
        yield '[a^^]' => ['[a^^]', false, [['class_double_punctuator', 2, true]]];
        yield '[^a]' => ['[^a]', true, []];
        yield 'a++' => ['a++', false, [['possessive', 0, true]]];
        yield '(?>a)' => ['(?>a)', false, [['atomic', 0, true]]];
        yield '(?|a|b)' => ['(?|a|b)', false, [['branch_reset', 0, true]]];
        yield '(?i)a' => ['(?i)a', false, [['inline_flags', 0, true]]];
        yield '(?i:a)' => ['(?i:a)', false, [['inline_flags', 0, true]]];
        yield '(?<n>a)' => ['(?<n>a)', true, []];
        yield '(?P<n>a)' => ['(?P<n>a)', false, [['named_group', 0, true]]];
        yield '\\h' => ['\\h', false, [['char_type', 0, true]]];
        yield '\\R' => ['\\R', false, [['char_type', 0, true]]];
        yield '\\A' => ['\\A', false, [['assertion', 0, true]]];
        yield '\\z' => ['\\z', false, [['assertion', 0, true]]];
        yield '\\Z' => ['\\Z', false, [['assertion', 0, true]]];
        yield '\\G' => ['\\G', false, [['assertion', 0, true]]];
        yield '[[:alpha:]]' => ['[[:alpha:]]', true, [['posix_class', 1, false]]];
        yield '\\Qa\\E' => ['\\Qa\\E', false, [['quote', 0, true]]];
        yield '(?(1)a|b)' => ['(?(1)a|b)', false, [['conditional', 0, true]]];
        yield '(a)(?1)' => ['(a)(?1)', false, [['subroutine', 3, true]]];
        yield '(*FAIL)' => ['(*FAIL)', false, [['verb', 0, true]]];
        yield 'a\\Kb' => ['a\\Kb', false, [['keep', 1, true]]];
        yield '\\d+' => ['\\d+', true, []];
        yield '\\w+@\\w+' => ['\\w+@\\w+', true, []];
        yield '[a-z]{2,}' => ['[a-z]{2,}', true, []];
        yield 'a{,3}' => ['a{,3}', false, [['brace', 1, true], ['brace', 4, true]]];
        yield '\\/' => ['\\/', true, []];
        yield '\\-' => ['\\-', false, [['escape', 0, true]]];
        yield '[\\w-]' => ['[\\w-]', false, [['class_syntax', 3, true]]];
        yield '[\\w\\-]' => ['[\\w\\-]', true, []];
        yield '.*' => ['.*', true, []];
        yield '^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$' => ['^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}$', false, [['class_syntax', 15, true], ['class_syntax', 30, true]]];
        yield '\\p{L}+' => ['\\p{L}+', true, []];
        yield '[\\p{L}\\p{N}]' => ['[\\p{L}\\p{N}]', true, []];
        yield '\\x41' => ['\\x41', true, []];
        yield 'A' => ['A', true, []];
        yield '\\cA' => ['\\cA', true, []];
        yield '\\0' => ['\\0', true, []];
        yield '\\1(a)' => ['\\1(a)', true, []];
        yield '(a)\\1' => ['(a)\\1', true, []];
        yield '\\k<n>(?<n>a)' => ['\\k<n>(?<n>a)', true, []];
        yield '(?<n>a)\\k<n>' => ['(?<n>a)\\k<n>', true, []];
        yield '[\\b]' => ['[\\b]', true, []];
        yield '\\v' => ['\\v', true, [['char_type', 0, false]]];
        yield '\\N' => ['\\N', false, [['char_type', 0, true]]];
        yield '\\X' => ['\\X', false, [['char_type', 0, true]]];
        yield 'a{2}+' => ['a{2}+', false, [['possessive', 0, true]]];
        yield '[[]' => ['[[]', false, [['class_syntax', 1, true], ['class_syntax', 2, true]]];
        yield '[]]' => ['[]]', false, [['class_syntax', 1, true]]];
        yield '[\\]]' => ['[\\]]', true, []];
        yield '[a-z&&[aeiou]]' => ['[a-z&&[aeiou]]', false, [['class_double_punctuator', 4, false], ['class_syntax', 6, true]]];
        yield 'x{1,2}?' => ['x{1,2}?', true, []];
        yield '(?=a)' => ['(?=a)', true, []];
        yield '(?<=a)b' => ['(?<=a)b', true, []];
        yield '(?!a)' => ['(?!a)', true, []];
        yield '(?<!a)b' => ['(?<!a)b', true, []];
        yield '#' => ['#', true, []];
        yield '~' => ['~', true, []];
        yield '%' => ['%', true, []];
        yield '@' => ['@', true, []];
        yield '[\\/]' => ['[\\/]', true, []];
        yield '[a/b]' => ['[a/b]', false, [['class_syntax', 2, true]]];
        yield '[\\[]' => ['[\\[]', true, []];
        yield '[a|b]' => ['[a|b]', false, [['class_syntax', 2, true]]];
        yield '[a&&]' => ['[a&&]', false, [['class_double_punctuator', 2, true]]];
        yield '[&&b]' => ['[&&b]', false, [['class_double_punctuator', 1, true]]];
        yield '[^&&b]' => ['[^&&b]', false, [['class_double_punctuator', 2, true]]];
        yield '\\x{41}' => ['\\x{41}', false, [['escape', 0, true]]];
        yield '\\o{101}' => ['\\o{101}', false, [['escape', 0, true]]];
        yield '\\g1(a)' => ['\\g1(a)', false, [['escape', 0, true]]];
        yield '(a)\\g{1}' => ['(a)\\g{1}', false, [['escape', 3, true]]];
        yield 'a{1,}?' => ['a{1,}?', true, []];
        yield '[\\x41]' => ['[\\x41]', true, []];
        yield '[a-\\x7A]' => ['[a-\\x7A]', true, []];
        yield '\\u{1F600}' => ['\\u{1F600}', true, []];
        yield '[\\Qa\\E]' => ['[\\Qa\\E]', false, [['quote', 1, true]]];
        yield '\\Q\\Ea}' => ['\\Q\\Ea}', false, [['quote', 0, true], ['brace', 5, true]]];
        yield 'x\\Qa\\E' => ['x\\Qa\\E', false, [['quote', 1, true]]];
        yield '\\Qa{' => ['\\Qa{', false, [['quote', 0, true]]];
        yield '\\Qa\\E{' => ['\\Qa\\E{', false, [['quote', 0, true], ['brace', 5, true]]];
        yield '\\\\Qa' => ['\\\\Qa', true, []];
        yield 'a\\E' => ['a\\E', false, [['escape', 1, true]]];
        yield '\\B' => ['\\B', true, []];
        yield '[\\B]' => ['[\\B]', false, [['escape', 1, true]]];
        yield '\\01' => ['\\01', false, [['escape', 0, true]]];
        yield '\\01a' => ['\\01a', false, [['escape', 0, true]]];
        yield '\\0a1' => ['\\0a1', true, []];
        yield '[\\0]' => ['[\\0]', true, []];
        yield '\\c1' => ['\\c1', false, [['escape', 0, true]]];
        yield '\\cA1' => ['\\cA1', true, []];
        yield '\\x4' => ['\\x4', false, [['escape', 0, true]]];
        yield '\\x414' => ['\\x414', true, []];
        yield '\\u0041' => ['\\u0041', true, []];
        yield '\\u004' => ['\\u004', false, [['escape', 0, true]]];
        yield '\\u00412' => ['\\u00412', true, []];
        yield '\\u{}' => ['\\u{}', false, [['escape', 0, true]]];
        yield '\\u{41}' => ['\\u{41}', true, []];
        yield '\\u{4G}' => ['\\u{4G}', false, [['escape', 0, true]]];
        yield '\\u{41' => ['\\u{41', false, [['escape', 0, true], ['brace', 2, true]]];
        yield '\\q{a}' => ['\\q{a}', false, [['escape', 0, true]]];
        yield '[\\q{a|b}]' => ['[\\q{a|b}]', true, []];
        yield '[\\qa]' => ['[\\qa]', false, [['escape', 1, true]]];
        yield '(?<n>a)\\k{n}' => ['(?<n>a)\\k{n}', false, [['escape', 7, true], ['brace', 9, true], ['brace', 11, true]]];
        yield '[^]]' => ['[^]]', false, [['class_syntax', 2, true]]];
        yield '[a]}' => ['[a]}', false, [['brace', 3, true]]];
        yield 'a{2}}' => ['a{2}}', false, [['brace', 4, true]]];
        yield '\\d{a}' => ['\\d{a}', false, [['brace', 2, true], ['brace', 4, true]]];
        yield '\\p{L}}' => ['\\p{L}}', false, [['brace', 5, true]]];
        yield '[(-z]' => ['[(-z]', false, [['class_syntax', 1, true]]];
        yield '[[:alpha:]]}' => ['[[:alpha:]]}', false, [['posix_class', 1, false], ['brace', 11, true]]];
        yield '[[:^alpha:]]' => ['[[:^alpha:]]', true, [['posix_class', 1, false]]];
        yield '[é&&]' => ['[é&&]', false, [['class_double_punctuator', 3, true]]];
        yield '\\\\\\Qa\\E' => ['\\\\\\Qa\\E', false, [['quote', 2, true]]];
        yield '\\N{U+41}' => ['\\N{U+41}', false, [['escape', 0, true]]];
        yield '\\u{4}' => ['\\u{4}', true, []];
        yield '\\uA1}' => ['\\uA1}', false, [['escape', 0, true], ['brace', 4, true]]];
        yield '\\pL' => ['\\pL', false, [['escape', 0, true]]];
        yield '[\\k<n>](?<n>a)' => ['[\\k<n>](?<n>a)', false, [['escape', 1, true]]];
        yield '\\bfoo' => ['\\bfoo', true, []];
        yield '[!-(]' => ['[!-(]', false, [['class_syntax', 3, true]]];
        yield '[\\p{L}|]' => ['[\\p{L}|]', false, [['class_syntax', 6, true]]];
        yield 'a{1,}}' => ['a{1,}}', false, [['brace', 5, true]]];
        yield 'a{1,2}}' => ['a{1,2}}', false, [['brace', 6, true]]];
    }

    /**
     * @param list<string> $messages
     */
    #[Test]
    #[DataProvider('provideMessages')]
    public function test_each_finding_says_what_the_browser_does(string $value, array $messages): void
    {
        $this->assertSame($messages, array_column(self::check($value), 'message'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function provideMessages(): iterable
    {
        yield 'a possessive' => ['a{2}+', ['JavaScript has no possessive quantifier: "a{2}+" is a syntax error under v.']];
        yield 'a flag setting' => ['(a(?i)b)', ['JavaScript takes no flag setting "(?i)" that runs to the end of the group: a syntax error under v.']];
        yield 'scoped modifiers' => ['(?i-m:a)', ['JavaScript reads the scoped modifiers "(?i-m:" from ES2025 on only; an older browser refuses the pattern.']];
        yield 'a Python named group' => ['(?P<n>a)', ['JavaScript writes a named group "(?<name>" only: "(?P<" is a syntax error under v.']];
        yield 'a quoted named group' => ["(?'n'a)", ['JavaScript writes a named group "(?<name>" only: "(?\'" is a syntax error under v.']];
        yield 'a POSIX class' => ['[[:alpha:]]', ['JavaScript has no POSIX class: under v "[:alpha:]" is a nested class of the characters ":alpha:".']];
        yield 'a quote' => ['[\\Qa\\E]', ['JavaScript has no \\Q...\\E: under v it is an invalid escape; escape each character instead.']];
        yield 'a doubled punctuator' => ['[a$$]', ['Under v a class refuses "$$": escape one of them, "\\$".']];
        yield 'a lone }' => ['a}', ['Under v a lone "}" is a syntax error: write "\\}".']];
        yield 'a lone ]' => ['a]', ['Under v a lone "]" is a syntax error: write "\\]".']];
        yield 'an empty class' => ['[]]', ['Under v "[]" is the empty class, and the "]" after it a syntax error: write "\\]".']];
        yield 'an unknown escape' => ['a\\E', ['JavaScript knows no escape "\\E" under v: a syntax error.']];
        yield 'an unknown escape with a brace' => ['\\o{101}', ['JavaScript knows no escape "\\o" under v: a syntax error.']];
    }

    #[Test]
    public function test_findings_come_in_the_order_written(): void
    {
        $findings = self::check('a++(?>b)[[:alpha:]-]\h');

        $this->assertSame([0, 3, 9, 18, 20], array_column($findings, 'offset'));
        $this->assertSame(['possessive', 'atomic', 'posix_class', 'class_syntax', 'char_type'], array_column($findings, 'construct'));
    }

    #[Test]
    public function test_a_finding_says_what_to_write(): void
    {
        $this->assertSame('Under v a class takes "-" escaped only: write "\-".', self::check('[a-z0-9-]')[0]['message']);
        $this->assertSame('Under v "&&" in a class is the intersection of its sides, not two "&": escape one of them, "\&".', self::check('[a&&b]')[0]['message']);
        $this->assertSame('JavaScript knows no escape "\x{" under v: a syntax error.', self::check('\x{41}')[0]['message']);
        $this->assertSame('Under v a "{" that opens no quantifier is a syntax error: write "\{".', self::check('a{,3}')[0]['message']);
    }

    #[Test]
    public function test_the_checker_can_be_reused(): void
    {
        $checker = new VModeCompatibility();
        $parser = RegexParser::create();

        $this->assertCount(1, $checker->check($parser->parse('/a++/u')));
        $this->assertSame([], $checker->check($parser->parse('/a+/u')));
    }

    /**
     * The html-pattern target escapes in a class what the checker reads as
     * class syntax: what it writes, the checker passes.
     */
    #[Test]
    public function test_what_the_html_pattern_target_writes_passes(): void
    {
        foreach (['/^[-a()|{}\/]+$/', '/^[a-z0-9._%+-]+@[a-z]+$/i', '/^a++b$/', '/^(?>a|b)c$/', '/^\d\w\s$/', '/^[!-\/]$/i'] as $pattern) {
            $value = (new Transpiler())->transpile($pattern, 'html-pattern')->pattern;

            $this->assertSame([], self::check($value), $pattern.' as '.$value);
        }
    }

    /**
     * @return list<array{offset: int, construct: string, message: string, refused: bool}>
     */
    private static function check(string $value): array
    {
        foreach (['/', '#', '~', '%', '@', '!'] as $delimiter) {
            if (!str_contains($value, $delimiter)) {
                return (new VModeCompatibility())->check(RegexParser::create()->parse($delimiter.$value.$delimiter.'u'));
            }
        }

        self::fail('No delimiter fits '.$value);
    }
}
