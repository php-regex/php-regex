<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Integration\Bridge\Symfony;

use PhpRegex\Parser\RegexParser;
use PhpRegex\Symfony\DependencyInjection\PhpRegexExtension;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The bundle configuration once the container is compiled: the runtime
 * service keeps the running engine, the lint command judges for the
 * project's target (the bundle's php_version / pcre_version, else the
 * project's composer.json, like the CLI) and never inherits the runtime
 * validation, which only the running engine can do.
 */
final class BundleConfigurationTest extends TestCase
{
    /**
     * Valid from PCRE2 10.43 (variable-length lookbehind, PcreFeature::
     * VariableLengthLookbehind), refused by 10.40, the release PHP 8.2
     * bundles. preg_match() on this PHP (PCRE2 10.49) compiles it.
     */
    private const NEWER_ENGINE_ONLY = '/(?<=a{1,2})x/';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/regex-parser-bundle-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir.'/src', 0o700, true);
        file_put_contents(
            $this->projectDir.'/src/Pattern.php',
            "<?php\n\npreg_match('".self::NEWER_ENGINE_ONLY."', \$subject);\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    #[Test]
    public function test_runtime_pcre_validation_is_off_on_a_debug_kernel(): void
    {
        $container = $this->compile([], debug: true);

        $this->assertFalse($container->getParameter('regex_parser.runtime_pcre_validation'));
        $this->assertFalse($this->runtimeValidationOf($this->regexService($container)->parser()));
    }

    #[Test]
    public function test_runtime_pcre_validation_is_on_when_asked(): void
    {
        $container = $this->compile(['runtime_pcre_validation' => true], debug: false);

        $this->assertTrue($this->runtimeValidationOf($this->regexService($container)->parser()));
    }

    #[Test]
    public function test_php_version_leaves_the_runtime_service_on_the_running_engine(): void
    {
        $container = $this->compile(['php_version' => '8.2', 'pcre_version' => '10.40']);

        $this->assertTrue($this->regexService($container)->target()->isRunningEngine());
    }

    #[Test]
    public function test_php_version_drives_the_lint_command_target(): void
    {
        $container = $this->compile(['php_version' => '8.2']);

        $report = $this->lint($container);

        $this->assertSame(1, $report['status']);
        $this->assertSame('8.2', $report['target']['php'] ?? null);
        $this->assertSame('10.40', $report['target']['pcre'] ?? null);
        $this->assertSame(1, $report['errors'], 'PHP 8.2 bundles PCRE2 10.40, which refuses a variable-length lookbehind.');
    }

    #[Test]
    public function test_pcre_version_alone_drives_the_lint_command_target(): void
    {
        $container = $this->compile(['pcre_version' => '10.42']);

        $report = $this->lint($container);

        $this->assertSame('10.42', $report['target']['pcre'] ?? null);
        $this->assertSame(1, $report['errors']);
    }

    #[Test]
    public function test_the_lint_command_does_not_inherit_runtime_validation(): void
    {
        // Runtime validation compiles with the running PHP, which cannot
        // judge PHP 8.2: handed to the lint command, it would make the
        // target unusable. The runtime service keeps it.
        $container = $this->compile(['runtime_pcre_validation' => true, 'php_version' => '8.2']);

        $this->assertTrue($this->runtimeValidationOf($this->regexService($container)->parser()));

        $report = $this->lint($container);

        $this->assertSame('8.2', $report['target']['php'] ?? null);
        $this->assertSame(1, $report['errors']);
    }

    #[Test]
    public function test_the_lint_command_reads_the_project_composer_json_without_php_version(): void
    {
        file_put_contents($this->projectDir.'/composer.json', (string) json_encode(['require' => ['php' => '>=8.2']]));
        $container = $this->compile([]);

        $report = $this->lint($container);

        $this->assertSame(['php' => '8.2', 'pcre' => '10.40', 'source' => 'composer.json require.php'], $report['target']);
        $this->assertSame(1, $report['errors']);
    }

    #[Test]
    public function test_the_lint_command_judges_for_the_running_php_without_composer_json(): void
    {
        // No composer.json in the project directory: the running PHP. A
        // regex.json there is the CLI's file, not the bundle's.
        file_put_contents($this->projectDir.'/regex.json', (string) json_encode(['phpVersion' => '8.2']));
        $container = $this->compile([]);

        $report = $this->lint($container);

        $expectedErrors = Regex::create()->validate(self::NEWER_ENGINE_ONLY)->isValid ? 0 : 1;
        $this->assertSame($expectedErrors, $report['errors']);
        $this->assertSame('running PHP', $report['target']['source'] ?? null);
    }

    #[Test]
    public function test_the_bundle_php_version_wins_over_composer_json(): void
    {
        file_put_contents($this->projectDir.'/composer.json', (string) json_encode(['require' => ['php' => '>=8.4']]));
        $container = $this->compile(['php_version' => '8.2']);

        $report = $this->lint($container);

        $this->assertSame('8.2', $report['target']['php'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedThresholds(): iterable
    {
        yield 'safe' => ['safe'];
        yield 'unknown' => ['unknown'];
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_the_container_refuses_a_verdict_as_threshold(string $threshold): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->compile(['redos' => ['threshold' => $threshold]]);
    }

    #[Test]
    public function test_the_container_takes_an_upper_case_threshold(): void
    {
        $container = $this->compile(['redos' => ['threshold' => 'CRITICAL']]);

        $this->assertSame('critical', $container->getParameter('regex_parser.redos.threshold'));
    }

    #[Test]
    public function test_exclude_is_the_lint_command_default(): void
    {
        $container = $this->compile(['exclude' => ['var', 'vendor']]);

        /** @var \PhpRegex\Symfony\Command\LintCommand $command */
        $command = $container->get('regex_parser.command.lint');

        $this->assertSame(['var', 'vendor'], $command->getDefinition()->getOption('exclude')->getDefault());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function compile(array $config, bool $debug = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', $debug);
        $container->setParameter('kernel.cache_dir', $this->projectDir.'/var/cache');
        $container->setParameter('kernel.project_dir', $this->projectDir);

        $extension = new PhpRegexExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), ['cache' => ['directory' => null]] + $config);
        $container->compile();

        return $container;
    }

    private function regexService(ContainerBuilder $container): Regex
    {
        $regex = $container->get('regex_parser.regex');
        $this->assertInstanceOf(Regex::class, $regex);

        return $regex;
    }

    private function runtimeValidationOf(RegexParser $parser): bool
    {
        $value = (new \ReflectionProperty(RegexParser::class, 'runtimePcreValidation'))->getValue($parser);
        $this->assertIsBool($value);

        return $value;
    }

    /**
     * The exit status, the "target" object and the error count of a JSON
     * lint run over the project's src/.
     *
     * @return array{status: int, target: array<mixed>|null, errors: mixed}
     */
    private function lint(ContainerBuilder $container): array
    {
        /** @var \PhpRegex\Symfony\Command\LintCommand $command */
        $command = $container->get('regex_parser.command.lint');

        $tester = new CommandTester($command);
        $status = $tester->execute([
            'paths' => [$this->projectDir.'/src'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);

        $json = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($json, 'The lint command did not print JSON: '.$tester->getDisplay());

        $target = $json['target'] ?? null;
        $stats = $json['stats'] ?? null;

        return [
            'status' => $status,
            'target' => \is_array($target) ? $target : null,
            'errors' => \is_array($stats) ? ($stats['errors'] ?? null) : null,
        ];
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}
