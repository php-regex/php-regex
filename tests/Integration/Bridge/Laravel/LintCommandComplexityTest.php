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
use Orchestra\Testbench\TestCase;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint reports a pattern at or above "analysis.warning_threshold" once
 * "analysis.complexity" turns the report on, as `regex lint` does under
 * "checks.complexity"; off, the threshold alone reports nothing.
 */
final class LintCommandComplexityTest extends TestCase
{
    use TemporaryProject;

    // Complexity score 15.
    private const COMPLEX_FILE = "<?php\n\npreg_match('/(a|b)+c/', \$subject);\n";

    #[Test]
    public function test_lint_with_complexity_on_reports_a_pattern_at_the_threshold(): void
    {
        $this->configure(true, 15);

        $this->assertContains('regex.lint.complexity', $this->lintIssueIds());
    }

    #[Test]
    public function test_lint_with_complexity_on_spares_a_pattern_below_the_threshold(): void
    {
        $this->configure(true, 16);

        $this->assertNotContains('regex.lint.complexity', $this->lintIssueIds());
    }

    #[Test]
    public function test_lint_with_complexity_off_reports_nothing_whatever_the_threshold(): void
    {
        $this->configure(false, 1);

        $this->assertNotContains('regex.lint.complexity', $this->lintIssueIds());
    }

    #[Test]
    public function test_complexity_is_off_by_default(): void
    {
        $this->assertFalse(config('php-regex.analysis.complexity'));
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('php-regex.cache.directory', null);
        $app['config']->set('php-regex.cache.store', null);
        $app['config']->set('php-regex.runtime_pcre_validation', false);
    }

    private function configure(bool $complexity, int $threshold): void
    {
        $config = $this->app?->make('config');
        $config?->set('php-regex.analysis.complexity', $complexity);
        $config?->set('php-regex.analysis.warning_threshold', $threshold);
    }

    /**
     * @return list<string>
     */
    private function lintIssueIds(): array
    {
        $project = $this->makeProject(['src/Pattern.php' => self::COMPLEX_FILE]);

        Artisan::call('regex:lint', [
            'paths' => [$project.'/src'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);
        $output = Artisan::output();

        $payload = json_decode($output, true);
        $this->assertIsArray($payload, 'output is not JSON: '.$output);
        $this->assertIsArray($payload['results'] ?? null);

        $ids = [];
        foreach ($payload['results'] as $result) {
            $this->assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                $this->assertIsArray($issue);
                $ids[] = (string) ($issue['issue_id'] ?? '');
            }
        }

        return $ids;
    }
}
