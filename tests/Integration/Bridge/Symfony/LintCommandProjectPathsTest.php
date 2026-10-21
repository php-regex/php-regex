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

use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Tests\Support\BundleContainer;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The paths and excludes of the bundle's configuration are read from the
 * project directory, whatever directory regex:lint runs from; the paths
 * given on the command line are read from the working directory, as
 * lint:yaml reads them.
 */
final class LintCommandProjectPathsTest extends TestCase
{
    use TemporaryProject;

    private const INVALID = "<?php\n\npreg_match('/[a-z/', \$subject);\n";

    #[Test]
    public function test_configured_paths_are_read_from_the_project_directory(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'lib/Bad.php' => self::INVALID]);
        $this->enterProject();

        [$status, $files] = $this->lint($project, ['paths' => ['lib']], []);

        $this->assertSame(1, $status);
        $this->assertSame([$project.'/lib/Bad.php'], $files);
    }

    #[Test]
    public function test_configured_excludes_are_read_from_the_project_directory(): void
    {
        $project = $this->makeProject([
            'composer.json' => '{"name": "acme/app"}',
            'lib/Bad.php' => self::INVALID,
            'lib/Legacy/Old.php' => self::INVALID,
            'lib/Generated/Proxy.php' => self::INVALID,
        ]);
        $this->enterProject();

        [$status, $files] = $this->lint($project, ['paths' => ['lib'], 'exclude' => ['lib/Legacy', 'Generated']], []);

        $this->assertSame(1, $status);
        $this->assertSame([$project.'/lib/Bad.php'], $files);
    }

    /**
     * An exclude names a directory inside the project: a directory of the
     * same name above it, where the project happens to live, excludes
     * nothing.
     */
    #[Test]
    public function test_an_exclude_named_like_a_directory_above_the_project_excludes_nothing(): void
    {
        $root = $this->makeProject(['vendor/acme/app/composer.json' => '{"name": "acme/app"}', 'vendor/acme/app/lib/Bad.php' => self::INVALID]);
        $project = $root.'/vendor/acme/app';
        $this->enterProject();

        [$status, $files] = $this->lint($project, ['paths' => ['lib'], 'exclude' => ['vendor']], []);

        $this->assertSame(1, $status);
        $this->assertSame([$project.'/lib/Bad.php'], $files);
    }

    #[Test]
    public function test_a_path_given_on_the_command_line_is_read_from_the_working_directory(): void
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'lib/Bad.php' => self::INVALID]);
        $elsewhere = $this->enterProject(['lib/Other.php' => self::INVALID]);

        [$status, $files] = $this->lint($project, ['paths' => ['lib']], ['paths' => ['lib']]);

        $this->assertSame(1, $status);
        $this->assertSame(['lib/Other.php'], $files, 'from '.$elsewhere);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $arguments
     *
     * @return array{int, list<string>} the exit code and the files of the results
     */
    private function lint(string $project, array $config, array $arguments): array
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $container->setParameter('kernel.project_dir', $project);
        $extension = BundleContainer::register($container);
        $container->loadFromExtension($extension->getAlias(), $config);
        $container->compile();

        $command = $container->get('php_regex.command.lint');
        $this->assertInstanceOf(LintCommand::class, $command);

        $tester = new CommandTester($command);
        $status = $tester->execute(
            $arguments + ['--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        $files = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($tester->getDisplay())['results'] ?? null) as $result) {
            $file = JsonContract::asArray($result)['file'] ?? null;
            $this->assertIsString($file);
            $files[] = $file;
        }

        return [$status, $files];
    }
}
