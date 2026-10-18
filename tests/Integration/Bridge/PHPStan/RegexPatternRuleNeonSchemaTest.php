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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPStan\DependencyInjection\Container;
use PHPStan\DependencyInjection\ContainerFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The "phpRegex" schema of extension.neon, checked by PHPStan itself while
 * it loads a configuration: a key outside the schema stops the analysis
 * before any file is read.
 */
final class RegexPatternRuleNeonSchemaTest extends TestCase
{
    use PhpUnitForwardCompat;

    private string $configDirectory;

    protected function setUp(): void
    {
        $this->configDirectory = sys_get_temp_dir().'/regex-parser-neon-schema-'.bin2hex(random_bytes(6));
        mkdir($this->configDirectory, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->configDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->configDirectory);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRemovedKeys(): iterable
    {
        yield 'ignoreParseErrors' => ['ignoreParseErrors: true', "phpRegex\u{A0}›\u{A0}ignoreParseErrors"];
        yield 'reportRedos' => ['reportRedos: true', "phpRegex\u{A0}›\u{A0}reportRedos"];
        yield 'redosMode' => ['redosMode: theoretical', "phpRegex\u{A0}›\u{A0}redosMode"];
        yield 'redosThreshold' => ['redosThreshold: low', "phpRegex\u{A0}›\u{A0}redosThreshold"];
        yield 'suggestOptimizations' => ['suggestOptimizations: true', "phpRegex\u{A0}›\u{A0}suggestOptimizations"];
        yield 'optimizationConfig' => ["optimizationConfig:\n            digits: true", "phpRegex\u{A0}›\u{A0}optimizationConfig"];
        yield 'checks.redos.mode' => ["checks:\n            redos:\n                mode: confirmed", "phpRegex\u{A0}›\u{A0}checks\u{A0}›\u{A0}redos\u{A0}›\u{A0}mode"];
        yield 'checks.redos.noJit' => ["checks:\n            redos:\n                noJit: false", "phpRegex\u{A0}›\u{A0}checks\u{A0}›\u{A0}redos\u{A0}›\u{A0}noJit"];
    }

    #[Test]
    #[DataProvider('provideRemovedKeys')]
    public function test_schema_refuses_a_removed_key(string $neon, string $path): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains("Unexpected item 'parameters\u{A0}›\u{A0}".$path."'");

        $this->loadExtensionWith($neon);
    }

    #[Test]
    public function test_schema_refuses_a_threshold_outside_the_severities(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains("'parameters\u{A0}›\u{A0}phpRegex\u{A0}›\u{A0}checks\u{A0}›\u{A0}redos\u{A0}›\u{A0}threshold'");

        $this->loadExtensionWith("checks:\n            redos:\n                threshold: severe");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUnreadableComplexitySettings(): iterable
    {
        yield 'threshold zero' => ["checks:\n            complexity:\n                threshold: 0"];
        yield 'threshold negative' => ["checks:\n            complexity:\n                threshold: -5"];
        yield 'threshold as a string' => ["checks:\n            complexity:\n                threshold: many"];
        yield 'switch as a string' => ["checks:\n            complexity:\n                enabled: maybe"];
        yield 'unknown key' => ["checks:\n            complexity:\n                max: 20"];
    }

    #[Test]
    #[DataProvider('provideUnreadableComplexitySettings')]
    public function test_schema_refuses_an_unreadable_complexity_setting(string $neon): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains("'parameters\u{A0}›\u{A0}phpRegex\u{A0}›\u{A0}checks\u{A0}›\u{A0}complexity\u{A0}›\u{A0}");

        $this->loadExtensionWith($neon);
    }

    /**
     * A name that is no preset stops PHPStan while it loads the
     * configuration, the schema naming the key and the presets it takes:
     * left out, it would leave a list that reads fewer calls than meant.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideUnknownPresets(): iterable
    {
        yield 'the name before the rename' => ['interop: [laravel-str]'];
        yield 'beside a known one' => ['interop: [nette-utils, phpunt]'];
    }

    #[Test]
    #[DataProvider('provideUnknownPresets')]
    public function test_schema_refuses_an_unknown_preset(string $neon): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/'parameters\u{A0}›\u{A0}phpRegex\u{A0}›\u{A0}interop\u{A0}›\u{A0}\\d' expects to match pattern '".preg_quote(implode('|', InteropPresets::names()), '/')."', '[a-z-]+' given/");

        $this->loadExtensionWith($neon);
    }

    /**
     * @return iterable<string, array{string, list<string>, mixed}>
     */
    public static function provideAcceptedSettings(): iterable
    {
        yield 'complexity switch' => ["checks:\n            complexity:\n                enabled: true", ['checks', 'complexity', 'enabled'], true];
        yield 'complexity threshold' => ["checks:\n            complexity:\n                threshold: 20", ['checks', 'complexity', 'threshold'], 20];
        yield 'complexity threshold of one' => ["checks:\n            complexity:\n                threshold: 1", ['checks', 'complexity', 'threshold'], 1];
        yield 'lint switch' => ["checks:\n            lint:\n                enabled: true", ['checks', 'lint', 'enabled'], true];
        yield 'threshold low' => ["checks:\n            redos:\n                threshold: low", ['checks', 'redos', 'threshold'], 'low'];
        yield 'threshold medium' => ["checks:\n            redos:\n                threshold: medium", ['checks', 'redos', 'threshold'], 'medium'];
        yield 'threshold high' => ["checks:\n            redos:\n                threshold: high", ['checks', 'redos', 'threshold'], 'high'];
        yield 'threshold critical' => ["checks:\n            redos:\n                threshold: critical", ['checks', 'redos', 'threshold'], 'critical'];
        yield 'php version as a string' => ["phpVersion: '8.2'", ['phpVersion'], '8.2'];
        yield 'php version as an integer' => ['phpVersion: 80200', ['phpVersion'], 80200];
        yield 'pcre version' => ["pcreVersion: '10.42'", ['pcreVersion'], '10.42'];
        yield 'php version runtime' => ['phpVersion: runtime', ['phpVersion'], 'runtime'];
        yield 'php version with a patch' => ["phpVersion: '8.4.10'", ['phpVersion'], '8.4.10'];
        yield 'php version id as a string' => ["phpVersion: '80400'", ['phpVersion'], '80400'];
        yield 'pcre version as PCRE_VERSION spells it' => ["pcreVersion: '10.44 2024-06-07'", ['pcreVersion'], '10.44 2024-06-07'];
        yield 'pcre pre-release' => ["pcreVersion: '10.45-RC1'", ['pcreVersion'], '10.45-RC1'];
        yield 'interop presets' => ['interop: [composer-pcre, nette-utils]', ['interop'], ['composer-pcre', 'nette-utils']];
        yield 'no interop preset' => ['interop: []', ['interop'], []];
    }

    /**
     * A version the library cannot read stops PHPStan while it loads the
     * configuration, naming the key, rather than as an internal error on the
     * first file the rule is built for.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideUnreadableVersions(): iterable
    {
        yield 'php version word' => ['phpVersion: banana', 'phpVersion'];
        yield 'php version empty' => ["phpVersion: ''", 'phpVersion'];
        yield 'php version zero' => ['phpVersion: 0', 'phpVersion'];
        yield 'php version negative' => ['phpVersion: -80200', 'phpVersion'];
        yield 'pcre version word' => ['pcreVersion: ten', 'pcreVersion'];
        yield 'pcre version without a minor' => ["pcreVersion: '10'", 'pcreVersion'];
    }

    #[Test]
    #[DataProvider('provideUnreadableVersions')]
    public function test_schema_refuses_a_version_the_library_cannot_read(string $neon, string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains("'parameters\u{A0}›\u{A0}phpRegex\u{A0}›\u{A0}".$key."'");

        $this->loadExtensionWith($neon);
    }

    /**
     * @param list<string> $path
     */
    #[Test]
    #[DataProvider('provideAcceptedSettings')]
    public function test_schema_accepts_the_documented_settings(string $neon, array $path, mixed $expected): void
    {
        $parameters = $this->loadExtensionWith($neon)->getParameter('phpRegex');

        $this->assertSame($expected, NeonParameters::read($parameters, ...$path));
    }

    private function loadExtensionWith(string $phpRegexNeon): Container
    {
        $config = $this->configDirectory.'/phpstan.neon';
        file_put_contents($config, \sprintf(
            "includes:\n    - %s\n\nparameters:\n    phpRegex:\n        %s\n",
            \dirname(__DIR__, 4).'/src/PHPStan/extension.neon',
            $phpRegexNeon,
        ));

        $containerFactory = new ContainerFactory(\dirname((string) (new \ReflectionClass(ContainerFactory::class))->getFileName(), 3));

        return $containerFactory->create(
            sys_get_temp_dir().'/phpstan-tests',
            [$containerFactory->getConfigDirectory().'/config.level8.neon', $config],
            [],
        );
    }
}
