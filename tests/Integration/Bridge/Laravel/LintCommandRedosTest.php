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
 * regex:lint with "redos.enabled" reports the ReDoS issues: 1.x built the
 * analysis with ReDoS on, then sent a lint request that never asked for it,
 * and the issues were dropped.
 */
final class LintCommandRedosTest extends LaravelTestCase
{
    use TemporaryProject;

    private const VULNERABLE_FILE = "<?php\n\npreg_match('/(a+)+\$/', \$subject);\n";

    /**
     * The same vulnerable body under a modifier, under a pair of braces,
     * and under another delimiter with a modifier.
     */
    private const SPELLINGS_FILE = "<?php\n\npreg_match('/(a+)+\$/', \$s);\npreg_match('/(a+)+\$/i', \$s);\npreg_match('{(a+)+\$}', \$s);\npreg_match('#(a+)+\$#u', \$s);\n";

    #[Test]
    public function test_lint_with_redos_enabled_reports_the_redos_issue(): void
    {
        self::configOf($this->application())->set('php-regex.redos.enabled', true);

        $this->assertContains('regex.lint.redos', $this->lintIssueIds());
    }

    #[Test]
    public function test_lint_with_redos_disabled_reports_no_redos_issue(): void
    {
        self::configOf($this->application())->set('php-regex.redos.enabled', false);

        $this->assertNotContains('regex.lint.redos', $this->lintIssueIds());
    }

    /**
     * "redos.ignored_patterns" takes a fragment of a pattern: its risk
     * analysis is skipped whatever the delimiters and the modifiers around
     * the body.
     */
    #[Test]
    public function test_lint_skips_an_ignored_fragment_under_any_delimiter_and_modifier(): void
    {
        $config = self::configOf($this->application());
        $config->set('php-regex.redos.enabled', true);
        $config->set('php-regex.redos.ignored_patterns', ['(a+)+']);

        $risky = array_values(array_filter($this->lintIssueIds(self::SPELLINGS_FILE), static fn (string $id): bool => \in_array($id, ['regex.lint.redos', 'regex.lint.quantifier.nested'], true)));

        $this->assertSame([], $risky);
    }

    /**
     * The control of the test above: without the list, each spelling is
     * reported.
     */
    #[Test]
    public function test_lint_reports_each_spelling_of_a_fragment_not_ignored(): void
    {
        self::configOf($this->application())->set('php-regex.redos.enabled', true);

        $risky = array_values(array_filter($this->lintIssueIds(self::SPELLINGS_FILE), static fn (string $id): bool => \in_array($id, ['regex.lint.redos', 'regex.lint.quantifier.nested'], true)));

        $this->assertCount(8, $risky);
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
     * @return list<string>
     */
    private function lintIssueIds(string $file = self::VULNERABLE_FILE): array
    {
        $project = $this->makeProject(['src/Pattern.php' => $file]);

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
