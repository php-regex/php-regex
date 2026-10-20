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

use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A shorthand class is written for a class only where the two match the
 * same characters whatever the program does. Without "u", PHP builds the
 * tables of "\w" from LC_CTYPE once a program calls setlocale(): under a
 * Latin-1 locale "\w" takes "\xE9" and "[a-zA-Z0-9_]" does not. "\d" stays
 * the ten ASCII digits under every locale, as C's isdigit() is; under
 * "(*UCP)" it reads Unicode properties, as under "u".
 */
final class LocaleIndependentShorthandTest extends TestCase
{
    /**
     * The locales that read "\xE9" as a letter, under the names systems
     * give them.
     */
    private const LATIN1_LOCALES = ['fr_FR.ISO8859-1', 'fr_FR.iso88591', 'fr_FR.ISO-8859-1', 'de_DE.ISO8859-1', 'en_US.ISO8859-1', 'fr_FR'];

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideClasses')]
    public function test_the_rewrite_keeps_the_class_where_the_shorthand_may_differ(string $pattern, string $expected, array $subjects): void
    {
        $optimized = Regex::create(['cache' => null])->optimize($pattern)->optimized;

        $this->assertSame($expected, $optimized);
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($optimized, $subject), \sprintf('%s rewritten as %s differs on %s.', $pattern, $optimized, bin2hex($subject)));
        }
    }

    /**
     * Run apart: once setlocale() named a locale, PHP keeps building PCRE's
     * tables from LC_CTYPE, even after the previous name is set back. Where
     * the machine has no Latin-1 locale, the C locale stays, and the answer
     * must be the same.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_the_rewrite_matches_what_the_class_matches_under_a_latin1_locale(): void
    {
        setlocale(\LC_CTYPE, self::LATIN1_LOCALES);
        $regex = Regex::create(['cache' => null]);

        foreach (self::provideClasses() as ['pattern' => $pattern, 'subjects' => $subjects]) {
            $optimized = $regex->optimize($pattern)->optimized;
            foreach ($subjects as $subject) {
                $this->assertSame(
                    preg_match($pattern, $subject),
                    preg_match($optimized, $subject),
                    \sprintf('%s rewritten as %s differs on %s under %s.', $pattern, $optimized, bin2hex($subject), (string) setlocale(\LC_CTYPE, '0')),
                );
            }
        }
    }

    /**
     * Where the locale is known to be C or POSIX, "\w" is the class: the
     * rewriter writes it then, and only then.
     */
    #[Test]
    #[DataProvider('provideKnownLocales')]
    public function test_the_word_class_becomes_a_shorthand_under_a_known_c_locale(string $pattern, ?string $locale, bool $optimizeWord, string $expected): void
    {
        $rewriter = new Rewriter(optimizeWord: $optimizeWord, locale: $locale);
        $rewritten = Regex::create(['cache' => null])->parse($pattern)->accept($rewriter);

        $this->assertSame($expected, $rewritten->accept(PatternPrinter::asWritten()));
        $this->assertSame(str_contains($expected, '\\w') || str_contains($expected, '\\W'), \in_array(Rewriter::WORD, $rewriter->changes(), true));
    }

    /**
     * @return iterable<string, array{pattern: string, locale: string|null, optimizeWord: bool, expected: string}>
     */
    public static function provideKnownLocales(): iterable
    {
        yield 'C' => ['pattern' => '/[a-zA-Z0-9_]/', 'locale' => 'C', 'optimizeWord' => true, 'expected' => '/\w/'];
        yield 'POSIX' => ['pattern' => '/[a-zA-Z0-9_]/', 'locale' => 'POSIX', 'optimizeWord' => true, 'expected' => '/\w/'];
        yield 'C, negated' => ['pattern' => '/[^_0-9A-Za-z]/', 'locale' => 'C', 'optimizeWord' => true, 'expected' => '/\W/'];
        yield 'C, a member written twice' => ['pattern' => '/[a-zA-Z0-9__]/', 'locale' => 'C', 'optimizeWord' => true, 'expected' => '/\w/'];
        yield 'C, one member more' => ['pattern' => '/[a-zA-Z0-9_-]/', 'locale' => 'C', 'optimizeWord' => true, 'expected' => '/[\-0-9A-Z_a-z]/'];
        yield 'C, the underscore missing' => ['pattern' => '/[a-zA-Z0-9]/', 'locale' => 'C', 'optimizeWord' => true, 'expected' => '/[0-9A-Za-z]/'];
        yield 'C, under u' => ['pattern' => '/[a-zA-Z0-9_]/u', 'locale' => 'C', 'optimizeWord' => true, 'expected' => '/[0-9A-Z_a-z]/u'];
        yield 'C, under (*UCP)' => ['pattern' => '/(*UCP)[a-zA-Z0-9_]/', 'locale' => 'C', 'optimizeWord' => true, 'expected' => '/(*UCP)[0-9A-Z_a-z]/'];
        yield 'C, the option off' => ['pattern' => '/[a-zA-Z0-9_]/', 'locale' => 'C', 'optimizeWord' => false, 'expected' => '/[0-9A-Z_a-z]/'];
        yield 'C.UTF-8, whose bytes past ASCII may be letters' => ['pattern' => '/[a-zA-Z0-9_]/', 'locale' => 'C.UTF-8', 'optimizeWord' => true, 'expected' => '/[0-9A-Z_a-z]/'];
        yield 'a Latin-1 locale' => ['pattern' => '/[a-zA-Z0-9_]/', 'locale' => 'fr_FR.ISO8859-1', 'optimizeWord' => true, 'expected' => '/[0-9A-Z_a-z]/'];
        yield 'the locale unknown' => ['pattern' => '/[a-zA-Z0-9_]/', 'locale' => null, 'optimizeWord' => true, 'expected' => '/[0-9A-Z_a-z]/'];
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subjects: list<string>}>
     */
    public static function provideClasses(): iterable
    {
        yield 'the word class without u' => [
            'pattern' => '/^[a-zA-Z0-9_]+$/',
            'expected' => '/^[0-9A-Z_a-z]+$/',
            'subjects' => ["caf\xE9", 'cafe_1', '-'],
        ];

        yield 'the negated word class without u' => [
            'pattern' => '/^[^a-zA-Z0-9_]$/',
            'expected' => '/^[^0-9A-Z_a-z]$/',
            'subjects' => ["\xE9", '-', 'a'],
        ];

        yield 'the word class under u' => [
            'pattern' => '/^[a-zA-Z0-9_]+$/u',
            'expected' => '/^[0-9A-Z_a-z]+$/u',
            'subjects' => ['café', 'cafe_1'],
        ];

        yield 'the digits without u' => [
            'pattern' => '/^[0-9]+$/',
            'expected' => '/^\d+$/',
            'subjects' => ['2026', "\xB2", "\xBD"],
        ];

        yield 'the digits under (*UCP) in UTF mode' => [
            'pattern' => '/(*UTF)(*UCP)^[0-9]$/',
            'expected' => '/(*UTF)(*UCP)^[0-9]$/',
            'subjects' => ["\u{0663}", '3'],
        ];

        yield 'the digits merged into a class under (*UCP) in UTF mode' => [
            'pattern' => '/(*UTF)(*UCP)^(?:\d|[a])$/',
            'expected' => '/(*UTF)(*UCP)^(?:\d|[a])$/',
            'subjects' => ["\u{0663}", '3', 'a'],
        ];
    }
}
