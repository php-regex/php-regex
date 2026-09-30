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

namespace RegexParser\Tests\Unit\Lint\Command;

use PHPUnit\Framework\TestCase;
use RegexParser\Lint\Command\LintConfigLoader;

final class LintConfigLoaderTest extends TestCase
{
    public function test_load_reads_and_normalizes_config(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);

        $tempDir = sys_get_temp_dir().'/regex-parser-config-'.uniqid('', true);
        mkdir($tempDir, 0o700, true);

        $configPath = $tempDir.'/regex.json';
        $config = [
            'paths' => ['src'],
            'exclude' => ['vendor'],
            'jobs' => 2,
            'minSavings' => 3,
            'format' => 'console',
            'rules' => [
                'redos' => false,
                'validation' => true,
                'optimization' => false,
            ],
        ];

        copy(__DIR__.'/../../../Fixtures/Config/paths_config.json', $configPath);

        chmod($configPath, 0o200);

        try {
            chdir($tempDir);

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNotNull($result->error);
            $this->assertStringContainsString('Failed to read config file', (string) $result->error);
        } finally {
            chmod($configPath, 0o600);
            chdir($cwd);
            @unlink($configPath);
            @rmdir($tempDir);
        }
    }

    public function test_load_returns_error_on_non_object_json(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);

        $tempDir = sys_get_temp_dir().'/regex-parser-config-'.uniqid('', true);
        mkdir($tempDir, 0o700, true);

        $configPath = $tempDir.'/regex.json';
        copy(__DIR__.'/../../../Fixtures/Config/string_config.json', $configPath);

        try {
            chdir($tempDir);

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNotNull($result->error);
            $this->assertStringContainsString('Config file must contain a JSON object', (string) $result->error);
        } finally {
            chdir($cwd);
            @unlink($configPath);
            @rmdir($tempDir);
        }
    }

    public function test_load_returns_error_on_numeric_keys(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);

        $tempDir = sys_get_temp_dir().'/regex-parser-config-'.uniqid('', true);
        mkdir($tempDir, 0o700, true);

        $configPath = $tempDir.'/regex.json';
        copy(__DIR__.'/../../../Fixtures/Config/array_config.json', $configPath);

        try {
            chdir($tempDir);

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNotNull($result->error);
            $this->assertStringContainsString('Config file must contain a JSON object', (string) $result->error);
        } finally {
            chdir($cwd);
            @unlink($configPath);
            @rmdir($tempDir);
        }
    }

    public function test_load_normalizes_optimizations_config(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);

        $tempDir = sys_get_temp_dir().'/regex-parser-config-'.uniqid('', true);
        mkdir($tempDir, 0o700, true);

        $configPath = $tempDir.'/regex.json';
        copy(__DIR__.'/../../../Fixtures/Config/optimizations_config.json', $configPath);

        try {
            chdir($tempDir);

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNull($result->error);
            $this->assertSame([
                'digits' => false,
                'word' => true,
                'ranges' => true,
                'canonicalizeCharClasses' => false,
                'possessive' => false,
                'factorize' => false,
            ], $result->config['optimizations']);
        } finally {
            chdir($cwd);
            @unlink($configPath);
            @rmdir($tempDir);
        }
    }

    public function test_load_normalizes_extraction_config(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);

        $tempDir = sys_get_temp_dir().'/regex-parser-config-'.uniqid('', true);
        mkdir($tempDir, 0o700, true);

        $configPath = $tempDir.'/regex.json';
        copy(__DIR__.'/../../../Fixtures/Config/extraction_config.json', $configPath);

        try {
            chdir($tempDir);

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNull($result->error);
            $this->assertSame([
                'interop' => ['composer-pcre', 'nette-utils'],
                'patternFunctions' => ['App\\Support\\Str::matches#1', 'regex_check'],
            ], $result->config);
        } finally {
            chdir($cwd);
            @unlink($configPath);
            @rmdir($tempDir);
        }
    }

    public function test_load_rejects_an_unknown_interop_preset(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);

        $tempDir = sys_get_temp_dir().'/regex-parser-config-'.uniqid('', true);
        mkdir($tempDir, 0o700, true);

        $configPath = $tempDir.'/regex.json';
        copy(__DIR__.'/../../../Fixtures/Config/invalid_extraction_config.json', $configPath);

        try {
            chdir($tempDir);

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNotNull($result->error);
            $this->assertStringContainsString('unknown preset "not-a-preset"', (string) $result->error);
        } finally {
            chdir($cwd);
            @unlink($configPath);
            @rmdir($tempDir);
        }
    }

    public function test_load_normalizes_checks_config(): void
    {
        $cwd = getcwd();
        $this->assertIsString($cwd);

        $tempDir = sys_get_temp_dir().'/regex-parser-config-'.uniqid('', true);
        mkdir($tempDir, 0o700, true);

        $configPath = $tempDir.'/regex.json';
        copy(__DIR__.'/../../../Fixtures/Config/checks_config.json', $configPath);

        try {
            chdir($tempDir);

            $loader = new LintConfigLoader();
            $result = $loader->load();

            $this->assertNull($result->error);
            $this->assertSame([
                'rules' => [
                    'validation' => false,
                    'redos' => true,
                    'optimization' => false,
                ],
                'redosMode' => 'confirmed',
                'redosThreshold' => 'critical',
                'redosNoJit' => true,
                'minSavings' => 3,
                'optimizations' => [
                    'digits' => false,
                    'word' => true,
                    'ranges' => false,
                    'canonicalizeCharClasses' => true,
                    'possessive' => true,
                    'factorize' => true,
                    'verifyWithAutomata' => false,
                    'minQuantifierCount' => 5,
                ],
            ], $result->config);
        } finally {
            chdir($cwd);
            @unlink($configPath);
            @rmdir($tempDir);
        }
    }
}
