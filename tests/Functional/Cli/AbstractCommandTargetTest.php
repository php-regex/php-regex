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
 * The commands that read one pattern take their target from the command
 * line alone: --php-version, a range included, --pcre-version and
 * --locale, else the running PHP. They read neither regex.json nor
 * composer.json of the working directory, whatever lint reads there: a
 * range is judged only when --php-version names one.
 */
final class AbstractCommandTargetTest extends TestCase
{
    use RunsRegexCli;
    use SinglePatternRange;
    use TemporaryProject;

    private const COMPOSER = '{"name": "acme/app", "require": {"php": ">=8.1"}}';

    private const PHP_FILE = "<?php\n\npreg_match('/a/', \$s);\n";

    /**
     * No --php-version, a composer.json that allows ">=8.1" and a
     * regex.json that names a range: the pattern is judged for the running
     * PHP alone, where lint judges the range.
     */
    #[Test]
    public function test_a_command_without_a_flag_judges_the_running_php_inside_a_project(): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'regex.json' => '{"phpVersion": ">=8.2"}', 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runRegex(['validate', '/a/', '--format=json']);
        [, $lint] = $this->runRegex(['lint', 'src', '--jobs=1', '--no-redos', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stderr, 'Nothing is read, so nothing is noted.');
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('validate', $document);
        $target = JsonContract::asArray($document['target'] ?? null);
        $running = ProjectTarget::fromSources([], [], null, [])->toArray();
        $this->assertSame('running PHP', $running['source']);
        $this->assertSame($running, $target);
        $this->assertCount(1, JsonContract::asArray($document['range'] ?? null), 'The running PHP alone is judged.');
        $this->assertSame('regex.json', JsonContract::asArray(JsonContract::decodeDocument($lint)['target'] ?? null)['source'] ?? null, 'lint reads the project.');
    }

    /**
     * No command reads composer.json: "a{,3}" judged from 8.1 would be read
     * otherwise from the first version whose PCRE2 reads the quantifier,
     * which the running PHP alone never says.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideEveryCommand')]
    public function test_no_command_reads_the_range_of_composer_json(array $command): void
    {
        self::assertEngineReadsQuantifierWithoutMin();
        $this->enterProject(['composer.json' => self::COMPOSER, 'regex.json' => '{"phpVersion": ">=8.1"}']);

        [, $stdout, $stderr] = $this->runRegex($command);

        $this->assertStringNotContainsString('parses differently', $stdout.$stderr);
        $this->assertStringNotContainsString('composer.json', $stdout.$stderr);
        $this->assertStringNotContainsString('regex.json', $stdout.$stderr);

        // --php-version names the range: then it is judged.
        [, $stdout, $stderr] = $this->runRegex(['--php-version=>=8.1', ...$command]);

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
     * --php-version is the target, as lint takes it before regex.json and
     * composer.json: the same flag names the same target in both.
     *
     * @param list<int> $points
     */
    #[Test]
    #[DataProvider('provideFlags')]
    public function test_the_target_is_the_one_the_flag_names_as_lint_reads_it(string $phpVersion, array $points): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER, 'regex.json' => '{"phpVersion": "8.2"}', 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout] = $this->runRegex(['--php-version='.$phpVersion, 'validate', '/a/', '--format=json']);
        [, $lint] = $this->runRegex(['--php-version='.$phpVersion, 'lint', 'src', '--jobs=1', '--no-redos', '--format=json']);

        $this->assertSame(0, $exitCode, $stdout);
        $target = JsonContract::asArray(JsonContract::decodeDocument($stdout)['target'] ?? null, $stdout);
        $this->assertSame(JsonContract::decodeDocument($lint)['target'] ?? null, $target);
        $this->assertSame('--php-version', $target['source'] ?? null);
        $this->assertSame(array_map(ProjectTarget::phpLabel(...), $points), array_column(JsonContract::asArray($target['range'] ?? null), 'php'));
    }

    /**
     * @return iterable<string, array{phpVersion: string, points: list<int>}>
     */
    public static function provideFlags(): iterable
    {
        yield 'a single version' => ['phpVersion' => '8.3', 'points' => [80300]];
        yield 'a range' => ['phpVersion' => '>=8.3', 'points' => [80300, 80400, 80425, 80500, 80510]];
        yield 'a hyphen range' => ['phpVersion' => '8.2 - 8.3', 'points' => [80200, 80300]];
    }

    /**
     * A regex.json lint cannot read stops lint, not these commands: they
     * do not read it.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideBrokenConfigurationsForEveryJsonCommand')]
    public function test_a_regex_json_lint_cannot_read_does_not_stop_a_json_command(string $regexJson, array $command): void
    {
        $this->enterProject(['regex.json' => $regexJson, 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runRegex([...$command, '--format=json']);
        [$lintExitCode, $lint] = $this->runRegex(['lint', 'src', '--jobs=1', '--format=json']);

        $this->assertSame(2, $lintExitCode, $lint);
        $this->assertSame('config', JsonContract::decodeDocument($lint)['stage'] ?? null, $lint);
        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame('', $stderr);
        // transpile's "target" is the dialect: the PHP judged is "php_target".
        $key = 'transpile' === $command[0] ? 'php_target' : 'target';
        $this->assertSame('running PHP', JsonContract::asArray(JsonContract::decodeDocument($stdout)[$key] ?? null)['source'] ?? null);
    }

    /**
     * @return iterable<string, array{regexJson: string, command: list<string>}>
     */
    public static function provideBrokenConfigurationsForEveryJsonCommand(): iterable
    {
        $configurations = [
            'not JSON' => '{',
            'a constraint without a lowest version' => '{"phpVersion": "<8.4"}',
            'min above max' => '{"phpVersion": {"min": "8.6", "max": "8.1"}}',
            'no version at all' => '{"phpVersion": "abc"}',
        ];
        // debug reads regex.json for its own defaults, through the loader
        // lint uses: it refuses each of these, and ExitCodeContractTest
        // keeps it.
        foreach (['validate', 'analyze', 'explain', 'redos', 'format'] as $name) {
            foreach ($configurations as $label => $regexJson) {
                yield $name.', '.$label => ['regexJson' => $regexJson, 'command' => [$name, '/a/']];
            }
        }
        yield 'transpile, not JSON' => ['regexJson' => '{', 'command' => ['transpile', '/a/']];
    }

    /**
     * The commands without JSON go on as well, nothing on stderr.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideConsoleCommands')]
    public function test_a_regex_json_lint_cannot_read_does_not_stop_a_console_command(array $command): void
    {
        $this->enterProject(['regex.json' => '{"phpVersion": "<8.4"}', 'src/a.php' => self::PHP_FILE]);

        [$exitCode, $stdout, $stderr] = $this->runRegex($command);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertNotSame('', $stdout);
        $this->assertSame('', $stderr);
    }

    /**
     * @return iterable<string, array{command: list<string>}>
     */
    public static function provideConsoleCommands(): iterable
    {
        foreach (['parse', 'diagram', 'highlight', 'graph', 'validate'] as $command) {
            yield $command => ['command' => [$command, '/a/']];
        }
        yield 'compare' => ['command' => ['compare', '/a/', '/b/']];
    }

    /**
     * A --php-version no source can read is the command line's error,
     * stage "usage", in lint's words.
     */
    #[Test]
    #[DataProvider('provideUnreadableFlags')]
    public function test_an_unreadable_php_version_is_a_usage_error_in_the_words_of_lint(string $phpVersion): void
    {
        $this->enterProject(['src/a.php' => self::PHP_FILE]);

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
     * The banner has a "Target PHP" line only when the command line names
     * a target, inside a project as anywhere: it names the target resolved,
     * where it came from and the later versions judged, as lint's Target
     * row does, never the raw text of the flag.
     *
     * @param list<string> $command
     */
    #[Test]
    #[DataProvider('provideCommandsWithABanner')]
    public function test_the_banner_names_the_target_the_flag_names_and_its_points(array $command): void
    {
        $this->enterProject(['composer.json' => self::COMPOSER]);

        [, $stdout] = $this->runWithBanner($command);

        $this->assertDoesNotMatchRegularExpression('/^Target PHP/m', $stdout);

        [, $stdout] = $this->runWithBanner(['--php-version=>=8.3', ...$command]);

        $this->assertMatchesRegularExpression('/^Target PHP *: '.preg_quote(self::bannerValue(ProjectTarget::fromSources(['--php-version' => '>=8.3'], [], null, [])), '/').'$/m', $stdout);
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
     * "PHP 8.3, PCRE2 10.42 (--php-version); also PHP 8.4, 8.4.25, 8.5,
     * 8.5.10".
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
