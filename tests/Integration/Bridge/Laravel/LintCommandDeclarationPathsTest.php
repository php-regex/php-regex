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
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
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
     * With no php-regex.paths, the declarations are read in the linted
     * paths, as `regex lint` reads them; vendor/ is read apart.
     *
     * @param list<string>|null $setting
     */
    #[Test]
    #[DataProvider('provideNoPathSettings')]
    public function test_lint_reads_declarations_in_the_linted_paths_when_no_path_is_configured(?array $setting): void
    {
        $project = $this->makeProject(['app/caller.php' => "<?php\n"]);
        $this->app?->setBasePath($project);
        config(['php-regex.paths' => $setting]);
        $source = new class implements PatternSourceInterface {
            public ?PatternSourceContext $context = null;

            public function getName(): string
            {
                return 'recording';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                $this->context = $context;

                return [];
            }
        };
        $analysis = $this->app?->make('php-regex.analysis');
        $this->assertInstanceOf(AnalysisService::class, $analysis);
        $this->app?->instance('php-regex.lint', new LintService($analysis, new PatternSourceCollection([$source])));

        Artisan::call('regex:lint', [
            'paths' => [$project.'/app'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);

        $this->assertSame([$project.'/app'], $source->context?->declarationPaths);
        $this->assertSame([$project.'/vendor'], $source->context?->vendorPaths);
    }

    /**
     * @return iterable<string, array{setting: list<string>|null}>
     */
    public static function provideNoPathSettings(): iterable
    {
        yield 'null' => ['setting' => null];
        yield 'an empty list' => ['setting' => []];
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
