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

namespace PHPRegex\Tests\Unit\Bridge\Laravel\Routing;

use Illuminate\Routing\Route;
use PHPRegex\Laravel\Routing\RouteAction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a route runs, as regex:routes and the lint's route source name it.
 */
final class RouteActionTest extends TestCase
{
    #[Test]
    public function test_a_controller_route_names_its_controller(): void
    {
        $this->assertSame('App\Http\Controllers\UserController@show', RouteAction::name(new Route(['GET'], '/users/{id}', ['controller' => 'App\Http\Controllers\UserController@show', 'uses' => 'App\Http\Controllers\UserController@show'])));
    }

    #[Test]
    public function test_a_uses_string_names_the_action(): void
    {
        $this->assertSame('App\Http\Controllers\HomeController@index', RouteAction::name(new Route(['GET'], '/', ['uses' => 'App\Http\Controllers\HomeController@index'])));
    }

    #[Test]
    public function test_a_closure_route_is_named_closure(): void
    {
        $this->assertSame('Closure', RouteAction::name(new Route(['GET'], '/', static fn (): string => 'home')));
    }

    #[Test]
    public function test_a_route_with_no_action_has_no_name(): void
    {
        $this->assertNull(RouteAction::name(new Route(['GET'], '/', [])));
    }
}
