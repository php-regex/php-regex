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

use PHPRegex\Laravel\Extractor\RoutePatternSource;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Laravel\Facades\Regex;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for Laravel pattern extractors.
 */
final class ExtractorsTest extends TestCase
{
    public function test_route_extractor_extracts_where_constraints(): void
    {
        // Define routes with constraints
        Route::get('/users/{id}', static fn () => 'test')
            ->where('id', '[0-9]+')
            ->name('users.show');

        Route::get('/posts/{slug}', static fn () => 'test')
            ->where('slug', '[a-z0-9-]+')
            ->name('posts.show');

        /** @var Router $router */
        $router = $this->app->make('router');
        $extractor = new RoutePatternSource($router);

        $context = new PatternSourceContext(
            paths: [base_path('app')],
            excludePaths: [],
        );

        $patterns = $extractor->extract($context);

        $this->assertNotEmpty($patterns);
        $this->assertContainsOnlyInstancesOf(PatternOccurrence::class, $patterns);

        // Check that our patterns were extracted
        $extractedPatterns = array_map(
            static fn (PatternOccurrence $p): string => $p->displayPattern ?? $p->pattern,
            $patterns,
        );

        $this->assertContains('[0-9]+', $extractedPatterns);
        $this->assertContains('[a-z0-9-]+', $extractedPatterns);
    }

    public function test_route_extractor_normalizes_patterns(): void
    {
        Route::get('/test/{id}', static fn () => 'test')
            ->where('id', '[0-9]+')
            ->name('test.route');

        /** @var Router $router */
        $router = $this->app->make('router');
        $extractor = new RoutePatternSource($router);

        $context = new PatternSourceContext(
            paths: [base_path('app')],
            excludePaths: [],
        );

        $patterns = $extractor->extract($context);

        // Find the pattern we just added
        $testPattern = null;
        foreach ($patterns as $pattern) {
            if (str_contains($pattern->source, 'test.route')) {
                $testPattern = $pattern;

                break;
            }
        }

        $this->assertNotNull($testPattern);
        // Pattern should be normalized with delimiters
        $this->assertMatchesRegularExpression('/^[\/\#\~\!\@\%\`]/', $testPattern->pattern);
    }

    public function test_route_extractor_includes_context_info(): void
    {
        Route::get('/api/items/{id}', static fn () => 'test')
            ->where('id', '\\d+')
            ->name('api.items.show');

        /** @var Router $router */
        $router = $this->app->make('router');
        $extractor = new RoutePatternSource($router);

        $context = new PatternSourceContext(
            paths: [base_path('app')],
            excludePaths: [],
        );

        $patterns = $extractor->extract($context);

        $apiPattern = null;
        foreach ($patterns as $pattern) {
            if (str_contains($pattern->source, 'api.items.show')) {
                $apiPattern = $pattern;

                break;
            }
        }

        $this->assertNotNull($apiPattern);
        $this->assertStringContainsString('route:', $apiPattern->source);
        $this->assertStringContainsString(':id', $apiPattern->source);
    }

    public function test_validation_extractor_extracts_regex_rules(): void
    {
        // Create a temporary PHP file with validation regex
        $tempDir = sys_get_temp_dir().'/regex_validation_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/TestRequest.php', <<<'PHP'
            <?php
            namespace App\Http\Requests;

            class TestRequest
            {
                public function rules(): array
                {
                    return [
                        'email' => ['required', 'regex:/^[a-z@.]+$/'],
                        'phone' => ['required', 'regex:/^\d{10}$/'],
                        'code' => ['nullable', 'not_regex:/[^a-zA-Z0-9]/'],
                    ];
                }
            }
            PHP);

        $extractor = new ValidationRulePatternSource();

        $context = new PatternSourceContext(
            paths: [$tempDir],
            excludePaths: [],
        );

        $patterns = $extractor->extract($context);

        $this->assertCount(3, $patterns);
        $this->assertContainsOnlyInstancesOf(PatternOccurrence::class, $patterns);

        $extractedPatterns = array_map(
            static fn (PatternOccurrence $p): string => $p->displayPattern ?? $p->pattern,
            $patterns,
        );

        $this->assertContains('/^[a-z@.]+$/', $extractedPatterns);
        $this->assertContains('/^\d{10}$/', $extractedPatterns);
        $this->assertContains('/[^a-zA-Z0-9]/', $extractedPatterns);

        // Clean up
        unlink($tempDir.'/TestRequest.php');
        rmdir($tempDir);
    }

    public function test_validation_extractor_handles_quotes_inside_pattern(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_quote_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/test.php', <<<'PHP'
            <?php
            $rules = [
                'no_double_quotes' => 'regex:/^[^"]+$/',
                'no_single_quotes' => "regex:/^[^']+$/",
                'escaped_quote' => 'regex:/^[^\']+$/',
            ];
            PHP);

        $extractor = new ValidationRulePatternSource();

        $context = new PatternSourceContext(
            paths: [$tempDir],
            excludePaths: [],
        );

        $patterns = $extractor->extract($context);

        $extracted = array_map(
            static fn (PatternOccurrence $p): string => $p->displayPattern ?? $p->pattern,
            $patterns,
        );

        // The other quote character (raw or escaped) must not truncate the pattern.
        $this->assertContains('/^[^"]+$/', $extracted);
        $this->assertContains("/^[^']+\$/", $extracted);
        $this->assertCount(3, $patterns);

        unlink($tempDir.'/test.php');
        rmdir($tempDir);
    }

    public function test_validation_extractor_handles_not_regex_rules(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_not_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/test.php', <<<'PHP'
            <?php
            $rules = [
                'name' => 'not_regex:/[<>]/',
            ];
            PHP);

        $extractor = new ValidationRulePatternSource();

        $context = new PatternSourceContext(
            paths: [$tempDir],
            excludePaths: [],
        );

        $patterns = $extractor->extract($context);

        $this->assertCount(1, $patterns);
        $this->assertStringContainsString('not_regex', $patterns[0]->source);

        // Clean up
        unlink($tempDir.'/test.php');
        rmdir($tempDir);
    }

    public function test_validation_extractor_includes_line_numbers(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_line_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/test.php', <<<'PHP'
            <?php
            // Line 2
            // Line 3
            // Line 4
            $rules = ['email' => 'regex:/^test$/'];
            PHP);

        $extractor = new ValidationRulePatternSource();

        $context = new PatternSourceContext(
            paths: [$tempDir],
            excludePaths: [],
        );

        $patterns = $extractor->extract($context);

        $this->assertCount(1, $patterns);
        $this->assertSame(5, $patterns[0]->line);

        // Clean up
        unlink($tempDir.'/test.php');
        rmdir($tempDir);
    }

    public function test_validation_extractor_respects_exclude_paths(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_exclude_test_'.uniqid();
        mkdir($tempDir.'/app', 0o777, true);
        mkdir($tempDir.'/vendor', 0o777, true);

        file_put_contents($tempDir.'/app/test.php', <<<'PHP'
            <?php
            $rules = ['field' => 'regex:/^included$/'];
            PHP);

        file_put_contents($tempDir.'/vendor/test.php', <<<'PHP'
            <?php
            $rules = ['field' => 'regex:/^excluded$/'];
            PHP);

        $extractor = new ValidationRulePatternSource();

        $context = new PatternSourceContext(
            paths: [$tempDir.'/app', $tempDir.'/vendor'],
            excludePaths: ['vendor'],
        );

        $patterns = $extractor->extract($context);

        $extractedPatterns = array_map(
            static fn (PatternOccurrence $p): string => $p->displayPattern ?? $p->pattern,
            $patterns,
        );

        $this->assertContains('/^included$/', $extractedPatterns);
        $this->assertNotContains('/^excluded$/', $extractedPatterns);

        // Clean up
        unlink($tempDir.'/app/test.php');
        unlink($tempDir.'/vendor/test.php');
        rmdir($tempDir.'/app');
        rmdir($tempDir.'/vendor');
        rmdir($tempDir);
    }

    /**
     * A rule whose pattern is only white space gives no pattern to lint;
     * the rule after it on the same line is still read.
     */
    #[Test]
    public function test_validation_extractor_skips_a_rule_with_a_blank_pattern(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_blank_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/test.php', <<<'PHP'
            <?php
            $rules = ['blank' => 'regex:   ', 'kept' => 'not_regex: /^kept$/ '];
            PHP);

        try {
            $patterns = (new ValidationRulePatternSource())->extract(new PatternSourceContext(paths: [$tempDir], excludePaths: []));
        } finally {
            unlink($tempDir.'/test.php');
            rmdir($tempDir);
        }

        $this->assertSame(
            [['/^kept$/', 2, 'validation:not_regex']],
            array_map(static fn (PatternOccurrence $p): array => [$p->pattern, $p->line, $p->source], $patterns),
        );
    }

    /**
     * Each rule is read with the quote that opened its literal: an escaped
     * quote of that kind is the quote itself, as PHP reads the literal. The
     * rule kind does not depend on the quote, and the line is the one the
     * rule is on, counted from the first byte of the file: a blank line
     * before "<?php" counts, and the lines after the rule do not.
     */
    #[Test]
    public function test_validation_extractor_reads_each_rule_with_its_quote_kind_and_line(): void
    {
        $tempDir = sys_get_temp_dir().'/regex_quote_kind_test_'.uniqid();
        mkdir($tempDir, 0o777, true);

        file_put_contents($tempDir.'/test.php', "\n".<<<'PHP'
            <?php
            $rules = [
                'single' => 'regex:/^[^\']+$/',
                'double' => "regex:/^[^\"]+$/",
                'single_not' => 'not_regex:/^x$/',
                'double_not' => "not_regex:/^y$/",
            ];
            PHP);

        try {
            $patterns = (new ValidationRulePatternSource())->extract(new PatternSourceContext(paths: [$tempDir], excludePaths: []));
        } finally {
            unlink($tempDir.'/test.php');
            rmdir($tempDir);
        }

        $this->assertSame(
            [
                ["/^[^']+\$/", 4, 'validation:regex'],
                ['/^[^"]+$/', 5, 'validation:regex'],
                ['/^x$/', 6, 'validation:not_regex'],
                ['/^y$/', 7, 'validation:not_regex'],
            ],
            array_map(static fn (PatternOccurrence $p): array => [$p->pattern, $p->line, $p->source], $patterns),
        );
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
}
