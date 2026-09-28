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

namespace RegexParser\Tests\Unit\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

/**
 * A class escape followed by \E and a hyphen, "[\w\E-a]": PCRE2 up to 10.44
 * reads the hyphen as a member, and from 10.45 it builds a range PCRE then
 * refuses (error 150). php-src bundles 10.40 in PHP 8.2, 10.42 in 8.3 and
 * 10.44 in 8.4 and 8.5, so every explicit target accepts these; without
 * one, the PCRE2 the running PHP links decides. Checked with pcre2test
 * 10.40, 10.42, 10.44, 10.45 and 10.48.
 */
final class ClassEscapeBeforeQuotedHyphenTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_every_bundled_pcre2_accepts_it(string $pattern): void
    {
        foreach (['8.2', '8.3', '8.4', '8.5'] as $phpVersion) {
            $result = Regex::create(['php_version' => $phpVersion])->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s compiles on the PCRE2 PHP %s bundles: %s', $pattern, $phpVersion, (string) $result->error));
        }
    }

    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_running_pcre2_decides_without_a_target(string $pattern): void
    {
        $refused = version_compare(explode(' ', \PCRE_VERSION)[0], '10.45', '>=');

        $this->assertSame(!$refused, Regex::create()->validate($pattern)->isValid, $pattern.' on PCRE '.\PCRE_VERSION);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'word class' => ['pattern' => '/[\w\E-a]/'];
        yield 'word class, empty quote' => ['pattern' => '/[\w\Q\E-a]/'];
        yield 'posix class' => ['pattern' => '/[[:alpha:]\E-z]/'];
    }
}
