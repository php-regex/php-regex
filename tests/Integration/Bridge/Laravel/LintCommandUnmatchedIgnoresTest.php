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
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint reports an inline marker that silences nothing as
 * regex.ignore.unmatched, as `regex lint` does, unless
 * "report_unmatched_ignores" turns the report off.
 */
final class LintCommandUnmatchedIgnoresTest extends LaravelTestCase
{
    use TemporaryProject;

    private const FILE = "<?php\n\npreg_match('/x/', \$subject); // @regex-ignore\npreg_match('/\\d+/i', \$subject); // @regex-ignore regex.lint.flag.useless.i\n";

    #[Test]
    public function test_the_report_is_on_by_default(): void
    {
        $this->assertTrue(config('php-regex.report_unmatched_ignores'));
        $this->assertSame([[3, 'regex.ignore.unmatched']], $this->lintIssues());
    }

    #[Test]
    public function test_the_setting_turns_the_report_off(): void
    {
        self::configOf($this->application())->set('php-regex.report_unmatched_ignores', false);

        $this->assertSame([], $this->lintIssues());
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
    }

    /**
     * @return list<array{mixed, mixed}>
     */
    private function lintIssues(): array
    {
        $project = $this->makeProject(['src/Pattern.php' => self::FILE]);

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

        $issues = [];
        foreach ($payload['results'] as $result) {
            $this->assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                $this->assertIsArray($issue);
                $issues[] = [$issue['line'] ?? null, $issue['issue_id'] ?? null];
            }
        }

        return $issues;
    }
}
