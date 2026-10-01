<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Bridge\Symfony\DependencyInjection;

use PhpRegex\Symfony\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * php_version takes what Regex::create() takes ("8.2", "8.2.4", 80200) and
 * pcre_version a release; a value neither can read stops the container
 * compile. A 1.x key is refused with the key that replaces it.
 */
final class ConfigurationTargetTest extends TestCase
{
    #[Test]
    public function test_php_version_takes_a_version_id(): void
    {
        $this->assertSame(80200, $this->process(['php_version' => 80200])['php_version']);
    }

    #[Test]
    public function test_an_explicit_null_version_is_kept(): void
    {
        $this->assertNull($this->process(['php_version' => null, 'pcre_version' => null])['php_version']);
    }

    #[Test]
    public function test_an_unquoted_yaml_version_is_refused_with_a_hint(): void
    {
        // YAML reads "php_version: 8.2" as the float 8.2, and 8.10 as 8.1.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('quoted');

        $this->process(['php_version' => 8.2]);
    }

    #[Test]
    public function test_a_threshold_that_is_no_string_is_refused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('php_regex.redos.threshold');

        $this->process(['redos' => ['threshold' => 3]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideUnreadableVersions(): iterable
    {
        yield 'php version that is no version' => [['php_version' => 'eight'], 'php_regex.php_version'];
        yield 'php version as a list' => [['php_version' => ['8.2']], 'php_regex.php_version'];
        yield 'pcre release that is no release' => [['pcre_version' => 'ten'], 'php_regex.pcre_version'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('provideUnreadableVersions')]
    public function test_an_unreadable_version_is_refused(array $settings, string $path): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($path);

        $this->process($settings);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function provideRemovedKeys(): iterable
    {
        yield 'exclude_paths' => [['exclude_paths' => ['vendor']], '"exclude"'];
        yield 'analysis.ignore_patterns' => [['analysis' => ['ignore_patterns' => ['foo']]], '"redos.ignored_patterns"'];
        yield 'analysis.redos_threshold' => [['analysis' => ['redos_threshold' => 100]], '"redos.threshold"'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('provideRemovedKeys')]
    public function test_a_removed_key_names_its_replacement(array $settings, string $replacement): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($replacement);

        $this->process($settings);
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
