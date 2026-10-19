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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\RangeDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP 7.3 and 7.4 compile every pattern with PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL
 * (php_pcre.c, PHP_PCRE_DEFAULT_EXTRA_COPTIONS), which the "X" modifier
 * clears: an escape PCRE2 refuses while it reads it is then the character
 * after the backslash, and the rest of the escape is read again as pattern
 * text. PHP 8.0 compiles without the option and ignores "X" (UPGRADING:
 * "When passing invalid escape sequences they are no longer interpreted as
 * literals").
 *
 * Every row was run on php:7.3-cli (7.3.33, PCRE2 10.32), php:7.4-cli
 * (7.4.33, PCRE2 10.35) and php:8.0-cli (8.0.30, PCRE2 10.35), and through
 * pcre2test 10.32 to 10.35 with "bad_escape_is_literal": the match is what
 * preg_match() finds on 7.3 and 7.4, and 8.0 refuses each pattern.
 */
final class BadEscapeIsLiteralTest extends TestCase
{
    /**
     * The tree the library reads for PHP 7.3 and 7.4, printed, finds on the
     * running engine what PHP 7.3 and 7.4 find with the pattern itself.
     */
    #[Test]
    #[DataProvider('provideBadEscapes')]
    public function test_php_7_reads_a_bad_escape_as_the_character_after_the_backslash(string $pattern, string $subject, ?string $match): void
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.33'], ['7.4', '10.35']] as [$php, $release]) {
            $parser = self::parser($php, $release);
            $validation = $parser->validate($pattern);
            $this->assertTrue($validation->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, $validation->error ?? ''));

            $printed = $parser->parse($pattern)->accept(new PatternPrinter());
            $this->assertSame(1 === preg_match($printed, $subject, $found) ? $found[0] : null, $match, \sprintf('%s printed as %s on PHP %s with PCRE2 %s', $pattern, $printed, $php, $release));
        }
    }

    #[Test]
    #[DataProvider('provideBadEscapePatterns')]
    public function test_php_8_refuses_a_bad_escape(string $pattern): void
    {
        $this->assertFalse(self::parser('8.0', '10.35')->validate($pattern)->isValid, $pattern.' on PHP 8.0');
    }

    /**
     * "X" clears the option: PHP 7.3 and 7.4 refuse the pattern then.
     */
    #[Test]
    #[DataProvider('provideBadEscapePatterns')]
    public function test_the_x_modifier_makes_php_7_refuse_a_bad_escape(string $pattern): void
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.33']] as [$php, $release]) {
            $this->assertFalse(self::parser($php, $release)->validate($pattern.'X')->isValid, $pattern.'X on PHP '.$php);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBadEscapePatterns(): iterable
    {
        foreach (self::provideBadEscapes() as $name => $row) {
            yield $name => ['pattern' => $row['pattern']];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, match: string|null}>
     */
    public static function provideBadEscapes(): iterable
    {
        // A letter no escape starts with, and the Perl case escapes.
        yield 'unknown letter' => ['pattern' => '/\y/', 'subject' => 'y', 'match' => 'y'];
        yield 'unknown capital' => ['pattern' => '/\Y/', 'subject' => 'Y', 'match' => 'Y'];
        yield 'case escape' => ['pattern' => '/\U/', 'subject' => 'U', 'match' => 'U'];
        yield 'lowercase case escape' => ['pattern' => '/\l/', 'subject' => 'l', 'match' => 'l'];
        yield 'u and four digits' => ['pattern' => '/\u0041/', 'subject' => 'u0041', 'match' => 'u0041'];
        yield 'repeated' => ['pattern' => '/\y+/', 'subject' => 'yyy', 'match' => 'yyy'];
        yield 'caseless' => ['pattern' => '/\y/i', 'subject' => 'Y', 'match' => 'Y'];
        yield 'under u' => ['pattern' => '/\y/u', 'subject' => 'y', 'match' => 'y'];
        yield 'under x' => ['pattern' => '/(?x) \y /', 'subject' => 'y', 'match' => 'y'];
        yield 'in a lookbehind' => ['pattern' => '/(?<=\y)a/', 'subject' => 'ya', 'match' => 'a'];
        yield 'in a class' => ['pattern' => '/[\y]/', 'subject' => 'y', 'match' => 'y'];
        yield 'ending a range' => ['pattern' => '/[a-\y]/', 'subject' => 'b', 'match' => 'b'];
        yield 'starting a range' => ['pattern' => '/[\y-z]/', 'subject' => 'z', 'match' => 'z'];

        // "\o": its braces are read again, a count among them.
        yield 'o alone' => ['pattern' => '/\o/', 'subject' => 'o', 'match' => 'o'];
        yield 'o and empty braces' => ['pattern' => '/\o{}/', 'subject' => 'o{}', 'match' => 'o{}'];
        yield 'o and no octal digit' => ['pattern' => '/\o{8}/', 'subject' => 'oooooooo', 'match' => 'oooooooo'];
        yield 'o and a range of counts' => ['pattern' => '/\o{1,3}/', 'subject' => 'ooo', 'match' => 'ooo'];
        yield 'o unclosed' => ['pattern' => '/\o{1/', 'subject' => 'o{1', 'match' => 'o{1'];
        yield 'o past a byte' => ['pattern' => '/\o{400}/', 'subject' => str_repeat('o', 400), 'match' => str_repeat('o', 400)];
        yield 'o in a class' => ['pattern' => '/[\o{8}]/', 'subject' => '8', 'match' => '8'];
        yield 'o in a lookbehind' => ['pattern' => '/(?<=\o{8})a/', 'subject' => 'ooooooooa', 'match' => 'a'];

        // "\x{...}".
        yield 'x and no hex digit' => ['pattern' => '/\x{zz}/', 'subject' => 'x{zz}', 'match' => 'x{zz}'];
        yield 'x and empty braces' => ['pattern' => '/\x{}/', 'subject' => 'x{}', 'match' => 'x{}'];
        yield 'x unclosed' => ['pattern' => '/\x{41/', 'subject' => 'x{41', 'match' => 'x{41'];
        yield 'x and a range of counts' => ['pattern' => '/\x{1,2}/', 'subject' => 'xx', 'match' => 'xx'];
        yield 'x past a byte' => ['pattern' => '/\x{100}/', 'subject' => str_repeat('x', 100), 'match' => str_repeat('x', 100)];
        yield 'x naming a surrogate' => ['pattern' => '/\x{d800}/u', 'subject' => 'x{d800}', 'match' => 'x{d800}'];
        yield 'x naming a surrogate under UTF' => ['pattern' => '/(*UTF)\x{d800}/', 'subject' => 'x{d800}', 'match' => 'x{d800}'];
        yield 'x naming the last surrogate' => ['pattern' => '/\x{dfff}/u', 'subject' => 'x{dfff}', 'match' => 'x{dfff}'];
        yield 'x in a class' => ['pattern' => '/[\x{zz}]/', 'subject' => '}', 'match' => '}'];
        yield 'x past Unicode in a class' => ['pattern' => '/[\x{110000}]/u', 'subject' => 'x', 'match' => 'x'];
        yield 'x and a quantified brace' => ['pattern' => '/\x{zz}+/', 'subject' => 'x{zz}}}', 'match' => 'x{zz}}}'];
        yield 'x in a lookbehind' => ['pattern' => '/(?<=\x{zz})a/', 'subject' => 'x{zz}a', 'match' => 'a'];

        // "\N{...}": "U+" without UTF, a name, a "U+" with no hex digit.
        yield 'N and U+ without UTF' => ['pattern' => '/\N{U+41}/', 'subject' => 'N{UU41}', 'match' => 'N{UU41}'];
        yield 'N and a name' => ['pattern' => '/\N{name}/', 'subject' => 'N{name}', 'match' => 'N{name}'];
        yield 'N and a name under u' => ['pattern' => '/\N{name}/u', 'subject' => 'N{name}', 'match' => 'N{name}'];
        yield 'N and U+ with no hex digit' => ['pattern' => '/\N{U+zz}/u', 'subject' => 'N{Uzz}', 'match' => 'N{Uzz}'];
        yield 'N and U+ unclosed' => ['pattern' => '/\N{U+41/u', 'subject' => 'N{U41', 'match' => 'N{U41'];
        yield 'N and an unclosed count' => ['pattern' => '/\N{2/', 'subject' => 'N{2', 'match' => 'N{2'];
        yield 'N and U+ in a class' => ['pattern' => '/[\N{U+41}]/', 'subject' => '+', 'match' => '+'];
        yield 'N and a name in a class' => ['pattern' => '/[\N{name}]/', 'subject' => 'N', 'match' => 'N'];

        // "\c" with no printable ASCII character after it.
        yield 'c at the end' => ['pattern' => '/a\c/', 'subject' => 'ac', 'match' => 'ac'];
        yield 'c after a control character' => ['pattern' => '/\cZ\c/', 'subject' => "\x1Ac", 'match' => "\x1Ac"];
        yield 'c and a letter past ASCII' => ['pattern' => "/\\c\u{E9}/u", 'subject' => "c\u{E9}", 'match' => "c\u{E9}"];
        yield 'c and a byte past ASCII' => ['pattern' => "/\\c\x80/", 'subject' => "c\x80", 'match' => "c\x80"];
        yield 'c and a newline' => ['pattern' => "/\\c\n/", 'subject' => "c\n", 'match' => "c\n"];
        yield 'c and the last control character' => ['pattern' => "/\\c\x1F/", 'subject' => "c\x1F", 'match' => "c\x1F"];

        // A backslash and digits: a number too big, an octal past a byte.
        yield 'number past 214748363' => ['pattern' => '/\214748364/', 'subject' => '214748364', 'match' => '214748364'];
        yield 'reference past 65535' => ['pattern' => '/\99999/', 'subject' => '99999', 'match' => '99999'];
        yield 'octal past a byte' => ['pattern' => '/\400/', 'subject' => '400', 'match' => '400'];
        yield 'octal past a byte after a group' => ['pattern' => '/(a)\400/', 'subject' => 'a400', 'match' => 'a400'];
        yield 'octal past a byte in a class' => ['pattern' => '/[\400]/', 'subject' => '0', 'match' => '0'];

        // "\g": no number or name, a number no group bears, too far back.
        yield 'g alone' => ['pattern' => '/\g/', 'subject' => 'g', 'match' => 'g'];
        yield 'g and a letter' => ['pattern' => '/\gx/', 'subject' => 'gx', 'match' => 'gx'];
        yield 'g and empty braces' => ['pattern' => '/\g{}/', 'subject' => 'g{}', 'match' => 'g{}'];
        yield 'g zero' => ['pattern' => '/\g0/', 'subject' => 'g0', 'match' => 'g0'];
        yield 'g zero braced, a count of zero' => ['pattern' => '/\g{0}/', 'subject' => 'g{0}', 'match' => ''];
        yield 'g minus zero' => ['pattern' => '/\g{-0}/', 'subject' => 'g{-0}', 'match' => 'g{-0}'];
        yield 'g plus zero, a repeated brace' => ['pattern' => '/\g{+0}/', 'subject' => 'g{{{0}', 'match' => 'g{{{0}'];
        yield 'g past 65535' => ['pattern' => '/\g99999/', 'subject' => 'g99999', 'match' => 'g99999'];
        yield 'g padded' => ['pattern' => '/\g{ -1 }/', 'subject' => 'g{ -1 }', 'match' => 'g{ -1 }'];
        yield 'g unclosed name' => ['pattern' => '/\g<x/', 'subject' => 'g<x', 'match' => 'g<x'];
        yield 'g relative with no group' => ['pattern' => '/\g{-1}/', 'subject' => 'g{-1}', 'match' => 'g{-1}'];
        yield 'g relative unbraced' => ['pattern' => '/\g-1/', 'subject' => 'g-1', 'match' => 'g-1'];
        yield 'g relative call' => ['pattern' => '/\g<-1>/', 'subject' => 'g<-1>', 'match' => 'g<-1>'];
        yield 'g relative quoted call' => ['pattern' => "/\\g'-1'/", 'subject' => "g'-1'", 'match' => "g'-1'"];
        yield 'g relative past the groups' => ['pattern' => '/(a)\g{-2}/', 'subject' => 'ag{-2}', 'match' => 'ag{-2}'];
        yield 'g relative past a branch reset' => ['pattern' => '/(?|(a)|(b))\g{-2}/', 'subject' => 'bg{-2}', 'match' => 'bg{-2}'];
        yield 'g relative past a group (?n) leaves unnumbered' => ['pattern' => '/(?n)(a)\g{-1}/', 'subject' => 'ag{-1}', 'match' => 'ag{-1}'];

        // "\g" and "+n" past 65535 less the groups opened so far, which
        // read_number() refuses: the "+" then repeats the character before
        // it, the "{", "<" or "'", or the "g" itself (php:7.3-cli,
        // php:7.4.0-cli, php:7.4.6-cli and php:7.4-cli agree on each row).
        yield 'g forward past 65535 less the groups' => ['pattern' => '/(a)\g{+65535}/', 'subject' => 'ag{{{65535}', 'match' => 'ag{{{65535}'];
        yield 'g forward past 65535 less two groups' => ['pattern' => '/(a)(b)\g{+65534}/', 'subject' => 'abg{65534}', 'match' => 'abg{65534}'];
        yield 'g forward past 65535 less the group still open' => ['pattern' => '/(a\g{+65535})/', 'subject' => 'ag{65535}', 'match' => 'ag{65535}'];
        yield 'g forward past 65535 less a branch reset' => ['pattern' => '/(?|(a)|(b))\g{+65535}/', 'subject' => 'ag{65535}', 'match' => 'ag{65535}'];
        yield 'g forward past 65535 less a named group' => ['pattern' => '/(?<n>a)\g{+65535}/', 'subject' => 'ag{65535}', 'match' => 'ag{65535}'];
        yield 'g forward past 65535 with leading zeros' => ['pattern' => '/(a)\g{+0065535}/', 'subject' => 'ag{0065535}', 'match' => 'ag{0065535}'];
        yield 'g forward past 65535, quantified' => ['pattern' => '/(a)\g{+65535}*/', 'subject' => 'ag{65535}}}', 'match' => 'ag{65535}}}'];
        yield 'g forward past 65535, optional' => ['pattern' => '/(a)\g{+65535}?/', 'subject' => 'ag{65535', 'match' => 'ag{65535'];
        yield 'g forward past 65535, caseless' => ['pattern' => '/(a)\g{+65535}/i', 'subject' => 'AG{65535}', 'match' => 'AG{65535}'];
        yield 'g forward past 65535 under u' => ['pattern' => '/(a)\g{+65535}/u', 'subject' => 'ag{65535}', 'match' => 'ag{65535}'];
        yield 'g forward past 65535 unbraced' => ['pattern' => '/(a)\g+65535/', 'subject' => 'agg65535', 'match' => 'agg65535'];
        yield 'g forward past 65535 unbraced, optional' => ['pattern' => '/(a)\g+65535?/', 'subject' => 'agg6553', 'match' => 'agg6553'];
        yield 'g forward call past 65535' => ['pattern' => '/(a)\g<+65535>/', 'subject' => 'ag<<65535>', 'match' => 'ag<<65535>'];
        yield 'g forward quoted call past 65535' => ['pattern' => "/(a)\\g'+65535'/", 'subject' => "ag''65535'", 'match' => "ag''65535'"];

        // "\k" with no well-formed name.
        yield 'k alone' => ['pattern' => '/\k/', 'subject' => 'k', 'match' => 'k'];
        yield 'k and a name starting with a digit' => ['pattern' => '/\k<1a>/', 'subject' => 'k<1a>', 'match' => 'k<1a>'];
        yield 'k and a name starting with an Arabic digit under u' => ['pattern' => "/\\k<\u{661}a>/u", 'subject' => "k<\u{661}a>", 'match' => "k<\u{661}a>"];
        // U+0870 is a letter from Unicode 14, which PCRE2 10.39 brought.
        yield 'k and a letter newer than the tables under u' => ['pattern' => "/\\k<a\u{870}>/u", 'subject' => "k<a\u{870}>", 'match' => "k<a\u{870}>"];
        yield 'k and an empty name' => ['pattern' => '/\k<>/', 'subject' => 'k<>', 'match' => 'k<>'];
        yield 'k unclosed' => ['pattern' => '/\k{x/', 'subject' => 'k{x', 'match' => 'k{x'];
        yield 'k and a name of 33 characters' => ['pattern' => '/\k<'.str_repeat('a', 33).'>/', 'subject' => 'k<'.str_repeat('a', 33).'>', 'match' => 'k<'.str_repeat('a', 33).'>'];

        // "\p" and "\P" outside a class: malformed or unknown.
        yield 'p alone' => ['pattern' => '/a\p/', 'subject' => 'ap', 'match' => 'ap'];
        yield 'p and an unknown letter' => ['pattern' => '/\py/', 'subject' => 'py', 'match' => 'py'];
        yield 'p and an unknown name' => ['pattern' => '/\p{Bogus}/', 'subject' => 'p{Bogus}', 'match' => 'p{Bogus}'];
        yield 'p and an unknown name under u' => ['pattern' => '/\p{Bogus}/u', 'subject' => 'p{Bogus}', 'match' => 'p{Bogus}'];
        yield 'p and a script PCRE2 10.39 added' => ['pattern' => '/\p{Vithkuqi}/', 'subject' => 'p{Vithkuqi}', 'match' => 'p{Vithkuqi}'];
        yield 'P and a script PCRE2 10.39 added, negated: the "^" an anchor' => ['pattern' => '/\P{^Toto}/u', 'subject' => 'P{^Toto}', 'match' => null];
        yield 'P and an unknown name' => ['pattern' => '/\P{Bogus}/', 'subject' => 'P{Bogus}', 'match' => 'P{Bogus}'];
        yield 'p and empty braces' => ['pattern' => '/\p{}/', 'subject' => 'p{}', 'match' => 'p{}'];
        yield 'p unclosed' => ['pattern' => '/\p{L/', 'subject' => 'p{L', 'match' => 'p{L'];
        yield 'p and a repeated brace' => ['pattern' => '/\p{Bogus}?a/u', 'subject' => 'p{Bogusa', 'match' => 'p{Bogusa'];
        yield 'p and a name of 31 characters' => ['pattern' => '/\p{'.str_repeat('a', 31).'}/', 'subject' => 'p{'.str_repeat('a', 31).'}', 'match' => 'p{'.str_repeat('a', 31).'}'];
        yield 'p in a lookbehind' => ['pattern' => '/(?<=\p{Bogus})a/', 'subject' => 'p{Bogus}a', 'match' => 'a'];
    }

    /**
     * An escape PCRE2 reads whole is what it is with the option as without
     * it: a code point named under UTF in a class, "\N" alone or before a
     * count, "\8" in a class, a class escape after a range or after a
     * hyphen that ended one (php:7.3-cli, php:7.4-cli and php:8.0-cli agree
     * on every row).
     */
    #[Test]
    #[DataProvider('provideEscapesTheOptionLeavesAlone')]
    public function test_php_7_reads_an_escape_its_pcre2_reads_whole_as_that_escape(string $pattern, string $subject, ?string $match): void
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.33'], ['7.4', '10.35'], ['8.0', '10.35']] as [$php, $release]) {
            $parser = self::parser($php, $release);
            $validation = $parser->validate($pattern);
            $this->assertTrue($validation->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, $validation->error ?? ''));

            $printed = $parser->parse($pattern)->accept(new PatternPrinter());
            $this->assertSame(1 === preg_match($printed, $subject, $found) ? $found[0] : null, $match, \sprintf('%s printed as %s on PHP %s with PCRE2 %s', $pattern, $printed, $php, $release));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, match: string|null}>
     */
    public static function provideEscapesTheOptionLeavesAlone(): iterable
    {
        yield 'code point in a class under UTF' => ['pattern' => '/[\N{U+41}]/u', 'subject' => 'A', 'match' => 'A'];
        yield 'code point in a class under UTF, not its letter' => ['pattern' => '/[\N{U+41}]/u', 'subject' => 'N', 'match' => null];
        yield 'N alone' => ['pattern' => '/a\Nc/', 'subject' => 'abc', 'match' => 'abc'];
        yield 'N and an open count' => ['pattern' => '/\N{2,}/', 'subject' => 'abc', 'match' => 'abc'];
        yield 'eight in a class' => ['pattern' => '/[\8]/', 'subject' => '8', 'match' => '8'];
        yield 'class escape after a range' => ['pattern' => '/[a-b\d]/', 'subject' => '5', 'match' => '5'];
        yield 'class escape after a hyphen ending a range' => ['pattern' => '/[!--\d]/', 'subject' => ',', 'match' => ','];
        yield 'class escape after a hyphen ending a range, not its letter' => ['pattern' => '/[!--\d]/', 'subject' => 'd', 'match' => null];
        yield 'call to the whole pattern' => ['pattern' => '/a\g<0>?b/', 'subject' => 'aabb', 'match' => 'aabb'];
        yield 'reference back to the group just closed' => ['pattern' => '/(a)\g{-1}/', 'subject' => 'aa', 'match' => 'aa'];
        yield 'call back to the group just closed' => ['pattern' => '/(a)\g<-1>/', 'subject' => 'aa', 'match' => 'aa'];
        yield 'x naming the last code point' => ['pattern' => '/\x{10ffff}/u', 'subject' => "\u{10FFFF}", 'match' => "\u{10FFFF}"];
        yield 'o naming the last code point' => ['pattern' => '/\o{4177777}/u', 'subject' => "\u{10FFFF}", 'match' => "\u{10FFFF}"];
        yield 'x naming the code point past the surrogates' => ['pattern' => '/\x{e000}/u', 'subject' => "\u{E000}", 'match' => "\u{E000}"];
        yield 'x naming the code point before the surrogates' => ['pattern' => '/\x{d7ff}/u', 'subject' => "\u{D7FF}", 'match' => "\u{D7FF}"];
        yield 'o naming the code point before the surrogates' => ['pattern' => '/\o{153777}/u', 'subject' => "\u{D7FF}", 'match' => "\u{D7FF}"];
        yield 'x naming the last byte' => ['pattern' => '/\x{ff}/', 'subject' => "\xFF", 'match' => "\xFF"];
        yield 'c and a space' => ['pattern' => '/\c /', 'subject' => '`', 'match' => '`'];
        yield 'c and a tilde' => ['pattern' => '/\c~/', 'subject' => '>', 'match' => '>'];
        yield 'N and a count up to 65535' => ['pattern' => '/a\N{0,65535}/', 'subject' => 'ab', 'match' => 'ab'];
    }

    /**
     * The body of an alphabetic assertion, from PCRE2 10.33, ends where the
     * literal reading of its escapes ends it: "\p{L)}" is "p{L" then the ")"
     * closing the body.
     */
    #[Test]
    #[DataProvider('provideBadEscapesInAssertionBodies')]
    public function test_php_7_4_reads_a_bad_escape_in_the_body_of_an_alphabetic_assertion(string $pattern, string $subject, string $match): void
    {
        foreach (['10.33', '10.35'] as $release) {
            $parser = self::parser('7.4', $release);
            $validation = $parser->validate($pattern);
            $this->assertTrue($validation->isValid, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error));

            $printed = $parser->parse($pattern)->accept(new PatternPrinter());
            $this->assertSame(1 === preg_match($printed, $subject, $found) ? $found[0] : null, $match, $pattern.' printed as '.$printed);
        }

        $this->assertFalse(self::parser('8.0', '10.35')->validate($pattern)->isValid, $pattern.' on PHP 8.0');
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, match: string}>
     */
    public static function provideBadEscapesInAssertionBodies(): iterable
    {
        yield 'unknown letter' => ['pattern' => '/a(*pla:\y)y/', 'subject' => 'ay', 'match' => 'ay'];
        yield 'x and no hex digit' => ['pattern' => '/(*plb:\x{zz})a/', 'subject' => 'x{zz}a', 'match' => 'a'];
        yield 'property name holding the closing parenthesis' => ['pattern' => '/(*nla:\p{L)}b/', 'subject' => 'p{L}b', 'match' => '}b'];
    }

    /**
     * PCRE2 10.32 also reads as a literal the escapes it refuses in a class
     * once it has read them: an assertion, "\N", "\k", and a class escape
     * ending a range. 10.33 refuses them, the option set or not (pcre2test
     * 10.33, 10.34 and 10.35; php:7.4-cli). Its group names are ASCII only:
     * "\k<é>" names none there, and is the text "k<é>".
     */
    #[Test]
    #[DataProvider('provideClassEscapesOnlyPcre2Ten32Reads')]
    public function test_pcre2_10_32_also_reads_a_class_escape_it_refuses_as_its_letter(string $pattern, string $subject, string $match, int $offset): void
    {
        $parser = self::parser('7.3', '10.32');
        $validation = $parser->validate($pattern);
        $this->assertTrue($validation->isValid, $pattern.': '.($validation->error ?? ''));
        $printed = $parser->parse($pattern)->accept(new PatternPrinter());
        $this->assertSame(1 === preg_match($printed, $subject, $found) ? $found[0] : null, $match, $pattern.' printed as '.$printed);

        foreach (['10.33', '10.35'] as $release) {
            $refusal = self::parser('7.4', $release)->validate($pattern);
            $this->assertFalse($refusal->isValid, $pattern.' on PCRE2 '.$release);
            $this->assertSame($offset, $refusal->offset, $pattern.' on PCRE2 '.$release);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, match: string, offset: int}>
     */
    public static function provideClassEscapesOnlyPcre2Ten32Reads(): iterable
    {
        yield 'assertion' => ['pattern' => '/[\B]/', 'subject' => 'B', 'match' => 'B', 'offset' => 2];
        yield 'line break' => ['pattern' => '/[\R]/', 'subject' => 'R', 'match' => 'R', 'offset' => 2];
        yield 'cluster' => ['pattern' => '/[\X]/', 'subject' => 'X', 'match' => 'X', 'offset' => 2];
        yield 'start of subject' => ['pattern' => '/[\A]/', 'subject' => 'A', 'match' => 'A', 'offset' => 2];
        yield 'end of subject' => ['pattern' => '/[\z]/', 'subject' => 'z', 'match' => 'z', 'offset' => 2];
        yield 'keep' => ['pattern' => '/[\K]/', 'subject' => 'K', 'match' => 'K', 'offset' => 2];
        yield 'code unit' => ['pattern' => '/[\C]/', 'subject' => 'C', 'match' => 'C', 'offset' => 2];
        yield 'not a newline' => ['pattern' => '/[\N]/', 'subject' => 'N', 'match' => 'N', 'offset' => 3];
        yield 'k' => ['pattern' => '/[\k]/', 'subject' => 'k', 'match' => 'k', 'offset' => 2];
        yield 'k and a name' => ['pattern' => '/[\k<x>]/', 'subject' => '<', 'match' => '<', 'offset' => 2];
        yield 'type ending a range' => ['pattern' => '/[a-\d]/', 'subject' => 'b', 'match' => 'b', 'offset' => 5];
        yield 'property ending a range' => ['pattern' => '/[a-\p{L}]/', 'subject' => 'L', 'match' => 'L', 'offset' => 5];
        yield 'assertion starting a range' => ['pattern' => '/[\B-z]/', 'subject' => 'q', 'match' => 'q', 'offset' => 2];
        yield 'assertion ending a range' => ['pattern' => '/[A-\B]/', 'subject' => 'B', 'match' => 'B', 'offset' => 4];
        yield 'assertion after a class escape' => ['pattern' => '/[\d\B]/', 'subject' => 'B', 'match' => 'B', 'offset' => 4];
        yield 'name past ASCII under UTF' => ['pattern' => "/\\k<\u{E9}>/u", 'subject' => "k<\u{E9}>", 'match' => "k<\u{E9}>", 'offset' => 3];
    }

    /**
     * What PCRE2 refuses after it read the escape is refused with the option
     * as without it: a reference to no group, which PCRE2 judges once the
     * pattern is read, a property in a class, a class escape before a "-",
     * a count read again past 65535. The offset is the one php:7.3-cli and
     * php:7.4-cli report.
     */
    #[Test]
    #[DataProvider('provideRefusalsTheOptionKeeps')]
    public function test_php_7_still_refuses_what_pcre2_refuses_after_reading_the_escape(string $pattern, int $offset): void
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.33'], ['7.4', '10.35']] as [$php, $release]) {
            $validation = self::parser($php, $release)->validate($pattern);
            $this->assertFalse($validation->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
            $this->assertSame($offset, $validation->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideRefusalsTheOptionKeeps(): iterable
    {
        yield 'reference to no group' => ['pattern' => '/\8/', 'offset' => 1];
        yield 'reference past the groups' => ['pattern' => '/(a)\2/', 'offset' => 4];
        yield 'braced reference to no group' => ['pattern' => '/\g{9}/', 'offset' => 4];
        yield 'relative reference forward to no group' => ['pattern' => '/\g{+1}/', 'offset' => 5];
        yield 'relative reference forward to 65535 less the groups' => ['pattern' => '/(a)\g{+65534}/', 'offset' => 12];
        yield 'relative reference forward past a group (?n) leaves unnumbered' => ['pattern' => '/(?n)(a)\g{+65535}/', 'offset' => 16];
        yield 'g forward past 65535 in a lookbehind, a repeat' => ['pattern' => '/(a)(?<=\g{+65535})/', 'offset' => 3];
        yield 'call to no group' => ['pattern' => '/\g<9>/', 'offset' => 4];
        yield 'call to no name' => ['pattern' => '/\g<x>/', 'offset' => 3];
        yield 'named reference to no group' => ['pattern' => '/\k<x>/', 'offset' => 3];
        yield 'named reference of 32 characters to no group' => ['pattern' => '/\k<'.str_repeat('a', 32).'>/', 'offset' => 3];
        yield 'unknown property in a class' => ['pattern' => '/[\p{Bogus}]/', 'offset' => 10];
        yield 'unknown property letter in a class' => ['pattern' => '/[\py]/', 'offset' => 4];
        yield 'malformed property in a class' => ['pattern' => '/[\p]/', 'offset' => 4];
        yield 'class escape before a hyphen' => ['pattern' => '/[\d-z]/', 'offset' => 3];
        yield 'class escape before a hyphen and a bad escape' => ['pattern' => '/[\d-\y]/', 'offset' => 3];
        yield 'x past Unicode, its braces a count past 65535' => ['pattern' => '/\x{110000}/u', 'offset' => 9];
        yield 'o naming a surrogate, its braces a count past 65535' => ['pattern' => '/\o{154000}/u', 'offset' => 9];
        yield 'g past 214748363, its braces a count past 65535' => ['pattern' => '/\g{214748364}/', 'offset' => 9];
        yield 'N and a count past 65535, read again as a count' => ['pattern' => '/a\N{0,65536}/', 'offset' => 11];
    }

    /**
     * Over a range of PHP versions, a pattern PHP 7.3 accepts through the
     * option is refused from PHP 8.0.
     */
    #[Test]
    public function test_a_range_from_php_7_names_php_8_0_as_refusing_a_bad_escape(): void
    {
        $verdict = RangeDiff::between(70300, 80400)->diff('/a\y/');

        $this->assertTrue($verdict->from->validation->isValid);
        $this->assertSame(80000, $verdict->refusedBy()?->target->phpVersionId);
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
