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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Parser\Cache\PsrCacheAdapter;
use PHPRegex\Symfony\Command\ExplainCommand;
use PHPRegex\Symfony\DependencyInjection\PHPRegexExtension;
use PHPRegex\Symfony\Validator\Constraints\ValidRegexValidator;
use PHPRegex\Tests\Support\DependencyInjectionFunctionOverrides;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class PHPRegexExtensionTest extends TestCase
{
    protected function tearDown(): void
    {
        DependencyInjectionFunctionOverrides::reset();
    }

    public function test_load_sets_parameters_and_services(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        $extension = new PHPRegexExtension();
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

        $this->assertSame(42, $container->getParameter('php_regex.max_pattern_length'));
        $cacheConfig = (array) $container->getParameter('php_regex.cache');
        $this->assertSame('/tmp/cache', $cacheConfig['directory']);
        $this->assertSame(1, $container->getParameter('php_regex.analysis.warning_threshold'));
        $this->assertFalse($container->getParameter('php_regex.analysis.complexity'));
        // 2.0 has one ignore list, and no complexity score named after ReDoS.
        $this->assertFalse($container->hasParameter('php_regex.analysis.redos_threshold'));
        $this->assertSame(['foo'], $container->getParameter('php_regex.redos.ignored_patterns'));
        $this->assertSame('hopcroft', $container->getParameter('php_regex.automata.minimization_algorithm'));
        $this->assertSame('subset-indexed', $container->getParameter('php_regex.automata.determinization_algorithm'));
        $this->assertSame([
            'digits' => true,
            'word' => true,
            'ranges' => true,
            'canonicalize_char_classes' => true,
            'possessive' => false,
            'factorize' => false,
            'min_quantifier_count' => 4,
        ], $container->getParameter('php_regex.optimizations'));

        $this->assertTrue($container->hasDefinition('php_regex.regex'));
        $this->assertTrue($container->hasDefinition('php_regex.extractor'));
        $this->assertTrue($container->hasDefinition('php_regex.command.lint'));
    }

    /**
     * regex:lint reads patterns with the bundle's settings, never with its
     * target or its runtime validation: the target is the project's, and
     * only the running PHP can validate at run time.
     */
    public function test_the_lint_command_takes_the_settings_patterns_are_read_with(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        (new PHPRegexExtension())->load([[]], $container);

        $regexOptions = $container->getDefinition('php_regex.command.lint')->getArgument('$regexOptions');
        $this->assertIsArray($regexOptions);
        $this->assertSame(['max_pattern_length', 'max_lookbehind_length', 'cache', 'redos_ignored_patterns'], array_keys($regexOptions));
    }

    /**
     * regex:explain is a console command of the bundle, built with its
     * Regex service, as the Laravel command is.
     */
    public function test_load_registers_the_explain_command(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        (new PHPRegexExtension())->load([[]], $container);

        $definition = $container->getDefinition('php_regex.command.explain');
        $this->assertSame(ExplainCommand::class, $definition->getClass());
        $this->assertTrue($definition->hasTag('console.command'));
        $regex = $definition->getArgument('$regex');
        $this->assertInstanceOf(Reference::class, $regex);
        $this->assertSame('php_regex.regex', (string) $regex);
    }

    /**
     * The ValidRegex constraint is judged with the bundle's Regex service,
     * found by the validator under the validator's class name.
     */
    public function test_load_registers_the_valid_regex_validator(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        (new PHPRegexExtension())->load([[]], $container);

        $definition = $container->getDefinition(ValidRegexValidator::class);
        $this->assertTrue($definition->hasTag('validator.constraint_validator'));
        $regex = $definition->getArgument('$regex');
        $this->assertInstanceOf(Reference::class, $regex);
        $this->assertSame('php_regex.regex', (string) $regex);
    }

    public function test_load_sets_custom_extractor_alias(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);

        $extension = new PHPRegexExtension();
        $extension->load([[
            'extractor_service' => 'my_custom_extractor',
        ]], $container);

        $this->assertTrue($container->hasAlias(ExtractorInterface::class));
        $this->assertSame('my_custom_extractor', (string) $container->getAlias(ExtractorInterface::class));
    }

    public function test_build_cache_definition_uses_pool(): void
    {
        $extension = new PHPRegexExtension();
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

        $extension = new PHPRegexExtension();
        $reflection = new \ReflectionClass($extension);
        $method = $reflection->getMethod('createExtractorDefinition');

        /** @var Definition $definition */
        $definition = $method->invoke($extension, ['interop' => [], 'functions' => []]);

        $this->assertSame(TokenBasedExtractionStrategy::class, $definition->getClass());
    }
}
