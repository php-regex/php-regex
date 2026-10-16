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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use PHPRegex\Laravel\Extractor\RoutePatternSource;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * What a route runs, as regex:routes and the lint name it: the controller,
 * else the "uses" string, else a closure; a route that names none is
 * unknown, and the lint locates it in the routes file.
 */
final class RouteActionTest extends LaravelTestCase
{
    /**
     * @param array<string, mixed> $action
     */
    #[Test]
    #[DataProvider('provideActions')]
    public function test_the_lint_names_what_the_route_runs(array $action, ?string $named, string $file): void
    {
        $this->constrainedRoute($action);
        $router = $this->application()->make('router');
        $this->assertInstanceOf(Router::class, $router);

        $occurrences = (new RoutePatternSource($router))->extract(new PatternSourceContext([base_path('app')], []));

        $this->assertCount(1, $occurrences);
        $this->assertInstanceOf(PatternOccurrence::class, $occurrences[0]);
        $this->assertSame(null === $named ? 'Route "action"' : \sprintf('Route "action" (controller: %s)', $named), $occurrences[0]->location);
        $this->assertSame('' === $file ? base_path('routes/web.php') : $file, $occurrences[0]->file);
    }

    /**
     * @param array<string, mixed> $action
     */
    #[Test]
    #[DataProvider('provideCommandActions')]
    public function test_the_routes_command_names_what_the_route_runs(array $action, ?string $named): void
    {
        $this->constrainedRoute($action);

        $this->assertSame(0, Artisan::call('regex:routes', ['--format' => 'json']));
        $document = json_decode(Artisan::output(), true);
        $this->assertIsArray($document);
        $this->assertIsArray($document['routes'] ?? null);
        $this->assertIsArray($document['routes'][0] ?? null);

        $this->assertSame($named ?? 'Unknown', $document['routes'][0]['action'] ?? null);
    }

    /**
     * @return iterable<string, array{action: array<string, mixed>, named: string|null, file: string}>
     */
    public static function provideActions(): iterable
    {
        $controller = self::class.'@show';

        yield 'a controller' => ['action' => ['uses' => $controller, 'controller' => $controller], 'named' => $controller, 'file' => __FILE__];
        yield 'a "uses" string alone' => ['action' => ['uses' => $controller], 'named' => $controller, 'file' => ''];
        yield 'a closure' => ['action' => ['uses' => static fn (): string => ''], 'named' => 'Closure', 'file' => ''];
        yield 'nothing' => ['action' => [], 'named' => null, 'file' => ''];
    }

    /**
     * @return iterable<string, array{action: array<string, mixed>, named: string|null}>
     */
    public static function provideCommandActions(): iterable
    {
        foreach (self::provideActions() as $name => $row) {
            yield $name => ['action' => $row['action'], 'named' => $row['named']];
        }
    }

    public function show(): string
    {
        return '';
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @param array<string, mixed> $action
     */
    private function constrainedRoute(array $action): void
    {
        $route = Route::get('/action/{id}', static fn (): string => '')->where('id', '[0-9]+')->name('action');
        $this->assertInstanceOf(RoutingRoute::class, $route);
        $route->setAction(['as' => 'action'] + $action);
    }
}
