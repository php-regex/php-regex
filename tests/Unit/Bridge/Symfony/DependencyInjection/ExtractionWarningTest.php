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

use PHPRegex\Symfony\DependencyInjection\PHPRegexExtension;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Where the warnings of php_regex.extraction.interop go: stderr outside the
 * console format, nowhere when a custom extractor service reads its own
 * calls. A name is printed as written, a "%" included.
 */
final class ExtractionWarningTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_other_formats_say_the_warning_on_stderr(): void
    {
        $project = $this->makeProject(['src/Check.php' => "<?php\n\npreg_match('/fine/', \$s);\n"]);
        $tester = new CommandTester($this->command(['extraction' => ['interop' => ['laravel-str']]], $project));

        $status = $tester->execute(
            ['paths' => [$project.'/src'], '--no-routes' => true, '--no-validators' => true, '--jobs' => '1', '--format' => 'json'],
            ['capture_stderr_separately' => true],
        );

        $this->assertSame(0, $status, $tester->getDisplay().$tester->getErrorOutput());
        $this->assertIsArray(json_decode($tester->getDisplay(), true), 'stdout is not JSON: '.$tester->getDisplay());
        $this->assertStringContainsString('Warning: Unknown extraction preset "laravel-str", ignored.', $tester->getErrorOutput());
    }

    #[Test]
    public function test_a_name_is_printed_as_written(): void
    {
        $container = $this->container(['extraction' => ['interop' => ['50%-off', 'nette-utils', '50%-off']]], $this->makeProject());

        $this->assertSame(['Unknown extraction preset "50%-off", ignored.'], $container->getParameter('php_regex.extraction.warnings'));
    }

    #[Test]
    public function test_an_entry_that_is_no_string_is_named_by_its_type(): void
    {
        $container = $this->container(['extraction' => ['interop' => [false, 'nette-utils', 42, false], 'functions' => [7, 'App\grep#1']]], $this->makeProject());

        $this->assertSame([
            'Extraction interop entry ignored: expected a string, got bool.',
            'Extraction interop entry ignored: expected a string, got int.',
            'Extraction functions entry ignored: expected a string, got int.',
        ], $container->getParameter('php_regex.extraction.warnings'));
    }

    #[Test]
    public function test_a_function_spec_the_lint_cannot_read_is_named(): void
    {
        $container = $this->container(['extraction' => ['functions' => ['x#1$', 'App\grep#1', 'x#a%']]], $this->makeProject());

        $this->assertSame([
            'Malformed pattern function "x#1$", ignored.',
            'Malformed pattern function "x#a%", ignored.',
        ], $container->getParameter('php_regex.extraction.warnings'));
    }

    #[Test]
    public function test_a_custom_extractor_service_says_nothing_about_the_presets(): void
    {
        $container = $this->container(['extractor_service' => 'app.extractor', 'extraction' => ['interop' => ['laravel-str']]], $this->makeProject(), compile: false);

        $this->assertSame([], $container->getParameter('php_regex.extraction.warnings'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function command(array $config, string $project): Command
    {
        $command = $this->container($config, $project)->get('php_regex.command.lint');
        $this->assertInstanceOf(Command::class, $command);

        return $command;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config, string $project, bool $compile = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', $project);
        $container->setParameter('kernel.cache_dir', $project.'/var/cache');
        $container->setParameter('kernel.environment', 'test');

        (new PHPRegexExtension())->load([$config], $container);
        if ($compile) {
            $container->getDefinition('php_regex.command.lint')->setPublic(true);
            $container->compile();
        }

        return $container;
    }
}
