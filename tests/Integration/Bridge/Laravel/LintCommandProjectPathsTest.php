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
 * The paths and excludes of config/php-regex.php are read from the
 * application's base path, whatever directory regex:lint runs from; the
 * paths given on the command line are read from the working directory.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
#[WithConfig('php-regex.runtime_pcre_validation', false)]
final class LintCommandProjectPathsTest extends LaravelTestCase
{
    use TemporaryProject;

    private const INVALID = "<?php\n\npreg_match('/[a-z/', \$subject);\n";

    #[Test]
    public function test_configured_paths_are_read_from_the_base_path(): void
    {
        $project = $this->makeProject(['app/Bad.php' => self::INVALID]);
        $this->enterProject();

        [$status, $files] = $this->lint($project, ['app'], ['vendor'], []);

        $this->assertSame(1, $status);
        $this->assertSame([$project.'/app/Bad.php'], $files);
    }

    #[Test]
    public function test_configured_excludes_are_read_from_the_base_path(): void
    {
        $project = $this->makeProject([
            'app/Bad.php' => self::INVALID,
            'app/Legacy/Old.php' => self::INVALID,
            'app/Generated/Proxy.php' => self::INVALID,
        ]);
        $this->enterProject();

        [$status, $files] = $this->lint($project, ['app'], ['app/Legacy', 'Generated'], []);

        $this->assertSame(1, $status);
        $this->assertSame([$project.'/app/Bad.php'], $files);
    }

    /**
     * The default excludes name directories inside the application: one of
     * the same name above it, where Testbench's skeleton lives under
     * vendor/, excludes nothing.
     */
    #[Test]
    public function test_an_exclude_named_like_a_directory_above_the_base_path_excludes_nothing(): void
    {
        $root = $this->makeProject(['vendor/orchestra/laravel/app/Bad.php' => self::INVALID]);
        $project = $root.'/vendor/orchestra/laravel';
        $this->enterProject();

        [$status, $files] = $this->lint($project, ['app'], ['vendor', 'node_modules', 'storage'], []);

        $this->assertSame(1, $status);
        $this->assertSame([$project.'/app/Bad.php'], $files);
    }

    #[Test]
    public function test_a_path_given_on_the_command_line_is_read_from_the_working_directory(): void
    {
        $project = $this->makeProject(['app/Bad.php' => self::INVALID]);
        $this->enterProject(['lib/Other.php' => self::INVALID]);

        [$status, $files] = $this->lint($project, ['app'], ['vendor'], ['paths' => ['lib']]);

        $this->assertSame(1, $status);
        $this->assertSame(['lib/Other.php'], $files);
    }

    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @param list<string>         $paths
     * @param list<string>         $exclude
     * @param array<string, mixed> $arguments
     *
     * @return array{int, list<string>} the exit code and the files of the results
     */
    private function lint(string $project, array $paths, array $exclude, array $arguments): array
    {
        $this->application()->setBasePath($project);
        config(['php-regex.paths' => $paths, 'php-regex.exclude' => $exclude]);

        $status = Artisan::call('regex:lint', $arguments + [
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);

        $files = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument(Artisan::output())['results'] ?? null) as $result) {
            $file = JsonContract::asArray($result)['file'] ?? null;
            $this->assertIsString($file);
            $files[] = $file;
        }

        return [$status, $files];
    }
}
