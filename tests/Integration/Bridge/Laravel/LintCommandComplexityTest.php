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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint reports a pattern at or above "complexity.threshold" once
 * "complexity.enabled" turns the report on, as `regex lint` does under
 * "checks.complexity"; off, the threshold alone reports nothing.
 */
final class LintCommandComplexityTest extends LaravelTestCase
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
        $this->assertFalse(config('php-regex.complexity.enabled'));
        $this->assertSame(50, config('php-regex.complexity.threshold'));
        $this->assertFalse(config()->has('php-regex.analysis'));
    }

    /**
     * A threshold below 1, or no integer, stops the run as a setting it
     * cannot use, as regex.json's checks.complexity.threshold does; 1 is
     * the least it takes.
     *
     * @return iterable<string, array{threshold: mixed, shown: string}>
     */
    public static function provideUnreadableThresholds(): iterable
    {
        yield 'zero' => ['threshold' => 0, 'shown' => '0'];
        yield 'negative' => ['threshold' => -5, 'shown' => '-5'];
        yield 'a string' => ['threshold' => '50', 'shown' => 'a string'];
    }

    #[Test]
    #[DataProvider('provideUnreadableThresholds')]
    public function test_a_threshold_below_one_stops_the_lint(mixed $threshold, string $shown): void
    {
        self::configOf($this->application())->set('php-regex.complexity.threshold', $threshold);

        $status = Artisan::call('regex:lint', ['paths' => [$this->makeProject(['src/Pattern.php' => self::COMPLEX_FILE]).'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1']);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(2, $status);
        $this->assertSame(['error' => 'Invalid config/php-regex.php: "complexity.threshold" must be an integer of at least 1, not '.$shown.'.', 'stage' => 'config'], $payload);
    }

    #[Test]
    public function test_a_threshold_of_one_is_read(): void
    {
        $this->configure(true, 1);

        $this->assertContains('regex.lint.complexity', $this->lintIssueIds());
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

    private function configure(bool $complexity, int $threshold): void
    {
        $config = self::configOf($this->application());
        $config->set('php-regex.complexity.enabled', $complexity);
        $config->set('php-regex.complexity.threshold', $threshold);
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
                $id = $issue['issue_id'] ?? '';
                $this->assertIsString($id);
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
