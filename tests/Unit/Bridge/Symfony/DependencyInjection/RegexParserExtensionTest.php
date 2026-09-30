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

namespace RegexParser\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPUnit\Framework\TestCase;
use RegexParser\Bridge\Symfony\DependencyInjection\RegexParserExtension;
use RegexParser\Cache\PsrCacheAdapter;
use RegexParser\Lint\Extraction\ExtractorInterface;
use RegexParser\Lint\Extraction\TokenBasedExtractionStrategy;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class DependencyInjectionFunctionOverrides
{
    public static ?bool $classExistsResult = null;

    public static function reset(): void
    {
        self::$classExistsResult = null;
    }
}

final class RegexParserExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyInjectionFunctionOverrides::reset();
    }

    public function test_load_sets_parameters_and_services(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        $extension = new RegexParserExtension();
        $extension->load([[
            'max_pattern_length' => 42,
            'cache' => [
                'directory' => '/tmp/cache',
            ],
            'analysis' => [
                'warning_threshold' => 1,
            ],
            'redos' => [
                'ignored_patterns' => ['foo'],
            ],
        ]], $container);

        $this->assertSame(42, $container->getParameter('regex_parser.max_pattern_length'));
        $cacheConfig = (array) $container->getParameter('regex_parser.cache');
        $this->assertSame('/tmp/cache', $cacheConfig['directory']);
        $this->assertSame(1, $container->getParameter('regex_parser.analysis.warning_threshold'));
        // 2.0 has one ignore list, and no complexity score named after ReDoS.
        $this->assertFalse($container->hasParameter('regex_parser.analysis.redos_threshold'));
        $this->assertSame(['foo'], $container->getParameter('regex_parser.redos.ignored_patterns'));
        $this->assertSame('hopcroft', $container->getParameter('regex_parser.automata.minimization_algorithm'));
        $this->assertSame('subset-indexed', $container->getParameter('regex_parser.automata.determinization_algorithm'));
        $this->assertSame([
            'digits' => true,
            'word' => true,
            'ranges' => true,
            'canonicalize_char_classes' => true,
            'possessive' => false,
            'factorize' => false,
            'min_quantifier_count' => 4,
        ], $container->getParameter('regex_parser.optimizations'));

        $this->assertTrue($container->hasDefinition('regex_parser.regex'));
        $this->assertTrue($container->hasDefinition('regex_parser.extractor'));
        $this->assertTrue($container->hasDefinition('regex_parser.command.lint'));
    }

    public function test_load_sets_custom_extractor_alias(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        $extension = new RegexParserExtension();
        $extension->load([[
            'extractor_service' => 'my_custom_extractor',
        ]], $container);

        $this->assertTrue($container->hasAlias(ExtractorInterface::class));
        $this->assertSame('my_custom_extractor', (string) $container->getAlias(ExtractorInterface::class));
    }

    public function test_build_cache_definition_uses_pool(): void
    {
        $extension = new RegexParserExtension();
        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('buildCacheDefinition');

        /** @var Definition $definition */
        $definition = $method->invoke($extension, [
            'cache' => [
                'pool' => 'cache.pool',
                'directory' => null,
                'prefix' => 'regex_',
            ],
        ]);

        $this->assertSame(PsrCacheAdapter::class, $definition->getClass());
        $argument = $definition->getArgument(0);
        $this->assertInstanceOf(Reference::class, $argument);
        $this->assertSame('cache.pool', $argument->__toString());
    }

    public function test_create_extractor_definition_falls_back_when_php_parser_missing(): void
    {
        DependencyInjectionFunctionOverrides::$classExistsResult = false;

        $extension = new RegexParserExtension();
        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('createExtractorDefinition');

        /** @var Definition $definition */
        $definition = $method->invoke($extension);

        $this->assertSame(TokenBasedExtractionStrategy::class, $definition->getClass());
    }

    public function test_resolve_editor_format_uses_framework_ide(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('framework.ide', 'phpstorm');

        $extension = new RegexParserExtension();
        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('resolveEditorFormat');

        $resolved = $method->invoke($extension, ['ide' => null], $container);

        $this->assertSame('phpstorm', $resolved);
    }
}

namespace RegexParser\Bridge\Symfony\DependencyInjection;

use RegexParser\Tests\Unit\Bridge\Symfony\DependencyInjection\DependencyInjectionFunctionOverrides;

function class_exists(string $class, bool $autoload = true): bool
{
    if (null !== DependencyInjectionFunctionOverrides::$classExistsResult) {
        return DependencyInjectionFunctionOverrides::$classExistsResult;
    }

    return \class_exists($class, $autoload);
}
