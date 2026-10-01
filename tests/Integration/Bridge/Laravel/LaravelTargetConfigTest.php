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

use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\RegexParser;
use Illuminate\Support\Facades\Artisan;
use Orchestra\Testbench\Attributes\WithConfig;
use Orchestra\Testbench\Attributes\WithEnv;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * config/php-regex.php in 2.0: runtime validation off unless asked,
 * whatever APP_DEBUG says; php_version / pcre_version judge the lint command
 * only, the Regex service keeps the running engine; one threshold reading;
 * the automata settings reach regex:compare.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
final class LaravelTargetConfigTest extends TestCase
{
    /**
     * Valid from PCRE2 10.43 (variable-length lookbehind, PcreFeature::
     * VariableLengthLookbehind), refused by 10.40, the release PHP 8.2
     * bundles. preg_match() on this PHP (PCRE2 10.49) compiles it.
     */
    private const NEWER_ENGINE_ONLY = '/(?<=a{1,2})x/';

    private string $sourceDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sourceDir = sys_get_temp_dir().'/regex-parser-laravel-'.bin2hex(random_bytes(6));
        mkdir($this->sourceDir, 0o700, true);
        file_put_contents($this->sourceDir.'/Pattern.php', "<?php\n\npreg_match('".self::NEWER_ENGINE_ONLY."', \$subject);\n");
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
    #[WithEnv('APP_DEBUG', 'true')]
    public function test_runtime_pcre_validation_is_off_in_debug(): void
    {
        // 1.x read APP_DEBUG: a debug app compiled every pattern twice.
        $this->assertTrue((bool) config('app.debug'), 'The test needs a debug app to mean anything.');

        $this->assertFalse(config('php-regex.runtime_pcre_validation'));
        $this->assertFalse($this->runtimeValidationOf($this->regexService()->parser()));
    }

    #[Test]
    public function test_php_and_pcre_versions_default_to_null(): void
    {
        $this->assertTrue(config()->has('php-regex.php_version'));
        $this->assertTrue(config()->has('php-regex.pcre_version'));
        $this->assertNull(config('php-regex.php_version'));
        $this->assertNull(config('php-regex.pcre_version'));
    }

    #[Test]
    #[WithConfig('php-regex.php_version', '8.2')]
    #[WithConfig('php-regex.pcre_version', '10.40')]
    public function test_php_version_leaves_the_regex_service_on_the_running_engine(): void
    {
        $this->assertTrue($this->regexService()->target()->isRunningEngine());
    }

    #[Test]
    #[WithConfig('php-regex.php_version', '8.2')]
    public function test_php_version_drives_the_lint_command_target(): void
    {
        [$status, $json] = $this->lintJson();

        $this->assertSame(1, $status);
        $this->assertSame('8.2', $json['target']['php'] ?? null);
        $this->assertSame('10.40', $json['target']['pcre'] ?? null);
        $this->assertSame(1, $json['stats']['errors'] ?? null, 'PHP 8.2 bundles PCRE2 10.40, which refuses a variable-length lookbehind.');
    }

    #[Test]
    #[WithConfig('php-regex.pcre_version', '10.42')]
    public function test_pcre_version_alone_drives_the_lint_command_target(): void
    {
        [, $json] = $this->lintJson();

        $this->assertSame('10.42', $json['target']['pcre'] ?? null);
        $this->assertSame(1, $json['stats']['errors'] ?? null);
    }

    #[Test]
    #[WithConfig('php-regex.runtime_pcre_validation', true)]
    #[WithConfig('php-regex.php_version', '8.2')]
    public function test_the_lint_command_does_not_inherit_runtime_validation(): void
    {
        // Runtime validation compiles with the running PHP, which cannot
        // judge PHP 8.2: the Regex service keeps it, the lint does not.
        $this->assertTrue($this->runtimeValidationOf($this->regexService()->parser()));

        [$status, $json] = $this->lintJson();

        $this->assertSame(1, $status);
        $this->assertSame('8.2', $json['target']['php'] ?? null);
        $this->assertSame(1, $json['stats']['errors'] ?? null);
    }

    #[Test]
    #[WithConfig('php-regex.redos.threshold', 'severe')]
    public function test_an_unknown_threshold_is_refused(): void
    {
        // 1.x read it as "high".
        $this->expectException(InvalidRegexOptionException::class);

        $this->app?->make('php-regex.analysis');
    }

    #[Test]
    #[WithConfig('php-regex.redos.threshold', 'safe')]
    public function test_safe_is_refused_as_threshold(): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        $this->app?->make('php-regex.analysis');
    }

    #[Test]
    #[WithConfig('php-regex.redos.threshold', 'CRITICAL')]
    public function test_an_upper_case_threshold_is_read(): void
    {
        $this->assertNotNull($this->app?->make('php-regex.analysis'));
    }

    #[Test]
    public function test_analysis_redos_threshold_is_gone_from_the_config(): void
    {
        $this->assertFalse(config()->has('php-regex.analysis.redos_threshold'));
        $this->assertSame(50, config('php-regex.analysis.warning_threshold'));
    }

    #[Test]
    #[WithConfig('php-regex.automata.minimization_algorithm', 'moore')]
    #[WithConfig('php-regex.automata.determinization_algorithm', 'subset')]
    public function test_the_automata_settings_are_the_compare_command_defaults(): void
    {
        $status = Artisan::call('regex:compare', ['pattern1' => '/[0-9]+/', 'pattern2' => '/\d+/', '--format' => 'json']);
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(0, $status);
        $this->assertIsArray($json);
        $this->assertSame(['minimizer' => 'moore', 'determinizer' => 'subset'], $json['algorithms'] ?? null);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    private function regexService(): Regex
    {
        $regex = $this->app?->make(Regex::class);
        $this->assertInstanceOf(Regex::class, $regex);

        return $regex;
    }

    private function runtimeValidationOf(RegexParser $parser): bool
    {
        $value = (new \ReflectionProperty(RegexParser::class, 'runtimePcreValidation'))->getValue($parser);
        $this->assertIsBool($value);

        return $value;
    }

    /**
     * @return array{int, array<string, mixed>}
     */
    private function lintJson(): array
    {
        $status = Artisan::call('regex:lint', [
            'paths' => [$this->sourceDir],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);
        $output = Artisan::output();
        $json = json_decode($output, true);
        $this->assertIsArray($json, 'regex:lint did not print JSON: '.$output);

        /** @var array<string, mixed> $json */
        return [$status, $json];
    }
}
