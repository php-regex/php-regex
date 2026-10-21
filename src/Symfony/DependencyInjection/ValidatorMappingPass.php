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

namespace PHPRegex\Symfony\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Hands regex:lint the validator mapping files the validator reads: those
 * FrameworkBundle, and any bundle after it, gives the validator builder
 * through addYamlMappings(), addXmlMappings() and their one-file forms
 * (config/validator/, framework.validation.mapping.paths, each bundle's
 * validation files). Without a validator builder there is none.
 *
 * @internal
 */
final class ValidatorMappingPass implements CompilerPassInterface
{
    /**
     * The service of the validator pattern source.
     */
    public const SOURCE = 'php_regex.pattern_source.validators';

    private const MAPPING_CALLS = ['addYamlMappings', 'addXmlMappings', 'addYamlMapping', 'addXmlMapping'];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::SOURCE)) {
            return;
        }

        $files = [];
        if ($container->hasDefinition('validator.builder')) {
            foreach ($container->getDefinition('validator.builder')->getMethodCalls() as $call) {
                [$method, $arguments] = \is_array($call) ? $call + [null, []] : [null, []];
                if (!\in_array($method, self::MAPPING_CALLS, true) || !\is_array($arguments) || [] === $arguments) {
                    continue;
                }

                // The one argument, given by position or by name.
                $paths = $arguments[array_key_first($arguments)];
                foreach (\is_array($paths) ? $paths : [$paths] as $path) {
                    if (\is_string($path) && '' !== $path) {
                        $files[] = $path;
                    }
                }
            }
        }

        // The paths stay as FrameworkBundle wrote them, a % escaped: the
        // container resolves them with the rest of the arguments.
        $container->getDefinition(self::SOURCE)->setArgument('$mappingFiles', array_values(array_unique($files)));
    }
}
