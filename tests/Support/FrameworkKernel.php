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

use PHPRegex\Symfony\PHPRegexBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

/**
 * An application kernel with FrameworkBundle and the PHPRegex bundle, over
 * a project directory: FrameworkBundle configured as a fresh application
 * configures it, with the given configuration on top, and the cache and
 * the logs written to a directory of their own, so that a fixture project
 * stays as it is checked in.
 */
final class FrameworkKernel extends Kernel
{
    private const FRAMEWORK = [
        'secret' => 'test',
        'http_method_override' => false,
        'handle_all_throwables' => true,
        'php_errors' => ['log' => true],
        'router' => ['resource' => __DIR__.'/../Fixtures/Symfony/no_routes.yaml', 'utf8' => true],
        'validation' => ['email_validation_mode' => 'html5'],
    ];

    /**
     * @param array<string, mixed> $phpRegex  the php_regex configuration
     * @param array<string, mixed> $framework merged over the framework configuration above
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly string $varDirectory,
        private readonly array $phpRegex = [],
        private readonly array $framework = [],
        bool $debug = false,
    ) {
        parent::__construct('test', $debug);
    }

    /**
     * @return list<FrameworkBundle|PHPRegexBundle>
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new PHPRegexBundle()];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            /** @var array<string, mixed> $framework */
            $framework = array_replace_recursive(self::FRAMEWORK, $this->framework);
            $container->loadFromExtension('framework', $framework);
            $container->loadFromExtension('php_regex', $this->phpRegex);
        });
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return $this->projectDirectory;
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return $this->varDirectory.'/cache/'.$this->environment;
    }

    #[\Override]
    public function getLogDir(): string
    {
        return $this->varDirectory.'/log';
    }

    /**
     * Without the configuration directory, which a debug kernel of Symfony
     * 7.4 and later writes its config/reference.php into: a fixture project
     * stays as it is checked in.
     *
     * @return array<mixed>
     */
    #[\Override]
    protected function getKernelParameters(): array
    {
        $parameters = parent::getKernelParameters();
        unset($parameters['.kernel.config_dir']);

        return $parameters;
    }
}
