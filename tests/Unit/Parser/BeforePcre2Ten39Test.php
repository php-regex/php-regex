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
 * The few changes before PCRE2 10.39 the library knows, each verified on a
 * real engine: pcre2test built from the 10.32, 10.33, 10.34, 10.35 and 10.37
 * release tarballs, and preg_match() on php:7.3-cli (PCRE2 10.32),
 * php:7.4-cli and php:8.0-cli (10.35) and php:8.1-cli (10.39).
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
    }

    #[Test]
    public function test_each_change_names_its_release(): void
    {
        $this->assertSame('10.33', PcreFeature::AlphabeticAssertionNames->release());
        $this->assertSame('10.37', PcreFeature::LargeOpenCountIsText->release());
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

    private static function parser(string $release): RegexParser
    {
        return RegexParser::create(['php_version' => PcreTarget::bundledWith(70300)->phpVersionId, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
