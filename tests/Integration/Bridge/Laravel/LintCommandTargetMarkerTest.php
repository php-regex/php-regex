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
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint reads "@regex-target" as `regex lint` does: under
 * php_version 8.2, a marker that keeps PHP 8.4 and later only keeps no
 * version judged, is reported as regex.target.empty, and leaves the
 * pattern it covers unjudged; "(?a)", which PHP 8.2 refuses, is reported
 * elsewhere.
 */
final class LintCommandTargetMarkerTest extends LaravelTestCase
{
    use PcreRule;
    use TemporaryProject;

    private const FILE = "<?php\n\npreg_match('/(?a)\\d+/', \$subject); // @regex-target php>=8.4\npreg_match('/(?a)\\d+/', \$subject);\n";

    #[Test]
    public function test_a_marker_narrows_the_versions_judged(): void
    {
        $this->requiresBundledPcre2Within(80200, 80700, __FUNCTION__);
        self::configOf($this->application())->set('php-regex.php_version', '8.2');

        $this->assertSame([[3, 'regex.target.empty'], [4, 'regex.group.syntax']], $this->lintIssues());
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

        sort($issues);

        return $issues;
    }
}
