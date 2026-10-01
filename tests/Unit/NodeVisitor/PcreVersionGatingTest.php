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

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Parser\ErrorCode;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Features that depend on the PCRE2 release a PHP version bundles.
 *
 * php-src bundles PCRE2 10.40 in PHP 8.2.0, 10.42 in 8.3.0 and 10.44 in
 * 8.4.0 and 8.5.0 (ext/pcre/pcre2lib/pcre2.h at each tag). Variable-length
 * lookbehinds need PCRE2 10.43; before it, only the top-level branches of a
 * lookbehind may differ in length. Every row was checked with pcre2test
 * 10.40, 10.42 and 10.44 built from the release tarballs.
 */
final class PcreVersionGatingTest extends TestCase
{
    #[Test]
    #[DataProvider('provideVariableLengthLookbehinds')]
    public function test_variable_length_lookbehind_is_refused_before_php_8_4(string $pattern): void
    {
        foreach (['8.2', '8.3'] as $phpVersion) {
            $result = Regex::create(['php_version' => $phpVersion])->validate($pattern);

            $this->assertFalse($result->isValid, \sprintf('%s does not compile on the PCRE2 PHP %s bundles.', $pattern, $phpVersion));
            $this->assertSame(ErrorCode::LookbehindVariableLengthNotSupported, $result->errorCode);
        }
    }

    #[Test]
    #[DataProvider('provideVariableLengthLookbehinds')]
    public function test_variable_length_lookbehind_is_accepted_from_php_8_4(string $pattern): void
    {
        foreach (['8.4', '8.5'] as $phpVersion) {
            $result = Regex::create(['php_version' => $phpVersion])->validate($pattern);

            $this->assertTrue($result->isValid, \sprintf('%s compiles on the PCRE2 PHP %s bundles: %s', $pattern, $phpVersion, (string) $result->error));
        }
    }

    #[Test]
    #[DataProvider('provideFixedLengthLookbehinds')]
    public function test_fixed_length_branches_are_accepted_before_php_8_4(string $pattern): void
    {
        $result = Regex::create(['php_version' => '8.2'])->validate($pattern);

        $this->assertTrue($result->isValid, \sprintf('%s compiles on PCRE2 10.40: %s', $pattern, (string) $result->error));
    }

    #[Test]
    public function test_bare_hex_escape_is_accepted_for_every_bundled_pcre2(): void
    {
        // A "\x" without digits is refused from PCRE2 10.45 on (error 178);
        // no PHP release bundles 10.45 yet, so an explicit target accepts it.
        foreach (['8.2', '8.3', '8.4', '8.5'] as $phpVersion) {
            $this->assertTrue(Regex::create(['php_version' => $phpVersion])->validate('/\x/')->isValid, $phpVersion);
        }
    }

    #[Test]
    public function test_bare_hex_escape_follows_the_running_pcre2(): void
    {
        // Without an explicit target, the PCRE2 this PHP runs on decides.
        $runtime = explode(' ', \PCRE_VERSION)[0];
        $refused = version_compare($runtime, '10.45', '>=');

        $this->assertSame(!$refused, Regex::create()->validate('/\x/')->isValid, 'PCRE '.$runtime);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideVariableLengthLookbehinds(): iterable
    {
        yield 'optional atom: /(?<=a?)b/' => ['pattern' => '/(?<=a?)b/'];
        yield 'bounded range: /(?<=a{1,3})b/' => ['pattern' => '/(?<=a{1,3})b/'];
        yield 'nested alternation of unequal lengths: /(?<=(?:a|bc))b/' => ['pattern' => '/(?<=(?:a|bc))b/'];
        yield 'call to a group of unequal branches: /(?<n>a|bc)(?<=(?1))b/' => ['pattern' => '/(?<n>a|bc)(?<=(?1))b/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideFixedLengthLookbehinds(): iterable
    {
        yield 'top-level branches of unequal lengths: /(?<=a|bc)b/' => ['pattern' => '/(?<=a|bc)b/'];
        yield 'fixed repeat: /(?<=a{3})b/' => ['pattern' => '/(?<=a{3})b/'];
        yield 'back reference to a fixed group: /(a)(?<=\\1)b/' => ['pattern' => '/(a)(?<=\\1)b/'];
        yield 'call to a fixed group: /(?<n>a)(?<=(?1))b/' => ['pattern' => '/(?<n>a)(?<=(?1))b/'];
    }
}
