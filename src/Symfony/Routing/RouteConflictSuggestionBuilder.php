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

use PHPRegex\Parser\Engine\PcreEngine;

/**
 * @internal
 *
 * @phpstan-import-type RouteDescriptor from RouteConflictReport
 * @phpstan-import-type RouteConflict from RouteConflictReport
 */
final readonly class RouteConflictSuggestionBuilder
{
    /**
     * @param PcreEngine $engine Runs a route's pattern on the example that conflicts
     */
    public function __construct(private PcreEngine $engine = new PcreEngine()) {}

    /**
     * @phpstan-param array<RouteConflict> $conflicts
     *
     * @return array<int, string>
     */
    public function collect(array $conflicts): array
    {
        $suggestions = [];

        foreach ($conflicts as $conflict) {
            $route = $conflict['route'];
            $other = $conflict['conflict'];

            $moveSuggestion = \sprintf(
                'Reorder routes: move "%s" before "%s".',
                $other['name'],
                $route['name'],
            );
            $suggestions[$moveSuggestion] = true;

            if (($conflict['equivalent'] ?? false) === true) {
                $suggestions[\sprintf(
                    'Merge duplicate routes: "%s" and "%s" match the same paths.',
                    $route['name'],
                    $other['name'],
                )] = true;
            }

            foreach ($this->suggestRequirementFixes($route, $other, $conflict['example']) as $suggestion) {
                $suggestions[$suggestion] = true;
            }
        }

        return array_keys($suggestions);
    }

    /**
     * @phpstan-param RouteDescriptor $route
     * @phpstan-param RouteDescriptor $other
     *
     * @return array<int, string>
     */
    private function suggestRequirementFixes(array $route, array $other, ?string $example): array
    {
        if (null === $example || '' === $example) {
            return [];
        }

        $variables = $this->extractRouteVariables($route['pathPattern'], $example);
        if ([] === $variables || [] === $other['staticSegments']) {
            return [];
        }

        $suggestions = [];
        $staticLookup = array_fill_keys($other['staticSegments'], true);

        foreach ($variables as $name => $value) {
            if ('' === $value || !isset($staticLookup[$value])) {
                continue;
            }

            $patternExample = $this->suggestRequirementPattern($name);
            if (null !== $patternExample) {
                $suggestions[] = \sprintf(
                    'Add a requirement for {%s} in "%s" (e.g. "%s").',
                    $name,
                    $route['name'],
                    $patternExample,
                );

                continue;
            }

            $suggestions[] = \sprintf(
                'Add a requirement for {%s} in "%s" to avoid matching "%s".',
                $name,
                $route['name'],
                $value,
            );
        }

        return $suggestions;
    }

    /**
     * @return array<string, string>
     */
    private function extractRouteVariables(string $pattern, string $example): array
    {
        $match = $this->engine->match($pattern, $example);
        if (true !== $match->matched) {
            return [];
        }

        $variables = [];
        foreach ($match->groups as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }
            $variables[$key] = $value;
        }

        return $variables;
    }

    private function suggestRequirementPattern(string $variable): ?string
    {
        $normalized = strtolower($variable);

        if ('uuid' === $normalized || str_contains($normalized, 'uuid')) {
            return '[0-9a-fA-F-]{36}';
        }

        if ('id' === $normalized || str_ends_with($normalized, 'id')) {
            return '\d+';
        }

        if (str_contains($normalized, 'slug')) {
            return '[a-z0-9-]+';
        }

        if (str_contains($normalized, 'locale')) {
            return '[a-z]{2}';
        }

        return null;
    }
}
