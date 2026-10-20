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

use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * An application kernel over a project directory: its config/packages
 * files loaded as an application's are, YAML and PHP alike, and the
 * SecurityBundle registered unless the test leaves it out.
 */
final class SecurityConfigKernel extends Kernel
{
    public function __construct(
        private readonly string $projectDirectory,
        string $environment = 'test',
        private readonly bool $withSecurityBundle = true,
    ) {
        parent::__construct($environment, false);
    }

    /**
     * @return list<SecurityBundle>
     */
    public function registerBundles(): iterable
    {
        return $this->withSecurityBundle ? [new SecurityBundle()] : [];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load($this->projectDirectory.'/config/{packages}/*.{yaml,php}', 'glob');
        $loader->load($this->projectDirectory.'/config/{services}.{yaml,php}', 'glob');
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return $this->projectDirectory;
    }
}
