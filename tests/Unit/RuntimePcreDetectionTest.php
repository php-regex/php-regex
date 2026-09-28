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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * Without a PHP version to target, a pattern is judged for the PHP running,
 * and what that PHP compiles is decided by the PCRE2 it links, not by its
 * own version: the PHP 8.4 packages of Ubuntu 24.04 link its PCRE2 10.42,
 * where PHP bundles 10.44. Syntax PCRE2 10.43 added is refused there.
 */
final class RuntimePcreDetectionTest extends TestCase
{
    #[Test]
    #[DataProvider('provideVersionDependentPatterns')]
    public function test_validate_follows_the_pcre2_this_php_links(string $pattern): void
    {
        error_clear_last();
        $compiles = false !== @preg_match($pattern, '') || null === error_get_last();

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertSame($compiles, $result->isValid, \sprintf(
            '%s %s on this PHP (PCRE2 %s) but was reported %s.',
            $pattern,
            $compiles ? 'compiles' : 'does not compile',
            \PCRE_VERSION,
            $result->isValid ? 'valid' : 'invalid: '.$result->error,
        ));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVersionDependentPatterns(): iterable
    {
        yield 'ASCII option, PCRE2 10.43' => ['pattern' => '/(?aD)/'];
        yield 'caseless restrict option, PCRE2 10.43' => ['pattern' => '/(?r)a/'];
        yield 'padding closed by a brace after U+, PCRE2 10.43' => ['pattern' => '/\\N{U+ }/u'];
        yield 'padded hex escape, PCRE2 10.43' => ['pattern' => '/\\x{ 41 }/'];
        yield 'variable-length lookbehind, PCRE2 10.43' => ['pattern' => '/(?<=ab?)c/'];
        yield 'open minimum with nothing to repeat, text before PCRE2 10.43' => ['pattern' => '/{,2}/'];
        yield 'padded count with nothing to repeat, text before PCRE2 10.43' => ['pattern' => '/{ 2 }/'];
        yield 'k in a class, PCRE2 10.45' => ['pattern' => '/[\\k]/'];
    }
}
