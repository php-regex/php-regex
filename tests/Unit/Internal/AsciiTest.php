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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\Ascii;
use PHPRegex\Parser\Internal\Bytes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE reads digits, letters and spaces in ASCII whatever the locale of the
 * process: a byte past 0x7F is none of them, as ctype_* would say under a
 * Latin-1 locale.
 */
final class AsciiTest extends TestCase
{
    /**
     * @param array{digit: bool, alpha: bool, alnum: bool, space: bool, hex: bool} $expected
     */
    #[Test]
    #[DataProvider('provideStrings')]
    public function test_classes_are_ascii_only(string $text, array $expected): void
    {
        $this->assertSame($expected, [
            'digit' => Ascii::isDigit($text),
            'alpha' => Ascii::isAlpha($text),
            'alnum' => Ascii::isAlnum($text),
            'space' => Ascii::isSpace($text),
            'hex' => Ascii::isHexDigit($text),
        ], var_export($text, true));
    }

    /**
     * @return iterable<string, array{text: string, expected: array{digit: bool, alpha: bool, alnum: bool, space: bool, hex: bool}}>
     */
    public static function provideStrings(): iterable
    {
        yield 'empty' => ['text' => '', 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
        yield 'digits' => ['text' => '0189', 'expected' => ['digit' => true, 'alpha' => false, 'alnum' => true, 'space' => false, 'hex' => true]];
        yield 'letters' => ['text' => 'azAZ', 'expected' => ['digit' => false, 'alpha' => true, 'alnum' => true, 'space' => false, 'hex' => false]];
        yield 'hex letters' => ['text' => 'afAF09', 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => true, 'space' => false, 'hex' => true]];
        yield 'letter past f' => ['text' => 'g', 'expected' => ['digit' => false, 'alpha' => true, 'alnum' => true, 'space' => false, 'hex' => false]];
        yield 'underscore' => ['text' => '_', 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
        yield 'every C space' => ['text' => " \t\n\r\v\f", 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => true, 'hex' => false]];
        yield 'mixed' => ['text' => '1 a', 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
        yield 'Latin-1 letter byte' => ['text' => "\xE4", 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
        yield 'Latin-1 no-break space byte' => ['text' => "\xA0", 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
        yield 'UTF-8 letter' => ['text' => 'é', 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
        yield 'Arabic-Indic digit' => ['text' => '٣', 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
        yield 'digit then a high byte' => ['text' => "1\xB2", 'expected' => ['digit' => false, 'alpha' => false, 'alnum' => false, 'space' => false, 'hex' => false]];
    }

    /**
     * What the library decides must not hang on the locale of the process
     * that runs it: no ctype_* call is left in the library.
     */
    #[Test]
    public function test_library_calls_no_locale_dependent_ctype_function(): void
    {
        $calls = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../../src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (\is_array($token) && \T_STRING === $token[0] && str_starts_with(strtolower($token[1]), 'ctype_')) {
                    $calls[] = $file->getFilename().':'.$token[2].' '.$token[1];
                }
            }
        }

        $this->assertSame([], $calls);
    }

    #[Test]
    #[DataProvider('provideCaseMappings')]
    public function test_case_mapping_touches_ascii_letters_only(string $text, string $lower, string $upper, string $lowerFirst, string $upperFirst): void
    {
        $this->assertSame($lower, Ascii::lower($text));
        $this->assertSame($upper, Ascii::upper($text));
        $this->assertSame($lowerFirst, Ascii::lowerFirst($text));
        $this->assertSame($upperFirst, Ascii::upperFirst($text));
    }

    /**
     * @return iterable<string, array{text: string, lower: string, upper: string, lowerFirst: string, upperFirst: string}>
     */
    public static function provideCaseMappings(): iterable
    {
        yield 'empty' => ['text' => '', 'lower' => '', 'upper' => '', 'lowerFirst' => '', 'upperFirst' => ''];
        yield 'mixed letters' => ['text' => 'aZ', 'lower' => 'az', 'upper' => 'AZ', 'lowerFirst' => 'aZ', 'upperFirst' => 'AZ'];
        yield 'dotted and dotless i' => ['text' => 'Ii', 'lower' => 'ii', 'upper' => 'II', 'lowerFirst' => 'ii', 'upperFirst' => 'Ii'];
        yield 'no letter' => ['text' => '_9-', 'lower' => '_9-', 'upper' => '_9-', 'lowerFirst' => '_9-', 'upperFirst' => '_9-'];
        yield 'Latin-1 letter bytes' => ['text' => "\xC4\xE4", 'lower' => "\xC4\xE4", 'upper' => "\xC4\xE4", 'lowerFirst' => "\xC4\xE4", 'upperFirst' => "\xC4\xE4"];
        yield 'UTF-8 letters' => ['text' => 'éÉ', 'lower' => 'éÉ', 'upper' => 'éÉ', 'lowerFirst' => 'éÉ', 'upperFirst' => 'éÉ'];
    }

    /**
     * PHP 8.1 maps case through the LC_CTYPE locale, where a Latin-1 locale
     * lowers "\xC4" to "\xE4": the library's mapping stays ASCII under it.
     * Run apart: once setlocale() named a locale, PHP keeps building PCRE's
     * tables from LC_CTYPE, even after the previous name is set back.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_case_mapping_ignores_a_latin1_locale(): void
    {
        $previous = setlocale(\LC_CTYPE, '0');
        // Where the machine has no such locale, the C locale stays: the
        // answer must be the same.
        setlocale(\LC_CTYPE, 'de_DE.ISO8859-1', 'de_DE.ISO-8859-1', 'de_DE.iso88591', 'de_DE');

        try {
            $this->assertSame("\xC4i", Ascii::lower("\xC4I"));
            $this->assertSame("\xE4I", Ascii::upper("\xE4i"));
        } finally {
            setlocale(\LC_CTYPE, false === $previous ? 'C' : $previous);
        }
    }

    /**
     * @param positive-int $length
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('provideSplits')]
    public function test_bytes_split_gives_no_piece_for_the_empty_string(string $text, int $length, array $expected): void
    {
        $this->assertSame($expected, Bytes::split($text, $length));
    }

    /**
     * @return iterable<string, array{text: string, length: positive-int, expected: list<string>}>
     */
    public static function provideSplits(): iterable
    {
        yield 'empty' => ['text' => '', 'length' => 1, 'expected' => []];
        yield 'empty in pairs' => ['text' => '', 'length' => 2, 'expected' => []];
        yield 'bytes' => ['text' => 'ab', 'length' => 1, 'expected' => ['a', 'b']];
        yield 'multibyte bytes' => ['text' => 'é', 'length' => 1, 'expected' => ["\xC3", "\xA9"]];
        yield 'pairs with a rest' => ['text' => 'abc', 'length' => 2, 'expected' => ['ab', 'c']];
    }

    /**
     * The packages that run on PHP 8.1 map case and split bytes through the
     * helpers: PHP 8.1 lowers through the locale and splits "" into [""].
     */
    #[Test]
    public function test_packages_on_php_8_1_call_no_locale_dependent_case_mapping(): void
    {
        $forbidden = ['strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'ucwords', 'stripos', 'stristr', 'strripos', 'str_ireplace', 'str_split'];
        $helpers = [realpath(__DIR__.'/../../../src/Parser/Internal/Ascii.php'), realpath(__DIR__.'/../../../src/Parser/Internal/Bytes.php')];
        // Psalm keys its functions by strtolower(): the plugin asks for a
        // name lowered the same way.
        $sameAsTheHost = ['Psalm/PregCallAnalyzer.php:'];
        $calls = [];
        foreach (glob(__DIR__.'/../../../src/*', \GLOB_ONLYDIR) ?: [] as $package) {
            if (\in_array(basename($package), ['Laravel', 'Symfony'], true)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($package, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || \in_array($file->getRealPath(), $helpers, true)) {
                    continue;
                }

                $tokens = token_get_all((string) file_get_contents($file->getPathname()));
                foreach ($tokens as $index => $token) {
                    if (!\is_array($token) || !\in_array($token[0], [\T_STRING, \T_NAME_FULLY_QUALIFIED], true) || !\in_array(Ascii::lower(ltrim($token[1], '\\')), $forbidden, true)) {
                        continue;
                    }

                    $previous = self::neighbour($tokens, $index, -1);
                    if (\is_array($previous) && \in_array($previous[0], [\T_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NULLSAFE_OBJECT_OPERATOR, \T_USE], true)) {
                        continue;
                    }
                    if ('(' !== self::neighbour($tokens, $index, 1)) {
                        continue;
                    }

                    $call = basename($package).'/'.$file->getFilename().':'.$token[2].' '.$token[1];
                    if (!\in_array(basename($package).'/'.$file->getFilename().':', $sameAsTheHost, true) || 'strtolower' !== $token[1]) {
                        $calls[] = $call;
                    }
                }
            }
        }

        $this->assertSame([], $calls);
    }

    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     *
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function neighbour(array $tokens, int $index, int $step): array|string|null
    {
        for ($at = $index + $step; isset($tokens[$at]); $at += $step) {
            if (!\is_array($tokens[$at]) || !\in_array($tokens[$at][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $tokens[$at];
            }
        }

        return null;
    }
}
