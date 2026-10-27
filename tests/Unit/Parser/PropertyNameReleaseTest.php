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
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether a release knows a property name is read from the names of that
 * release, not asked of the engine that runs: on PHP 8.1 (PCRE2 10.39) a
 * target of PHP 8.4 (10.44) knows "\p{greek}", which 10.39 refuses. From
 * 10.40 PCRE2 drops spaces, "\t" to "\r", hyphens and underscores, lowers
 * the letters, and reads "bc:", "sc:" and "scx:" and their long names, the
 * script codes, the Bidi classes and the binary properties; "sc:" and
 * "scx:" take a script only. 10.43 added the scripts of Unicode 15, 10.45
 * those of Unicode 16 and its binary properties and a "^" after the
 * characters it drops, 10.48 the scripts of Unicode 17.
 *
 * Each row names the first release that compiles the pattern, or none.
 * Every verdict is PHP's on the official images (10.39, 10.40, 10.42 and
 * 10.44) and on PCRE2 10.49.
 */
final class PropertyNameReleaseTest extends TestCase
{
    use PcreGates;

    /**
     * The release each target is judged with, and the PHP that bundles it
     * or the first that may link it.
     */
    private const TARGETS = [
        '10.39' => 80100, '10.40' => 80200, '10.41' => 80200, '10.42' => 80300, '10.43' => 80300,
        '10.44' => 80400, '10.45' => 80400, '10.46' => 80400, '10.47' => 80400, '10.48' => 80400, '10.49' => 80400,
    ];

    #[Test]
    #[DataProvider('provideNames')]
    public function test_a_name_is_judged_by_the_names_of_the_release_targeted(string $pattern, ?string $since): void
    {
        foreach (self::TARGETS as $release => $phpVersion) {
            $result = RegexParser::create(['cache' => null, 'php_version' => $phpVersion, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);
            $label = \sprintf('%s for PCRE2 %s', addcslashes($pattern, "\0..\37"), $release);

            if (null !== $since && version_compare($release, $since, '>=')) {
                $this->assertTrue($result->isValid, $label.' compiles: '.(string) $result->error);

                continue;
            }

            $this->assertFalse($result->isValid, $label.' is refused.');
            $this->assertSame(ErrorCode::UnicodePropertyInvalid, $result->errorCode, $label);
            // Past the escape, where PCRE has read the whole name.
            $this->assertSame(str_contains($pattern, '{') ? strrpos($pattern, '}') : strcspn($pattern, '\\') + 2, $result->offset, $label);

            // A release that knows it is named.
            if (null !== $since && version_compare($release, '10.40', '>=')) {
                $this->assertStringContainsString('It needs PCRE2 '.$since, (string) $result->error, $label);
            }
        }
    }

    #[Test]
    #[DataProvider('provideNames')]
    public function test_without_a_target_the_running_pcre2_decides(string $pattern, ?string $since): void
    {
        unset($since);

        $this->assertSame(false !== @preg_match($pattern, ''), RegexParser::create(['cache' => null])->validate($pattern)->isValid, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, since: string|null}>
     */
    public static function provideNames(): iterable
    {
        // Known to 10.39 by their exact names.
        yield 'a script' => ['pattern' => '/\\p{Greek}/u', 'since' => '10.39'];
        yield 'a script of Unicode 14' => ['pattern' => '/\\p{Toto}/u', 'since' => '10.39'];
        yield 'any character' => ['pattern' => '/\\p{Any}/u', 'since' => '10.39'];
        yield 'cased letters' => ['pattern' => '/\\p{L&}/u', 'since' => '10.39'];
        yield 'a category letter' => ['pattern' => '/\\pL/', 'since' => '10.39'];

        // Loose names, 10.40.
        yield 'a script in lower case' => ['pattern' => '/\\p{greek}/u', 'since' => '10.40'];
        yield 'a category spaced out' => ['pattern' => '/\\p{ L }/', 'since' => '10.40'];
        yield 'a category letter in lower case' => ['pattern' => '/\\pl/', 'since' => '10.40'];
        yield 'cased letters in lower case' => ['pattern' => '/\\p{l&}/u', 'since' => '10.40'];
        yield 'cased letters by their code' => ['pattern' => '/\\p{lc}/u', 'since' => '10.40'];
        yield 'a script split by a newline' => ['pattern' => "/\\p{Old\nItalic}/u", 'since' => '10.40'];
        yield 'a PCRE special in lower case' => ['pattern' => '/\\p{xan}/u', 'since' => '10.40'];
        yield 'the currency symbols, not a table' => ['pattern' => '/\\p{sc}/u', 'since' => '10.40'];
        yield 'a caret, then a space' => ['pattern' => '/\\p{^ Lu}/', 'since' => '10.40'];

        // The script tables, 10.40.
        yield 'the script table by its long name' => ['pattern' => '/\\p{Script=Greek}/', 'since' => '10.40'];
        yield 'the script table by a script code' => ['pattern' => '/\\p{sc=Grek}/u', 'since' => '10.40'];
        yield 'the script extensions table' => ['pattern' => '/\\p{scx=Hira}/u', 'since' => '10.40'];
        yield 'the script extensions table by its long name' => ['pattern' => '/\\p{Script_Extensions=Newa}/u', 'since' => '10.40'];
        yield 'the common script' => ['pattern' => '/\\p{scx=Common}/u', 'since' => '10.40'];
        yield 'the common script by its code' => ['pattern' => '/\\p{sc=Zyyy}/u', 'since' => '10.40'];
        yield 'the unknown script' => ['pattern' => '/\\p{sc=Unknown}/u', 'since' => '10.40'];
        yield 'the unknown script by its code' => ['pattern' => '/\\p{Zzzz}/u', 'since' => '10.40'];
        yield 'the Coptic alias' => ['pattern' => '/\\p{Qaac}/u', 'since' => '10.40'];
        yield 'the inherited alias' => ['pattern' => '/\\p{Qaai}/u', 'since' => '10.40'];

        // Binary properties and Bidi classes, 10.40.
        yield 'a binary property' => ['pattern' => '/\\p{Alphabetic}/u', 'since' => '10.40'];
        yield 'a binary property in lower case' => ['pattern' => '/\\p{ascii}/u', 'since' => '10.40'];
        yield 'a Bidi class as one name' => ['pattern' => '/\\p{bidial}/u', 'since' => '10.40'];
        yield 'a Bidi class with a hyphen' => ['pattern' => '/\\p{Bidi-AL}/u', 'since' => '10.40'];
        yield 'the Bidi class table' => ['pattern' => '/\\p{bc=al}/u', 'since' => '10.40'];
        yield 'the Bidi class table by its long name' => ['pattern' => '/\\p{bidiclass:AL}/u', 'since' => '10.40'];
        yield 'the left-to-right class' => ['pattern' => '/\\p{bc:L}/u', 'since' => '10.40'];

        // Names a later release added.
        yield 'a script of Unicode 15' => ['pattern' => '/\\p{Kawi}/u', 'since' => '10.43'];
        yield 'a script of Unicode 15, split by a form feed' => ['pattern' => "/\\p{Ka\fwi}/u", 'since' => '10.43'];
        yield 'a script of Unicode 15, split by a vertical tab' => ['pattern' => "/\\p{Ka\vwi}/u", 'since' => '10.43'];
        yield 'a script of Unicode 15 in the script table' => ['pattern' => '/\\p{sc=Nag_Mundari}/u', 'since' => '10.43'];
        yield 'a script of Unicode 16' => ['pattern' => '/\\p{Garay}/u', 'since' => '10.45'];
        yield 'a script of Unicode 16 by its code' => ['pattern' => '/\\p{scx:Tutg}/u', 'since' => '10.45'];
        yield 'a binary property of 10.45' => ['pattern' => '/\\p{InCB}/u', 'since' => '10.45'];
        yield 'a binary property of 10.45, spelled out' => ['pattern' => '/\\p{ID_Compat_Math_Start}/u', 'since' => '10.45'];
        yield 'a script of Unicode 17' => ['pattern' => '/\\p{Taiyo}/u', 'since' => '10.48'];
        yield 'a script of Unicode 17 by its code' => ['pattern' => '/\\p{sc = Tols}/u', 'since' => '10.48'];

        // A "^" after a space, 10.45.
        yield 'a space, then a caret' => ['pattern' => '/\\p{ ^Lu}/', 'since' => '10.45'];
        yield 'a space, then a caret, of a negated escape' => ['pattern' => '/\\P{ ^ greek}/u', 'since' => '10.45'];
        yield 'a space, then a caret, before a script of Unicode 15' => ['pattern' => '/\\p{ ^Kawi}/u', 'since' => '10.45'];
        yield 'a space, then a caret, before a script of Unicode 17' => ['pattern' => '/\\p{ ^Taiyo}/u', 'since' => '10.48'];

        // Known to none.
        yield 'an unknown letter' => ['pattern' => '/\\pX/', 'since' => null];
        yield 'two carets' => ['pattern' => '/\\p{^^Lu}/', 'since' => null];
        yield 'a category in the script table' => ['pattern' => '/\\p{sc=Lu}/u', 'since' => null];
        yield 'a binary property in the script table' => ['pattern' => '/\\p{sc=Alpha}/u', 'since' => null];
        yield 'a binary property of 10.45 in the script table' => ['pattern' => '/\\p{sc=InCB}/u', 'since' => null];
        yield 'cased letters in the script table' => ['pattern' => '/\\p{sc=L&}/u', 'since' => null];
        yield 'any character in the script table' => ['pattern' => '/\\p{sc=any}/u', 'since' => null];
        yield 'a PCRE special in the script table' => ['pattern' => '/\\p{sc=xan}/u', 'since' => null];
        yield 'a script in the Bidi class table' => ['pattern' => '/\\p{bc=Greek}/u', 'since' => null];
        yield 'a Bidi class by its long name' => ['pattern' => '/\\p{Bidi_Class = Left_To_Right}/u', 'since' => null];
        yield 'the general category table' => ['pattern' => '/\\p{gc=Lu}/u', 'since' => null];
        yield 'a second separator' => ['pattern' => '/\\p{sc:Greek:x}/u', 'since' => null];
        yield 'no script after the table' => ['pattern' => '/\\p{sc=}/u', 'since' => null];
        yield 'no table before the separator' => ['pattern' => '/\\p{=Greek}/u', 'since' => null];
        yield 'the script extensions table alone' => ['pattern' => '/\\p{scx}/u', 'since' => null];
        yield 'the kana script code' => ['pattern' => '/\\p{Hrkt}/u', 'since' => null];
        yield 'the kana script' => ['pattern' => '/\\p{Katakana_Or_Hiragana}/u', 'since' => null];
    }

    /**
     * From 10.40 a NUL byte ends the name: the escape is malformed past it.
     * PHP 8.1 refuses the NUL byte itself.
     */
    #[Test]
    #[DataProvider('provideNulBytes')]
    public function test_a_nul_byte_makes_the_name_malformed_from_pcre2_10_40(string $pattern, int $offset): void
    {
        foreach (self::TARGETS as $release => $phpVersion) {
            if ('10.39' === $release) {
                continue;
            }

            $result = RegexParser::create(['cache' => null, 'php_version' => $phpVersion, 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);
            $label = \sprintf('%s for PCRE2 %s', addcslashes($pattern, "\0"), $release);

            $this->assertFalse($result->isValid, $label);
            $this->assertSame(ErrorCode::UnicodePropertyMalformed, $result->errorCode, $label);
            $this->assertSame($offset, $result->offset, $label);
        }
    }

    #[Test]
    #[DataProvider('provideNulBytes')]
    public function test_a_nul_byte_without_a_target(string $pattern, int $offset): void
    {
        unset($offset);
        $this->requiresPhpReading($pattern);

        $this->assertFalse(RegexParser::create(['cache' => null])->validate($pattern)->isValid);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideNulBytes(): iterable
    {
        yield 'inside the name' => ['pattern' => "/\\p{L\0u}/", 'offset' => 5];
        yield 'first' => ['pattern' => "/\\p{\0Lu}/", 'offset' => 4];
        yield 'after the caret' => ['pattern' => "/\\p{^\0Lu}/", 'offset' => 5];
        yield 'after a space' => ['pattern' => "/a\\p{L u\0}/", 'offset' => 8];
    }
}
