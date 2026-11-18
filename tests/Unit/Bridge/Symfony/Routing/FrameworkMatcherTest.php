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
}
