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

use PHPRegex\Symfony\DependencyInjection\ValidatorMappingPass;
use PHPRegex\Symfony\Extractor\ValidatorPatternSource;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * The pass hands the validator source the files the validator builder is
 * given, in every form the builder takes them.
 */
final class ValidatorMappingPassTest extends TestCase
{
    #[Test]
    public function test_the_mapping_files_of_the_validator_builder_are_handed_to_the_source(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ValidatorMappingPass::SOURCE, new Definition(ValidatorPatternSource::class));
        $container->setDefinition('validator.builder', (new Definition())
            ->addMethodCall('addYamlMappings', [['/app/config/validator/a.yaml', '/app/config/validator/b.yml']])
            ->addMethodCall('addXmlMappings', [['/app/config/validator/c.xml']])
            ->addMethodCall('addYamlMapping', ['/app/config/validator/a.yaml'])
            ->addMethodCall('addXmlMapping', ['$path' => '/app/%%weird%%/d.xml'])
            ->addMethodCall('enableAttributeMapping'));

        (new ValidatorMappingPass())->process($container);

        $this->assertSame(
            ['/app/config/validator/a.yaml', '/app/config/validator/b.yml', '/app/config/validator/c.xml', '/app/%%weird%%/d.xml'],
            $container->getDefinition(ValidatorMappingPass::SOURCE)->getArgument('$mappingFiles'),
        );
    }

    #[Test]
    public function test_without_a_validator_builder_the_source_has_no_file(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(ValidatorMappingPass::SOURCE, new Definition(ValidatorPatternSource::class));

        (new ValidatorMappingPass())->process($container);

        $this->assertSame([], $container->getDefinition(ValidatorMappingPass::SOURCE)->getArgument('$mappingFiles'));
    }

    #[Test]
    public function test_without_the_source_the_pass_does_nothing(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('validator.builder', (new Definition())->addMethodCall('addYamlMappings', [['/app/a.yaml']]));

        (new ValidatorMappingPass())->process($container);

        $this->assertFalse($container->hasDefinition(ValidatorMappingPass::SOURCE));
    }
}
