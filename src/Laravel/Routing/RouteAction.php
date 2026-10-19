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

namespace PHPRegex\Laravel\Routing;

use Illuminate\Routing\Route;

/**
 * What a route runs, as regex:routes and the lint's route source name it.
 *
 * @internal
 */
final class RouteAction
{
    /**
     * The controller, or the "uses" string, or "Closure"; null for a route
     * with none of them.
     */
    public static function name(Route $route): ?string
    {
        $controller = $route->getAction('controller');
        if (\is_string($controller)) {
            return $controller;
        }

        $uses = $route->getAction('uses');
        if (\is_string($uses)) {
            return $uses;
        }

        return $uses instanceof \Closure ? 'Closure' : null;
    }
}
