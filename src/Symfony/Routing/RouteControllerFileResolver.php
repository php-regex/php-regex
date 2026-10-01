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

namespace PHPRegex\Symfony\Routing;

use Symfony\Component\Routing\Route;

/**
 * Resolves the source file for routes based on their controller definition.
 *
 * @internal
 */
final readonly class RouteControllerFileResolver
{
    public function resolve(Route $route): ?string
    {
        $controller = $route->getDefault('_controller');
        if (!\is_string($controller) || '' === $controller) {
            return null;
        }

        if (str_contains($controller, '::')) {
            [$class] = explode('::', $controller, 2);
        } else {
            $class = $controller;
        }

        if (!class_exists($class)) {
            return null;
        }

        $reflection = new \ReflectionClass($class);
        $fileName = $reflection->getFileName();

        return false !== $fileName ? $fileName : null;
    }
}
