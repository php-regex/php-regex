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
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint reads the functions marked #[RegexPattern] in the configured
 * paths and in the application's vendor/, whatever paths it lints.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
#[WithConfig('php-regex.runtime_pcre_validation', false)]
final class LintCommandDeclarationPathsTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_lint_of_one_file_reads_declarations_of_the_app_and_of_vendor(): void
    {
        $project = $this->makeProject([
            'app/Support/helper.php' => "<?php\n\nnamespace App;\n\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\n\nfunction grep(#[RegexPattern] string \$regex): void {}\n",
            'app/caller.php' => "<?php\n\n\\App\\grep('/b02(/');\n\\Acme\\search('/c03(/');\n",
            'vendor/acme/search/helper.php' => "<?php\n\nnamespace Acme;\n\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\n\nfunction search(#[RegexPattern] string \$regex): void {}\n",
        ]);
        $this->app?->setBasePath($project);
        config(['php-regex.paths' => [$project.'/app']]);

        $status = Artisan::call('regex:lint', [
            'paths' => [$project.'/app/caller.php'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);

        $this->assertSame(1, $status);
        $patterns = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument(Artisan::output())['results'] ?? null) as $result) {
            $patterns[] = JsonContract::asArray($result)['pattern'] ?? null;
        }
        sort($patterns);
        $this->assertSame(['/b02(/', '/c03(/'], $patterns);
    }

    /**
     * A php-regex.paths setting that is no list, null or a single path, does
     * not break the run.
     */
    #[Test]
    #[DataProvider('provideOddPathSettings')]
    public function test_lint_runs_whatever_the_paths_setting_holds(mixed $setting, int $expected): void
    {
        $project = $this->makeProject([
            'app/Support/helper.php' => "<?php\n\nnamespace App;\n\nuse PHPRegex\\Parser\\Attribute\\RegexPattern;\n\nfunction grep(#[RegexPattern] string \$regex): void {}\n",
            'app/caller.php' => "<?php\n\n\\App\\grep('/b02(/');\n",
        ]);
        $this->app?->setBasePath($project);
        config(['php-regex.paths' => \is_string($setting) ? $project.'/'.$setting : $setting]);

        $status = Artisan::call('regex:lint', [
            'paths' => [$project.'/app/caller.php'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);

        $this->assertSame($expected, $status);
        $this->assertCount($expected, JsonContract::asArray(JsonContract::decodeDocument(Artisan::output())['results'] ?? null));
    }

    /**
     * @return iterable<string, array{setting: mixed, expected: int}>
     */
    public static function provideOddPathSettings(): iterable
    {
        yield 'null' => ['setting' => null, 'expected' => 0];
        yield 'a single path' => ['setting' => 'app', 'expected' => 1];
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
