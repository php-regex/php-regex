<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Internal\Ascii;

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
}
