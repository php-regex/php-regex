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

namespace RegexParser\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Bridge\Symfony\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * The bundle's tree in 2.0: runtime validation off unless asked, a target
 * for the lint command, one threshold reading, and the leaf names of the
 * shared vocabulary (1.x names refused, never aliased).
 */
final class ConfigurationVocabularyTest extends TestCase
{
    #[Test]
    public function test_runtime_pcre_validation_defaults_to_false(): void
    {
        // 1.x defaulted to "%kernel.debug%": a debug kernel compiled every
        // pattern with the running PHP.
        $this->assertFalse($this->process([])['runtime_pcre_validation']);
    }

    #[Test]
    public function test_php_and_pcre_versions_default_to_null(): void
    {
        $config = $this->process([]);

        $this->assertArrayHasKey('php_version', $config);
        $this->assertArrayHasKey('pcre_version', $config);
        $this->assertNull($config['php_version']);
        $this->assertNull($config['pcre_version']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, mixed}>
     */
    public static function provideTargetSettings(): iterable
    {
        yield 'php version as a string' => [['php_version' => '8.2'], 'php_version', '8.2'];
        yield 'php version with a patch' => [['php_version' => '8.3.7'], 'php_version', '8.3.7'];
        yield 'pcre version' => [['pcre_version' => '10.42'], 'pcre_version', '10.42'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('provideTargetSettings')]
    public function test_php_and_pcre_versions_are_accepted(array $settings, string $key, mixed $expected): void
    {
        $this->assertSame($expected, $this->process($settings)[$key]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAcceptedThresholds(): iterable
    {
        yield 'low' => ['low', 'low'];
        yield 'medium' => ['medium', 'medium'];
        yield 'high' => ['high', 'high'];
        yield 'critical' => ['critical', 'critical'];
        yield 'upper case' => ['HIGH', 'high'];
        yield 'mixed case' => ['Critical', 'critical'];
    }

    #[Test]
    #[DataProvider('provideAcceptedThresholds')]
    public function test_redos_threshold_reads_a_severity_in_any_case(string $value, string $expected): void
    {
        $config = $this->process(['redos' => ['threshold' => $value]]);

        $this->assertIsArray($config['redos']);
        $this->assertSame($expected, $config['redos']['threshold']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedThresholds(): iterable
    {
        // 1.x accepted "safe": a threshold that reports every pattern.
        yield 'safe is a verdict' => ['safe'];
        yield 'safe in upper case' => ['SAFE'];
        yield 'unknown is a verdict' => ['unknown'];
        yield 'a word that is no severity' => ['severe'];
    }

    #[Test]
    #[DataProvider('provideRefusedThresholds')]
    public function test_redos_threshold_refuses_what_is_no_threshold(string $value): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('regex_parser.redos.threshold');

        $this->process(['redos' => ['threshold' => $value]]);
    }

    #[Test]
    public function test_exclude_is_the_leaf_name_and_defaults_to_vendor(): void
    {
        $config = $this->process([]);

        $this->assertArrayHasKey('exclude', $config);
        $this->assertArrayNotHasKey('exclude_paths', $config);
        $this->assertSame(['vendor'], $config['exclude']);
        $this->assertSame(['var', 'vendor'], $this->process(['exclude' => ['var', 'vendor']])['exclude']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideRemovedKeys(): iterable
    {
        yield 'exclude_paths, renamed exclude' => [['exclude_paths' => ['vendor']], 'exclude_paths'];
        yield 'analysis.ignore_patterns, merged into redos.ignored_patterns' => [['analysis' => ['ignore_patterns' => ['foo']]], 'ignore_patterns'];
        yield 'analysis.redos_threshold, never read' => [['analysis' => ['redos_threshold' => 100]], 'redos_threshold'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('provideRemovedKeys')]
    public function test_a_removed_key_is_refused(array $settings, string $key): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"'.$key.'"');

        $this->process($settings);
    }

    #[Test]
    public function test_analysis_keeps_its_warning_threshold(): void
    {
        // The one key under "analysis" that is read: the complexity score
        // above which the lint warns.
        $default = $this->process([]);
        $this->assertIsArray($default['analysis']);
        $this->assertSame(['warning_threshold' => 50], $default['analysis']);

        $custom = $this->process(['analysis' => ['warning_threshold' => 7]]);
        $this->assertIsArray($custom['analysis']);
        $this->assertSame(7, $custom['analysis']['warning_threshold']);
    }

    #[Test]
    public function test_redos_ignored_patterns_is_the_one_ignore_list(): void
    {
        $config = $this->process(['redos' => ['ignored_patterns' => ['[a-z]+', '/^\d+$/']]]);

        $this->assertIsArray($config['redos']);
        $this->assertSame(['[a-z]+', '/^\d+$/'], $config['redos']['ignored_patterns']);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    private function process(array $settings): array
    {
        /** @var array<string, mixed> $config */
        $config = (new Processor())->processConfiguration(new Configuration(), [$settings]);

        return $config;
    }
}
