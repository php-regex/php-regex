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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Tests\Support\FrameworkKernel;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * What the bundle leaves in an application's container: its configuration
 * goes into its own services, not into container parameters the
 * application could read or override.
 */
final class ContainerSurfaceKernelTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_the_bundle_sets_no_container_parameter(): void
    {
        $container = $this->boot();
        $this->assertInstanceOf(Container::class, $container);

        $parameters = array_map(strval(...), array_keys($container->getParameterBag()->all()));

        $this->assertSame([], array_values(array_filter($parameters, static fn (string $name): bool => str_starts_with($name, 'php_regex.'))));
    }

    #[Test]
    public function test_the_configuration_reaches_the_services(): void
    {
        $long = '/'.str_repeat('a', 2000).'/';

        $configured = $this->boot(['max_pattern_length' => 1234])->get(Regex::class);
        $default = $this->boot()->get(Regex::class);

        $this->assertInstanceOf(Regex::class, $configured);
        $this->assertInstanceOf(Regex::class, $default);
        $this->assertFalse($configured->validate($long)->isValid);
        $this->assertTrue($default->validate($long)->isValid);
    }

    /**
     * @param array<string, mixed> $phpRegex
     */
    private function boot(array $phpRegex = []): ContainerInterface
    {
        $kernel = new FrameworkKernel($this->makeProject(), $this->makeProject(), $phpRegex);
        $kernel->boot();

        try {
            return $kernel->getContainer();
        } finally {
            $kernel->shutdown();
        }
    }
}
