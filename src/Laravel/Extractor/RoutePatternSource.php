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

namespace PHPRegex\Laravel\Extractor;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PHPRegex\Laravel\Routing\RouteAction;
use PHPRegex\Laravel\Routing\RouteConstraintNormalizer;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;

/**
 * Extracts regex patterns from Laravel route constraints.
 *
 * @internal
 */
final class RoutePatternSource implements PatternSourceInterface
{
    public function __construct(private readonly Router $router, private readonly RouteConstraintNormalizer $normalizer = new RouteConstraintNormalizer()) {}

    public function getName(): string
    {
        return 'routes';
    }

    public function isSupported(): bool
    {
        return true;
    }

    /**
     * @return array<\PHPRegex\Linter\PatternOccurrence>
     */
    public function extract(PatternSourceContext $context): array
    {
        $patterns = [];
        $routes = $this->router->getRoutes();
        $lineCounter = -1;

        foreach ($routes->getRoutes() as $route) {
            $routeName = $route->getName() ?? $route->uri();
            $wheres = $route->wheres;
            $controller = RouteAction::name($route);

            foreach ($wheres as $parameter => $pattern) {
                if (!\is_string($pattern) || '' === $pattern) {
                    continue;
                }

                $normalized = $this->normalizer->normalize($route, (string) $parameter, $pattern);
                $location = $this->formatRouteLocation($routeName, $controller);

                $patterns[] = new PatternOccurrence(
                    $normalized,
                    $this->resolveRouteFile($route),
                    $lineCounter--,
                    'route:'.$routeName.':'.$parameter,
                    $pattern,
                    $location,
                );
            }
        }

        return $patterns;
    }

    /**
     * Resolve the file where the route is defined.
     */
    private function resolveRouteFile(Route $route): string
    {
        // Try to get controller file
        $action = $route->getAction('controller');
        if (\is_string($action)) {
            $controller = explode('@', $action)[0];
            if (class_exists($controller)) {
                try {
                    $reflection = new \ReflectionClass($controller);
                    $filename = $reflection->getFileName();
                    if (\is_string($filename)) {
                        return $filename;
                    }
                } catch (\ReflectionException) {
                    // Ignore reflection errors
                }
            }
        }

        // Fallback to routes file
        return base_path('routes/web.php');
    }

    /**
     * Format a human-readable route location.
     */
    private function formatRouteLocation(string $routeName, ?string $controller): string
    {
        if (null === $controller || '' === $controller) {
            return \sprintf('Route "%s"', $routeName);
        }

        return \sprintf('Route "%s" (controller: %s)', $routeName, $controller);
    }
}
