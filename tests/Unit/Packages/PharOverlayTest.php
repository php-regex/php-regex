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

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The phar carries the MCP server's SDK, which the monorepo package never
 * requires: the phar build installs from its own locked manifest, the root
 * manifest plus that one dependency at an exact version, pinned by hand.
 */
#[Group('documentation')]
final class PharOverlayTest extends TestCase
{
    #[Test]
    public function test_the_phar_manifest_is_the_root_manifest_plus_the_sdk(): void
    {
        $root = self::decode(self::root().'/composer.json');
        $phar = self::decode(self::root().'/phar/composer.json');

        $require = self::strings($root['require'] ?? null);
        $require['mcp/sdk'] = self::pharSdkVersion();
        $expected = $root;
        $expected['require'] = $require;

        $this->assertSame($expected, $phar);
        $this->assertArrayNotHasKey('mcp/sdk', self::strings($root['require'] ?? null), 'The monorepo package never requires the SDK.');
    }

    #[Test]
    public function test_the_phar_lock_pins_the_sdk_at_the_version_the_package_allows(): void
    {
        $lock = self::decode(self::root().'/phar/composer.lock');
        $locked = null;
        foreach ((array) ($lock['packages'] ?? []) as $package) {
            if (\is_array($package) && 'mcp/sdk' === ($package['name'] ?? null)) {
                $locked = $package['version'] ?? null;
            }
        }

        $this->assertSame('v'.self::pharSdkVersion(), $locked);

        $manifest = self::decode(self::root().'/src/Mcp/composer.json');
        $pin = self::strings($manifest['require'] ?? null)['mcp/sdk'] ?? null;
        $this->assertIsString($pin);
        $this->assertMatchesRegularExpression('~^\^\d+\.\d+$~', $pin);
        $this->assertStringStartsWith(substr($pin, 1).'.', self::pharSdkVersion(), 'The phar locks a release of the minor the package pins.');
        $this->assertSame('<'.substr($pin, 1), self::strings($manifest['conflict'] ?? null)['mcp/sdk'] ?? null);
    }

    #[Test]
    public function test_the_phar_build_installs_from_the_overlay(): void
    {
        $build = (string) file_get_contents(self::root().'/phar/build');

        $this->assertStringContainsString('phar/composer.json', $build);
        $this->assertStringContainsString('phar/composer.lock', $build);
        $this->assertFileIsReadable(self::root().'/phar/lock');
        $this->assertTrue(is_executable(self::root().'/phar/lock'));
    }

    #[Test]
    public function test_the_phar_jobs_install_the_extensions_the_sdk_needs(): void
    {
        foreach (['.github/workflows/phar.yml', '.github/workflows/packages.yml'] as $workflow) {
            $content = (string) file_get_contents(self::root().'/'.$workflow);
            preg_match_all('~^\s+extensions: (.+)$~m', $content, $lines);
            $this->assertNotSame([], $lines[1], $workflow);
            foreach ($lines[1] as $line) {
                $this->assertStringContainsString('fileinfo', $line, $workflow);
            }
        }
    }

    #[Test]
    public function test_the_phar_check_drives_the_mcp_server(): void
    {
        $action = (string) file_get_contents(self::root().'/.github/actions/check-phar/action.yml');

        $this->assertStringContainsString('bin/regex.phar mcp', $action);
        $this->assertStringContainsString('tools/list', $action);
    }

    #[Test]
    public function test_a_workflow_watches_the_sdk_releases(): void
    {
        $workflow = (string) file_get_contents(self::root().'/.github/workflows/sdk-watch.yml');

        $this->assertStringContainsString('schedule:', $workflow);
        $this->assertStringContainsString('mcp/sdk', $workflow);
        $this->assertStringContainsString('src/Mcp/composer.json', $workflow);
        $this->assertStringContainsString('issues: write', $workflow);
    }

    private static function pharSdkVersion(): string
    {
        $version = self::strings(self::decode(self::root().'/phar/composer.json')['require'] ?? null)['mcp/sdk'] ?? null;
        self::assertIsString($version);
        self::assertMatchesRegularExpression('~^\d+\.\d+\.\d+$~', $version, 'The phar pins an exact release.');

        return $version;
    }

    /**
     * @return array<string, string>
     */
    private static function strings(mixed $value): array
    {
        $strings = [];
        foreach (\is_array($value) ? $value : [] as $key => $item) {
            if (\is_string($item)) {
                $strings[(string) $key] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $path): array
    {
        self::assertFileExists($path);
        $data = json_decode((string) file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        $typed = [];
        foreach ($data as $key => $value) {
            $typed[(string) $key] = $value;
        }

        return $typed;
    }

    private static function root(): string
    {
        return \dirname(__DIR__, 3);
    }
}
