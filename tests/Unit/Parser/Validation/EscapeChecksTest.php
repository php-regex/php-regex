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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\EscapeChecks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EscapeChecksTest extends TestCase
{
    /**
     * Whether a property exists is asked of the running engine, with the X
     * modifier: PHP 7.3 and 7.4 compile without it under
     * PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, which reads an unknown property as
     * the letters it spells, so the probe would find every name. On
     * php:7.4-cli, preg_match('/^\p{Bogus}$/u', '') gives 0 and
     * preg_match('/^\p{Bogus}$/uX', '') refuses the name; from PHP 8.0 both
     * refuse it, and X changes nothing.
     */
    #[Test]
    public function test_a_property_is_probed_as_php_8_reads_it_whichever_php_runs(): void
    {
        $probe = (new \ReflectionClass(EscapeChecks::class))->getMethod('propertyProbe');

        $this->assertSame('/^\p{Greek}$/uX', $probe->invoke(null, 'p{Greek}'));

        $unknown = $probe->invoke(null, 'p{Bogus}');
        $this->assertIsString($unknown);
        $this->assertFalse(@preg_match($unknown, ''));

        $letter = $probe->invoke(null, 'p{L}');
        $this->assertIsString($letter);
        $this->assertSame(1, preg_match($letter, 'a'));
    }

    /**
     * Each verified with preg_match() on PCRE2 10.49: the first error PCRE
     * meets, reported where it is.
     *
     * @param array{0: ErrorCode, 1: int}|null $error
     */
    #[Test]
    #[DataProvider('provideEscapes')]
    public function test_the_escape_is_judged_as_pcre_reads_it(string $pattern, ?array $error): void
    {
        $result = RegexParser::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.49', 'runtime_pcre_validation' => false])->validate($pattern);

        $this->assertSame($error, null === $result->errorCode ? null : [$result->errorCode, $result->offset], $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, error: array{0: ErrorCode, 1: int}|null}>
     */
    public static function provideEscapes(): iterable
    {
        // PCRE reads escapes and structure in one pass: the character name
        // is refused before the group left open ("PCRE2 does not support
        // ... \N{name} ... at offset 3").
        yield 'character name before a group left open' => ['pattern' => '/\N{foo}(/', 'error' => [ErrorCode::EscapeUnsupported, 3]];
        // "\N{U+41}" without UTF, refused past its "}" before the group left
        // open ("\N{U+dddd} is supported only in Unicode (UTF) mode at
        // offset 8").
        yield 'code point without UTF before a group left open' => ['pattern' => '/\N{U+41}(/', 'error' => [ErrorCode::UnicodeNamedRequiresUtf, 8]];
        // The same code point ending a group left open: PCRE2 10.49 reports
        // the escape, at the end of the pattern where the group fails too.
        yield 'code point without UTF ending a group left open' => ['pattern' => '/(?>\N{U+41}/', 'error' => [ErrorCode::UnicodeNamedRequiresUtf, 11]];
        // "\N{U+ }": padding alone up to the brace is read, and only the
        // group left open is refused ("missing closing parenthesis at offset
        // 10").
        yield 'padded code point of no digit in a group left open' => ['pattern' => '/(?>\N{U+ }/u', 'error' => [ErrorCode::GroupUnclosed, 10]];
    }
}
