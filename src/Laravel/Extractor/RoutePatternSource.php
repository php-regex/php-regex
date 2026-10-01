<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Laravel\Extractor;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use PhpRegex\Linter\PatternOccurrence;
use PhpRegex\Linter\Source\PatternSourceContext;
use PhpRegex\Linter\Source\PatternSourceInterface;

/**
 * Extracts regex patterns from Laravel route constraints.
 *
 * @internal
 */
final readonly class RoutePatternSource implements PatternSourceInterface
{
    public function __construct(private Router $router) {}

    public function getName(): string
    {
        return 'routes';
    }

    public function isSupported(): bool
    {
        return true;
    }

    /**
     * @return array<\PhpRegex\Linter\PatternOccurrence>
     */
    public function extract(PatternSourceContext $context): array
    {
        $patterns = [];
        $routes = $this->router->getRoutes();
        $lineCounter = -1;

        /** @var Route $route */
        foreach ($routes as $route) {
            $routeName = $route->getName() ?? $route->uri();
            $wheres = $route->wheres;
            $action = $route->getAction();
            $controller = $this->resolveControllerInfo($action);

            foreach ($wheres as $parameter => $pattern) {
                if (!\is_string($pattern) || '' === $pattern) {
                    continue;
                }

                $normalized = $this->normalizePattern($pattern);
                $location = $this->formatRouteLocation($routeName, $controller);

                $patterns[] = new PatternOccurrence(
                    $normalized,
                    $this->resolveRouteFile($action),
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
     * Normalize a route constraint pattern to a valid regex.
     */
    private function normalizePattern(string $pattern): string
    {
        // If pattern doesn't have delimiters, wrap it
        if (!$this->hasDelimiters($pattern)) {
            return '/'.addcslashes($pattern, '/').'/';
        }

        return $pattern;
    }

    /**
     * Check if a pattern has regex delimiters.
     */
    private function hasDelimiters(string $pattern): bool
    {
        if (\strlen($pattern) < 2) {
            return false;
        }

        $firstChar = $pattern[0];
        $validDelimiters = ['/', '#', '~', '!', '@', '%', '`'];

        if (!\in_array($firstChar, $validDelimiters, true)) {
            return false;
        }

        // Check if it ends with the same delimiter (possibly with flags)
        $lastDelimiterPos = strrpos($pattern, $firstChar);

        return false !== $lastDelimiterPos && $lastDelimiterPos > 0;
    }

    /**
     * Resolve controller information from route action.
     *
     * @param array<string, mixed> $action
     */
    private function resolveControllerInfo(array $action): ?string
    {
        if (isset($action['controller'])) {
            $controller = $action['controller'];
            if (\is_string($controller)) {
                return $controller;
            }
        }

        if (isset($action['uses'])) {
            $uses = $action['uses'];
            if (\is_string($uses)) {
                return $uses;
            }
            if ($uses instanceof \Closure) {
                return 'Closure';
            }
        }

        return null;
    }

    /**
     * Resolve the file where the route is defined.
     *
     * @param array<string, mixed> $action
     */
    private function resolveRouteFile(array $action): string
    {
        // Try to get controller file
        if (isset($action['controller']) && \is_string($action['controller'])) {
            $controller = explode('@', $action['controller'])[0];
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
