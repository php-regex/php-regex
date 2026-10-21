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

use PHPRegex\Tests\Support\BundleContainer;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * php_version takes what Regex::create() takes ("8.2", "8.2.4", 80200) and
 * pcre_version a release; a value neither can read stops the container
 * compile. A 1.x key is refused like any key the tree never knew.
 */
final class ConfigurationTargetTest extends TestCase
{
    use PhpUnitForwardCompat;

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
        $this->expectExceptionMessageIsOrContains('quoted');

        $this->process(['php_version' => 8.2]);
    }

    #[Test]
    public function test_a_threshold_that_is_no_string_is_refused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains('php_regex.redos.threshold');

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
        $this->expectExceptionMessageIsOrContains($path);

        $this->process($settings);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}>
     */
    public static function provideRemovedKeys(): iterable
    {
        yield 'exclude_paths' => [['exclude_paths' => ['vendor']], 'exclude_paths', 'php_regex', 'Available options are'];
        yield 'analysis.ignore_patterns' => [['analysis' => ['ignore_patterns' => ['foo']]], 'ignore_patterns', 'php_regex.analysis', 'Available options are'];
        yield 'analysis.redos_threshold' => [['analysis' => ['redos_threshold' => 100]], 'redos_threshold', 'php_regex.analysis', 'Available options are'];
    }

    /**
     * A 1.x key no longer carries a message of its own: it is simply a key
     * the tree does not know, and the one refusal names it under its parent
     * node with the options that do exist.
     *
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('provideRemovedKeys')]
    public function test_a_removed_key_is_only_an_unrecognized_option(array $settings, string $key, string $parentPath, string $optionsPhrase): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches(\sprintf('/Unrecognized option "%s" under "%s"\. %s/', preg_quote($key, '/'), preg_quote($parentPath, '/'), preg_quote($optionsPhrase, '/')));

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
        $config = (new Processor())->processConfiguration(BundleContainer::configuration(), [$settings]);

        return $config;
    }
}
