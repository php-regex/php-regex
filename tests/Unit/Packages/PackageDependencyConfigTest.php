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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Tests\TestUtils\PackageDependencyConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The configuration the dependency analyser runs with on one package
 * installed alone: the installed copy is the only path scanned, and what
 * the manifest suggests may be used without being required.
 */
final class PackageDependencyConfigTest extends TestCase
{
    #[Test]
    public function test_the_installed_copy_is_the_only_path_scanned(): void
    {
        $configuration = PackageDependencyConfig::configuration(['name' => 'php-regex/regex-x'], 'vendor/php-regex/regex-x');

        $this->assertStringStartsWith("<?php\n", $configuration);
        $this->assertStringContainsString('->disableComposerAutoloadPathScan()', $configuration);
        $this->assertStringContainsString("->addPathToScan(__DIR__.'/vendor/php-regex/regex-x', isDev: false)", $configuration);
        // the package's own classes are not a dependency of the package
        $this->assertStringContainsString("->ignoreErrorsOnPackage('php-regex/regex-x', [ErrorType::SHADOW_DEPENDENCY])", $configuration);
        // what a manifest suggests may well be unused by the sources
        $this->assertStringContainsString('->disableReportingUnmatchedIgnores()', $configuration);
        $this->assertStringNotContainsString('ignoreErrorsOnPackages', $configuration, 'Nothing suggested, nothing allowed.');
        $this->assertStringNotContainsString('ignoreErrorsOnExtensions', $configuration, 'Nothing suggested, nothing allowed.');
        $this->assertStringNotContainsString('nikic/php-parser', $configuration);
        $this->assertStringNotContainsString('ignoreErrorsOnPath', $configuration);
        $written = $this->written($configuration);

        try {
            $this->assertSame('No syntax errors detected in '.$written, trim((string) shell_exec(escapeshellarg(\PHP_BINARY).' -d auto_prepend_file= -l '.escapeshellarg($written).' 2>&1')));
        } finally {
            unlink($written);
        }
    }

    #[Test]
    public function test_what_the_manifest_suggests_may_be_used_without_being_required(): void
    {
        $manifest = ['name' => 'php-regex/regex-x', 'suggest' => ['ext-posix' => 'For TTY detection.', 'nikic/php-parser' => 'To extract patterns.', 'ext-intl' => 'For names.']];

        $configuration = PackageDependencyConfig::configuration($manifest, 'vendor/php-regex/regex-x');

        $this->assertStringContainsString("->ignoreErrorsOnPackages(['nikic/php-parser'], [ErrorType::SHADOW_DEPENDENCY])", $configuration);
        $this->assertStringContainsString("->ignoreErrorsOnExtensions(['ext-posix', 'ext-intl'], [ErrorType::SHADOW_DEPENDENCY])", $configuration);
        $this->assertSame(1, substr_count($configuration, 'ignoreErrorsOnPackages'));
        $this->assertSame(1, substr_count($configuration, 'ignoreErrorsOnExtensions'));
    }

    #[Test]
    public function test_a_manifest_suggesting_extensions_alone_allows_no_package(): void
    {
        $configuration = PackageDependencyConfig::configuration(['name' => 'php-regex/regex-x', 'suggest' => ['ext-pcntl' => 'To fork.']], 'vendor/php-regex/regex-x');

        $this->assertStringNotContainsString('ignoreErrorsOnPackages', $configuration);
        $this->assertStringContainsString("->ignoreErrorsOnExtensions(['ext-pcntl'], [ErrorType::SHADOW_DEPENDENCY])", $configuration);
    }

    /**
     * A bridge reaches php-parser through its host, which runs on it:
     * PHPStan and Rector ship it inside their phar, where the run installs
     * a copy beside the phar to read the classes; Psalm requires it, so the
     * copy is already there. None of them is a requirement of the bridge,
     * which could only conflict with the host's.
     *
     * @return iterable<string, array{require: string, helpers: list<string>}>
     */
    public static function provideHostsCarryingPhpParser(): iterable
    {
        yield 'PHPStan' => ['require' => 'phpstan/phpstan', 'helpers' => ['nikic/php-parser']];
        yield 'Rector' => ['require' => 'rector/rector', 'helpers' => ['nikic/php-parser']];
        yield 'Psalm' => ['require' => 'vimeo/psalm', 'helpers' => []];
    }

    /**
     * @param list<string> $helpers
     */
    #[Test]
    #[DataProvider('provideHostsCarryingPhpParser')]
    public function test_a_host_that_carries_php_parser_provides_it_to_its_bridge(string $require, array $helpers): void
    {
        $manifest = ['name' => 'php-regex/regex-x', 'require' => [$require => '^2.0']];

        $this->assertStringContainsString("->ignoreErrorsOnPackage('nikic/php-parser', [ErrorType::SHADOW_DEPENDENCY])", PackageDependencyConfig::configuration($manifest, 'vendor/php-regex/regex-x'));
        $this->assertSame($helpers, PackageDependencyConfig::helpers($manifest));
    }

    #[Test]
    public function test_the_symfony_container_dsl_is_read_as_functions_the_loader_declares(): void
    {
        $manifest = ['name' => 'php-regex/regex-x', 'require' => ['symfony/dependency-injection' => '^7.4']];

        $this->assertStringContainsString("->ignoreErrorsOnPath(__DIR__.'/vendor/php-regex/regex-x/Resources/config', [ErrorType::UNKNOWN_FUNCTION])", PackageDependencyConfig::configuration($manifest, 'vendor/php-regex/regex-x'));
    }

    #[Test]
    public function test_the_helpers_are_the_suggested_packages_and_nothing_else(): void
    {
        $manifest = ['name' => 'php-regex/regex-x', 'require' => ['symfony/console' => '^7.4'], 'suggest' => ['ext-posix' => 'TTY.', 'symfony/yaml' => 'YAML.', 'nikic/php-parser' => 'Parsing.']];

        $this->assertSame(['symfony/yaml', 'nikic/php-parser'], PackageDependencyConfig::helpers($manifest));
        $this->assertSame([], PackageDependencyConfig::helpers(['name' => 'php-regex/regex-x']));
    }

    private function written(string $configuration): string
    {
        $path = sys_get_temp_dir().'/php-regex-dependency-config-'.md5($configuration).'.php';
        file_put_contents($path, $configuration);

        return $path;
    }
}
