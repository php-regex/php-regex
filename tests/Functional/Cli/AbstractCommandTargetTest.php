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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\ApplicationFactory;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RangePatterns;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\SinglePatternRange;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The commands that read one pattern find their target where lint finds
 * it, in the working directory: --php-version, then regex.json's
 * phpVersion, then composer.json (config.platform.php, then require.php),
 * then the running PHP. A regex.json lint cannot read stops them as it
 * stops lint, stage "config", exit 2.
 */
final class AbstractCommandTargetTest extends TestCase
{
    use RunsRegexCli;
    use SinglePatternRange;
    use TemporaryProject;

    private const COMPOSER = '{"name": "acme/app", "require": {"php": ">=8.1"}}';

    private const PHP_FILE = "<?php\n\npreg_match('/a/', \$s);\n";

    /**
     * No --php-version, a composer.json that allows ">=8.1": the pattern is
     * judged for 8.1 and every later version, as lint judges it there.
     */
    #[Test]
    public function test_a_command_without_a_flag_is_judged_for_the_range_composer_json_allows(): void
    {
        self::assertEngineKeepInLookbehind();
        $directory = $this->enterProject(['composer.json' => self::COMPOSER, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runRegex(['validate', RangePatterns::KEEP_IN_LOOKBEHIND, '--format=json']);
        [, $lint] = $this->runRegex(['lint', 'src', '--jobs=1', '--no-redos', '--format=json']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('validate', $document);
        $target = $document['target'] ?? null;
        $this->assertSame(JsonContract::decodeDocument($lint)['target'] ?? null, $target);
        $this->assertSame(ProjectTarget::resolve(null, null, [], $directory, [])->toArray(), $target);
        $this->assertSame('composer.json require.php', JsonContract::asArray($target)['source'] ?? null);
        self::assertRange(
            $document['range'] ?? null,
            RangePatterns::FROM_8_1,
            RangePatterns::KEEP_IN_LOOKBEHIND,
            static fn (int $point): bool => $point < 80500,
            static fn (int $point): bool => false,
        );
    }

    /**
     * Every command reads composer.json: "a{,3}" judged from 8.1 is read
     * otherwise from the first version whose PCRE2 reads the quantifier,
     * which the running PHP alone never says.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideEveryCommand')]
    public function test_every_command_reads_the_range_of_composer_json(array $command): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $this->enterProject(['composer.json' => self::COMPOSER]);

        [, $stdout, $stderr] = $this->runRegex($command);

        $this->assertStringContainsString(self::parsesDifferentlyMessage(self::firstPointReadingQuantifierOtherwise(RangePatterns::FROM_8_1)), $stdout.$stderr);
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideEveryCommand(): iterable
    {
        foreach (['validate', 'analyze', 'explain', 'redos', 'parse', 'diagram', 'format', 'transpile', 'highlight', 'graph', 'debug'] as $command) {
            yield $command => ['command' => [$command, RangePatterns::QUANTIFIER_WITHOUT_MIN]];
        }
        yield 'compare' => ['command' => ['compare', RangePatterns::QUANTIFIER_WITHOUT_MIN, '/b/']];
    }

    /**
     * config.platform.php is the PHP Composer resolves for: a single
     * version, judged alone, before require.php.
     */
    #[Test]
    public function test_config_platform_php_beats_require_php(): void
    {
        $directory = $this->enterProject(['composer.json' => '{"name": "acme/app", "require": {"php": ">=8.1"}, "config": {"platform": {"php": "8.2.0"}}}']);

        [$exitCode, $stdout] = $this->runRegex(['validate', RangePatterns::KEEP_IN_LOOKBEHIND, '--format=json']);

        $this->assertSame(0, $exitCode, $stdout);
        $expected = ProjectTarget::resolve(null, null, [], $directory, [])->toArray();
        $this->assertSame(['8.2', 'composer.json config.platform.php'], [$expected['php'], $expected['source']]);
        $this->assertSame($expected, JsonContract::decodeDocument($stdout)['target'] ?? null);
    }

    /**
     * regex.json's phpVersion, and the pcreVersion beside it, win over
     * composer.json; --php-version wins over both.
     *
     * @param list<string> $flags
     * @param list<int>    $points
     */
    #[Test]
    #[DataProvider('provideSourcesInOrder')]
    public function test_the_target_comes_from_the_first_source_that_names_one(string $regexJson, array $flags, string $source, array $points): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'regex.json' => $regexJson, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runRegex([...$flags, 'validate', '/a/', '--format=json']);
        [, $lint] = $this->runRegex([...$flags, 'lint', 'src', '--jobs=1', '--no-redos', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout);
        $target = JsonContract::asArray(JsonContract::decodeDocument($stdout)['target'] ?? null, $stdout);
        $this->assertSame(JsonContract::decodeDocument($lint)['target'] ?? null, $target);
        $this->assertSame($source, $target['source'] ?? null);
        $this->assertSame(array_map(ProjectTarget::phpLabel(...), $points), array_column(JsonContract::asArray($target['range'] ?? null), 'php'));
    }

    /**
     * @return iterable<string, array{regexJson: string, flags: list<string>, source: string, points: list<int>}>
     */
    public static function provideSourcesInOrder(): iterable
    {
        yield 'a single version in regex.json beats require.php' => ['regexJson' => '{"phpVersion": "8.2"}', 'flags' => [], 'source' => 'regex.json', 'points' => [80200]];
        yield 'a range in regex.json beats require.php' => ['regexJson' => '{"phpVersion": ">=8.4"}', 'flags' => [], 'source' => 'regex.json', 'points' => [80400, 80425, 80500, 80510]];
        yield 'a min/max object in regex.json' => ['regexJson' => '{"phpVersion": {"min": "8.2", "max": "8.3"}}', 'flags' => [], 'source' => 'regex.json', 'points' => [80200, 80300]];
        yield 'the PCRE2 regex.json pins' => ['regexJson' => '{"phpVersion": "8.2", "pcreVersion": "10.42"}', 'flags' => [], 'source' => 'regex.json', 'points' => [80200]];
        yield '--php-version beats regex.json and require.php' => ['regexJson' => '{"phpVersion": "8.2"}', 'flags' => ['--php-version=>=8.3'], 'source' => '--php-version', 'points' => [80300, 80400, 80425, 80500, 80510]];
        yield 'a regex.json without phpVersion leaves require.php' => ['regexJson' => '{"paths": ["src"]}', 'flags' => [], 'source' => 'composer.json require.php', 'points' => RangePatterns::FROM_8_1];
    }

    /**
     * A regex.json lint cannot read stops each command as it stops lint:
     * the same message, stage "config", exit 2.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideBrokenConfigurationsForEveryJsonCommand')]
    public function test_a_regex_json_lint_cannot_read_is_a_config_error(string $regexJson, array $command): void
    {
        $this->enterProject(['regex.json' => $regexJson, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runRegex([...$command, '--format=json']);
        [, $lint] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json']);

        $this->assertSame(2, $exitCode, $stdout);
        $lintEnvelope = JsonContract::decodeDocument($lint);
        $this->assertSame('config', $lintEnvelope['stage'] ?? null, $lint);
        $this->assertSame($lintEnvelope, JsonContract::decodeDocument($stdout));
    }

    /**
     * @return iterable<string, array{regexJson: string, command: list<string>}>
     */
    public static function provideBrokenConfigurationsForEveryJsonCommand(): iterable
    {
        $configurations = [
            'not JSON' => '{',
            'a constraint without a lowest version' => '{"phpVersion": "<8.4"}',
            'a PHP_VERSION_ID inside a constraint' => '{"phpVersion": ">=80100"}',
            'an object without max' => '{"phpVersion": {"min": "8.1"}}',
            'min above max' => '{"phpVersion": {"min": "8.6", "max": "8.1"}}',
            'no version at all' => '{"phpVersion": "abc"}',
        ];
        // debug loaded regex.json for its defaults before ranges, through
        // the loader lint uses: it refuses each of these already, and
        // ExitCodeContractTest keeps it.
        foreach (['validate', 'analyze', 'explain', 'redos', 'format'] as $name) {
            foreach ($configurations as $label => $regexJson) {
                yield $name.', '.$label => ['regexJson' => $regexJson, 'command' => [$name, '/a/']];
            }
        }
        yield 'transpile, not JSON' => ['regexJson' => '{', 'command' => ['transpile', '/a/']];
        yield 'transpile, a constraint without a lowest version' => ['regexJson' => '{"phpVersion": "<8.4"}', 'command' => ['transpile', '/a/']];
    }

    /**
     * The commands without JSON stop as well, with lint's message on
     * stderr and nothing on stdout.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideBrokenConfigurationsForEveryConsoleCommand')]
    public function test_a_regex_json_lint_cannot_read_stops_a_console_command(array $command): void
    {
        $this->enterProject(['regex.json' => '{"phpVersion": "<8.4"}', 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runRegex($command);
        [, $lint] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json']);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stdout);
        $error = JsonContract::decodeDocument($lint)['error'] ?? null;
        $this->assertIsString($error, $lint);
        $this->assertStringContainsString($error, $stderr);
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideBrokenConfigurationsForEveryConsoleCommand(): iterable
    {
        foreach (['parse', 'diagram', 'highlight', 'graph', 'validate'] as $command) {
            yield $command => ['command' => [$command, '/a/']];
        }
        yield 'compare' => ['command' => ['compare', '/a/', '/b/']];
    }

    /**
     * A --php-version no source can read stays the command line's error,
     * stage "usage", in lint's words, whatever regex.json says.
     */
    #[Test]
    #[DataProvider('provideUnreadableFlags')]
    public function test_an_unreadable_php_version_stays_a_usage_error_in_the_words_of_lint(string $phpVersion): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'regex.json' => '{"phpVersion": "8.2"}', 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runRegex(['--php-version='.$phpVersion, 'validate', '/a/', '--format=json']);
        [, $lint] = $this->runRegex(['--php-version='.$phpVersion, 'lint', 'src', '--jobs=1', '--format=json']);

        $this->assertSame(2, $exitCode, $stdout);
        $lintEnvelope = JsonContract::decodeDocument($lint);
        $this->assertSame('usage', $lintEnvelope['stage'] ?? null, $lint);
        $this->assertSame($lintEnvelope, JsonContract::decodeDocument($stdout));
    }

    /**
     * @return iterable<string, array{phpVersion: string}>
     */
    public static function provideUnreadableFlags(): iterable
    {
        yield 'no lowest version' => ['phpVersion' => '<8.4'];
        yield 'a PHP_VERSION_ID inside a constraint' => ['phpVersion' => '>=80100'];
        yield 'runtime inside a constraint' => ['phpVersion' => 'runtime || ^9.0'];
        yield 'a hyphen range written backwards' => ['phpVersion' => '8.6 - 8.1'];
        yield 'no version at all' => ['phpVersion' => 'abc'];
    }

    /**
     * The banner's "Target PHP" line names the target resolved, where it
     * came from and the later versions judged, as lint's Target row does,
     * never the raw text of the flag.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideCommandsWithABanner')]
    public function test_the_banner_names_the_resolved_target_its_source_and_its_points(array $command): void
    {
        $directory = $this->enterProject(['composer.json' => self::COMPOSER]);
        $target = ProjectTarget::resolve(null, null, [], $directory, []);

        [, $stdout] = $this->runWithBanner($command);

        $this->assertMatchesRegularExpression('/^Target PHP *: '.preg_quote(self::bannerValue($target), '/').'$/m', $stdout);

        [, $stdout] = $this->runWithBanner(['--php-version=>=8.3', ...$command]);

        $this->assertMatchesRegularExpression('/^Target PHP *: '.preg_quote(self::bannerValue(ProjectTarget::resolve('>=8.3', null, [], $directory, [])), '/').'$/m', $stdout);
        $this->assertStringNotContainsString('>=8.3', $stdout);
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideCommandsWithABanner(): iterable
    {
        foreach (['validate', 'analyze', 'explain', 'parse', 'diagram', 'debug'] as $command) {
            yield $command => ['command' => [$command, '/a/']];
        }
        yield 'compare' => ['command' => ['compare', '/a/', '/b/']];
    }

    /**
     * "PHP 8.1, PCRE2 10.39 (composer.json require.php); also PHP 8.2,
     * 8.3, 8.4, 8.4.25, 8.5, 8.5.10".
     */
    private static function bannerValue(ProjectTarget $target): string
    {
        $later = array_map(static fn (PcreTarget $point): string => ProjectTarget::phpLabel($point->phpVersionId), \array_slice($target->range(), 1));
        Assert::assertNotSame([], $later, 'The banner test judges a range.');

        return $target->label().' ('.$target->source().'); also PHP '.implode(', ', $later);
    }

    /**
     * runRegex() with the banner on: the application as a user runs it on
     * a terminal, without --no-visuals.
     *
     * @param list<string> $arguments
     *
     * @return array{int, string, string}
     */
    private function runWithBanner(array $arguments): array
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $application = ApplicationFactory::create(new Output(false, false, '#', '-', $errors));

        $level = ob_get_level();
        ob_start();

        try {
            $exitCode = $application->run(['regex', '--no-ansi', ...$arguments]);
            $stdout = (string) ob_get_contents();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        rewind($errors);
        $stderr = (string) stream_get_contents($errors);
        fclose($errors);

        return [$exitCode, $stdout, $stderr];
    }
}
