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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\PHPStan\RegexPatternRule;
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
        yield 'noNewMixed' => ["noNewMixed:\n            enabled: true", "phpRegex\u{A0}›\u{A0}noNewMixed"];
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
     * A name that is no preset stops PHPStan when it builds the rule, the
     * error naming the key and the preset the name most likely means, as
     * the other bridges say it: left out, it would leave a list that reads
     * fewer calls than meant. The schema takes any list of names, so a
     * preset a later release adds needs no edit of it.
     *
     * @return iterable<string, array{neon: string, message: string}>
     */
    public static function provideUnknownPresets(): iterable
    {
        yield 'the name before the rename' => ['neon' => 'interop: [laravel-str]', 'message' => 'Unknown extraction preset "laravel-str" in phpRegex.interop: did you mean "illuminate-support"?'];
        yield 'beside a known one' => ['neon' => 'interop: [nette-utils, phpunt]', 'message' => 'Unknown extraction preset "phpunt" in phpRegex.interop: did you mean "phpunit"?'];
        yield 'nothing near' => ['neon' => 'interop: [zzz]', 'message' => 'Unknown extraction preset "zzz" in phpRegex.interop: the presets are '.implode(', ', InteropPresets::names()).'.'];
    }

    #[Test]
    #[DataProvider('provideUnknownPresets')]
    public function test_the_rule_refuses_an_unknown_preset(string $neon, string $message): void
    {
        $container = $this->loadExtensionWith($neon);

        $this->expectException(InvalidRegexOptionException::class);
        $this->expectExceptionMessageIsOrContains($message);

        $container->getByType(RegexPatternRule::class);
    }

    #[Test]
    public function test_the_schema_reads_every_preset_from_the_presets(): void
    {
        $neon = (string) file_get_contents(\dirname(__DIR__, 4).'/src/PHPStan/extension.neon');

        $this->assertStringNotContainsString(InteropPresets::COMPOSER_PCRE.'|', $neon);
        $parameters = $this->loadExtensionWith('interop: ['.implode(', ', InteropPresets::names()).']')->getParameter('phpRegex');
        $this->assertIsArray($parameters);
        $this->assertSame(InteropPresets::names(), $parameters['interop'] ?? null);
    }

    /**
     * @return iterable<string, array{neon: string, path: list<string>, expected: mixed}>
     */
    public static function provideAcceptedSettings(): iterable
    {
        yield 'complexity switch' => ['neon' => "checks:\n            complexity:\n                enabled: true", 'path' => ['checks', 'complexity', 'enabled'], 'expected' => true];
        yield 'complexity threshold' => ['neon' => "checks:\n            complexity:\n                threshold: 20", 'path' => ['checks', 'complexity', 'threshold'], 'expected' => 20];
        yield 'complexity threshold of one' => ['neon' => "checks:\n            complexity:\n                threshold: 1", 'path' => ['checks', 'complexity', 'threshold'], 'expected' => 1];
        yield 'lint switch' => ['neon' => "checks:\n            lint:\n                enabled: true", 'path' => ['checks', 'lint', 'enabled'], 'expected' => true];
        yield 'threshold low' => ['neon' => "checks:\n            redos:\n                threshold: low", 'path' => ['checks', 'redos', 'threshold'], 'expected' => 'low'];
        yield 'threshold medium' => ['neon' => "checks:\n            redos:\n                threshold: medium", 'path' => ['checks', 'redos', 'threshold'], 'expected' => 'medium'];
        yield 'threshold high' => ['neon' => "checks:\n            redos:\n                threshold: high", 'path' => ['checks', 'redos', 'threshold'], 'expected' => 'high'];
        yield 'threshold critical' => ['neon' => "checks:\n            redos:\n                threshold: critical", 'path' => ['checks', 'redos', 'threshold'], 'expected' => 'critical'];
        yield 'php version as a string' => ['neon' => "phpVersion: '8.2'", 'path' => ['phpVersion'], 'expected' => '8.2'];
        yield 'php version as an integer' => ['neon' => 'phpVersion: 80200', 'path' => ['phpVersion'], 'expected' => 80200];
        yield 'pcre version' => ['neon' => "pcreVersion: '10.42'", 'path' => ['pcreVersion'], 'expected' => '10.42'];
        yield 'php version runtime' => ['neon' => 'phpVersion: runtime', 'path' => ['phpVersion'], 'expected' => 'runtime'];
        yield 'php version with a patch' => ['neon' => "phpVersion: '8.4.10'", 'path' => ['phpVersion'], 'expected' => '8.4.10'];
        yield 'php version id as a string' => ['neon' => "phpVersion: '80400'", 'path' => ['phpVersion'], 'expected' => '80400'];
        yield 'php version as an open constraint' => ['neon' => "phpVersion: '>=8.1'", 'path' => ['phpVersion'], 'expected' => '>=8.1'];
        yield 'php version as a constraint with a ceiling' => ['neon' => "phpVersion: '^8.1 <8.7'", 'path' => ['phpVersion'], 'expected' => '^8.1 <8.7'];
        yield 'php version as a hyphen range' => ['neon' => "phpVersion: '8.1 - 8.6'", 'path' => ['phpVersion'], 'expected' => '8.1 - 8.6'];
        yield 'php version from the last gate point' => ['neon' => "phpVersion: '>=8.5.10'", 'path' => ['phpVersion'], 'expected' => '>=8.5.10'];
        yield 'php version above every gate point' => ['neon' => "phpVersion: '>8.6'", 'path' => ['phpVersion'], 'expected' => '>8.6'];
        yield 'php version as min and max ids' => ['neon' => "phpVersion:\n            min: 80100\n            max: 80699", 'path' => ['phpVersion'], 'expected' => ['min' => 80100, 'max' => 80699]];
        yield 'php version as min and max versions' => ['neon' => "phpVersion:\n            min: '8.1'\n            max: '8.6'", 'path' => ['phpVersion'], 'expected' => ['min' => '8.1', 'max' => '8.6']];
        yield 'pcre version as PCRE_VERSION spells it' => ['neon' => "pcreVersion: '10.44 2024-06-07'", 'path' => ['pcreVersion'], 'expected' => '10.44 2024-06-07'];
        yield 'pcre pre-release' => ['neon' => "pcreVersion: '10.45-RC1'", 'path' => ['pcreVersion'], 'expected' => '10.45-RC1'];
        yield 'interop presets' => ['neon' => 'interop: [composer-pcre, nette-utils]', 'path' => ['interop'], 'expected' => ['composer-pcre', 'nette-utils']];
        yield 'no interop preset' => ['neon' => 'interop: []', 'path' => ['interop'], 'expected' => []];
    }

    /**
     * A version the library cannot read stops PHPStan while it loads the
     * configuration, naming the key, rather than as an internal error on the
     * first file the rule is built for.
     *
     * @return iterable<string, array{neon: string, key: string}>
     */
    public static function provideUnreadableVersions(): iterable
    {
        yield 'php version word' => ['neon' => 'phpVersion: banana', 'key' => 'phpVersion'];
        yield 'php version empty' => ['neon' => "phpVersion: ''", 'key' => 'phpVersion'];
        yield 'php version zero' => ['neon' => 'phpVersion: 0', 'key' => 'phpVersion'];
        yield 'php version negative' => ['neon' => 'phpVersion: -80200', 'key' => 'phpVersion'];
        yield 'php version with text after it' => ['neon' => "phpVersion: '8.2foo'", 'key' => 'phpVersion'];
        yield 'php version with a patch past 99' => ['neon' => "phpVersion: '8.2.100'", 'key' => 'phpVersion'];
        yield 'php version with four parts' => ['neon' => "phpVersion: '8.2.1.9'", 'key' => 'phpVersion'];
        yield 'php version range without max' => ['neon' => "phpVersion:\n            min: 80100", 'key' => 'phpVersion'];
        yield 'php version range without min' => ['neon' => "phpVersion:\n            max: 80699", 'key' => 'phpVersion'];
        yield 'php version range with another key' => ['neon' => "phpVersion:\n            min: 80100\n            max: 80699\n            step: 1", 'key' => 'phpVersion'];
        yield 'php version range with a bound that is no version' => ['neon' => "phpVersion:\n            min: true\n            max: 80699", 'key' => 'phpVersion'];
        yield 'php version range with a bound of PHP 10' => ['neon' => "phpVersion:\n            min: '8.1'\n            max: '10.1'", 'key' => 'phpVersion'];
        yield 'php version range with a PHP_VERSION_ID before PHP 7' => ['neon' => "phpVersion:\n            min: 60000\n            max: 80699", 'key' => 'phpVersion'];
        yield 'php version range with float bounds' => ['neon' => "phpVersion:\n            min: 8.1\n            max: 8.6", 'key' => 'phpVersion'];
        yield 'pcre version word' => ['neon' => 'pcreVersion: ten', 'key' => 'pcreVersion'];
        yield 'pcre version without a minor' => ['neon' => "pcreVersion: '10'", 'key' => 'pcreVersion'];
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
     * Unquoted, 8.1 is a float in neon: the error says a version is quoted.
     */
    #[Test]
    public function test_schema_asks_float_bounds_to_be_quoted(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains("{min, max}, each a quoted version like '8.1' or a PHP_VERSION_ID");

        $this->loadExtensionWith("phpVersion:\n            min: 8.1\n            max: 8.6");
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
