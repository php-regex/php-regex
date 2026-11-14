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
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "(*UTF)" turns UTF-8 mode on without the "u" flag. PCRE refuses "\C" in a
 * lookbehind there, where the lookbehind is measured ("\C is not allowed in
 * a lookbehind assertion in UTF-8 mode at offset 6"); elsewhere PHP refuses
 * it from 8.4.29, 8.5.14 and 8.6.1, and the library for every PHP.
 */
final class LookbehindBackslashCUnderUtfTest extends TestCase
{
    #[Test]
    #[DataProvider('provideLookbehinds')]
    public function test_backslash_c_in_a_lookbehind_under_utf_is_refused(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid);
        $this->assertSame($offset, $result->offset);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideLookbehinds(): iterable
    {
        yield 'utf verb' => ['pattern' => '/(*UTF)(?<=b\C)/', 'offset' => 6];
    }

    /**
     * With a branch reset anywhere in the pattern, PCRE2 measures no back
     * reference in a lookbehind: "length of lookbehind assertion is not
     * limited", where the lookbehind starts.
     */
    #[Test]
    #[DataProvider('provideReferencesBesideABranchReset')]
    public function test_a_back_reference_in_a_lookbehind_beside_a_branch_reset_is_not_limited(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, ''));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideReferencesBesideABranchReset(): iterable
    {
        yield 'number' => ['pattern' => '/(a)(?|b|c)(?<=\1)/', 'offset' => 10];
        yield 'name' => ['pattern' => '/(?<n>a)(?|b|c)(?<=\k<n>)/', 'offset' => 14];
        yield 'g number' => ['pattern' => '/(a)(?|b|c)(?<=\g1)/', 'offset' => 10];
        yield 'branch reset after the lookbehind' => ['pattern' => '/(a)(?<=\1)(?|b|c)/', 'offset' => 3];
    }

    /**
     * Outside a lookbehind, PHP 8.4.29, 8.5.14 and 8.6.1 refuse it after
     * compiling ("using \C is incompatible with UTF-8 mode", GH-25452), as
     * they refuse it under "u"; earlier releases compile it, and matching it
     * can crash them. Like "\C" under "u", it is refused for every PHP, at
     * the "\C".
     */
    #[Test]
    #[DataProvider('provideUtfVerbs')]
    public function test_backslash_c_outside_a_lookbehind_under_utf_is_refused_for_every_php(string $pattern, int $offset): void
    {
        $refuses = \PHP_VERSION_ID >= 80601 || (\PHP_VERSION_ID >= 80514 && \PHP_VERSION_ID < 80600) || (\PHP_VERSION_ID >= 80429 && \PHP_VERSION_ID < 80500);
        $this->assertSame(!$refuses, EngineProbe::compiles($pattern), 'What this PHP does with it.');

        foreach ([['php_version' => '8.2'], ['php_version' => '8.4', 'pcre_version' => '10.42'], ['php_version' => '8.5'], ['php_version' => '8.6'], []] as $target) {
            $result = Regex::create(['cache' => null] + $target)->validate($pattern);

            $this->assertFalse($result->isValid, (string) json_encode($target));
            $this->assertSame(ErrorCode::EscapeSingleByteInUtf, $result->errorCode);
            $this->assertSame($offset, $result->offset);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUtfVerbs(): iterable
    {
        yield 'utf verb' => ['pattern' => '/(*UTF)b\C/', 'offset' => 9];
        yield 'utf8 verb' => ['pattern' => '/(*UTF8)a\Cb/', 'offset' => 10];
        yield 'after another start option' => ['pattern' => '/(*CR)(*UTF)a\C/', 'offset' => 14];
    }
}
