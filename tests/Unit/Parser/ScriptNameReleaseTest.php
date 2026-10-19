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
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Before 10.40 each PCRE2 release knows the scripts of its own Unicode
 * version, by one exact name (pcre2_tables.c): 10.32 (Unicode 11) has no
 * "Unknown", which 10.33 added; 10.34 (Unicode 12.1) added Elymaic,
 * Nandinagari, Nyiakeng_Puachue_Hmong and Wancho; 10.35 (Unicode 13)
 * Chorasmian, Dives_Akuru, Khitan_Small_Script and Yezidi; 10.36 to 10.38
 * none; 10.39 (Unicode 14) Cypro_Minoan, Old_Uyghur, Tangsa, Toto and
 * Vithkuqi. A name the release does not know is refused past its "}"; on
 * PHP 7.3 and 7.4 without "X", outside a class, it is the text "p{...}".
 *
 * Every name was run on php:7.3-cli (PCRE2 10.32), php:7.4.0-cli (10.33),
 * php:7.4.6-cli (10.34), php:7.4-cli and php:8.0-cli (10.35),
 * php:8.1.0-cli (10.37) and php:8.1-cli (10.39), as "\p{...}", with "X",
 * in a class, as "\P{^...}" and under "u": "unknown property name after \P
 * or \p at offset 12" for "\p{Vithkuqi}" with "X" up to 10.37, the text
 * "p{Vithkuqi}" matched without it on PHP 7.3 and 7.4.
 */
final class ScriptNameReleaseTest extends TestCase
{
    private const TARGETS = [['7.3', '10.32'], ['7.4', '10.33'], ['7.4', '10.34'], ['7.4', '10.35'], ['8.0', '10.35'], ['8.1', '10.37'], ['8.1', '10.39']];

    #[Test]
    #[DataProvider('provideScripts')]
    public function test_a_script_is_known_from_the_release_that_added_it(string $name, string $since): void
    {
        foreach (self::TARGETS as [$php, $release]) {
            $known = version_compare($release, $since, '>=');
            // "X" keeps PHP 7.3 and 7.4 from reading an unknown name as text.
            foreach (['/\p{'.$name.'}/X', '/[\p{'.$name.'}]/X', '/\P{^'.$name.'}/uX'] as $pattern) {
                $result = self::parser($php, $release)->validate($pattern);
                if ($known) {
                    $this->assertTrue($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));

                    continue;
                }

                $this->assertFalse($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
                $this->assertSame(ErrorCode::UnicodePropertyInvalid, $result->errorCode, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));
                $this->assertSame(strrpos($pattern, '}'), $result->offset, \sprintf('%s on PHP %s with PCRE2 %s', $pattern, $php, $release));
            }
        }
    }

    /**
     * Without "X", PHP 7.3 and 7.4 read a name their PCRE2 does not know as
     * the text "p{...}" outside a class, and still refuse it in a class.
     */
    #[Test]
    #[DataProvider('provideScripts')]
    public function test_php_7_reads_a_script_its_release_does_not_know_as_text(string $name, string $since): void
    {
        foreach ([['7.3', '10.32'], ['7.4', '10.33'], ['7.4', '10.34'], ['7.4', '10.35']] as [$php, $release]) {
            $known = version_compare($release, $since, '>=');
            $parser = self::parser($php, $release);
            foreach (['/\p{'.$name.'}/', '/\p{'.$name.'}/u'] as $pattern) {
                $result = $parser->validate($pattern);
                $this->assertTrue($result->isValid, \sprintf('%s on PHP %s with PCRE2 %s: %s', $pattern, $php, $release, (string) $result->error));

                $printed = $parser->parse($pattern)->accept(new PatternPrinter());
                $text = 'p{'.$name.'}';
                $this->assertSame($known ? null : $text, 1 === preg_match($printed, $text, $found) ? $found[0] : null, \sprintf('%s printed as %s on PHP %s with PCRE2 %s', $pattern, $printed, $php, $release));
            }

            $inClass = $parser->validate('/[\p{'.$name.'}]/');
            $this->assertSame($known, $inClass->isValid, \sprintf('[\p{%s}] on PHP %s with PCRE2 %s', $name, $php, $release));
            if (!$known) {
                $this->assertSame(\strlen($name) + 5, $inClass->offset, \sprintf('[\p{%s}] on PHP %s with PCRE2 %s', $name, $php, $release));
            }
        }
    }

    /**
     * @return iterable<string, array{name: string, since: string}>
     */
    public static function provideScripts(): iterable
    {
        yield 'Unknown' => ['name' => 'Unknown', 'since' => '10.33'];
        yield 'Elymaic' => ['name' => 'Elymaic', 'since' => '10.34'];
        yield 'Nandinagari' => ['name' => 'Nandinagari', 'since' => '10.34'];
        yield 'Nyiakeng_Puachue_Hmong' => ['name' => 'Nyiakeng_Puachue_Hmong', 'since' => '10.34'];
        yield 'Wancho' => ['name' => 'Wancho', 'since' => '10.34'];
        yield 'Chorasmian' => ['name' => 'Chorasmian', 'since' => '10.35'];
        yield 'Dives_Akuru' => ['name' => 'Dives_Akuru', 'since' => '10.35'];
        yield 'Khitan_Small_Script' => ['name' => 'Khitan_Small_Script', 'since' => '10.35'];
        yield 'Yezidi' => ['name' => 'Yezidi', 'since' => '10.35'];
        yield 'Cypro_Minoan' => ['name' => 'Cypro_Minoan', 'since' => '10.39'];
        yield 'Old_Uyghur' => ['name' => 'Old_Uyghur', 'since' => '10.39'];
        yield 'Tangsa' => ['name' => 'Tangsa', 'since' => '10.39'];
        yield 'Toto' => ['name' => 'Toto', 'since' => '10.39'];
        yield 'Vithkuqi' => ['name' => 'Vithkuqi', 'since' => '10.39'];
        yield 'Greek, every release' => ['name' => 'Greek', 'since' => '10.32'];
    }

    private static function parser(string $php, string $release): RegexParser
    {
        return RegexParser::create(['cache' => null, 'php_version' => $php, 'pcre_version' => $release, 'runtime_pcre_validation' => false]);
    }
}
