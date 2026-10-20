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
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In a property name PCRE2 skips spaces, hyphens and underscores. Up to
 * 10.44 a "^" after one of them is a letter of the name, which no property
 * holds; from 10.45 it negates, and the name after it is looked up as after
 * a "^" right behind the brace. Each row as pcre2test 10.40 to 10.49 report
 * it: null where the release compiles the pattern, else the offset of
 * "unknown property after \P or \p".
 */
final class PropertyCaretAfterIgnoredCharactersTest extends TestCase
{
    /**
     * @param array<string, int|null> $outcomes
     */
    #[Test]
    #[DataProvider('provideNames')]
    public function test_a_caret_after_a_skipped_character_is_read_as_the_release_reads_it(string $pattern, array $outcomes): void
    {
        foreach ($outcomes as $release => $offset) {
            $validation = RegexParser::create(['php_version' => '8.4', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);
            if (null === $offset) {
                $this->assertTrue($validation->isValid, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));

                continue;
            }

            $this->assertFalse($validation->isValid, \sprintf('%s on PCRE2 %s', $pattern, $release));
            $this->assertSame($offset, $validation->offset, \sprintf('%s on PCRE2 %s: %s', $pattern, $release, $validation->error ?? ''));
            $this->assertSame(ErrorCode::UnicodePropertyInvalid, $validation->errorCode, \sprintf('%s on PCRE2 %s', $pattern, $release));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, outcomes: array<string, int|null>}>
     */
    public static function provideNames(): iterable
    {
        yield 'caret after a hyphen' => ['pattern' => '/\p{-^LL-}/', 'outcomes' => ['10.40' => 9, '10.44' => 9, '10.45' => null, '10.49' => null]];
        yield 'caret after a hyphen in a class' => ['pattern' => '/[\p{-^LL-}a]/', 'outcomes' => ['10.42' => 10, '10.45' => null]];
        yield 'caret after an underscore' => ['pattern' => '/\p{_^Lu}/', 'outcomes' => ['10.43' => 8, '10.47' => null]];
        yield 'caret after a space' => ['pattern' => '/\p{ ^L}/', 'outcomes' => ['10.44' => 7, '10.45' => null, '10.48' => null]];
        yield 'caret after a hyphen before a script of Unicode 15' => ['pattern' => '/\p{-^Kawi}/', 'outcomes' => ['10.42' => 10, '10.44' => 10, '10.45' => null]];
        yield 'caret after a space before a script of Unicode 16' => ['pattern' => '/\p{ ^Gara}/', 'outcomes' => ['10.44' => 10, '10.45' => null, '10.49' => null]];
        yield 'caret after a space before a script of Unicode 17' => ['pattern' => '/\p{ ^Sidetic}/', 'outcomes' => ['10.45' => 13, '10.47' => 13, '10.48' => null]];
        yield 'caret after a hyphen before a script of Unicode 17' => ['pattern' => '/\p{-^Tayo}/', 'outcomes' => ['10.44' => 10, '10.47' => 10, '10.48' => null]];
        yield 'caret after a space before no name' => ['pattern' => '/\P{ ^}/', 'outcomes' => ['10.44' => 6, '10.45' => 6, '10.47' => 6, '10.48' => 6, '10.49' => 6]];
        yield 'caret after a space before an unknown name' => ['pattern' => '/\p{ ^Foo}/', 'outcomes' => ['10.45' => 9, '10.46' => 9, '10.48' => 9, '10.49' => 9]];
        yield 'caret after a space before an unknown name in a class' => ['pattern' => '/[\p{ ^a]}/u', 'outcomes' => ['10.45' => 9, '10.48' => 9]];
        yield 'hyphen after the caret' => ['pattern' => '/\p{^-Lu}/', 'outcomes' => ['10.40' => null, '10.44' => null, '10.49' => null]];
    }
}
