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
 * regex:lint with "redos.enabled" reports the ReDoS issues: 1.x built the
 * analysis with ReDoS on, then sent a lint request that never asked for it,
 * and the issues were dropped.
 */
final class LintCommandRedosTest extends TestCase
{
    use TemporaryProject;

    private const VULNERABLE_FILE = "<?php\n\npreg_match('/(a+)+\$/', \$subject);\n";

    #[Test]
    public function test_lint_with_redos_enabled_reports_the_redos_issue(): void
    {
        $this->app?->make('config')->set('php-regex.redos.enabled', true);

        $this->assertContains('regex.lint.redos', $this->lintIssueIds());
    }

    #[Test]
    public function test_lint_with_redos_disabled_reports_no_redos_issue(): void
    {
        $this->app?->make('config')->set('php-regex.redos.enabled', false);

        $this->assertNotContains('regex.lint.redos', $this->lintIssueIds());
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

    /**
     * @return list<string>
     */
    private function lintIssueIds(): array
    {
        $project = $this->makeProject(['src/Pattern.php' => self::VULNERABLE_FILE]);

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
