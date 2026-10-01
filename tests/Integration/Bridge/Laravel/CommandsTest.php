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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Laravel\Facades\Regex;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;

/**
 * Tests for Laravel Artisan commands.
 */
final class CommandsTest extends TestCase
{
    public function test_explain_command_explains_pattern(): void
    {
        $this->artisan('regex:explain', ['pattern' => '/^[a-z]+$/'])
            ->assertSuccessful()
            ->expectsOutputToContain('Pattern Explanation')
            ->expectsOutputToContain('Pattern:')
            ->expectsOutputToContain('Explanation:');
    }

    public function test_explain_command_fails_on_invalid_pattern(): void
    {
        $this->artisan('regex:explain', ['pattern' => '/^(unclosed/'])
            ->assertFailed()
            ->expectsOutputToContain('Invalid regex pattern');
    }

    public function test_compare_command_detects_equivalent_patterns(): void
    {
        $this->artisan('regex:compare', [
            'pattern1' => '/[0-9]+/',
            'pattern2' => '/\\d+/',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('EQUIVALENT');
    }

    public function test_compare_command_detects_non_equivalent_patterns(): void
    {
        $this->artisan('regex:compare', [
            'pattern1' => '/[a-z]+/',
            'pattern2' => '/[A-Z]+/',
        ])
            ->assertFailed()
            ->expectsOutputToContain('NOT EQUIVALENT');
    }

    public function test_compare_command_shows_counterexample(): void
    {
        $this->artisan('regex:compare', [
            'pattern1' => '/[a-z]+/',
            'pattern2' => '/[A-Z]+/',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Counterexample');
    }

    public function test_compare_command_json_output(): void
    {
        $this->artisan('regex:compare', [
            'pattern1' => '/[0-9]+/',
            'pattern2' => '/\\d+/',
            '--format' => 'json',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"equivalent": true');
    }

    public function test_compare_command_fails_on_invalid_pattern(): void
    {
        $this->artisan('regex:compare', [
            'pattern1' => '/^(unclosed/',
            'pattern2' => '/valid/',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Invalid first pattern');
    }

    public function test_transpile_command_transpiles_to_javascript(): void
    {
        $this->artisan('regex:transpile', [
            'pattern' => '/^[a-z]+$/',
            '--target' => 'javascript',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Pattern Transpilation')
            ->expectsOutputToContain('Target (Javascript)');
    }

    public function test_transpile_command_json_output(): void
    {
        $this->artisan('regex:transpile', [
            'pattern' => '/^[a-z]+$/',
            '--target' => 'python',
            '--format' => 'json',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"target": "python"');
    }

    public function test_transpile_command_fails_on_invalid_target(): void
    {
        $this->artisan('regex:transpile', [
            'pattern' => '/test/',
            '--target' => 'invalid',
        ])
            ->assertFailed()
            ->expectsOutputToContain('Invalid target');
    }

    public function test_routes_command_shows_routes_with_constraints(): void
    {
        // Define a route with a constraint
        Route::get('/users/{id}', static fn () => 'test')
            ->where('id', '[0-9]+')
            ->name('users.show');

        $this->artisan('regex:routes', ['--show-constraints' => true])
            ->assertSuccessful();
    }

    public function test_routes_command_validates_constraints(): void
    {
        // Define a route with a valid constraint
        Route::get('/posts/{slug}', static fn () => 'test')
            ->where('slug', '[a-z0-9-]+')
            ->name('posts.show');

        $this->artisan('regex:routes', ['--validate' => true])
            ->assertSuccessful();
    }

    public function test_routes_command_json_output(): void
    {
        Route::get('/test/{id}', static fn () => 'test')
            ->where('id', '[0-9]+')
            ->name('test.route');

        $this->artisan('regex:routes', ['--format' => 'json'])
            ->assertSuccessful()
            ->expectsOutputToContain('"routes"');
    }

    public function test_lint_command_runs_without_errors(): void
    {
        // Create a temporary directory with a PHP file containing regex
        $tempDir = sys_get_temp_dir().'/regex_lint_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/test.php', <<<'PHP'
            <?php
            preg_match('/^[a-z]+$/', 'test');
            PHP);

        $this->artisan('regex:lint', [
            'paths' => [$tempDir],
            '--format' => 'json',
        ])
            ->assertSuccessful();

        // Clean up
        unlink($tempDir.'/test.php');
        rmdir($tempDir);
    }

    public function test_lint_command_detects_invalid_patterns(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_lint_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/invalid.php', <<<'PHP'
            <?php
            preg_match('/^(unclosed/', 'test');
            PHP);

        $this->artisan('regex:lint', [
            'paths' => [$tempDir],
            '--format' => 'json',
        ])
            ->assertFailed();

        // Clean up
        unlink($tempDir.'/invalid.php');
        rmdir($tempDir);
    }

    public function test_lint_command_supports_exclude_option(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_lint_test_'.uniqid();
        mkdir($tempDir.'/src', 0o777, true);
        mkdir($tempDir.'/vendor', 0o777, true);

        file_put_contents($tempDir.'/src/test.php', <<<'PHP'
            <?php
            preg_match('/^[a-z]+$/', 'test');
            PHP);

        file_put_contents($tempDir.'/vendor/test.php', <<<'PHP'
            <?php
            preg_match('/^(unclosed/', 'test');
            PHP);

        // Should pass because vendor is excluded
        $this->artisan('regex:lint', [
            'paths' => [$tempDir],
            '--exclude' => ['vendor'],
            '--format' => 'json',
        ])
            ->assertSuccessful();

        // Clean up
        unlink($tempDir.'/src/test.php');
        unlink($tempDir.'/vendor/test.php');
        rmdir($tempDir.'/src');
        rmdir($tempDir.'/vendor');
        rmdir($tempDir);
    }

    /**
     * Get package providers.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PHPRegexServiceProvider::class,
        ];
    }

    /**
     * Get package aliases.
     *
     * @param \Illuminate\Foundation\Application $app
     *
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Regex' => Regex::class,
        ];
    }

    /**
     * Define environment setup.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('php-regex.cache.directory', null);
        $app['config']->set('php-regex.cache.store', null);
        $app['config']->set('php-regex.runtime_pcre_validation', false);
    }
}
