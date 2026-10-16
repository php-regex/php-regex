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
use Illuminate\Support\Facades\Route;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint judges a route constraint as the router matches it: anchored at
 * both ends, under the s, D and u flags the route compiler adds. Unanchored,
 * (a+)+ finds a match at once and its backtracking stayed hidden; and the
 * flags the router adds are no finding, since nobody can remove them.
 */
final class LintCommandRouteConstraintTest extends LaravelTestCase
{
    use TemporaryProject;

    #[Test]
    public function test_lint_reports_the_redos_of_an_anchored_route_constraint(): void
    {
        Route::get('/x/{v}', static fn (): string => '')->where('v', '(a+)+')->name('nested');

        $this->assertContains('regex.lint.redos', $this->routeIssueIds('route:nested:v'));
    }

    #[Test]
    public function test_lint_reports_no_finding_on_the_flags_the_router_adds(): void
    {
        Route::get('/x/{id}', static fn (): string => '')->where('id', '\d+')->name('numeric');
        Route::get('/y/{v}', static fn (): string => '')->where('v', '[a-z]+')->domain('{account}.example.com')->where('account', '[0-9]+')->name('tenant');

        $ids = [...$this->routeIssueIds('route:numeric:id'), ...$this->routeIssueIds('route:tenant:account')];

        $this->assertSame([], array_values(array_filter($ids, static fn (string $id): bool => str_starts_with($id, 'regex.lint.flag.'))));
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
        self::configOf($app)->set('php-regex.cache.directory', null);
        self::configOf($app)->set('php-regex.cache.store', null);
        self::configOf($app)->set('php-regex.runtime_pcre_validation', false);
        self::configOf($app)->set('php-regex.redos.enabled', true);
    }

    /**
     * The issue ids of one route constraint; a constraint with nothing to
     * report has no result.
     *
     * @return list<string>
     */
    private function routeIssueIds(string $source): array
    {
        $project = $this->makeProject(['src/.keep' => '']);

        Artisan::call('regex:lint', [
            'paths' => [$project.'/src'],
            '--format' => 'json',
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
            if ($source !== ($result['source'] ?? null)) {
                continue;
            }
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                $this->assertIsArray($issue);
                $id = $issue['issue_id'] ?? '';
                $this->assertIsString($id);
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
