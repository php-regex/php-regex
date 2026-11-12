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
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPRegex\Tests\Support\RangePatterns;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:lint judges the application over the PHP range its composer.json
 * allows, as `regex lint` does: a pattern valid on PHP 8.2 and refused from
 * 8.5 on, "\K" in a lookbehind, is an error under ">=8.2", named with the
 * versions that refuse it. php-regex.php_version names one version, judged
 * alone, or a Composer constraint, judged as `regex lint --php-version`
 * judges it: every point it allows, the floor first, named as the
 * setting's.
 */
#[WithConfig('php-regex.cache.directory', null)]
#[WithConfig('php-regex.cache.store', null)]
final class LintCommandRangeTest extends LaravelTestCase
{
    use PcreRule;
    use RangeFixtureReadings;
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
        $this->assertSame(['php' => '8.5', 'pcre' => self::judgedPcre(80500), 'guard' => null, 'guard_pcre' => null], $issues[0]['target'] ?? null);
        $range = JsonContract::asArray(JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
        $this->assertSame(['php' => '8.2', 'pcre' => self::judgedPcre(80200)], $range[0] ?? null);
    }

    #[Test]
    #[WithConfig('php-regex.php_version', '8.2')]
    public function test_the_php_version_setting_judges_that_version_only(): void
    {
        [$status, $document] = $this->lintJson();

        $this->assertSame(0, $status);
        $this->assertSame([], self::issues($document));
        $this->assertSame([['php' => '8.2', 'pcre' => self::judgedPcre(80200)]], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    /**
     * The setting names a range: a pattern a later PHP of it refuses is the
     * error lint gives it, named with that PHP, and composer.json's
     * require.php is not read.
     */
    #[Test]
    #[WithConfig('php-regex.php_version', '>=8.2')]
    public function test_a_php_version_constraint_judges_every_point_it_allows(): void
    {
        self::assertEngineKeepInLookbehind();

        [$status, $document] = $this->lintJson(['composer.json' => '{"name": "acme/app", "require": {"php": ">=8.4"}}', 'app/a.php' => self::KEEP_IN_LOOKBEHIND]);

        $this->assertSame(1, $status);
        $this->assertSame(
            ['php' => '8.2', 'pcre' => self::judgedPcre(80200), 'locale' => 'C', 'source' => 'config php-regex.php_version', 'range' => self::rangeRows([80200, 80300, 80400, 80425, 80500, 80510])],
            $document['target'] ?? null,
        );
        $issues = self::issues($document);
        $this->assertCount(1, $issues);
        $this->assertSame('error', $issues[0]['severity'] ?? null);
        $this->assertSame('regex.keep.in_lookaround', $issues[0]['issue_id'] ?? null);
        $message = $issues[0]['message'] ?? null;
        $this->assertIsString($message);
        $this->assertStringStartsWith('On PHP 8.5 and later: ', (string) $message);
        $this->assertSame(['php' => '8.5', 'pcre' => self::judgedPcre(80500), 'guard' => null, 'guard_pcre' => null], $issues[0]['target'] ?? null);
    }

    /**
     * A ceiling below the refusing PHP leaves it out, as in lint.
     */
    #[Test]
    #[WithConfig('php-regex.php_version', '^8.2 <8.5')]
    public function test_a_php_version_constraint_stops_at_its_ceiling(): void
    {
        self::assertEngineKeepInLookbehind();

        [$status, $document] = $this->lintJson(['app/a.php' => self::KEEP_IN_LOOKBEHIND]);

        $this->assertSame(0, $status);
        $this->assertSame([], self::issues($document));
        $this->assertSame(self::rangeRows([80200, 80300, 80400, 80425]), JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    /**
     * "a{,3}" is read otherwise from the first point whose PCRE2 reads the
     * quantifier: the warning lint gives it, at that point.
     */
    #[Test]
    #[WithConfig('php-regex.php_version', '>=8.1')]
    public function test_a_php_version_constraint_warns_where_a_later_php_reads_the_pattern_otherwise(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $points = [80100, 80200, 80300, 80400, 80425, 80500, 80510];

        [$status, $document] = $this->lintJson(['app/a.php' => RangePatterns::QUANTIFIER_WITHOUT_MIN_FILE]);

        $this->assertSame(0, $status);
        $this->assertSame(self::rangeRows($points), JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
        $first = self::readsQuantifierOtherwiseFrom($points);
        $expected = null === $first ? [] : [['warning', 'regex.lint.compat.meaningChanges', ProjectTarget::phpLabel($first)]];
        $found = array_map(
            static fn (array $issue): array => [$issue['severity'] ?? null, $issue['issue_id'] ?? null, JsonContract::asArray($issue['target'] ?? null)['php'] ?? null],
            self::issues($document),
        );
        $this->assertSame($expected, $found);
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }

    /**
     * @param array<string, string>|null $files the application's files, the
     *                                          composer.json range and the
     *                                          "\K" pattern when null
     *
     * @return array{int, array<mixed>}
     */
    private function lintJson(?array $files = null): array
    {
        $project = $this->makeProject($files ?? ['composer.json' => self::COMPOSER, 'app/a.php' => self::KEEP_IN_LOOKBEHIND]);
        $this->application()->setBasePath($project);

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
