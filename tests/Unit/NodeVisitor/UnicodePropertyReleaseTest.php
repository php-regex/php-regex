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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Each PCRE2 release knows the scripts and properties of its Unicode
 * version. PHP 8.1 bundles PCRE2 10.39, which knows a property only by one
 * exact name ("Greek", "Lu", "L&"): no other case, no space, hyphen or
 * underscore dropped, no "sc:" or "scx:", no four-letter script code, no
 * Bidi class and no binary property, all of which 10.40 added. PHP 8.2
 * bundles PCRE2 10.40, 8.3 bundles 10.42 (Unicode 14), 8.4
 * and 8.5 bundle 10.44 (Unicode 15: Kawi, Nag Mundari). The Unicode 16 and 17
 * scripts and the binary properties 10.45 added are unknown to all of them,
 * as is a "^" after spaces, which 10.45 started to allow. Every verdict and
 * offset below is pcre2test's on those releases ("pcre2test -LS" and "-LP"
 * list what each knows), and PHP 8.1's on its bundled 10.39.
 */
final class UnicodePropertyReleaseTest extends TestCase
{
    use PcreGates;

    /**
     * The rows verified against a newer release than the floor: a "^"
     * after spaces is PCRE2 10.45.
     */
    private const VERIFIED_AGAINST = [
        '10.45' => ['/\\p{ ^Lu}/', '/\\p{  ^ L u }/'],
    ];

    /**
     * @param list<int> $refusedBy
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_a_property_is_judged_for_the_pcre2_the_target_bundles(string $pattern, array $refusedBy): void
    {
        // On an older running engine the live probe answers for the target
        // that bundles it, and its verdict is not the table's.
        $this->requiresPcre2For($pattern, self::VERIFIED_AGAINST);

        foreach ([80100, 80200, 80300, 80400, 80500] as $phpVersion) {
            $result = Regex::create(['cache' => null, 'php_version' => $phpVersion])->validate($pattern);

            if (\in_array($phpVersion, $refusedBy, true)) {
                $this->assertFalse($result->isValid, \sprintf('%s is refused on PHP %d.', $pattern, $phpVersion));
                $this->assertSame(ErrorCode::UnicodePropertyInvalid, $result->errorCode);
                // Past the "}", where PCRE has read the whole name.
                $this->assertSame(strrpos($pattern, '}'), $result->offset, $pattern);
            } else {
                $this->assertTrue($result->isValid, \sprintf('%s compiles on PHP %d: %s', $pattern, $phpVersion, (string) $result->error));
            }
        }
    }

    /**
     * @param list<int> $refusedBy
     */
    #[Test]
    #[DataProvider('provideProperties')]
    public function test_without_a_target_the_running_pcre2_decides(string $pattern, array $refusedBy): void
    {
        unset($refusedBy);

        $this->requiresPcre2For($pattern, self::VERIFIED_AGAINST);

        $this->assertSame(false !== @preg_match($pattern, ''), Regex::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, refusedBy: list<int>}>
     */
    public static function provideProperties(): iterable
    {
        $all = [80100, 80200, 80300, 80400, 80500];

        // Unicode 15, PCRE2 10.43.
        yield 'Kawi' => ['pattern' => '/\\p{Kawi}/u', 'refusedBy' => [80100, 80200, 80300]];
        yield 'Nag Mundari by its code' => ['pattern' => '/\\p{sc=Nagm}/u', 'refusedBy' => [80100, 80200, 80300]];
        yield 'Nag Mundari, loosely spelled' => ['pattern' => '/\\p{Script = nag-mundari}/u', 'refusedBy' => [80100, 80200, 80300]];

        // Unicode 16 and 17, PCRE2 10.45 and later.
        yield 'Garay' => ['pattern' => '/\\p{Garay}/u', 'refusedBy' => $all];
        yield 'Garay by its code' => ['pattern' => '/\\P{sc=Gara}/u', 'refusedBy' => $all];
        yield 'Gurung Khema in script extensions' => ['pattern' => '/\\p{scx:Gukh}/u', 'refusedBy' => $all];
        yield 'Tulu Tigalari' => ['pattern' => '/\\p{Tulu_Tigalari}/u', 'refusedBy' => $all];
        yield 'Sidetic' => ['pattern' => '/\\p{Sidetic}/u', 'refusedBy' => $all];
        yield 'Beria Erfe in script extensions' => ['pattern' => '/\\p{Script_Extensions=Berf}/u', 'refusedBy' => $all];
        yield 'binary property of 10.45' => ['pattern' => '/\\p{IDS_Unary_Operator}/u', 'refusedBy' => $all];
        yield 'binary property of 10.45 by its code' => ['pattern' => '/\\p{MCM}/u', 'refusedBy' => $all];

        // A "^" after spaces, PCRE2 10.45.
        yield 'caret after a space' => ['pattern' => '/\\p{ ^Lu}/', 'refusedBy' => $all];
        yield 'caret after spaces, name spaced out' => ['pattern' => '/\\p{  ^ L u }/', 'refusedBy' => $all];

        // Known to every release, by its exact name.
        yield 'Greek' => ['pattern' => '/\\p{Greek}/u', 'refusedBy' => []];
        yield 'Greek negated' => ['pattern' => '/\\P{^Greek}/u', 'refusedBy' => []];
        yield 'a script named with an underscore' => ['pattern' => '/\\p{Old_Italic}/u', 'refusedBy' => []];
        yield 'a script of Unicode 14' => ['pattern' => '/\\p{Cypro_Minoan}/u', 'refusedBy' => []];
        yield 'a general category' => ['pattern' => '/\\p{Lu}/', 'refusedBy' => []];
        yield 'cased letters' => ['pattern' => '/\\p{L&}/u', 'refusedBy' => []];
        yield 'any character' => ['pattern' => '/\\p{Any}/u', 'refusedBy' => []];
        yield 'a PCRE special' => ['pattern' => '/\\p{Xan}/u', 'refusedBy' => []];
        yield 'unknown script' => ['pattern' => '/\\p{Unknown}/u', 'refusedBy' => []];

        // PCRE2 10.40: loose names, "sc:" and "scx:", script codes, Bidi
        // classes and binary properties.
        yield 'spaces around the name' => ['pattern' => '/\\p{ Lu }/', 'refusedBy' => [80100]];
        yield 'caret first, then a space' => ['pattern' => '/\\p{^ Lu}/', 'refusedBy' => [80100]];
        yield 'bidi class loosely spelled' => ['pattern' => '/\\p{ bidi class = al }/u', 'refusedBy' => [80100]];
        yield 'a script in lower case' => ['pattern' => '/\\p{greek}/u', 'refusedBy' => [80100]];
        yield 'a script in upper case' => ['pattern' => '/\\p{GREEK}/u', 'refusedBy' => [80100]];
        yield 'a category in lower case' => ['pattern' => '/\\p{lu}/', 'refusedBy' => [80100]];
        yield 'a PCRE special in lower case' => ['pattern' => '/\\p{xan}/u', 'refusedBy' => [80100]];
        yield 'a script with its underscore dropped' => ['pattern' => '/\\p{OldItalic}/u', 'refusedBy' => [80100]];
        yield 'a script with a hyphen' => ['pattern' => '/\\p{Old-Italic}/u', 'refusedBy' => [80100]];
        yield 'a category with an underscore' => ['pattern' => '/\\p{L_}/', 'refusedBy' => [80100]];
        yield 'a script by its code' => ['pattern' => '/\\p{Grek}/u', 'refusedBy' => [80100]];
        yield 'the script table' => ['pattern' => '/\\p{sc:Greek}/u', 'refusedBy' => [80100]];
        yield 'the script table, long name' => ['pattern' => '/\\p{script:Greek}/u', 'refusedBy' => [80100]];
        yield 'the script extensions table' => ['pattern' => '/\\p{scx:Greek}/u', 'refusedBy' => [80100]];
        yield 'the script extensions table, long name' => ['pattern' => '/\\p{Script_Extensions:Greek}/u', 'refusedBy' => [80100]];
        yield 'a Bidi class' => ['pattern' => '/\\p{bc=L}/u', 'refusedBy' => [80100]];
        yield 'a Bidi class, long name' => ['pattern' => '/\\p{Bidi_Class:L}/u', 'refusedBy' => [80100]];
        yield 'Bidi_Control' => ['pattern' => '/\\p{Bidi_Control}/u', 'refusedBy' => [80100]];
        yield 'a binary property' => ['pattern' => '/\\p{Alphabetic}/u', 'refusedBy' => [80100]];
        yield 'a binary property by its code' => ['pattern' => '/\\p{Alpha}/u', 'refusedBy' => [80100]];
        yield 'White_Space' => ['pattern' => '/\\p{White_Space}/u', 'refusedBy' => [80100]];
        yield 'ASCII' => ['pattern' => '/\\p{ASCII}/u', 'refusedBy' => [80100]];
        yield 'a script in lower case inside a class' => ['pattern' => '/[\\p{greek}]/u', 'refusedBy' => [80100]];
    }

    /**
     * PCRE2 10.39 reads a property letter in its case, and at most 31
     * characters of a braced name: past them, the sequence is malformed.
     * Offsets are PHP 8.1's on its bundled 10.39.
     */
    #[Test]
    #[DataProvider('provideMalformedOnPcre239')]
    public function test_pcre2_10_39_reads_a_letter_in_its_case_and_a_name_of_31_characters(string $pattern, ErrorCode $errorCode, int $offset): void
    {
        $result = Regex::create(['cache' => null, 'php_version' => 80100])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($errorCode, $result->errorCode, (string) $result->error);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, errorCode: ErrorCode, offset: int}>
     */
    public static function provideMalformedOnPcre239(): iterable
    {
        yield 'a property letter in lower case' => ['pattern' => '/\\pl/', 'errorCode' => ErrorCode::UnicodePropertyInvalid, 'offset' => 3];
        yield 'a name of 30 characters' => ['pattern' => '/\\p{'.str_repeat('a', 30).'}/', 'errorCode' => ErrorCode::UnicodePropertyInvalid, 'offset' => 34];
        yield 'a name of 31 characters' => ['pattern' => '/\\p{'.str_repeat('a', 31).'}/', 'errorCode' => ErrorCode::UnicodePropertyMalformed, 'offset' => 34];
        yield 'a name of 32 characters' => ['pattern' => '/\\p{'.str_repeat('a', 32).'}/', 'errorCode' => ErrorCode::UnicodePropertyMalformed, 'offset' => 34];
        yield 'a negated name of 31 characters' => ['pattern' => '/\\p{^'.str_repeat('a', 31).'}/', 'errorCode' => ErrorCode::UnicodePropertyMalformed, 'offset' => 35];
    }
}
