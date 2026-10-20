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

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\WithConfig;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * A path regex:lint is given that does not exist stops the run with exit
 * code 2, as `regex lint` stops: a typo in a CI job is never a green run.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
#[WithConfig('php-regex.runtime_pcre_validation', false)]
final class LintCommandMissingPathTest extends LaravelTestCase
{
    use TemporaryProject;

    #[Test]
    public function test_a_missing_path_on_the_command_line_is_a_usage_error(): void
    {
        $status = Artisan::call('regex:lint', ['paths' => [__DIR__, 'does-not-exist'], '--no-routes' => true, '--no-validators' => true]);

        $this->assertSame(2, $status);
        $this->assertStringContainsString('Path not found: does-not-exist', Artisan::output());
        $this->assertStringNotContainsString('PASS', Artisan::output());
    }

    #[Test]
    public function test_a_missing_path_on_the_command_line_prints_the_usage_envelope(): void
    {
        $status = Artisan::call('regex:lint', ['paths' => ['does-not-exist'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true]);

        $this->assertSame(2, $status);
        $this->assertSame(['error' => 'Path not found: does-not-exist', 'stage' => 'usage'], JsonContract::decodeDocument(Artisan::output()));
    }

    #[Test]
    public function test_a_missing_configured_path_is_a_configuration_error_naming_the_setting(): void
    {
        $project = $this->makeProject(['app/.keep' => '']);
        $this->application()->setBasePath($project);
        config(['php-regex.paths' => ['app', 'lib']]);

        $status = Artisan::call('regex:lint', ['--format' => 'json', '--no-routes' => true, '--no-validators' => true]);

        $this->assertSame(2, $status);
        $this->assertSame(['error' => 'Path not found: '.$project.'/lib (from php-regex.paths)', 'stage' => 'config'], JsonContract::decodeDocument(Artisan::output()));
    }

    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
