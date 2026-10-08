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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Security;

use PHPRegex\Symfony\Security\SecurityPatternNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Symfony matches a path with preg_match('{'.$path.'}s', ...) and a host
 * with preg_match('{'.$host.'}i', ...) (PathRequestMatcher,
 * HostRequestMatcher): the normalized pattern is that pattern, so a path
 * or a host is a fragment, never a delimited pattern.
 */
final class SecurityPatternNormalizerTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_normalize_writes_the_pattern_symfony_matches_with(string $pattern, bool $host, string $expected): void
    {
        $this->assertSame($expected, (new SecurityPatternNormalizer())->normalize($pattern, $host));
    }

    /**
     * @return iterable<string, array{pattern: string, host: bool, expected: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'anchored path' => ['pattern' => '^/admin', 'host' => false, 'expected' => '{^/admin}s'];
        yield 'path starting with a slash' => ['pattern' => '/admin', 'host' => false, 'expected' => '{/admin}s'];
        yield 'path that looks delimited' => ['pattern' => '#^/admin#x', 'host' => false, 'expected' => '{#^/admin#x}s'];
        yield 'path holding a hash' => ['pattern' => '^/a#b', 'host' => false, 'expected' => '{^/a#b}s'];
        yield 'host' => ['pattern' => '^api\.example\.com$', 'host' => true, 'expected' => '{^api\.example\.com$}i'];
        yield 'empty path' => ['pattern' => '', 'host' => false, 'expected' => '{}s'];
    }

    /**
     * Oracle, PHP 8.4.26 / PCRE2 10.49.
     */
    #[Test]
    #[DataProvider('provideSubjects')]
    public function test_normalize_matches_what_symfony_matches(string $pattern, bool $host, string $subject, bool $matches): void
    {
        $this->assertSame($matches ? 1 : 0, preg_match((new SecurityPatternNormalizer())->normalize($pattern, $host), $subject));
    }

    /**
     * @return iterable<string, array{pattern: string, host: bool, subject: string, matches: bool}>
     */
    public static function provideSubjects(): iterable
    {
        // No anchor is added: "/api" matches anywhere in the path.
        yield 'unanchored path' => ['pattern' => '/api', 'host' => false, 'subject' => '/v1/api/users', 'matches' => true];
        yield 'anchored path' => ['pattern' => '^/api', 'host' => false, 'subject' => '/v1/api', 'matches' => false];
        yield 'dot over a newline in a path' => ['pattern' => '^/a.b', 'host' => false, 'subject' => "/a\nb", 'matches' => true];
        yield 'host in another case' => ['pattern' => '^example\.com$', 'host' => true, 'subject' => 'EXAMPLE.com', 'matches' => true];
    }
}
