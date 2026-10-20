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

namespace PHPRegex\Symfony;

use PHPRegex\Symfony\DependencyInjection\ValidatorMappingPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony Bundle for the PHPRegex library.
 */
final class PHPRegexBundle extends Bundle
{
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        // After the passes of other bundles, which may give the validator
        // mapping files of their own.
        $container->addCompilerPass(new ValidatorMappingPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -1024);
    }

    /**
     * @return string the absolute path to the bundle's root directory
     */
    #[\Override]
    public function getPath(): string
    {
        return __DIR__;
    }
}
