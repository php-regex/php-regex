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
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * php-regex.extraction.{interop,functions} name the calls regex:lint reads,
 * in the shape of regex.json's "extraction" (the bridge does not read
 * regex.json). A published file that sets one key only keeps the default of
 * the other: mergeConfigFrom() fills top-level keys only.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
#[WithConfig('php-regex.runtime_pcre_validation', false)]
final class ExtractionConfigTest extends TestCase
{
    use TemporaryProject;

    private const CALLS = <<<'PHP'
        <?php

        namespace App;

        use Nette\Utils\Strings;
        use Webmozart\Assert\Assert;

        function check(string $s): void
        {
            preg_match('/native/', $s);
            \Composer\Pcre\Preg::match('/composer/', $s);
            Strings::match($s, '/nette/');
            Assert::regex($s, '/webmozart/');
            grep([], '/custom/');
        }

        PHP;

    #[Test]
    public function test_the_published_config_holds_the_extraction_keys(): void
    {
        $published = require __DIR__.'/../../../../src/Laravel/config/php-regex.php';

        $this->assertIsArray($published);
        $this->assertIsArray($published['extraction'] ?? null);
        $this->assertSame(['interop', 'functions'], array_keys($published['extraction']));
        $this->assertSame([], $published['extraction']['functions']);
    }

    /**
     * Without nikic/php-parser, the tokenizer reads the same calls.
     */
    #[Test]
    public function test_the_token_strategy_is_built_without_php_parser(): void
    {
        $this->assertNotNull($this->app);
        LintFunctionOverrides::queueClassExists(false);

        try {
            $strategy = $this->app->make('php-regex.extractor.strategy');
        } finally {
            LintFunctionOverrides::reset();
        }

        $this->assertInstanceOf(TokenBasedExtractionStrategy::class, $strategy);
    }

    #[Test]
    public function test_no_extraction_config_reads_every_preset(): void
    {
        config(['php-regex.extraction' => null]);

        $this->assertSame(['/native/', '/composer/', '/nette/', '/webmozart/'], $this->patterns());
    }

    #[Test]
    public function test_interop_alone_keeps_the_default_functions(): void
    {
        config(['php-regex.extraction' => ['interop' => ['nette-utils']]]);

        $this->assertSame(['/native/', '/nette/'], $this->patterns());
    }

    #[Test]
    public function test_functions_alone_keeps_every_preset(): void
    {
        config(['php-regex.extraction' => ['functions' => ['App\grep#1']]]);

        $this->assertSame(['/native/', '/composer/', '/nette/', '/webmozart/', '/custom/'], $this->patterns());
    }

    #[Test]
    public function test_an_empty_interop_reads_the_native_calls_only(): void
    {
        config(['php-regex.extraction' => ['interop' => [], 'functions' => []]]);

        $this->assertSame(['/native/'], $this->patterns());
    }

    /**
     * An unknown preset is a warning in the command's output, the name
     * ignored; the run goes on and reads the presets it knows.
     */
    #[Test]
    public function test_an_unknown_preset_warns_and_the_lint_goes_on(): void
    {
        $project = $this->makeProject(['app/Check.php' => "<?php\n\nNette\\Utils\\Strings::match(\$s, '/(nette/');\n"]);
        $this->app?->setBasePath($project);
        config(['php-regex.extraction' => ['interop' => ['laravel-str', 'nette-utils']]]);

        $status = Artisan::call('regex:lint', [
            'paths' => [$project.'/app'],
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $status, $output);
        $this->assertStringContainsString('Unknown extraction preset "laravel-str", ignored.', $output);
        $this->assertStringContainsString('/(nette/', $output);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        $project = $this->makeProject(['app/Check.php' => self::CALLS]);
        $strategy = $this->app?->make('php-regex.extractor.strategy');
        $this->assertInstanceOf(ExtractorInterface::class, $strategy);

        return array_values(array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->pattern,
            $strategy->extract([$project.'/app/Check.php']),
        ));
    }
}
