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
final class RegexParserRuleNeonSchemaTest extends TestCase
{
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
        $this->expectExceptionMessage("Unexpected item 'parameters\u{A0}›\u{A0}".$path."'");

        $this->loadExtensionWith($neon);
    }

    #[Test]
    public function test_schema_refuses_a_threshold_outside_the_severities(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("'parameters\u{A0}›\u{A0}phpRegex\u{A0}›\u{A0}checks\u{A0}›\u{A0}redos\u{A0}›\u{A0}threshold'");

        $this->loadExtensionWith("checks:\n            redos:\n                threshold: severe");
    }

    /**
     * @return iterable<string, array{string, list<string>, mixed}>
     */
    public static function provideAcceptedSettings(): iterable
    {
        yield 'lint switch' => ["checks:\n            lint:\n                enabled: true", ['checks', 'lint', 'enabled'], true];
        yield 'threshold low' => ["checks:\n            redos:\n                threshold: low", ['checks', 'redos', 'threshold'], 'low'];
        yield 'threshold medium' => ["checks:\n            redos:\n                threshold: medium", ['checks', 'redos', 'threshold'], 'medium'];
        yield 'threshold high' => ["checks:\n            redos:\n                threshold: high", ['checks', 'redos', 'threshold'], 'high'];
        yield 'threshold critical' => ["checks:\n            redos:\n                threshold: critical", ['checks', 'redos', 'threshold'], 'critical'];
        yield 'php version as a string' => ["phpVersion: '8.2'", ['phpVersion'], '8.2'];
        yield 'php version as an integer' => ['phpVersion: 80200', ['phpVersion'], 80200];
        yield 'pcre version' => ["pcreVersion: '10.42'", ['pcreVersion'], '10.42'];
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
