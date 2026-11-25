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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Routing;

use Composer\InstalledVersions;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class FrameworkMatcherTest extends TestCase
{
    /**
     * The matcher's answer to a request: a redirect, a match, a path no
     * route matches, or a path a route matches for other methods alone.
     */
    #[Test]
    #[DataProvider('provideAnswers')]
    public function test_the_answer_tells_a_method_mismatch_from_a_missing_path(string $method, string $path, string $answer, ?string $reached): void
    {
        $collection = new RouteCollection();
        $collection->add('foo', (new Route('/foo/'))->setMethods(['GET']));

        $this->assertSame($answer, FrameworkMatcher::answer($collection, $method, $path));
        $this->assertSame($reached, FrameworkMatcher::reached($collection, $method, $path));
    }

    /**
     * @return iterable<string, array{method: string, path: string, answer: string, reached: string|null}>
     */
    public static function provideAnswers(): iterable
    {
        yield 'a redirect' => ['method' => 'GET', 'path' => '/foo', 'answer' => 'redirect', 'reached' => 'foo'];
        yield 'a match' => ['method' => 'GET', 'path' => '/foo/', 'answer' => 'match', 'reached' => 'foo'];
        yield 'a path no route matches' => ['method' => 'GET', 'path' => '/bar', 'answer' => '404', 'reached' => null];
        yield 'a path matched for another method' => ['method' => 'POST', 'path' => '/foo/', 'answer' => '405', 'reached' => null];
    }

    /**
     * symfony/routing looks a route with no variable up for a request with
     * one trailing slash at most since 6.4.51, 7.4.24 and 8.1.12, never on
     * 8.0, and tries the routes with a regex on a request with more: before,
     * it trims every trailing slash off the request first. A request no
     * route takes is still redirected with its slashes trimmed off.
     */
    #[Test]
    public function test_the_matcher_trims_every_trailing_slash_before_symfony_routing_6_4_51_7_4_24_and_8_1_12(): void
    {
        $collection = new RouteCollection();
        $collection->add('foo', new Route('/foo'));
        $collection->add('slashes', new Route('/{p<foo//>}'));
        $trims = !InstalledVersions::satisfies(new VersionParser(), 'symfony/routing', '~6.4.51 || ~7.4.24 || >=8.1.12');

        $this->assertSame($trims, FrameworkMatcher::trimsEveryTrailingSlash());
        $this->assertSame($trims ? 'foo' : 'slashes', FrameworkMatcher::reached($collection, 'GET', '/foo//'));
        $this->assertSame('foo', FrameworkMatcher::reached($collection, 'GET', '/foo/'));
        $this->assertSame('foo', FrameworkMatcher::reached($collection, 'GET', '/foo////'));
        $this->assertSame('redirect', FrameworkMatcher::answer($collection, 'GET', '/foo////'));
    }
}
