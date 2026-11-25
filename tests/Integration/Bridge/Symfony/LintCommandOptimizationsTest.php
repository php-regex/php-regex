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
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * php_regex.optimizations is nested as regex.json's checks.optimizations:
 * "enabled" switches the suggestions, "min_savings" is the saving below
 * which none is reported unless --min-savings says otherwise, and
 * "options" says what the optimizer may rewrite.
 */
final class LintCommandOptimizationsTest extends TestCase
{
    use TemporaryProject;

    // [0-9]+ becomes \d+: a saving of 3 characters.
    private const DIGITS_FILE = "<?php\n\npreg_match('/[0-9]+/', \$subject);\n";

    /**
     * @return iterable<string, array{optimizations: array<string, mixed>, input: array<string, string>, expected: int}>
     */
    public static function provideOptimizationSettings(): iterable
    {
        yield 'on by default' => ['optimizations' => [], 'input' => [], 'expected' => 1];
        yield 'switched off' => ['optimizations' => ['enabled' => false], 'input' => [], 'expected' => 0];
        yield 'switched off, a saving asked on the command line' => ['optimizations' => ['enabled' => false], 'input' => ['--min-savings' => '1'], 'expected' => 0];
        yield 'a saving the rewrite reaches' => ['optimizations' => ['min_savings' => 3], 'input' => [], 'expected' => 1];
        yield 'a saving the rewrite misses' => ['optimizations' => ['min_savings' => 4], 'input' => [], 'expected' => 0];
        yield 'the command line wins over the saving' => ['optimizations' => ['min_savings' => 4], 'input' => ['--min-savings' => '3'], 'expected' => 1];
        yield 'an option the rewrite needs, off' => ['optimizations' => ['options' => ['digits' => false]], 'input' => [], 'expected' => 0];
    }

    /**
     * @param array<string, mixed>  $optimizations
     * @param array<string, string> $input
     */
    #[Test]
    #[DataProvider('provideOptimizationSettings')]
    public function test_the_optimization_settings_reach_the_run(array $optimizations, array $input, int $expected): void
    {
        $this->assertSame($expected, $this->optimizationCount($optimizations, $input));
    }

    #[Test]
    public function test_the_configured_saving_is_the_option_default(): void
    {
        $command = $this->command(['min_savings' => 7]);

        $this->assertSame(7, $command->getDefinition()->getOption('min-savings')->getDefault());
    }

    /**
     * @param array<string, mixed>  $optimizations
     * @param array<string, string> $input
     */
    private function optimizationCount(array $optimizations, array $input): int
    {
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app"}', 'src/Pattern.php' => self::DIGITS_FILE]);

        $tester = new CommandTester($this->command($optimizations));
        $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'] + $input,
            ['capture_stderr_separately' => true],
        );

        $payload = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($payload, 'stdout is not JSON: '.$tester->getDisplay());
        $this->assertIsArray($payload['stats'] ?? null);
        $this->assertIsInt($payload['stats']['optimizations'] ?? null);

        return $payload['stats']['optimizations'];
    }

    /**
     * @param array<string, mixed> $optimizations
     */
    private function command(array $optimizations): LintCommand
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);
        $container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $extension = BundleContainer::register($container);
        $container->loadFromExtension($extension->getAlias(), ['cache' => ['directory' => null], 'optimizations' => $optimizations]);
        $container->compile();

        $command = $container->get('php_regex.command.lint');
        $this->assertInstanceOf(LintCommand::class, $command);

        return $command;
    }
}
