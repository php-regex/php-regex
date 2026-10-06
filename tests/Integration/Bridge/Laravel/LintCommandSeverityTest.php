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
 * regex:lint honours the severity a rule declares: a rule at Error fails
 * the command, an Info rule does not. Without /u, [é] matches the byte
 * \xC3 on its own.
 */
final class LintCommandSeverityTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_lint_fails_on_a_rule_at_error(): void
    {
        $this->assertSame(1, preg_match('/^[é]$/', "\xC3"));

        [$code, $types] = $this->lint("<?php\n\npreg_match('/[é]/', \$subject);\n");

        $this->assertSame(1, $code);
        $this->assertSame(['regex.lint.unicode.multibyteInClassWithoutU' => 'error'], $types);
    }

    #[Test]
    public function test_lint_passes_on_a_rule_at_info(): void
    {
        [$code, $types] = $this->lint("<?php\n\npreg_match('/(a)+/', \$subject);\n");

        $this->assertSame(0, $code);
        $this->assertSame(['regex.lint.group.quantifiedCapture' => 'info'], $types);
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
     * @return array{int, array<string, string>}
     */
    private function lint(string $file): array
    {
        $project = $this->makeProject(['src/Pattern.php' => $file]);

        $code = Artisan::call('regex:lint', [
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

        $types = [];
        foreach ($payload['results'] as $result) {
            $this->assertIsArray($result);
            foreach ((array) ($result['issues'] ?? []) as $issue) {
                $this->assertIsArray($issue);
                $this->assertIsString($issue['issue_id'] ?? null);
                $this->assertIsString($issue['severity'] ?? null);
                $types[$issue['issue_id']] = $issue['severity'];
            }
        }

        return [$code, $types];
    }
}
