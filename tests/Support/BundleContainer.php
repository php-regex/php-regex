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

namespace PHPRegex\Tests\Support;

use PHPRegex\Symfony\PHPRegexSymfonyBundle;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

/**
 * The PHPRegex bundle's container extension and configuration, and a
 * container to load them into, as a kernel builds one: a bundle extension
 * reads the kernel's environment.
 */
final class BundleContainer
{
    public static function builder(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        self::kernelParameters($container);

        return $container;
    }

    /**
     * The bundle's extension registered in the container, the bundle's
     * compiler passes added, as a kernel registers them. The bundle's
     * services, all private, are made public for the test to fetch, as the
     * test container of FrameworkBundle exposes them.
     */
    public static function register(ContainerBuilder $container): ExtensionInterface
    {
        self::kernelParameters($container);

        $bundle = new PHPRegexSymfonyBundle();
        $extension = self::extensionOf($bundle);
        $container->registerExtension($extension);
        $bundle->build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                foreach ($container->getDefinitions() as $id => $definition) {
                    if (str_starts_with($id, 'php_regex.')) {
                        $definition->setPublic(true);
                    }
                }
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, -2048);

        return $extension;
    }

    public static function extension(): ExtensionInterface
    {
        return self::extensionOf(new PHPRegexSymfonyBundle());
    }

    public static function configuration(): ConfigurationInterface
    {
        $extension = self::extension();
        $configuration = $extension instanceof ConfigurationExtensionInterface ? $extension->getConfiguration([], self::builder()) : null;
        if (!$configuration instanceof ConfigurationInterface) {
            throw new \LogicException('The PHPRegex bundle has no configuration.');
        }

        return $configuration;
    }

    /**
     * The kernel's parameters a bundle extension reads: the environment,
     * and, on Symfony 6.4, the build directory.
     */
    private static function kernelParameters(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('kernel.environment')) {
            $container->setParameter('kernel.environment', 'test');
        }

        if (!$container->hasParameter('kernel.build_dir')) {
            $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        }
    }

    private static function extensionOf(PHPRegexSymfonyBundle $bundle): ExtensionInterface
    {
        $extension = $bundle->getContainerExtension();
        if (null === $extension) {
            throw new \LogicException('The PHPRegex bundle has no container extension.');
        }

        return $extension;
    }
}
