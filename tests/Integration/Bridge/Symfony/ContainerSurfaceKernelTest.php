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

use PHPRegex\Symfony\Validator\Constraints\ValidRegexValidator;
use PHPRegex\Tests\Support\FrameworkKernel;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
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
     * The application autowires the Regex service and the validator of the
     * ValidRegex constraint; every other class of the bundle is internal.
     */
    #[Test]
    public function test_only_the_regex_service_and_the_constraint_validator_are_autowirable(): void
    {
        $kernel = new FrameworkKernel($this->makeProject(), $this->makeProject(), debug: true);

        try {
            $tester = new CommandTester((new Application($kernel))->find('debug:autowiring'));
            $tester->execute(['search' => 'PHPRegex', '--all' => true]);
        } finally {
            $kernel->shutdown();
        }

        $types = [];
        foreach (explode("\n", $tester->getDisplay()) as $line) {
            if (str_starts_with($line, ' PHPRegex\\')) {
                // The type alone: the alias it points to is written
                // "→ id" or "- alias:id" depending on the Symfony release.
                $types[] = strtok(trim($line), ' ');
            }
        }

        $this->assertSame([
            ValidRegexValidator::class,
            Regex::class,
        ], $types);
    }

    /**
     * Nothing of the bundle is fetched from the container by its id: the
     * commands are lazy, and the Regex service is injected, or fetched as
     * PHPRegex\Toolkit\Regex.
     */
    #[Test]
    public function test_the_services_are_private_but_the_regex_alias(): void
    {
        $container = $this->boot();

        $this->assertTrue($container->has(Regex::class));
        foreach (['php_regex.regex', 'php_regex.command.lint', 'php_regex.command.compare', 'php_regex.command.routes', 'php_regex.command.security', 'php_regex.command.analyze', 'php_regex.command.explain', 'php_regex.command.transpile'] as $id) {
            $this->assertFalse($container->has($id), $id);
        }
    }

    /**
     * regex:analyze runs the routes analyzer, then the security one.
     */
    #[Test]
    public function test_regex_analyze_is_given_the_routes_and_the_security_analyzers(): void
    {
        $kernel = new FrameworkKernel($this->makeProject(), $this->makeProject());

        try {
            $tester = new CommandTester((new Application($kernel))->find('regex:analyze'));
            $status = $tester->execute(['--only' => ['nope'], '--format' => 'json']);
        } finally {
            $kernel->shutdown();
        }

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('Unknown analyzer ids: nope.', $tester->getDisplay());

        $kernel = new FrameworkKernel($this->makeProject(), $this->makeProject());

        try {
            $tester = new CommandTester((new Application($kernel))->find('regex:analyze'));
            $tester->execute(['--format' => 'json']);
        } finally {
            $kernel->shutdown();
        }

        $sections = JsonContract::asArray(JsonContract::decodeDocument($tester->getDisplay())['sections'] ?? null);
        $this->assertSame(['routes', 'security'], array_values(array_unique(array_map(
            static function (mixed $section): string {
                $id = JsonContract::asArray($section)['id'] ?? null;

                return \is_string($id) ? explode('_', $id)[0] : '';
            },
            $sections,
        ))));
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
