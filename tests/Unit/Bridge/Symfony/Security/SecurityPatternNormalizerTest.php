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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcher\HostRequestMatcher;
use Symfony\Component\HttpFoundation\RequestMatcher\PathRequestMatcher;

/**
 * Symfony matches a path with preg_match('{'.$path.'}s', ...) and a host
 * with preg_match('{'.$host.'}i', ...) (PathRequestMatcher,
 * HostRequestMatcher): the normalized pattern is that pattern, so a path
 * or a host is a fragment, never a delimited pattern, taken as written.
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
        yield 'path opening with a space' => ['pattern' => ' ^/admin', 'host' => false, 'expected' => '{ ^/admin}s'];
        yield 'path ending on an escaped line feed' => ['pattern' => "^/a\\\n", 'host' => false, 'expected' => "{^/a\\\n}s"];
        yield 'host ending on a tab' => ['pattern' => "^example\\.com\t", 'host' => true, 'expected' => "{^example\\.com\t}i"];
    }

    /**
     * PathRequestMatcher and HostRequestMatcher compile the path or the
     * host as written: a space or a tab at either end is part of the
     * pattern, and a backslash before a line feed that ends it escapes
     * that line feed.
     */
    #[Test]
    #[DataProvider('provideUntrimmedPatterns')]
    public function test_normalize_keeps_the_spaces_symfony_compiles(string $pattern, bool $host, string $url, bool $matches): void
    {
        $request = Request::create($url);
        $this->assertSame($matches, ($host ? new HostRequestMatcher($pattern) : new PathRequestMatcher($pattern))->matches($request));

        $subject = $host ? $request->getHost() : rawurldecode($request->getPathInfo());
        $this->assertSame($matches ? 1 : 0, preg_match((new SecurityPatternNormalizer())->normalize($pattern, $host), $subject));
    }

    /**
     * @return iterable<string, array{pattern: string, host: bool, url: string, matches: bool}>
     */
    public static function provideUntrimmedPatterns(): iterable
    {
        yield 'path ending on an escaped line feed' => ['pattern' => "^/a\\\n", 'host' => false, 'url' => 'http://localhost/a%0A', 'matches' => true];
        yield 'path opening with a space' => ['pattern' => ' ^/admin', 'host' => false, 'url' => 'http://localhost/admin', 'matches' => false];
        yield 'path ending on a space after its end' => ['pattern' => '^/admin$ ', 'host' => false, 'url' => 'http://localhost/admin', 'matches' => false];
        yield 'path ending on a space the request path holds' => ['pattern' => '^/admin ', 'host' => false, 'url' => 'http://localhost/admin%20', 'matches' => true];
        yield 'path ending on a tab' => ['pattern' => "^/admin\t", 'host' => false, 'url' => 'http://localhost/admin', 'matches' => false];
        yield 'path of spaces only' => ['pattern' => '  ', 'host' => false, 'url' => 'http://localhost/admin', 'matches' => false];
        yield 'host opening with a space' => ['pattern' => ' admin\.example\.com', 'host' => true, 'url' => 'http://admin.example.com/', 'matches' => false];
        yield 'host ending on a tab after its end' => ['pattern' => "^admin\\.example\\.com$\t", 'host' => true, 'url' => 'http://admin.example.com/', 'matches' => false];
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
