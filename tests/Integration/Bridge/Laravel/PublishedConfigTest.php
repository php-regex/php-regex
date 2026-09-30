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

namespace RegexParser\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use RegexParser\Bridge\Laravel\RegexParserServiceProvider;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexLintService;
use RegexParser\Regex;

/**
 * A config/regex-parser.php published from an older release: sections the
 * app kept but that lack keys added since, and a key 2.0 removed. The
 * provider falls back to the package default for every missing nested key
 * (Laravel's mergeConfigFrom() only fills top-level keys), and the stale key
 * is reported by regex:lint, never while the app boots.
 *
 * The attribute is not deferred: the config is in place before the provider
 * registers, as a published file is.
 */
#[WithConfig('regex-parser', [
    'max_pattern_length' => 1000,
    'runtime_pcre_validation' => false,
    'cache' => ['store' => null, 'directory' => null],
    'redos' => ['enabled' => true],
    'analysis' => ['redos_threshold' => 100],
    'automata' => ['minimization_algorithm' => 'moore'],
    'optimizations' => ['digits' => false],
    'paths' => ['app'],
], defer: false)]
final class PublishedConfigTest extends TestCase
{
    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir().'/regex-parser-laravel-published-'.bin2hex(random_bytes(6));
        mkdir($this->sourceDir, 0o700, true);
        file_put_contents($this->sourceDir.'/Pattern.php', "<?php\n\npreg_match('/^[a-z]+\$/', \$subject);\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->sourceDir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->sourceDir);

        parent::tearDown();
    }

    #[Test]
    public function test_every_missing_nested_key_falls_back_to_its_default(): void
    {
        // What the app wrote stays.
        $this->assertSame(1000, config('regex-parser.max_pattern_length'));
        $this->assertTrue(config('regex-parser.redos.enabled'));
        $this->assertSame('moore', config('regex-parser.automata.minimization_algorithm'));
        $this->assertFalse(config('regex-parser.optimizations.digits'));

        // What it lacks comes from the package.
        $this->assertSame('high', config('regex-parser.redos.threshold'));
        $this->assertSame([], config('regex-parser.redos.ignored_patterns'));
        $this->assertSame(50, config('regex-parser.analysis.warning_threshold'));
        $this->assertSame('subset-indexed', config('regex-parser.automata.determinization_algorithm'));
        $this->assertSame('regex_', config('regex-parser.cache.prefix'));
        $this->assertTrue(config('regex-parser.optimizations.word'));
        $this->assertSame(4, config('regex-parser.optimizations.min_quantifier_count'));
        $this->assertSame(Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH, config('regex-parser.max_lookbehind_length'));
        $this->assertNull(config('regex-parser.php_version'));
    }

    #[Test]
    public function test_the_services_resolve_from_a_partial_config(): void
    {
        $this->assertInstanceOf(Regex::class, $this->app?->make(Regex::class));
        $this->assertInstanceOf(RegexAnalysisService::class, $this->app?->make('regex-parser.analysis'));
        $this->assertInstanceOf(RegexLintService::class, $this->app?->make('regex-parser.lint'));
    }

    #[Test]
    public function test_the_compare_command_takes_the_missing_default(): void
    {
        $status = Artisan::call('regex:compare', ['pattern1' => '/a/', 'pattern2' => '/a/', '--format' => 'json']);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(0, $status);
        $this->assertIsArray($json);
        $this->assertSame(['minimizer' => 'moore', 'determinizer' => 'subset-indexed'], $json['algorithms'] ?? null);
    }

    #[Test]
    public function test_the_lint_command_reports_the_stale_key(): void
    {
        $status = Artisan::call('regex:lint', [
            'paths' => [$this->sourceDir],
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);
        $output = Artisan::output();

        // A warning, not a failure: the pattern is fine.
        $this->assertSame(0, $status, $output);
        $this->assertStringContainsString('analysis.redos_threshold', $output);
    }

    #[Test]
    public function test_the_stale_key_is_not_reported_outside_the_lint_command(): void
    {
        Artisan::call('regex:explain', ['pattern' => '/^[a-z]+$/']);

        $this->assertStringNotContainsString('redos_threshold', Artisan::output());
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RegexParserServiceProvider::class];
    }
}
