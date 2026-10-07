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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Extractor;

use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Symfony\Extractor\RoutePatternSource;
use PHPRegex\Symfony\Routing\RouteRequirementNormalizer;
use PHPRegex\Tests\Support\SymfonyExtractorFunctionOverrides;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\ResourceInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class RouteRegexPatternSourceTest extends TestCase
{
    private RouteRequirementNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RouteRequirementNormalizer();
    }

    protected function tearDown(): void
    {
        SymfonyExtractorFunctionOverrides::reset();
    }

    public function test_construct(): void
    {
        $this->assertInstanceOf(RoutePatternSource::class, new RoutePatternSource($this->normalizer));
        // Source created successfully
    }

    public function test_construct_with_router(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $this->assertInstanceOf(RoutePatternSource::class, new RoutePatternSource($this->normalizer, $router));
    }

    public function test_get_name(): void
    {
        $source = new RoutePatternSource($this->normalizer);
        $this->assertSame('routes', $source->getName());
    }

    public function test_is_supported_returns_false_when_no_router(): void
    {
        $source = new RoutePatternSource($this->normalizer);
        $this->assertFalse($source->isSupported());
    }

    public function test_is_supported_returns_true_when_router_present(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $source = new RoutePatternSource($this->normalizer, $router);
        $this->assertTrue($source->isSupported());
    }

    public function test_extract_returns_empty_array_when_no_router(): void
    {
        $source = new RoutePatternSource($this->normalizer);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_with_empty_route_collection(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();
        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_with_route_having_requirements(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $route = new Route('/test/{id}');
        $route->setRequirements(['id' => '\d+']);
        $collection->add('test_route', $route);

        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertSame('#^\d+$#', $result[0]->pattern);
        $this->assertSame('Symfony routes', $result[0]->file);
        $this->assertSame('route:test_route:id', $result[0]->source);
        $this->assertSame('\d+', $result[0]->displayPattern);
        $this->assertStringContainsString('Route "test_route"', (string) $result[0]->location);
    }

    public function test_extract_skips_non_scalar_requirements(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $route = new Route('/test/{id}');
        $ref = new \ReflectionProperty(Route::class, 'requirements');
        $ref->setValue($route, ['id' => ['array']]);
        $collection->add('test_route', $route);

        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_with_route_having_controller(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $route = new Route('/test/{id}');
        $route->setRequirements(['id' => '\d+']);
        $route->setDefault('_controller', 'App\\Controller\\TestController::index');
        $collection->add('test_route', $route);

        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertStringContainsString('controller: App\\Controller\\TestController::index', (string) $result[0]->location);
    }

    public function test_extract_with_multiple_requirements(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $route = new Route('/test/{id}/{slug}');
        $route->setRequirements([
            'id' => '\d+',
            'slug' => '[a-z-]+',
        ]);
        $collection->add('test_route', $route);

        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(2, $result);
        $patterns = array_map(static fn ($occurrence) => $occurrence->pattern, $result);
        $this->assertContains('#^\d+$#', $patterns);
        $this->assertContains('#^[a-z-]+$#', $patterns);
    }

    public function test_extract_ignores_empty_requirements(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $route = new Route('/test/{id}/{slug}');
        $route->setRequirements([
            'id' => '\d+',
            'slug' => '   ', // Whitespace-only requirement (will be trimmed to empty)
        ]);
        $collection->add('test_route', $route);

        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertSame('#^\d+$#', $result[0]->pattern);
    }

    public function test_extract_with_yaml_resources(): void
    {
        $tempYaml = tempnam(sys_get_temp_dir(), 'routes');
        copy(__DIR__.'/../../../../Fixtures/Symfony/test_content.yaml', $tempYaml);

        try {
            $router = $this->createStub(RouterInterface::class);
            $collection = new RouteCollection();

            $route = new Route('/test/{id}');
            $route->setRequirements(['id' => '\d+']);
            $collection->add('test_route', $route);

            // Add a YAML resource
            $yamlResource = new FileResource($tempYaml);
            $collection->addResource($yamlResource);

            $router->method('getRouteCollection')->willReturn($collection);

            $source = new RoutePatternSource($this->normalizer, $router);
            $context = new PatternSourceContext(['.'], []);

            $result = $source->extract($context);

            $this->assertCount(1, $result);
            // Should extract the pattern
            $this->assertSame('#^\d+$#', $result[0]->pattern);
        } finally {
            unlink($tempYaml);
        }
    }

    public function test_extract_with_multiple_yaml_files(): void
    {
        $tempYaml1 = tempnam(sys_get_temp_dir(), 'routes1');
        $tempYaml2 = tempnam(sys_get_temp_dir(), 'routes2');
        copy(__DIR__.'/../../../../Fixtures/Symfony/test_content1.yaml', $tempYaml1);
        copy(__DIR__.'/../../../../Fixtures/Symfony/test_content2.yaml', $tempYaml2);

        try {
            $router = $this->createStub(RouterInterface::class);
            $collection = new RouteCollection();

            $route = new Route('/test/{id}');
            $route->setRequirements(['id' => '\d+']);
            $collection->add('test_route', $route);

            // Add multiple YAML resources
            $collection->addResource(new FileResource($tempYaml1));
            $collection->addResource(new FileResource($tempYaml2));

            $router->method('getRouteCollection')->willReturn($collection);

            $source = new RoutePatternSource($this->normalizer, $router);
            $context = new PatternSourceContext(['.'], []);

            $result = $source->extract($context);

            $this->assertCount(1, $result);
            $this->assertSame('Symfony routes', $result[0]->file); // Multiple YAML files fall back to default
        } finally {
            unlink($tempYaml1);
            unlink($tempYaml2);
        }
    }

    public function test_extract_ignores_non_yaml_resources(): void
    {
        $tempPhp = tempnam(sys_get_temp_dir(), 'routes');
        $tempXml = tempnam(sys_get_temp_dir(), 'routes');
        copy(__DIR__.'/../../../../Fixtures/Symfony/test.php', $tempPhp);
        copy(__DIR__.'/../../../../Fixtures/Symfony/routes.xml', $tempXml);

        try {
            $router = $this->createStub(RouterInterface::class);
            $collection = new RouteCollection();

            $route = new Route('/test/{id}');
            $route->setRequirements(['id' => '\d+']);
            $collection->add('test_route', $route);

            // Add non-YAML resources
            $collection->addResource(new FileResource($tempPhp));
            $collection->addResource(new FileResource($tempXml));

            $router->method('getRouteCollection')->willReturn($collection);

            $source = new RoutePatternSource($this->normalizer, $router);
            $context = new PatternSourceContext(['.'], []);

            $result = $source->extract($context);

            $this->assertCount(1, $result);
            $this->assertSame('Symfony routes', $result[0]->file);
        } finally {
            unlink($tempPhp);
            unlink($tempXml);
        }
    }

    public function test_extract_with_already_delimited_patterns(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $route = new Route('/test/{id}');
        $route->setRequirements(['id' => '/\d+/']); // Already delimited
        $collection->add('test_route', $route);

        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertSame('/\d+/', $result[0]->pattern); // Should remain unchanged
    }

    public function test_extract_with_anchor_patterns(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $route = new Route('/test/{slug}');
        $route->setRequirements(['slug' => '^[\w-]+$']); // Starts and ends with anchors
        $collection->add('test_route', $route);

        $router->method('getRouteCollection')->willReturn($collection);

        $source = new RoutePatternSource($this->normalizer, $router);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertSame('#^[\w-]+$#', $result[0]->pattern); // Should be wrapped with #
    }

    public function test_extract_with_yaml_route_definitions(): void
    {
        $tempYaml = tempnam(sys_get_temp_dir(), 'routes').'.yaml';
        copy(__DIR__.'/../../../../Fixtures/Symfony/yaml_content.yaml', $tempYaml);

        try {
            $router = $this->createStub(RouterInterface::class);
            $collection = new RouteCollection();

            // Add a route with the same name as in YAML
            $route = new Route('/test/{id}/{slug}');
            $collection->add('test_route', $route);

            // Add the YAML resource
            $yamlResource = new FileResource($tempYaml);
            $collection->addResource($yamlResource);

            $router->method('getRouteCollection')->willReturn($collection);

            $source = new RoutePatternSource($this->normalizer, $router);
            $context = new PatternSourceContext(['.'], []);

            $result = $source->extract($context);

            // Should extract patterns from YAML
            $this->assertGreaterThanOrEqual(2, \count($result));
            $patterns = array_map(static fn ($occurrence) => $occurrence->pattern, $result);
            $this->assertContains('#^\d+$#', $patterns);
            $this->assertContains('#^[a-z-]+$#', $patterns);
        } finally {
            unlink($tempYaml);
        }
    }

    public function test_collect_yaml_resources_skips_non_file_resource_and_empty_path(): void
    {
        $collection = new RouteCollection();
        $collection->addResource(new class implements \Stringable, ResourceInterface {
            public function __toString(): string
            {
                return 'dummy';
            }
        });
        $tempYaml = tempnam(sys_get_temp_dir(), 'routes').'.yaml';
        copy(__DIR__.'/../../../../Fixtures/Symfony/routes_only.yaml', $tempYaml);

        try {
            $resource = new FileResource($tempYaml);
            $ref = new \ReflectionProperty(FileResource::class, 'resource');
            $ref->setValue($resource, '');
            $collection->addResource($resource);
        } finally {
            unlink($tempYaml);
        }

        $source = new RoutePatternSource($this->normalizer);
        $method = new \ReflectionMethod(RoutePatternSource::class, 'collectYamlResources');

        $this->assertSame([], $method->invoke($source, $collection));
    }

    public function test_extract_yaml_metadata_returns_empty_when_file_missing(): void
    {
        SymfonyExtractorFunctionOverrides::queueFileResult(false);

        $source = new RoutePatternSource($this->normalizer);
        $method = new \ReflectionMethod(RoutePatternSource::class, 'extractYamlRouteMetadata');

        $result = $method->invoke($source, 'missing.yaml', ['test_route' => true]);

        $this->assertSame([], $result);
    }

    public function test_extract_yaml_metadata_handles_when_blocks_and_requirements(): void
    {
        $tempYaml = tempnam(sys_get_temp_dir(), 'routes').'.yaml';
        copy(__DIR__.'/../../../../Fixtures/Symfony/foo_bar_routes.yaml', $tempYaml);

        try {
            $source = new RoutePatternSource($this->normalizer);
            $method = new \ReflectionMethod(RoutePatternSource::class, 'extractYamlRouteMetadata');

            $result = $method->invoke($source, $tempYaml, ['foo' => true, 'bar' => true]);

            $this->assertIsArray($result);
            $this->assertArrayHasKey('foo', $result);
            $this->assertArrayHasKey('bar', $result);
        } finally {
            unlink($tempYaml);
        }
    }

    public function test_extract_key_from_line_supports_quoted_keys(): void
    {
        $source = new RoutePatternSource($this->normalizer);
        $method = new \ReflectionMethod(RoutePatternSource::class, 'extractKeyFromLine');

        $this->assertSame('single', $method->invoke($source, "  'single': value"));
        $this->assertSame('double', $method->invoke($source, '  "double": value'));
    }

    public function test_extract_skips_yaml_requirement_when_line_mismatch(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $collection = new RouteCollection();

        $tempYaml = tempnam(sys_get_temp_dir(), 'routes').'.yaml';
        copy(__DIR__.'/../../../../Fixtures/Symfony/test_route_requirements.yaml', $tempYaml);

        try {
            $route = new Route('/test/{id}');
            $collection->add('test_route', $route);
            $collection->addResource(new FileResource($tempYaml));

            $router->method('getRouteCollection')->willReturn($collection);

            SymfonyExtractorFunctionOverrides::queueFileResult([
                'test_route:',
                '  requirements:',
                "    id: '\\d+'",
            ]);
            SymfonyExtractorFunctionOverrides::queueFileResult([
                'test_route:',
                '  requirements:',
                "    other: 'x'",
            ]);

            $source = new RoutePatternSource($this->normalizer, $router);
            $context = new PatternSourceContext(['.'], []);

            $result = $source->extract($context);

            $this->assertSame([], $result);
        } finally {
            unlink($tempYaml);
        }
    }

    public function test_extract_skips_yaml_requirements_when_route_missing(): void
    {
        $tempYaml = tempnam(sys_get_temp_dir(), 'routes').'.yaml';
        copy(__DIR__.'/../../../../Fixtures/Symfony/inline_requirements.yaml', $tempYaml);

        try {
            $router = $this->createStub(RouterInterface::class);
            $collection = new NullGetRouteCollection();

            $route = new Route('/test/{id}');
            $collection->add('test_route', $route);
            $collection->addResource(new FileResource($tempYaml));

            $router->method('getRouteCollection')->willReturn($collection);

            $source = new RoutePatternSource($this->normalizer, $router);
            $context = new PatternSourceContext(['.'], []);

            $result = $source->extract($context);

            $this->assertSame([], $result);
        } finally {
            unlink($tempYaml);
        }
    }

    public function test_extract_with_yaml_complex_route_definitions(): void
    {
        $tempYaml = tempnam(sys_get_temp_dir(), 'routes').'.yaml';
        copy(__DIR__.'/../../../../Fixtures/Symfony/complex_yaml.yaml', $tempYaml);

        try {
            $router = $this->createStub(RouterInterface::class);
            $collection = new RouteCollection();

            // Add a route with the same name as in YAML
            $route = new Route('/test/{slug}');
            $collection->add('test_route', $route);

            // Add the YAML resource
            $yamlResource = new FileResource($tempYaml);
            $collection->addResource($yamlResource);

            $router->method('getRouteCollection')->willReturn($collection);

            $source = new RoutePatternSource($this->normalizer, $router);
            $context = new PatternSourceContext(['.'], []);

            $result = $source->extract($context);

            // Should extract patterns from YAML, handling when@ conditions
            $this->assertGreaterThanOrEqual(1, \count($result));
            $patterns = array_map(static fn ($occurrence) => $occurrence->pattern, $result);
            $this->assertContains('#^[a-z0-9_-]+$#', $patterns);
        } finally {
            unlink($tempYaml);
        }
    }

    public function test_extract_skips_when_symfony_not_available(): void
    {
        if (class_exists(Route::class)) {
            $this->markTestSkipped('Symfony Routing is available, skipping test');
        }

        // This test will only run when Symfony is not available
        $source = new RoutePatternSource($this->normalizer);
        $this->assertFalse($source->isSupported());
        $this->assertSame('routes', $source->getName());
    }
}

final class NullGetRouteCollection extends RouteCollection
{
    public function get(string $name): ?Route
    {
        return null;
    }
}
