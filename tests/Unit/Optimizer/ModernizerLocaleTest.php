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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Modernizer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Modernizer writes a class as a shorthand only where the two match the
 * same characters whatever the program does. Without "u", PHP builds the
 * tables of "\s" from LC_CTYPE once a program calls setlocale(): 167 of the
 * 288 locales of macOS give it the no-break space, byte 0xA0, which the six
 * characters of "[\t\n\x0B\f\r ]" do not hold. "\d" stays the ten ASCII
 * digits under every locale, as C's isdigit() is. Under "u" or "(*UCP)"
 * both read Unicode properties, "\s" taking U+00A0 and "\d" U+0663, and the
 * classes stay. The Modernizer writes no "\w", "\h" or "\v".
 */
final class ModernizerLocaleTest extends TestCase
{
    /**
     * Locales that give "\s" the byte 0xA0 on some systems, or another
     * locale than C where none of them is installed.
     */
    private const WIDENING_LOCALES = ['en_US.UTF-8', 'fr_FR.UTF-8', 'C.UTF-8', 'fr_FR.ISO8859-1', 'de_DE.ISO8859-1'];

    #[Test]
    #[DataProvider('provideClasses')]
    public function test_a_class_becomes_a_shorthand_only_under_a_locale_known_to_match_alike(string $pattern, ?string $locale, string $expected): void
    {
        $tree = RegexParser::create(['cache' => null])->parse($pattern);

        $this->assertSame($expected, $tree->accept(new Modernizer($locale))->accept(PatternPrinter::asWritten()));
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string|null, expected: string}>
     */
    public static function provideClasses(): iterable
    {
        yield 'spaces, the locale unknown' => ['pattern' => '/[\t\n\x0B\f\r ]/', 'locale' => null, 'expected' => '/[\t\n\x0B\f\r ]/'];
        yield 'spaces, C' => ['pattern' => '/[\t\n\x0B\f\r ]/', 'locale' => 'C', 'expected' => '/\s/'];
        yield 'spaces, POSIX' => ['pattern' => '/[\t\n\x0B\f\r ]/', 'locale' => 'POSIX', 'expected' => '/\s/'];
        yield 'spaces as a range, C' => ['pattern' => '/[\t-\r ]/', 'locale' => 'C', 'expected' => '/\s/'];
        yield 'spaces, C.UTF-8' => ['pattern' => '/[\t\n\x0B\f\r ]/', 'locale' => 'C.UTF-8', 'expected' => '/[\t\n\x0B\f\r ]/'];
        yield 'spaces, a UTF-8 locale' => ['pattern' => '/[\t\n\x0B\f\r ]/', 'locale' => 'en_US.UTF-8', 'expected' => '/[\t\n\x0B\f\r ]/'];
        yield 'spaces, a Latin-1 locale' => ['pattern' => '/[\t\n\x0B\f\r ]/', 'locale' => 'fr_FR.ISO8859-1', 'expected' => '/[\t\n\x0B\f\r ]/'];
        yield 'spaces, C, under u' => ['pattern' => '/[\t\n\x0B\f\r ]/u', 'locale' => 'C', 'expected' => '/[\t\n\x0B\f\r ]/u'];
        yield 'spaces, C, under (*UCP)' => ['pattern' => '/(*UCP)[\t\n\x0B\f\r ]/', 'locale' => 'C', 'expected' => '/(*UCP)[\t\n\x0B\f\r ]/'];
        yield 'spaces negated, C' => ['pattern' => '/[^\t\n\x0B\f\r ]/', 'locale' => 'C', 'expected' => '/[^\t\n\x0B\f\r ]/'];
        yield 'digits, the locale unknown' => ['pattern' => '/[0-9]/', 'locale' => null, 'expected' => '/\d/'];
        yield 'digits, a Latin-1 locale' => ['pattern' => '/[0-9]/', 'locale' => 'fr_FR.ISO8859-1', 'expected' => '/\d/'];
        yield 'digits, C, under u' => ['pattern' => '/[0-9]/u', 'locale' => 'C', 'expected' => '/[0-9]/u'];
    }

    /**
     * Run apart: once setlocale() named a locale, PHP keeps building PCRE's
     * tables from LC_CTYPE. Under the first of the locales the machine
     * has, the modernized pattern matches each byte as the class does, and
     * "\d" takes no byte above ASCII; where "\s" takes 0xA0 there, the class
     * does not.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_the_modernized_pattern_matches_as_the_class_under_another_locale(): void
    {
        $locale = setlocale(\LC_CTYPE, self::WIDENING_LOCALES);

        try {
            $parser = RegexParser::create(['cache' => null]);
            foreach (['/^[\t\n\x0B\f\r ]$/', '/^[0-9]$/'] as $pattern) {
                $modernized = $parser->parse($pattern)->accept(new Modernizer())->accept(PatternPrinter::asWritten());
                foreach (range(0, 255) as $byte) {
                    $this->assertSame(
                        preg_match($pattern, \chr($byte)),
                        preg_match($modernized, \chr($byte)),
                        \sprintf('%s modernized as %s differs on %02X under %s.', $pattern, $modernized, $byte, var_export($locale, true)),
                    );
                }
            }

            foreach (range(0x80, 0xFF) as $byte) {
                $this->assertSame(0, preg_match('/^\d$/', \chr($byte)), \sprintf('"\d" takes %02X under %s.', $byte, var_export($locale, true)));
            }

            $this->assertSame(0, preg_match('/^[\t\n\x0B\f\r ]$/', "\xA0"));
            if (1 === preg_match('/^\s$/', "\xA0")) {
                $this->assertSame('/^[\t\n\x0B\f\r ]$/', $parser->parse('/^[\t\n\x0B\f\r ]$/')->accept(new Modernizer())->accept(PatternPrinter::asWritten()));
            }
        } finally {
            setlocale(\LC_CTYPE, 'C');
        }
    }

    /**
     * Under "u", "\s" takes U+00A0 and "\d" U+0663, which the classes do
     * not: they stay, whatever the locale.
     */
    #[Test]
    public function test_the_classes_stay_under_u_where_the_shorthands_read_unicode(): void
    {
        $this->assertSame(1, preg_match('/^\s$/u', "\u{A0}"));
        $this->assertSame(0, preg_match('/^[\t\n\x0B\f\r ]$/u', "\u{A0}"));
        $this->assertSame(1, preg_match('/^\d$/u', "\u{663}"));
        $this->assertSame(0, preg_match('/^[0-9]$/u', "\u{663}"));

        $parser = RegexParser::create(['cache' => null]);
        foreach (['/^[\t\n\x0B\f\r ]$/u', '/^[0-9]$/u'] as $pattern) {
            $this->assertSame($pattern, $parser->parse($pattern)->accept(new Modernizer('C'))->accept(PatternPrinter::asWritten()));
        }
    }
}
