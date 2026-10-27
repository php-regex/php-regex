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

use PHPRegex\Explain\HtmlExplainer;
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\InstalledLocales;
use PHPRegex\Toolkit\ExplainFormat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Given the target a pattern is judged for, an explanation says what that
 * engine reads where the reading moved across releases or locales, instead
 * of naming the release or the locale it depends on.
 *
 * Engine: the official PHP images, php:8.1-cli (PCRE2 10.39), php:8.2-cli
 * (10.40), php:8.3-cli (10.42), php:8.4-cli (10.44), and PHP 8.4.27 on
 * PCRE2 10.49 on macOS. "/^\w$/u" matches "\u{301}" (Mn) and "\u{203F}"
 * (Pc) on 10.44 and 10.49, on neither from 10.39 to 10.42; "\u{903}" (Mc)
 * on none. "(?(VERSION>=10.5)" holds on 10.49 and on no release up to
 * 10.44, "(?(VERSION=10.4)" holds on 10.40 alone: up to 10.46 the minor is
 * two digits, "10.4" is 10.40; from 10.47 it is read whole. Under
 * setlocale(LC_CTYPE, 'fr_FR.ISO8859-1') "/\w/" matches "\xE9" and the
 * other Latin-1 letters, "/\d/" no byte past ASCII; under fr_FR.UTF-8 on
 * macOS "/\s/" matches "\xA0".
 */
final class ExplainTargetTest extends TestCase
{
    use InstalledLocales;

    private const UNICODE_WORD_BEFORE_10_43 = 'A word character: a Unicode letter (\p{L}) or number (\p{N}), or an underscore';

    private const UNICODE_WORD_FROM_10_43 = 'A word character: a Unicode letter (\p{L}) or number (\p{N}), an underscore, a non-spacing mark (\p{Mn}) or connector punctuation (\p{Pc})';

    private const LATIN1 = 'fr_FR.ISO8859-1';

    #[Test]
    #[DataProvider('provideReadings')]
    public function test_a_construct_is_explained_as_the_target_reads_it(string $pattern, string $pcre, string $locale, string $description): void
    {
        if ('C' !== $locale) {
            $this->requiresLocale($locale, $pattern);
        }

        $target = new PcreTarget(80400, $pcre, $locale);
        $tree = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => $pcre, 'locale' => $locale])->parse($pattern);
        $text = (string) $tree->accept(new TextExplainer($target));
        $html = html_entity_decode((string) $tree->accept(new HtmlExplainer($target)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        // The whole description, not the start of a longer one.
        $this->assertMatchesRegularExpression('/(: |\()'.preg_quote($description, '/').'(\) THEN)?$/m', $text, 'text');
        $this->assertStringContainsString($description, $html, 'html');
    }

    /**
     * @return iterable<string, array{pattern: string, pcre: string, locale: string, description: string}>
     */
    public static function provideReadings(): iterable
    {
        yield 'a word character under u, 10.39' => ['pattern' => '/\w/u', 'pcre' => '10.39', 'locale' => 'C', 'description' => self::UNICODE_WORD_BEFORE_10_43];
        yield 'a word character under u, 10.42' => ['pattern' => '/\w/u', 'pcre' => '10.42', 'locale' => 'C', 'description' => self::UNICODE_WORD_BEFORE_10_43];
        yield 'a word character under u, 10.43' => ['pattern' => '/\w/u', 'pcre' => '10.43', 'locale' => 'C', 'description' => self::UNICODE_WORD_FROM_10_43];
        yield 'a word character under u, 10.44' => ['pattern' => '/\w/u', 'pcre' => '10.44', 'locale' => 'C', 'description' => self::UNICODE_WORD_FROM_10_43];
        yield 'a word character under a leading UCP, 10.42' => ['pattern' => '/(*UTF)(*UCP)\w/', 'pcre' => '10.42', 'locale' => 'C', 'description' => self::UNICODE_WORD_BEFORE_10_43];
        // The locale tables do not reach the types under Unicode properties.
        yield 'a word character under u, 10.44, Latin-1' => ['pattern' => '/\w/u', 'pcre' => '10.44', 'locale' => self::LATIN1, 'description' => self::UNICODE_WORD_FROM_10_43];
        yield 'a word character without u, C' => ['pattern' => '/\w/', 'pcre' => '10.44', 'locale' => 'C', 'description' => 'A word character: [a-zA-Z_0-9]'];
        yield 'a word character after every ASCII option, C' => ['pattern' => '/(?a)\w/u', 'pcre' => '10.44', 'locale' => 'C', 'description' => 'A word character: [a-zA-Z_0-9]'];
        yield 'a word character without u, Latin-1' => ['pattern' => '/\w/', 'pcre' => '10.44', 'locale' => self::LATIN1, 'description' => 'A word character: [a-zA-Z_0-9], and the letters of the fr_FR.ISO8859-1 locale'];
        // An ASCII option keeps "\w" to the tables, which the locale gives.
        yield 'a word character after every ASCII option, Latin-1' => ['pattern' => '/(?a)\w/u', 'pcre' => '10.44', 'locale' => self::LATIN1, 'description' => 'A word character: [a-zA-Z_0-9], and the letters of the fr_FR.ISO8859-1 locale'];
        yield 'a whitespace character without u, C' => ['pattern' => '/\s/', 'pcre' => '10.44', 'locale' => 'C', 'description' => 'A whitespace character: [ \t\n\x0B\f\r]'];
        yield 'a whitespace character without u, Latin-1' => ['pattern' => '/\s/', 'pcre' => '10.44', 'locale' => self::LATIN1, 'description' => 'A whitespace character: [ \t\n\x0B\f\r], and the whitespace characters of the fr_FR.ISO8859-1 locale'];
        // isdigit() takes the ASCII digits alone under every locale.
        yield 'a digit without u, Latin-1' => ['pattern' => '/\d/', 'pcre' => '10.44', 'locale' => self::LATIN1, 'description' => 'A digit: [0-9]'];
        yield 'a version at least one, minor of one digit, 10.44' => ['pattern' => '/(?(VERSION>=10.4)a|b)/', 'pcre' => '10.44', 'locale' => 'C', 'description' => 'PCRE2 >= 10.4, read as 10.40, which holds for PCRE2 10.44'];
        yield 'a version at least one, minor of one digit, 10.42' => ['pattern' => '/(?(VERSION>=10.5)a|b)/', 'pcre' => '10.42', 'locale' => 'C', 'description' => 'PCRE2 >= 10.5, read as 10.50, which does not hold for PCRE2 10.42'];
        yield 'a version at least one, minor of one digit, 10.49' => ['pattern' => '/(?(VERSION>=10.5)a|b)/', 'pcre' => '10.49', 'locale' => 'C', 'description' => 'PCRE2 >= 10.5, read as 10.05, which holds for PCRE2 10.49'];
        yield 'a version at least one, two-digit minor, 10.44' => ['pattern' => '/(?(VERSION>=10.45)a|b)/', 'pcre' => '10.44', 'locale' => 'C', 'description' => 'PCRE2 >= 10.45, which does not hold for PCRE2 10.44'];
        yield 'a version at least one, no minor, 10.39' => ['pattern' => '/(?(VERSION>=10)a|b)/', 'pcre' => '10.39', 'locale' => 'C', 'description' => 'PCRE2 >= 10, read as 10.00, which holds for PCRE2 10.39'];
        yield 'a version equal to one, 10.40' => ['pattern' => '/(?(VERSION=10.4)a|b)/', 'pcre' => '10.40', 'locale' => 'C', 'description' => 'PCRE2 = 10.4, read as 10.40, which holds for PCRE2 10.40'];
        yield 'a version equal to one, 10.42' => ['pattern' => '/(?(VERSION=10.4)a|b)/', 'pcre' => '10.42', 'locale' => 'C', 'description' => 'PCRE2 = 10.4, read as 10.40, which does not hold for PCRE2 10.42'];
        yield 'a version equal to one, minor read whole, 10.49' => ['pattern' => '/(?(VERSION=10.049)a|b)/', 'pcre' => '10.49', 'locale' => 'C', 'description' => 'PCRE2 = 10.049, read as 10.49, which holds for PCRE2 10.49'];
        // PCRE2 compares with ">=" and "=" only, and refuses the others.
        yield 'a comparison PCRE2 refuses' => ['pattern' => '/(?(VERSION!=10.4)a|b)/', 'pcre' => '10.44', 'locale' => 'C', 'description' => 'Version Condition: PCRE2 != 10.4'];
        yield 'a version of three numbers PCRE2 refuses' => ['pattern' => '/(?(VERSION>=10.4.1)a|b)/', 'pcre' => '10.44', 'locale' => 'C', 'description' => 'Version Condition: PCRE2 >= 10.4.1'];
    }

    /**
     * The release rows on the running engine: the release it links reads
     * "\w" and the version conditions as the rows of that release say.
     */
    #[Test]
    public function test_the_running_engine_reads_as_its_explanation_says(): void
    {
        $runtime = PcreTarget::runtime();
        $text = (string) RegexParser::create(['cache' => null])->parse('/\w/u')->accept(new TextExplainer($runtime));
        $marks = 1 === preg_match('/^\w$/u', "\u{301}");

        $this->assertSame($marks, 1 === preg_match('/^\w$/u', "\u{203F}"));
        $this->assertSame(0, preg_match('/^\w$/u', "\u{903}"));
        $this->assertStringContainsString($marks ? self::UNICODE_WORD_FROM_10_43 : self::UNICODE_WORD_BEFORE_10_43, $text);

        $condition = (string) RegexParser::create(['cache' => null])->parse('/(?(VERSION>=10.5)a|b)/')->accept(new TextExplainer($runtime));
        $holds = 1 === preg_match('/^(?(VERSION>=10.5)a|b)$/', 'a');
        $this->assertStringContainsString($holds ? ', which holds for' : ', which does not hold for', $condition);
    }

    /**
     * The locale rows on the engine. Under C the class each type is
     * explained with is the one PHP matches, byte for byte. Under the
     * Latin-1 locale "\w" takes bytes past it, which the explanation says by
     * naming the locale, and "\d" none. Once LC_CTYPE has been set, PHP
     * keeps building its tables from it: the test runs on its own.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_the_class_of_a_type_is_the_one_the_engine_matches(): void
    {
        $this->requiresLocale(self::LATIN1, 'The locale rows');
        $explained = [];
        foreach (['C', self::LATIN1] as $locale) {
            $parser = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.44', 'locale' => $locale]);
            foreach (['d', 's', 'w'] as $type) {
                $explained[$locale][$type] = (string) $parser->parse('/\\'.$type.'/')->accept(new TextExplainer(new PcreTarget(80400, '10.44', $locale)));
            }
        }

        $previous = (string) setlocale(\LC_CTYPE, '0');

        try {
            $matched = [];
            // C first: once a locale was named, its tables stay.
            foreach (['C', self::LATIN1] as $locale) {
                $this->assertNotFalse(setlocale(\LC_CTYPE, $locale));
                foreach (['d', 's', 'w'] as $type) {
                    $this->assertSame(1, preg_match('/: (\[[^]]+\])/', $explained[$locale][$type], $class), $type);
                    $matched[$locale][$type] = [
                        'type' => array_values(array_filter(range(0, 255), static fn (int $byte): bool => 1 === preg_match('/\\'.$type.'/', \chr($byte)))),
                        'class' => array_values(array_filter(range(0, 255), static fn (int $byte): bool => 1 === preg_match('/'.$class[1].'/', \chr($byte)))),
                    ];
                }
            }
        } finally {
            setlocale(\LC_CTYPE, $previous);
        }

        foreach (['d', 's', 'w'] as $type) {
            $this->assertSame($matched['C'][$type]['class'], $matched['C'][$type]['type'], $type.' under C');
            $this->assertStringNotContainsString('locale', $explained['C'][$type], $type.' under C');
            $this->assertSame([], array_diff($matched[self::LATIN1][$type]['class'], $matched[self::LATIN1][$type]['type']), $type.' under '.self::LATIN1);
        }

        $this->assertNotSame($matched[self::LATIN1]['w']['class'], $matched[self::LATIN1]['w']['type']);
        $this->assertStringEndsWith(', and the letters of the '.self::LATIN1.' locale', $explained[self::LATIN1]['w']);
        $this->assertSame($matched[self::LATIN1]['d']['class'], $matched[self::LATIN1]['d']['type']);
        $this->assertStringNotContainsString('locale', $explained[self::LATIN1]['d']);
    }

    /**
     * Without a target an explanation names what the reading depends on.
     */
    #[Test]
    public function test_without_a_target_the_explanation_names_what_the_reading_depends_on(): void
    {
        $parser = RegexParser::create(['cache' => null]);

        $this->assertStringContainsString('and from PCRE2 10.43 a non-spacing mark', (string) $parser->parse('/\w/u')->accept(new TextExplainer()));
        $this->assertStringContainsString('and the letters of the LC_CTYPE locale when it is not C', (string) $parser->parse('/\w/')->accept(new HtmlExplainer()));
        $this->assertStringContainsString('Version Condition: PCRE2 >= 10.4)', (string) $parser->parse('/(?(VERSION>=10.4)a|b)/')->accept(new TextExplainer()));
    }

    /**
     * The facade explains for the target it judges patterns for.
     */
    #[Test]
    public function test_the_facade_explains_for_its_target(): void
    {
        $older = Regex::create(['cache' => null, 'php_version' => '8.3', 'pcre_version' => '10.42', 'locale' => 'C']);
        $newer = Regex::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.44', 'locale' => 'C']);

        $this->assertStringContainsString(self::UNICODE_WORD_BEFORE_10_43, $older->explain('/\w/u'));
        $this->assertStringContainsString(self::UNICODE_WORD_FROM_10_43, $newer->explain('/\w/u'));
        $this->assertStringContainsString(self::UNICODE_WORD_FROM_10_43, html_entity_decode($newer->explain('/\w/u', ExplainFormat::Html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
        $this->assertStringContainsString('A word character: [a-zA-Z_0-9]', $newer->explain('/\w/'));
        $this->assertStringNotContainsString('locale', $newer->explain('/\w/'));
    }
}
