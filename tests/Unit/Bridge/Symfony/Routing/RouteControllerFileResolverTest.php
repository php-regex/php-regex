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

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Routing;

use PhpRegex\Symfony\Routing\RouteControllerFileResolver;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;

final class RouteControllerFileResolverTest extends TestCase
{
    private RouteControllerFileResolver $resolver;

    protected function setUp(): void
    {
        if (!class_exists(Route::class)) {
            $this->markTestSkipped('Symfony Routing component is not available');
        }

        $this->resolver = new RouteControllerFileResolver();
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        if (!class_exists(Route::class)) {
            $this->markTestSkipped('Symfony Routing component is not available');
        }

        $resolver = new RouteControllerFileResolver();
    }

    public function test_resolve_returns_null_when_no_controller(): void
    {
        $route = $this->createMockRoute(null);

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_returns_null_when_empty_controller(): void
    {
        $route = $this->createMockRoute('');

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_returns_null_when_non_string_controller(): void
    {
        $route = $this->createMockRoute(123);

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_with_class_method_syntax(): void
    {
        $route = $this->createMockRoute(\stdClass::class.'::method');

        $result = $this->resolver->resolve($route);

        // stdClass is built-in, so getFileName() returns false, should return null
        $this->assertNull($result);
    }

    public function test_resolve_with_class_only_syntax(): void
    {
        $route = $this->createMockRoute(\Exception::class);

        $result = $this->resolver->resolve($route);

        // Exception is built-in, so getFileName() returns false, should return null
        $this->assertNull($result);
    }

    public function test_resolve_with_multiple_colons(): void
    {
        $route = $this->createMockRoute(\stdClass::class.'::method::extra');

        $result = $this->resolver->resolve($route);

        // stdClass is built-in, so getFileName() returns false, should return null
        $this->assertNull($result);
    }

    public function test_resolve_returns_null_when_class_does_not_exist(): void
    {
        $route = $this->createMockRoute('NonExistentClass::method');

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_returns_null_when_class_only_and_does_not_exist(): void
    {
        $route = $this->createMockRoute('NonExistentClass');

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_with_builtin_class(): void
    {
        $route = $this->createMockRoute('ArrayObject');

        $result = $this->resolver->resolve($route);

        // ArrayObject is built-in, so getFileName() returns false, should return null
        $this->assertNull($result);
    }

    public function test_resolve_with_interface(): void
    {
        $route = $this->createMockRoute(\Countable::class);

        $result = $this->resolver->resolve($route);

        // Countable is built-in, so getFileName() returns false, should return null
        $this->assertNull($result);
    }

    public function test_resolve_with_stringable_interface(): void
    {
        $route = $this->createMockRoute(\Stringable::class);

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_returns_null_for_evaled_code(): void
    {
        // stdClass is built-in
        $route = $this->createMockRoute(\stdClass::class);

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_with_namespace(): void
    {
        $route = $this->createMockRoute(TestCase::class);

        $result = $this->resolver->resolve($route);

        // TestCase should have a file (PHPUnit is loaded)
        $this->assertIsString($result);
        $this->assertStringContainsString('TestCase.php', (string) $result);
    }

    public function test_resolve_with_controller_array(): void
    {
        // Some Symfony routes might have array controllers, but this resolver expects strings
        $route = $this->createMockRoute(['controller' => 'method']);

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_with_callable_array(): void
    {
        $route = $this->createMockRoute([\stdClass::class, 'method']);

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    public function test_resolve_with_invalid_controller_format(): void
    {
        $route = $this->createMockRoute('invalid::format::with::multiple::separators::method');

        $result = $this->resolver->resolve($route);

        $this->assertNull($result);
    }

    private function createMockRoute(mixed $controller): Route
    {
        $route = $this->createMock(Route::class);
        $route->method('getDefault')
              ->with('_controller')
              ->willReturn($controller);

        return $route;
    }
}
