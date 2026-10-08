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

namespace PHPRegex\Symfony\Extractor;

use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\Internal\LibraryPcre;
use PHPRegex\Symfony\Routing\RouteRequirementNormalizer;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

/**
 * Extracts regex patterns from Symfony route requirements.
 *
 * @internal
 */
final readonly class RoutePatternSource implements PatternSourceInterface
{
    public function __construct(private RouteRequirementNormalizer $patternNormalizer, private ?RouterInterface $router = null) {}

    public function getName(): string
    {
        return 'routes';
    }

    public function isSupported(): bool
    {
        return null !== $this->router;
    }

    public function extract(PatternSourceContext $context): array
    {
        if (null === $this->router) {
            return [];
        }

        $router = $this->router;
        $collection = $router->getRouteCollection();
        $patterns = [];
        $routeNames = array_keys($collection->all());
        /** @var array<string, bool> $routeNameLookup */
        $routeNameLookup = array_fill_keys($routeNames, true);
        $yamlFiles = $this->collectYamlResources($collection);
        $yamlIndex = $this->buildYamlRouteIndex($yamlFiles, $routeNameLookup);
        $defaultYamlFile = 1 === \count($yamlFiles) ? $yamlFiles[0] : null;
        $lineCounter = -1;
        $hasYamlResources = [] !== $yamlFiles;

        foreach ($collection as $name => $route) {
            $routeLocation = $yamlIndex[$name] ?? null;
            $file = $routeLocation['file'] ?? $defaultYamlFile ?? 'Symfony routes';
            $routeLine = $routeLocation['line'] ?? null;
            $requirementLines = $routeLocation['requirements'] ?? [];
            $location = null === $routeLine ? $this->formatRouteLocation($name, $route, $hasYamlResources) : null;

            foreach ($route->getRequirements() as $parameter => $requirement) {
                if (!\is_scalar($requirement)) {
                    continue;
                }

                $pattern = trim((string) $requirement);
                if ('' === $pattern) {
                    continue;
                }

                $normalized = $this->patternNormalizer->normalize($pattern, (bool) $route->getOption('utf8'));
                $patterns[] = new PatternOccurrence(
                    $normalized,
                    $file,
                    $requirementLines[$parameter] ?? $routeLine ?? $lineCounter--,
                    'route:'.$name.':'.$parameter,
                    $pattern,
                    $location,
                );
            }
        }

        // Process YAML requirements
        foreach ($yamlIndex as $routeName => $yamlRoute) {
            $yamlRequirements = $yamlRoute['requirements'] ?? [];
            $yamlFile = $yamlRoute['file'];
            $routeLine = $yamlRoute['line'];
            $lines = @file($yamlFile, \FILE_IGNORE_NEW_LINES);
            if (false !== $lines) {
                foreach ($yamlRequirements as $parameter => $lineIndex) {
                    $line = $lines[$lineIndex] ?? '';
                    if (!LibraryPcre::match('/^\s*'.preg_quote($parameter, '/').'\s*:\s*(.+)$/', $line, $matches)) {
                        continue;
                    }
                    $value = trim($matches[1], " \t\n\r\0\x0B'\"");
                    $route = $collection->get($routeName);
                    if (null === $route) {
                        continue;
                    }
                    $normalized = $this->patternNormalizer->normalize($value, (bool) $route->getOption('utf8'));
                    $patterns[] = new PatternOccurrence(
                        $normalized,
                        $yamlFile,
                        $routeLine ?? $lineIndex + 1,
                        'route:'.$routeName.':'.$parameter,
                        $value,
                        null === $routeLine ? $this->formatRouteLocation($routeName, $route, $hasYamlResources) : null,
                    );
                }
            }
        }

        return $patterns;
    }

    /**
     * @return array<string>
     */
    private function collectYamlResources(RouteCollection $collection): array
    {
        $files = [];

        foreach ($collection->getResources() as $resource) {
            if (!$resource instanceof FileResource) {
                continue;
            }

            $path = $resource->getResource();
            if ('' === $path) {
                continue;
            }

            $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
            if (!\in_array($extension, ['yml', 'yaml'], true)) {
                continue;
            }

            $files[$path] = true;
        }

        return array_keys($files);
    }

    /**
     * @param array<string>       $yamlFiles
     * @param array<string, bool> $routeNames
     *
     * @return array<string, array{file: string, line: int, requirements: array<string, int>}>
     */
    private function buildYamlRouteIndex(array $yamlFiles, array $routeNames): array
    {
        $index = [];

        foreach ($yamlFiles as $path) {
            $routes = $this->extractYamlRouteMetadata($path, $routeNames);
            foreach ($routes as $name => $line) {
                $index[$name] ??= [
                    'file' => $path,
                    'line' => $line['line'],
                    'requirements' => $line['requirements'],
                ];
            }
        }

        return $index;
    }

    /**
     * @param array<string, bool> $routeNames
     *
     * @return array<string, array{line: int, requirements: array<string, int>}>
     */
    private function extractYamlRouteMetadata(string $path, array $routeNames): array
    {
        $lines = @file($path, \FILE_IGNORE_NEW_LINES);
        if (false === $lines) {
            return [];
        }

        $routes = [];
        $whenIndent = null;
        $whenRouteIndent = null;
        $currentRoute = null;
        $routeIndent = null;
        $requirementsIndent = null;
        $requirementsEntryIndent = null;

        foreach ($lines as $index => $line) {
            $trimmed = ltrim($line);
            if ('' === $trimmed || str_starts_with($trimmed, '#')) {
                continue;
            }

            $indent = \strlen($line) - \strlen($trimmed);
            $key = $this->extractKeyFromLine($line);

            if (null !== $whenIndent && $indent <= $whenIndent) {
                $whenIndent = null;
                $whenRouteIndent = null;
            }

            if (null !== $currentRoute && $indent <= $routeIndent) {
                $currentRoute = null;
                $routeIndent = null;
                $requirementsIndent = null;
                $requirementsEntryIndent = null;
            }

            if (null !== $currentRoute) {
                if (null !== $requirementsIndent && $indent <= $requirementsIndent) {
                    $requirementsIndent = null;
                    $requirementsEntryIndent = null;
                }

                if (null === $requirementsIndent && 'requirements' === $key && $indent > $routeIndent) {
                    $requirementsIndent = $indent;
                    $requirementsEntryIndent = null;

                    continue;
                }

                if (null !== $requirementsIndent && $indent > $requirementsIndent) {
                    $requirementsEntryIndent ??= $indent;

                    if ($indent === $requirementsEntryIndent && null !== $key) {
                        /** @var array<string, int> $requirements */
                        $requirements = $routes[$currentRoute]['requirements'] ?? [];
                        $requirements[$key] = $index;
                        $routes[$currentRoute]['requirements'] = $requirements;
                    }

                    continue;
                }
            }

            if (null !== $key && 0 === $indent && str_starts_with($key, 'when@')) {
                $whenIndent = $indent;
                $whenRouteIndent = null;

                continue;
            }

            if (null === $key || !isset($routeNames[$key])) {
                continue;
            }

            if (null !== $whenIndent) {
                $whenRouteIndent ??= $indent;

                if ($indent !== $whenRouteIndent) {
                    continue;
                }
            } elseif (0 !== $indent) {
                continue;
            }

            $currentRoute = $key;
            $routeIndent = $indent;
            $requirementsIndent = null;
            $requirementsEntryIndent = null;
            $routes[$key] ??= [];
            $routes[$key]['line'] = $index + 1;
            $routes[$key]['requirements'] ??= [];
        }

        // Ensure proper structure for each route
        $result = [];
        foreach ($routes as $routeName => $routeData) {
            $line = \is_int($routeData['line'] ?? null) ? $routeData['line'] : 0;
            $requirements = \is_array($routeData['requirements'] ?? null) ? $routeData['requirements'] : [];

            $result[$routeName] = [
                'line' => $line,
                'requirements' => $requirements,
            ];
        }

        return $result;
    }

    private function extractKeyFromLine(string $line): ?string
    {
        if (!LibraryPcre::match('/^\s*(?:\'([^\']+)\'|"([^"]+)"|([A-Za-z0-9_.-]+))\s*:/', $line, $matches)) {
            return null;
        }

        if ('' !== $matches[1]) {
            return $matches[1];
        }
        if ('' !== $matches[2]) {
            return $matches[2];
        }

        return $matches[3];
    }

    private function formatRouteLocation(string $name, Route $route, bool $hasYamlResources): string
    {
        $controller = $route->getDefault('_controller');
        $sourceLabel = $hasYamlResources ? 'YAML config' : 'routing config';
        if (!\is_string($controller) || '' === $controller) {
            return \sprintf('Route "%s" (%s)', $name, $sourceLabel);
        }

        return \sprintf('Route "%s" (%s, controller: %s)', $name, $sourceLabel, $controller);
    }
}
