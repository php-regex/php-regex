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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Symfony\Command\LintCommand;
use PHPRegex\Tests\Support\BundleContainer;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPRegex\Tests\Support\RangePatterns;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * regex:lint judges a project over the PHP range its composer.json allows,
 * as `regex lint` does: a pattern valid on PHP 8.2 and refused from 8.5 on,
 * "\K" in a lookbehind, is an error under ">=8.2", named with the versions
 * that refuse it. php_regex.php_version names one version, judged alone.
 */
final class LintCommandRangeTest extends TestCase
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
        $project = $this->makeProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$status, $document] = $this->lintJson($project, null);

        $this->assertSame(Command::FAILURE, $status);
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
    public function test_the_php_version_setting_judges_that_version_only(): void
    {
        $project = $this->makeProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$status, $document] = $this->lintJson($project, '8.2');

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([], self::issues($document));
        $this->assertSame([['php' => '8.2', 'pcre' => self::judgedPcre(80200)]], JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    /**
     * The bundle setting names a range: a pattern a later PHP of it refuses
     * is the error lint gives it, named with that PHP, and composer.json's
     * require.php is not read.
     */
    #[Test]
    public function test_a_php_version_constraint_judges_every_point_it_allows(): void
    {
        self::assertEngineKeepInLookbehind();
        $project = $this->makeProject(['composer.json' => '{"name": "acme/app", "require": {"php": ">=8.4"}}', 'src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$status, $document] = $this->lintThroughTheBundle($project, '>=8.2');

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(
            ['php' => '8.2', 'pcre' => self::judgedPcre(80200), 'locale' => 'C', 'source' => 'php_regex.php_version', 'range' => self::rangeRows([80200, 80300, 80400, 80425, 80500, 80510])],
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
    public function test_a_php_version_constraint_stops_at_its_ceiling(): void
    {
        self::assertEngineKeepInLookbehind();
        $project = $this->makeProject(['src/a.php' => self::KEEP_IN_LOOKBEHIND]);

        [$status, $document] = $this->lintThroughTheBundle($project, '^8.2 <8.5');

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([], self::issues($document));
        $this->assertSame(self::rangeRows([80200, 80300, 80400, 80425]), JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
    }

    /**
     * "a{,3}" is read otherwise from the first point whose PCRE2 reads the
     * quantifier: the warning lint gives it, at that point.
     */
    #[Test]
    public function test_a_php_version_constraint_warns_where_a_later_php_reads_the_pattern_otherwise(): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $project = $this->makeProject(['src/a.php' => RangePatterns::QUANTIFIER_WITHOUT_MIN_FILE]);
        $points = [80100, 80200, 80300, 80400, 80425, 80500, 80510];

        [$status, $document] = $this->lintThroughTheBundle($project, '>=8.1');

        $this->assertSame(Command::SUCCESS, $status);
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
     * @return iterable<string, array{constraint: string, points: list<int>}>
     */
    public static function provideBoundaryConstraints(): iterable
    {
        yield 'from the last gate point' => ['constraint' => '>=8.5.10', 'points' => [80510]];
        yield 'above every gate point' => ['constraint' => '>8.6', 'points' => [80601]];
    }

    /**
     * @param list<int> $points
     */
    #[Test]
    #[DataProvider('provideBoundaryConstraints')]
    public function test_a_php_version_constraint_at_the_edge_of_the_gate_points_judges_its_floor_alone(string $constraint, array $points): void
    {
        $project = $this->makeProject(['src/a.php' => "<?php\n\npreg_match('/a/', \$s);\n"]);

        [$status, $document] = $this->lintThroughTheBundle($project, $constraint);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(self::rangeRows($points), JsonContract::asArray($document['target'] ?? null)['range'] ?? null);
        $this->assertSame('php_regex.php_version', JsonContract::asArray($document['target'] ?? null)['source'] ?? null);
    }

    /**
     * Only regex:lint reads the setting: the php_regex.regex service the
     * validator and the other commands use judges for the running PHP.
     */
    #[Test]
    public function test_a_php_version_constraint_leaves_the_runtime_service_on_the_running_engine(): void
    {
        $container = $this->compileBundle($this->makeProject(), '>=8.1');

        $regex = $container->get('php_regex.regex');
        $this->assertInstanceOf(Regex::class, $regex);
        $this->assertTrue($regex->target()->isRunningEngine());
    }

    /**
     * @return array{int, array<mixed>}
     */
    private function lintJson(string $project, ?string $phpVersion): array
    {
        $analysis = new AnalysisService(RegexParser::create());
        $sources = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy())),
        ]);
        $tester = new CommandTester(new LintCommand(
            lint: new LintService($analysis, $sources),
            analysis: $analysis,
            phpVersion: $phpVersion,
            projectDir: $project,
        ));

        $status = $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        return [$status, JsonContract::decodeDocument($tester->getDisplay())];
    }

    /**
     * A JSON lint run of the project's src/ by the command the bundle
     * builds from its php_version setting.
     *
     * @return array{int, array<mixed>}
     */
    private function lintThroughTheBundle(string $project, string $phpVersion): array
    {
        $command = $this->compileBundle($project, $phpVersion)->get('php_regex.command.lint');
        $this->assertInstanceOf(LintCommand::class, $command);

        $tester = new CommandTester($command);
        $status = $tester->execute(
            ['paths' => [$project.'/src'], '--format' => 'json', '--no-routes' => true, '--no-validators' => true, '--jobs' => '1'],
            ['capture_stderr_separately' => true],
        );

        return [$status, JsonContract::decodeDocument($tester->getDisplay())];
    }

    private function compileBundle(string $project, string $phpVersion): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.cache_dir', $project.'/var/cache');
        $container->setParameter('kernel.project_dir', $project);

        $extension = BundleContainer::register($container);
        $container->loadFromExtension($extension->getAlias(), ['cache' => ['directory' => null], 'php_version' => $phpVersion]);
        $container->compile();

        return $container;
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
