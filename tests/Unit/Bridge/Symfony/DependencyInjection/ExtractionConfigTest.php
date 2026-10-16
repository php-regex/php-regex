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

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Symfony\DependencyInjection\Configuration;
use PHPRegex\Symfony\DependencyInjection\PHPRegexExtension;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * php_regex.extraction.{interop,functions} name the calls regex:lint reads,
 * in the shape of regex.json's "extraction" (the bundle does not read
 * regex.json). The tree takes any preset name: an unknown one is a warning
 * when the lint runs, never a configuration error.
 */
final class ExtractionConfigTest extends TestCase
{
    use TemporaryProject;

    private const CALLS = <<<'PHP'
        <?php

        namespace App;

        use Nette\Utils\Strings;
        use Webmozart\Assert\Assert;

        function check(string $s): void
        {
            preg_match('/native/', $s);
            \Composer\Pcre\Preg::match('/composer/', $s);
            Strings::match($s, '/nette/');
            Assert::regex($s, '/webmozart/');
            grep([], '/custom/');
        }

        PHP;

    #[Test]
    public function test_the_tree_takes_the_extraction_keys(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'extraction' => ['interop' => ['nette-utils', 'phpunit'], 'functions' => ['App\grep#1']],
        ]]);

        $extraction = $config['extraction'] ?? null;
        $this->assertIsArray($extraction);
        $this->assertSame(['nette-utils', 'phpunit'], $extraction['interop'] ?? null);
        $this->assertSame(['App\grep#1'], $extraction['functions'] ?? null);
    }

    #[Test]
    public function test_no_extraction_config_reads_every_preset(): void
    {
        $this->assertSame(['/native/', '/composer/', '/nette/', '/webmozart/'], $this->patterns([]));
    }

    #[Test]
    public function test_the_configured_presets_and_functions_are_read(): void
    {
        $this->assertSame(['/native/', '/nette/', '/custom/'], $this->patterns(['interop' => ['nette-utils'], 'functions' => ['App\grep#1']]));
    }

    #[Test]
    public function test_functions_alone_keeps_every_preset(): void
    {
        $this->assertSame(['/native/', '/composer/', '/nette/', '/webmozart/', '/custom/'], $this->patterns(['functions' => ['App\grep#1']]));
    }

    /**
     * One name, written without a list, is a list of that name, as the
     * Laravel package reads it.
     */
    #[Test]
    public function test_one_name_without_a_list_is_a_list_of_it(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['extraction' => ['interop' => 'nette-utils', 'functions' => 'App\grep#1']]]);

        $extraction = $config['extraction'] ?? null;
        $this->assertIsArray($extraction);
        $this->assertSame(['nette-utils'], $extraction['interop'] ?? null);
        $this->assertSame(['App\grep#1'], $extraction['functions'] ?? null);
    }

    #[Test]
    public function test_a_null_interop_is_every_preset_in_the_tree(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['extraction' => ['interop' => null]]]);

        $extraction = $config['extraction'] ?? null;
        $this->assertIsArray($extraction);
        $this->assertSame(InteropPresets::DEFAULT_PRESETS, $extraction['interop'] ?? null);
    }

    #[Test]
    public function test_a_null_interop_reads_every_preset(): void
    {
        $this->assertSame(['/native/', '/composer/', '/nette/', '/webmozart/'], $this->patterns(['interop' => null]));
    }

    #[Test]
    public function test_an_empty_interop_reads_the_native_calls_only(): void
    {
        $this->assertSame(['/native/'], $this->patterns(['interop' => []]));
    }

    /**
     * An unknown preset is a configuration error, as any value the tree
     * refuses, which names the preset it most likely means: left out, it
     * would leave a list that reads fewer calls than meant, the native ones
     * only when it was the only one.
     */
    #[Test]
    public function test_an_unknown_preset_is_a_configuration_error(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid configuration for path "php_regex.extraction.interop": Unknown extraction preset "laravel-str": did you mean "illuminate-support"?');

        $this->container(['interop' => ['laravel-str', 'nette-utils']], $this->makeProject());
    }

    #[Test]
    public function test_a_list_made_only_of_an_unknown_preset_is_a_configuration_error(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Unknown extraction preset "acme": the presets are composer-pcre, ');

        $this->container(['interop' => 'acme'], $this->makeProject());
    }

    /**
     * @param array<string, mixed> $extraction
     *
     * @return list<string>
     */
    private function patterns(array $extraction): array
    {
        $project = $this->makeProject(['src/Check.php' => self::CALLS]);
        $strategy = $this->container($extraction, $project)->get('php_regex.extractor.instance');
        $this->assertInstanceOf(ExtractorInterface::class, $strategy);

        return array_values(array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->pattern,
            $strategy->extract([$project.'/src/Check.php']),
        ));
    }

    /**
     * @param array<string, mixed> $extraction
     */
    private function container(array $extraction, string $project): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', $project);
        $container->setParameter('kernel.cache_dir', $project.'/var/cache');
        $container->setParameter('kernel.environment', 'test');

        (new PHPRegexExtension())->load([[] === $extraction ? [] : ['extraction' => $extraction]], $container);
        $container->getDefinition('php_regex.extractor.instance')->setPublic(true);
        $container->getDefinition('php_regex.command.lint')->setPublic(true);
        $container->compile();

        return $container;
    }
}
