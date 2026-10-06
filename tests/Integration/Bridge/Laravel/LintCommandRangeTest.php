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
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint judges the application over the PHP range its composer.json
 * allows, as `regex lint` does: a pattern valid on PHP 8.2 and refused from
 * 8.5 on, "\K" in a lookbehind, is an error under ">=8.2", named with the
 * versions that refuse it. php-regex.php_version names one version, judged
 * alone.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
final class LintCommandRangeTest extends TestCase
{
    use TemporaryProject;

    private const COMPOSER = '{"name": "acme/app", "require": {"php": ">=8.2"}}';

    /**
     * Compiles on PHP 8.4.26 (preg_match() returns 1); PHP 8.5 compiles
     * without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 then refuses it
     * (pcre2test 10.49: error 199).
     */
    private const KEEP_IN_LOOKBEHIND = "<?php\n\npreg_match('/(?<=a\\Kb)c/', \$s);\n";

    #[Test]
    public function test_a_pattern_a_later_php_of_the_composer_range_refuses_is_an_error(): void
    {
        [$status, $document] = $this->lintJson();

        $this->assertSame(1, $status);
        $issues = self::issues($document);
        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['severity'] ?? null);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringContainsString('PHP 8.5 and later', (string) $message);
        $this->assertSame(['php' => '8.5', 'pcre' => '10.44'], $issues[0]['target'] ?? null);
        $range = JsonContract::asArray(JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
        $this->assertSame(['php' => '8.2', 'pcre' => '10.40'], $range[0] ?? null);
    }

    #[Test]
    #[WithConfig('php-regex.php_version', '8.2')]
    public function test_the_php_version_setting_judges_that_version_only(): void
    {
        [$status, $document] = $this->lintJson();

        $this->assertSame(0, $status);
        $this->assertSame([], self::issues($document));
        $this->assertSame([['php' => '8.2', 'pcre' => '10.40']], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @return array{int, array<mixed>}
     */
    private function lintJson(): array
    {
        $project = $this->makeProject(['composer.json' => self::COMPOSER, 'app/a.php' => self::KEEP_IN_LOOKBEHIND]);
        $this->app?->setBasePath($project);

        $status = Artisan::call('regex:lint', [
            'paths' => [$project.'/app'],
            '--format' => 'json',
            '--no-routes' => true,
            '--no-validators' => true,
            '--jobs' => '1',
        ]);

        return [$status, JsonContract::decodeDocument(Artisan::output())];
    }

    /**
     * @param array<mixed> $document
     *
     * @return list<array<mixed>>
     */
    private static function issues(array $document): array
    {
        $issues = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            foreach (JsonContract::asArray(JsonContract::asArray($result)['issues'] ?? null) as $issue) {
                $issues[] = JsonContract::asArray($issue);
            }
        }

        return $issues;
    }
}
