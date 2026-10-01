<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Bridge\Symfony;

use PhpRegex\Parser\Cache\FilesystemCache;
use PhpRegex\Parser\PcreTarget;
use PhpRegex\Symfony\Command\LintCommand;
use PhpRegex\Symfony\DependencyInjection\PhpRegexExtension;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class RegexParserBundleTest extends TestCase
{
    public function test_regex_service_registered_with_filesystem_cache(): void
    {
        $cacheDir = sys_get_temp_dir().'/php_regex_'.uniqid();
        $container = $this->createContainer([
            'max_pattern_length' => 5000,
            'cache' => [
                'directory' => $cacheDir,
            ],
        ]);
        $container->compile();

        /** @var \PhpRegex\Toolkit\Regex $regex */
        $regex = $container->get('php_regex.regex');
        $regex->parse('/abc/');

        $cache = new FilesystemCache($cacheDir);
        $cacheFile = $cache->generateKey(Regex::cacheSeed('/abc/', PcreTarget::runtime(), 1024));

        $this->assertFileExists($cacheFile);

        $cache->clear();
    }

    public function test_command_is_registered_as_console_service(): void
    {
        $container = $this->createContainer([]);
        $container->compile();

        $this->assertTrue($container->hasDefinition('php_regex.command.lint'));
        $definition = $container->getDefinition('php_regex.command.lint');
        $this->assertSame(LintCommand::class, $definition->getClass());
        $this->assertArrayHasKey('console.command', $definition->getTags());

        /** @var \PhpRegex\Symfony\Command\LintCommand $command */
        $command = $container->get('php_regex.command.lint');
        $this->assertSame('regex:lint', $command->getName());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createContainer(array $config, bool $loadExtension = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());

        $extension = new PhpRegexExtension();
        $container->registerExtension($extension);

        if ($loadExtension) {
            $container->loadFromExtension($extension->getAlias(), $config);
        }

        return $container;
    }
}

final readonly class RouteCollectionRouter implements RouterInterface
{
    public function __construct(private RouteCollection $routes) {}

    public function setContext(RequestContext $context): void {}

    public function getContext(): RequestContext
    {
        return new RequestContext();
    }

    public function getRouteCollection(): RouteCollection
    {
        return $this->routes;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function match(string $pathinfo): array
    {
        return [];
    }
}
