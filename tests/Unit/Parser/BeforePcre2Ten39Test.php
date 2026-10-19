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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\CompatibilityChecker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The changes before PCRE2 10.39 the library knows, each verified on a real
 * engine: pcre2test built from each release tarball from 10.32 to 10.40,
 * and preg_match() on php:7.3-cli (PCRE2 10.32), php:7.4-cli and
 * php:8.0-cli (10.35) and php:8.1-cli (10.39).
 *
 * The alphabetic names of the assertions, "(*pla:" and the others, arrived
 * in 10.33: 10.32 refuses them where the run of letters of the name ends
 * ("(*VERB) not recognized or malformed", error 160). A count past 65535
 * in a brace that is no quantifier is text from 10.37; before, "number too
 * big in {} quantifier" (error 105) at the digit that takes it past 65535.
 */
final class BeforePcre2Ten39Test extends TestCase
{
    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_an_older_release_refuses_what_it_did_not_know(string $pattern, string $release, int $offset, ErrorCode $code, string $from): void
    {
        $validation = self::parser($release)->validate($pattern);

        $this->assertFalse($validation->isValid, $pattern.' on PCRE2 '.$release);
        $this->assertSame($offset, $validation->offset);
        $this->assertSame($code, $validation->errorCode);
        $this->assertTrue(self::parser($from)->validate($pattern)->isValid, $pattern.' on PCRE2 '.$from);
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, offset: int, code: ErrorCode, from: string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'lookahead' => ['pattern' => '/(*pla:a)a/', 'release' => '10.32', 'offset' => 5, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'negative lookahead' => ['pattern' => '/(*nla:b)a/', 'release' => '10.32', 'offset' => 5, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'lookbehind' => ['pattern' => '/b(*plb:a)/', 'release' => '10.32', 'offset' => 6, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'negative lookbehind' => ['pattern' => '/b(*nlb:a)/', 'release' => '10.32', 'offset' => 6, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'atomic group' => ['pattern' => '/(*atomic:a|ab)c/', 'release' => '10.32', 'offset' => 8, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        // The run of letters stops at the underscore: "(*positive".
        yield 'long lookahead name' => ['pattern' => '/(*positive_lookahead:a)a/', 'release' => '10.32', 'offset' => 10, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'long lookbehind name' => ['pattern' => '/b(*negative_lookbehind:a)/', 'release' => '10.32', 'offset' => 11, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'assertion as a condition' => ['pattern' => '/(?(*pla:a)a|b)/', 'release' => '10.32', 'offset' => 3, 'code' => ErrorCode::ConditionalInvalid, 'from' => '10.33'];
        yield 'empty assertion' => ['pattern' => '/(*nla:)/', 'release' => '10.32', 'offset' => 5, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'open count' => ['pattern' => '/a{65536/', 'release' => '10.35', 'offset' => 7, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'open count at the start' => ['pattern' => '/{65536/', 'release' => '10.35', 'offset' => 6, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'open count opening a group' => ['pattern' => '/(?:{65536)/', 'release' => '10.35', 'offset' => 9, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'open count after a comma' => ['pattern' => '/a{1,65536/', 'release' => '10.32', 'offset' => 9, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'open count with a zero first' => ['pattern' => '/a{065536/', 'release' => '10.35', 'offset' => 8, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'open count past every integer' => ['pattern' => '/a{6553600000000000000/', 'release' => '10.35', 'offset' => 7, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'count followed by a letter' => ['pattern' => '/a{65536x/', 'release' => '10.33', 'offset' => 7, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'count followed by a space' => ['pattern' => '/a{65536 /', 'release' => '10.32', 'offset' => 7, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        // A count padded before its "}", read as text from 10.37 to 10.42,
        // is still read as digits first: pcre2test 10.32 to 10.36.
        yield 'padded count' => ['pattern' => '/a{65536 }/', 'release' => '10.36', 'offset' => 7, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'padded count before a comma' => ['pattern' => '/a{65536, }/', 'release' => '10.35', 'offset' => 7, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'padded count after a comma' => ['pattern' => '/a{1,65536 }/', 'release' => '10.32', 'offset' => 9, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        yield 'padded count at the start' => ['pattern' => '/{65536 }/', 'release' => '10.33', 'offset' => 6, 'code' => ErrorCode::QuantifierTooBig, 'from' => '10.37'];
        // Script runs arrived in 10.33: 10.32 refuses the name where its
        // run of letters ends.
        yield 'script run' => ['pattern' => '/(*sr:a)b/', 'release' => '10.32', 'offset' => 4, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'atomic script run' => ['pattern' => '/(*asr:a)b/', 'release' => '10.32', 'offset' => 5, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'long script run name' => ['pattern' => '/(*script_run:a)b/', 'release' => '10.32', 'offset' => 8, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        yield 'long atomic script run name' => ['pattern' => '/x(*atomic_script_run:a)b/', 'release' => '10.32', 'offset' => 9, 'code' => ErrorCode::VerbInvalid, 'from' => '10.33'];
        // A group name of any script under UTF arrived in 10.33: 10.32 wants
        // a name where a non-ASCII letter opens it, and its end where one
        // follows ASCII ones.
        yield 'name opening with a letter past ASCII' => ['pattern' => '/(?<é>a)\k<é>/u', 'release' => '10.32', 'offset' => 3, 'code' => ErrorCode::GroupNameExpected, 'from' => '10.33'];
        yield 'quoted name opening with a letter past ASCII' => ['pattern' => "/(?'é'a)/u", 'release' => '10.32', 'offset' => 3, 'code' => ErrorCode::GroupNameExpected, 'from' => '10.33'];
        yield 'Python name opening with a letter past ASCII' => ['pattern' => '/(?P<é>a)/u', 'release' => '10.32', 'offset' => 4, 'code' => ErrorCode::GroupNameExpected, 'from' => '10.33'];
        yield 'name holding a letter past ASCII' => ['pattern' => '/(?<aé>a)/u', 'release' => '10.32', 'offset' => 4, 'code' => ErrorCode::GroupNameUnterminated, 'from' => '10.33'];
        yield 'name holding a digit past ASCII' => ['pattern' => '/(?<a٣>a)/u', 'release' => '10.32', 'offset' => 4, 'code' => ErrorCode::GroupNameUnterminated, 'from' => '10.33'];
        // A repeated "(*ACCEPT)" arrived in 10.34: before, the quantifier
        // follows nothing it can repeat.
        yield 'optional accept' => ['pattern' => '/a(*ACCEPT)?b/', 'release' => '10.33', 'offset' => 10, 'code' => ErrorCode::QuantifierNothingToRepeat, 'from' => '10.34'];
        yield 'lazy accept with a name' => ['pattern' => '/a(*ACCEPT:x)??b/', 'release' => '10.32', 'offset' => 12, 'code' => ErrorCode::QuantifierNothingToRepeat, 'from' => '10.34'];
        yield 'accept repeated by a count' => ['pattern' => '/a(*ACCEPT){2}b/', 'release' => '10.33', 'offset' => 12, 'code' => ErrorCode::QuantifierNothingToRepeat, 'from' => '10.34'];
        // The non-atomic lookarounds arrived in 10.34: 10.32 knows no
        // alphabetic name, 10.33 not these.
        yield 'non-atomic lookahead on 10.33' => ['pattern' => '/(*napla:a)a/', 'release' => '10.33', 'offset' => 7, 'code' => ErrorCode::VerbInvalid, 'from' => '10.34'];
        yield 'non-atomic lookahead on 10.32' => ['pattern' => '/(*napla:a)a/', 'release' => '10.32', 'offset' => 7, 'code' => ErrorCode::VerbInvalid, 'from' => '10.34'];
        yield 'non-atomic lookbehind' => ['pattern' => '/b(*naplb:a)/', 'release' => '10.33', 'offset' => 8, 'code' => ErrorCode::VerbInvalid, 'from' => '10.34'];
        yield 'long non-atomic name on 10.33' => ['pattern' => '/(*non_atomic_positive_lookahead:a)a/', 'release' => '10.33', 'offset' => 31, 'code' => ErrorCode::VerbInvalid, 'from' => '10.34'];
        yield 'long non-atomic name on 10.32' => ['pattern' => '/(*non_atomic_positive_lookahead:a)a/', 'release' => '10.32', 'offset' => 5, 'code' => ErrorCode::VerbInvalid, 'from' => '10.34'];
        // Their short spellings arrived in 10.35.
        yield 'short non-atomic lookahead' => ['pattern' => '/(?*a)a/', 'release' => '10.34', 'offset' => 2, 'code' => ErrorCode::GroupSyntax, 'from' => '10.35'];
        yield 'short non-atomic lookbehind' => ['pattern' => '/(?<*a)b/', 'release' => '10.34', 'offset' => 3, 'code' => ErrorCode::GroupNameExpected, 'from' => '10.35'];
        // Before 10.37 the digits after a backslash are one number, refused
        // past 214748363 at the digit that takes it there.
        yield 'backslash and nine digits' => ['pattern' => '/\214748364/', 'release' => '10.36', 'offset' => 10, 'code' => ErrorCode::GroupNumberTooBig, 'from' => '10.37'];
        yield 'backslash and ten digits' => ['pattern' => '/a\1000000000/', 'release' => '10.35', 'offset' => 12, 'code' => ErrorCode::GroupNumberTooBig, 'from' => '10.37'];
        yield 'backslash and ten digits past the ninth' => ['pattern' => '/\2147483630/', 'release' => '10.32', 'offset' => 11, 'code' => ErrorCode::GroupNumberTooBig, 'from' => '10.37'];
        yield 'backslash, an eight and digits' => ['pattern' => '/\899999999999/', 'release' => '10.33', 'offset' => 10, 'code' => ErrorCode::GroupNumberTooBig, 'from' => '10.37'];
        yield 'backslash and digits after an option' => ['pattern' => '/(?i)\214748364/', 'release' => '10.35', 'offset' => 14, 'code' => ErrorCode::GroupNumberTooBig, 'from' => '10.37'];
    }

    /**
     * Where a later release refuses the pattern too, for another reason,
     * an older one refuses it where it stopped reading it: each offset as
     * pcre2test 10.32 to 10.35 report it.
     *
     * @param list<string> $releases
     */
    #[Test]
    #[DataProvider('provideRefusalsWhereReadingStopped')]
    public function test_an_older_release_refuses_where_it_stopped_reading(string $pattern, array $releases, int $offset, ErrorCode $code): void
    {
        foreach ($releases as $release) {
            $validation = self::parser($release)->validate($pattern);

            $this->assertFalse($validation->isValid, $pattern.' on PCRE2 '.$release);
            $this->assertSame($offset, $validation->offset, $pattern.' on PCRE2 '.$release);
            $this->assertSame($code, $validation->errorCode, $pattern.' on PCRE2 '.$release);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, releases: list<string>, offset: int, code: ErrorCode}>
     */
    public static function provideRefusalsWhereReadingStopped(): iterable
    {
        yield 'script run as a condition' => ['pattern' => '/(?(*sr:a)a|b)/', 'releases' => ['10.32'], 'offset' => 3, 'code' => ErrorCode::ConditionalInvalid];
        yield 'unclosed script run' => ['pattern' => '/(*sr:a/', 'releases' => ['10.32'], 'offset' => 4, 'code' => ErrorCode::VerbInvalid];
        yield 'non-atomic lookahead as a condition' => ['pattern' => '/(?(*napla:a)a|b)/', 'releases' => ['10.32'], 'offset' => 3, 'code' => ErrorCode::ConditionalInvalid];
        yield 'non-atomic lookahead as a condition on 10.33' => ['pattern' => '/(?(*napla:a)a|b)/', 'releases' => ['10.33'], 'offset' => 9, 'code' => ErrorCode::VerbInvalid];
        yield 'unclosed non-atomic lookahead' => ['pattern' => '/(*napla:a/', 'releases' => ['10.32', '10.33'], 'offset' => 7, 'code' => ErrorCode::VerbInvalid];
        yield 'unclosed short non-atomic lookahead' => ['pattern' => '/(?*a/', 'releases' => ['10.32', '10.33', '10.34'], 'offset' => 2, 'code' => ErrorCode::GroupSyntax];
    }

    /**
     * A refusal names the spelling the older release reads, where one says
     * the same: "(?:(*ACCEPT))?" repeats the verb in a group on every
     * release, and "(*napla:" is "(?*" on 10.34.
     */
    #[Test]
    public function test_the_refusals_name_a_spelling_the_older_release_reads(): void
    {
        $this->assertStringContainsString('(?:(*ACCEPT))?', (string) self::parser('10.33')->validate('/a(*ACCEPT)?b/')->error);
        $this->assertTrue(self::parser('10.32')->validate('/a(?:(*ACCEPT))?b/')->isValid);
        $this->assertStringContainsString('write "(*napla:" instead', (string) self::parser('10.34')->validate('/(?*a)a/')->error);
        $this->assertStringContainsString('write "(*naplb:" instead', (string) self::parser('10.34')->validate('/(?<*a)b/')->error);
        $this->assertStringNotContainsString('instead', (string) self::parser('10.33')->validate('/(?*a)a/')->error);
    }

    #[Test]
    public function test_an_assertion_as_a_condition_is_refused_on_its_star_before_10_33(): void
    {
        $this->assertSame(
            'Invalid conditional construct at position 3. Condition must be a group reference, lookaround, or (DEFINE).',
            self::parser('10.32')->validate('/(?(*pla:a)a|b)/')->error,
        );
    }

    /**
     * The refusal names the spelling every release reads.
     */
    #[Test]
    #[DataProvider('provideClassicSpellings')]
    public function test_the_refusal_of_an_alphabetic_name_gives_its_classic_spelling(string $pattern, string $classic): void
    {
        $this->assertStringContainsString(\sprintf('write "%s" instead', $classic), (string) self::parser('10.32')->validate($pattern)->error);
    }

    /**
     * @return iterable<string, array{pattern: string, classic: string}>
     */
    public static function provideClassicSpellings(): iterable
    {
        yield 'lookahead' => ['pattern' => '/(*positive_lookahead:a)a/', 'classic' => '(?='];
        yield 'negative lookahead' => ['pattern' => '/(*nla:b)a/', 'classic' => '(?!'];
        yield 'lookbehind' => ['pattern' => '/b(*plb:a)/', 'classic' => '(?<='];
        yield 'negative lookbehind' => ['pattern' => '/b(*negative_lookbehind:a)/', 'classic' => '(?<!'];
        yield 'atomic group' => ['pattern' => '/(*atomic:a|ab)c/', 'classic' => '(?>'];
    }

    /**
     * "x(*plb:a" is refused at its name on 10.32 (error 160 at 6), and for
     * its missing parenthesis from 10.33 (error 114 at 8).
     */
    #[Test]
    public function test_an_unclosed_assertion_is_refused_at_its_name_before_10_33(): void
    {
        $old = self::parser('10.32')->validate('/x(*plb:a/');
        $this->assertFalse($old->isValid);
        $this->assertSame(6, $old->offset);
        $this->assertSame(ErrorCode::VerbInvalid, $old->errorCode);

        $new = self::parser('10.33')->validate('/x(*plb:a/');
        $this->assertFalse($new->isValid);
        $this->assertSame(8, $new->offset);
        $this->assertSame(ErrorCode::GroupUnclosed, $new->errorCode);
    }

    #[Test]
    #[DataProvider('provideAcceptedEverywhere')]
    public function test_an_older_release_accepts_what_it_read_as_text(string $pattern): void
    {
        foreach (['10.32', '10.33', '10.35', '10.37', '10.39'] as $release) {
            $this->assertTrue(self::parser($release)->validate($pattern)->isValid, $pattern.' on PCRE2 '.$release);
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAcceptedEverywhere(): iterable
    {
        yield 'classic lookahead' => ['pattern' => '/(?=a)a/'];
        yield 'classic atomic group' => ['pattern' => '/(?>a|ab)c/'];
        yield 'count at the limit' => ['pattern' => '/a{65535/'];
        yield 'count with leading zeros at the limit' => ['pattern' => '/a{00000000065535/'];
        yield 'padded count' => ['pattern' => '/a{ 65536/'];
        yield 'open minimum' => ['pattern' => '/a{,65536/'];
        yield 'third number' => ['pattern' => '/a{1,2,65536/'];
        yield 'brace in a class' => ['pattern' => '/[a{65536]/'];
        yield 'quoted brace' => ['pattern' => '/\Q{65536\E/'];
        yield 'escaped brace' => ['pattern' => '/a\{65536/'];
        yield 'accept repeated in a group' => ['pattern' => '/a(?:(*ACCEPT))?b/'];
        yield 'backslash and digits up to 214748363' => ['pattern' => '/\214748363/'];
        yield 'backslash and nine digits in a class' => ['pattern' => '/[\214748364]/'];
        yield 'backslash and nine digits quoted' => ['pattern' => '/\Q\214748364\E/'];
        yield 'backslash and digits a space parts under x' => ['pattern' => '/(?x)\21474 8364/'];
        yield 'octal escape and digits' => ['pattern' => '/\0214748364/'];
        yield 'ASCII group name under UTF' => ['pattern' => '/(?<name>a)\k<name>/u'];
    }

    #[Test]
    public function test_each_change_names_its_release(): void
    {
        $this->assertSame('10.33', PcreFeature::AlphabeticAssertionNames->release());
        $this->assertSame('10.37', PcreFeature::LargeOpenCountIsText->release());
        $this->assertSame('10.33', PcreFeature::ScriptRuns->release());
        $this->assertSame('10.33', PcreFeature::NonAsciiGroupNames->release());
        $this->assertSame('10.34', PcreFeature::QuantifiedAccept->release());
        $this->assertSame('10.34', PcreFeature::NonAtomicAssertionNames->release());
        $this->assertSame('10.35', PcreFeature::NonAtomicAssertionShortSyntax->release());
        $this->assertSame('10.37', PcreFeature::OverflowingBackslashNumberIsOctal->release());
    }

    /**
     * Each refusal in full: the release that changed, the spelling an older
     * one reads where one exists, and what PHP 7.3 and 7.4 do with it.
     */
    #[Test]
    #[DataProvider('provideFullRefusals')]
    public function test_a_refusal_says_it_all(string $pattern, string $phpVersion, string $release, string $error): void
    {
        $this->assertSame($error, RegexParser::create(['php_version' => $phpVersion, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern)->error);
    }

    /**
     * @return iterable<string, array{pattern: string, phpVersion: string, release: string, error: string}>
     */
    public static function provideFullRefusals(): iterable
    {
        yield 'unknown property' => ['pattern' => '/\p{Bogus}/u', 'phpVersion' => '8.0', 'release' => '10.35', 'error' => 'Invalid or unsupported Unicode property: \p{Bogus}. PCRE2 10.35 knows a property by one exact name only.'];
        yield 'backslash number' => ['pattern' => '/\214748364/', 'phpVersion' => '8.0', 'release' => '10.35', 'error' => 'Number too big at position 10: PCRE2 before 10.37 reads the digits after a backslash as one number, at most 214748363.'];
        yield 'repeated accept' => ['pattern' => '/a(*ACCEPT)?b/', 'phpVersion' => '7.4', 'release' => '10.33', 'error' => 'Quantifier "?" cannot be applied to assertion or verb "(*ACCEPT)" at position 10: PCRE2 10.34 lets "(*ACCEPT)" be repeated; write "(?:(*ACCEPT))?", which every release reads'];
        yield 'short non-atomic lookahead' => ['pattern' => '/x(?*a)a/', 'phpVersion' => '7.4', 'release' => '10.34', 'error' => 'Invalid group modifier syntax at position 3: PCRE2 10.35 added "(?*" for "(*napla:"; write "(*napla:" instead.'];
        yield 'script run' => ['pattern' => '/x(*sr:a)/', 'phpVersion' => '7.3', 'release' => '10.32', 'error' => 'Unknown verb "(*sr:": PCRE2 10.33 added script runs.'];
    }

    /**
     * PHP 7.3.33 and 7.4.33 compile "\p{Bogus}" and "\214748364", which
     * their PCRE2 refuses, without a warning: they compile with
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, under which the backslash and the
     * letter or digit after it are that character, and the rest is read
     * again. "/\p{Bogus}|a/u" finds "a" in "a" and "p{Bogus}" in
     * "p{Bogus}"; "/\p{Bogus}?a/u" finds nothing in "a" and "p{Bogusa" in
     * "p{Bogusa", the "?" making the "}" optional. PHP 8.0 refuses both. In
     * a class, 7.3 and 7.4 refuse a property PCRE2 does not know.
     */
    #[Test]
    #[DataProvider('provideEscapesPhp7ReadsAsText')]
    public function test_php_7_reads_an_escape_its_pcre2_refuses_as_text(string $pattern, string $phpVersion, bool $accepted): void
    {
        $validation = RegexParser::create(['php_version' => $phpVersion, 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame($accepted, $validation->isValid, $pattern.' on PHP '.$phpVersion.': '.($validation->error ?? ''));
        $this->assertStringNotContainsString('without a warning', (string) $validation->error);
    }

    /**
     * @return iterable<string, array{pattern: string, phpVersion: string, accepted: bool}>
     */
    public static function provideEscapesPhp7ReadsAsText(): iterable
    {
        yield 'unknown property, PHP 7.3' => ['pattern' => '/\p{Bogus}/u', 'phpVersion' => '7.3', 'accepted' => true];
        yield 'unknown property, PHP 7.4' => ['pattern' => '/\p{Bogus}|a/u', 'phpVersion' => '7.4', 'accepted' => true];
        yield 'property name too long, PHP 7.3' => ['pattern' => '/\p{aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}|a/u', 'phpVersion' => '7.3', 'accepted' => true];
        yield 'unknown property, PHP 8.0' => ['pattern' => '/\p{Bogus}/u', 'phpVersion' => '8.0', 'accepted' => false];
        yield 'unknown property in a class, PHP 7.4' => ['pattern' => '/[\p{Bogus}a]/u', 'phpVersion' => '7.4', 'accepted' => false];
        yield 'backslash and nine digits, PHP 7.3' => ['pattern' => '/\214748364/', 'phpVersion' => '7.3', 'accepted' => true];
        yield 'backslash and nine digits, PHP 7.4' => ['pattern' => '/b\214748364|a/', 'phpVersion' => '7.4', 'accepted' => true];
        yield 'backslash and nine digits, PHP 8.0' => ['pattern' => '/\214748364/', 'phpVersion' => '8.0', 'accepted' => false];
    }

    /**
     * Before 10.37 "\N{" reads the digits after it as a count too, whatever
     * follows them, and refuses one past 65535 past the "\N", in a class or
     * not: "number too big in {} quantifier at offset 2" for "\N{65536 }"
     * (pcre2test 10.32 to 10.36). From 10.37, "\N{name} is not supported".
     */
    #[Test]
    #[DataProvider('provideCountsTooBigAfterN')]
    public function test_a_count_too_big_after_n_is_refused_as_too_big_before_10_37(string $pattern, string $release, int $offset): void
    {
        $validation = self::parser($release)->validate($pattern);

        $this->assertFalse($validation->isValid, $pattern.' on PCRE2 '.$release);
        $this->assertSame(ErrorCode::QuantifierTooBig, $validation->errorCode, $pattern.' on PCRE2 '.$release.': '.($validation->error ?? ''));
        $this->assertSame($offset, $validation->offset, $pattern.' on PCRE2 '.$release);
        $this->assertSame(ErrorCode::EscapeUnsupported, self::parser('10.37')->validate($pattern)->errorCode, $pattern.' on PCRE2 10.37');
    }

    /**
     * @return iterable<string, array{pattern: string, release: string, offset: int}>
     */
    public static function provideCountsTooBigAfterN(): iterable
    {
        yield 'padded count' => ['pattern' => '/\N{65536 }/', 'release' => '10.36', 'offset' => 2];
        yield 'padded count after a comma' => ['pattern' => '/a\N{1,65536 }/', 'release' => '10.32', 'offset' => 3];
        yield 'count before a comma and a space' => ['pattern' => '/\N{65536, }/', 'release' => '10.35', 'offset' => 2];
        yield 'count before a letter' => ['pattern' => '/\N{65536x}/', 'release' => '10.33', 'offset' => 2];
        yield 'count left open' => ['pattern' => '/\N{65536/', 'release' => '10.34', 'offset' => 2];
        yield 'padded count in a class' => ['pattern' => '/[\N{65536 }]/', 'release' => '10.35', 'offset' => 3];
        yield 'count left open in a class' => ['pattern' => '/[a\N{65536]/', 'release' => '10.32', 'offset' => 4];
    }

    /**
     * PHP 7.3 and 7.4 without "X" read "\g{65536 }", "\k{65536 }" and
     * "\N{65536 }", which their PCRE2 refuses, as the letter then the
     * braces, whose digits PCRE2 before 10.37 reads as a count:
     * "number too big in {} quantifier at offset 8" for "\g{65536 }"
     * (php:7.3-cli, php:7.4-cli).
     */
    #[Test]
    #[DataProvider('provideCountsTooBigAfterALetter')]
    public function test_php_7_refuses_a_count_too_big_after_an_escape_it_reads_as_text(string $pattern, string $phpVersion, string $release, int $offset): void
    {
        $validation = RegexParser::create(['cache' => null, 'php_version' => $phpVersion, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertFalse($validation->isValid, $pattern.' on PHP '.$phpVersion);
        $this->assertSame(ErrorCode::QuantifierTooBig, $validation->errorCode, $pattern.' on PHP '.$phpVersion.': '.($validation->error ?? ''));
        $this->assertSame($offset, $validation->offset, $pattern.' on PHP '.$phpVersion);
    }

    /**
     * @return iterable<string, array{pattern: string, phpVersion: string, release: string, offset: int}>
     */
    public static function provideCountsTooBigAfterALetter(): iterable
    {
        yield 'g, PHP 7.3' => ['pattern' => '/\g{65536 }/', 'phpVersion' => '7.3', 'release' => '10.32', 'offset' => 8];
        yield 'g after a group, PHP 7.4' => ['pattern' => '/(a)\g{65536 }/', 'phpVersion' => '7.4', 'release' => '10.35', 'offset' => 11];
        yield 'g with a count after a comma, PHP 7.4' => ['pattern' => '/(a)\g{1,65536 }/', 'phpVersion' => '7.4', 'release' => '10.33', 'offset' => 13];
        yield 'k, PHP 7.3' => ['pattern' => '/(a)\k{65536 }/', 'phpVersion' => '7.3', 'release' => '10.32', 'offset' => 11];
        yield 'N, PHP 7.4' => ['pattern' => '/\N{65536 }/', 'phpVersion' => '7.4', 'release' => '10.35', 'offset' => 8];
    }

    #[Test]
    public function test_the_compatibility_matrix_starts_at_the_oldest_pcre2_php_bundles(): void
    {
        $refused = [];
        foreach ((new CompatibilityChecker())->check('/(*pla:a)a/')->invalidVerdicts() as $verdict) {
            $refused[$verdict->target->pcreVersion] = true;
        }

        $this->assertSame(['10.32' => true], $refused);
    }

    /**
     * A release judged for PHP 8.0, which compiles without
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL: PHP 7.3 and 7.4 read an escape the
     * release refuses as text (BadEscapeIsLiteralTest).
     */
    private static function parser(string $release): RegexParser
    {
        return RegexParser::create(['php_version' => PcreTarget::bundledWith(80000)->phpVersionId, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
