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
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\RangeFixtureReadings;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every command of the binary exits with one of three codes: 0 when it did
 * what it was asked and found nothing wrong, 1 when the patterns or files it
 * judged have a problem, 2 when the command line or the configuration cannot
 * be used.
 */
final class ExitCodeContractTest extends TestCase
{
    use PcreRule;
    use RangeFixtureReadings;
    use TemporaryProject;

    private const BAD_PHP_FILE = <<<'PHP'
        <?php

        preg_match('/(/', 'abc');

        PHP;

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideUsageErrors')]
    public function test_a_command_line_that_cannot_be_used_exits_2(array $arguments): void
    {
        $this->enterProject();

        $this->assertSame(2, $this->runRegex($arguments));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideUsageErrors(): iterable
    {
        yield 'no command' => [[]];
        yield 'unknown command' => [['nosuch']];
        yield 'global option without its value' => [['--php-version']];
        yield 'help for an unknown command' => [['help', 'nosuch']];
        // The binary configures no cache that outlives the run: there is
        // none to clear, and the command is gone.
        yield 'the removed clear-cache' => [['clear-cache']];
        yield 'the removed cache:clear' => [['cache:clear']];

        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph', 'format'] as $command) {
            yield $command.' without a pattern' => [[$command]];
            yield $command.' with an unknown option' => [[$command, '/a/', '--bogus']];
            yield $command.' with an invalid --php-version' => [['--php-version=abc', $command, '/a/']];
            yield $command.' with a second pattern' => [[$command, '/a/', '/b/']];
            yield $command.' with a second pattern after the end of the options' => [[$command, '--', '/a/', '/b/']];
        }

        // A command without arguments refuses every one, as the others do.
        yield 'version with an unknown option' => [['version', '--bogus']];
        yield 'version with an unknown --format' => [['version', '--format=bogus']];
        yield 'version with an argument' => [['version', 'extra']];
        yield 'self-update with an unknown option' => [['self-update', '--bogus']];
        yield 'self-update with an argument' => [['self-update', 'extra']];
        yield 'lsp with an unknown option' => [['lsp', '--bogus']];
        yield 'lsp with an argument' => [['lsp', 'extra']];
        // The server reads its target from the editor, regex.json and
        // composer.json: a target on the command line would be ignored.
        yield 'lsp with a --php-version' => [['--php-version=8.2', 'lsp']];
        yield 'lsp with a --pcre-version' => [['--pcre-version=10.40', 'lsp']];
        yield 'help with an unknown option' => [['help', '--bogus']];
        yield 'help with a second argument' => [['help', 'analyze', 'extra']];

        foreach (['analyze', 'debug', 'diagram', 'highlight', 'explain', 'transpile', 'redos', 'graph', 'format'] as $command) {
            yield $command.' with an unknown --format' => [[$command, '/a/', '--format=xml']];
        }

        foreach (['analyze', 'debug', 'diagram', 'highlight', 'explain', 'redos', 'format'] as $command) {
            yield $command.' with --format and no value' => [[$command, '/a/', '--format']];
        }

        yield 'analyze with an unknown --redos-mode' => [['analyze', '/a/', '--redos-mode=bogus']];
        yield 'analyze with an unknown --redos-threshold' => [['analyze', '/a/', '--redos-threshold=bogus']];
        yield 'analyze with the removed --redos-no-jit' => [['analyze', '/a/', '--redos-no-jit']];
        yield 'debug with an unknown --redos-mode' => [['debug', '/a/', '--redos-mode=bogus']];
        yield 'debug with --input and no value' => [['debug', '/a/', '--input']];
        yield 'debug with the removed --redos-no-jit' => [['debug', '/a/', '--redos-no-jit']];
        yield 'redos with an invalid --iterations' => [['redos', '/a/', '--iterations=0']];
        yield 'redos with an invalid --jit' => [['redos', '/a/', '--jit=2']];
        yield 'redos with the removed --redos-no-jit' => [['redos', '/a/', '--redos-no-jit']];
        yield 'redos with both --input and --input-file' => [['redos', '/a/', '--input=a', '--input-file=a.txt']];
        yield 'redos with an --input-file it cannot read' => [['redos', '/a/', '--input-file=missing.txt']];
        yield 'transpile with an unknown --target' => [['transpile', '/a/', '--target=perl']];
        yield 'format with --width=0' => [['format', '/a/', '--width=0']];
        yield 'format with a --width that is not a number' => [['format', '/a/', '--width=abc']];
        // Each prints its one rendering: --format is not an option of theirs.
        yield 'parse with --format' => [['parse', '/a/', '--format=console']];
        yield 'compare with --format' => [['compare', '/a/', '/b/', '--format=console']];
        yield 'diagram with an --output it cannot write' => [['diagram', '/a/', '--output=missing/dir/a.txt']];
        yield 'diagram with an SVG --output it cannot write' => [['diagram', '/a/', '--format=svg', '--output=missing/dir/a.svg']];
        yield 'graph with an --output it cannot write' => [['graph', '/a/', '--output=missing/dir/a.dot']];
        yield 'redos with an --input-file that is a directory' => [['redos', '/a/', '--input-file=.']];
        yield 'parse with --validate and an unknown option' => [['parse', '/a/', '--validate', '--bogus']];
        yield 'compare without its second pattern' => [['compare', '/a/']];
        yield 'compare with an unknown option' => [['compare', '/a/', '/b/', '--bogus']];
        yield 'compare with an unknown --method' => [['compare', '/a/', '/b/', '--method=bogus']];
        yield 'compare with an unknown --minimizer' => [['compare', '/a/', '/b/', '--minimizer=bogus']];
        yield 'compare with an unknown --determinizer' => [['compare', '/a/', '/b/', '--determinizer=bogus']];
        yield 'compare with a third pattern' => [['compare', '/a/', '/b/', '/c/']];
        yield 'compare with an invalid --php-version' => [['--php-version=abc', 'compare', '/a/', '/b/']];
        yield 'lint with an unknown option' => [['lint', '--bogus']];
        yield 'lint with an unknown --format' => [['lint', '--format=xml']];
        yield 'lint with an unknown --disable-rule' => [['lint', '--disable-rule=quantifer.nested']];
        yield 'lint with an unknown --enable-rule' => [['lint', '--enable-rule=nope.nope']];
        yield 'lint with an invalid --php-version' => [['--php-version=abc', 'lint', '.', '--jobs=1']];
        yield 'lint with a --php-version range without a lowest version' => [['--php-version=<8.4', 'lint', '.', '--jobs=1']];
        yield 'lint with a PHP_VERSION_ID inside a --php-version range' => [['--php-version=>=80100', 'lint', '.', '--jobs=1']];
        yield 'lint with runtime inside a --php-version range' => [['--php-version=runtime || ^9.0', 'lint', '.', '--jobs=1']];
        // A constraint no PHP satisfies names no range, as min above max
        // does: refused, never judged at its floor.
        yield 'lint with a --php-version hyphen range written backwards' => [['--php-version=8.6 - 8.1', 'lint', '.', '--jobs=1']];
        yield 'lint with a --php-version range below its lowest version' => [['--php-version=>=8.4 <8.2', 'lint', '.', '--jobs=1']];
        yield 'lint with two exact --php-version versions ANDed' => [['--php-version=8.1 8.2', 'lint', '.', '--jobs=1']];
        // A version past PHP 9.99 is refused inside a range as it is alone.
        yield 'lint with a --php-version range from PHP 10' => [['--php-version=>=10.0', 'lint', '.', '--jobs=1']];
        yield 'lint with a --php-version range from a major past every integer' => [['--php-version=>=99999999999999999999.1', 'lint', '.', '--jobs=1']];
        yield 'lint with a doubled separator in a --php-version range' => [['--php-version=>=8.1,,<8.3', 'lint', '.', '--jobs=1']];

        // The commands that read one pattern read --php-version as lint
        // does: a constraint lint cannot read, they cannot either.
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph', 'format'] as $command) {
            yield $command.' with a --php-version range without a lowest version' => [['--php-version=<8.4', $command, '/a/']];
            yield $command.' with a PHP_VERSION_ID inside a --php-version range' => [['--php-version=>=80100', $command, '/a/']];
        }
        yield 'compare with a --php-version range without a lowest version' => [['--php-version=<8.4', 'compare', '/a/', '/b/']];
        yield 'compare with a PHP_VERSION_ID inside a --php-version range' => [['--php-version=>=80100', 'compare', '/a/', '/b/']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideUnreadablePhpVersionsInTheConfiguration')]
    public function test_a_php_version_regex_json_cannot_read_exits_2(string $configuration, array $arguments): void
    {
        $this->enterProject(['regex.json' => $configuration, 'src/a.php' => "<?php\n\npreg_match('/a/', 'abc');\n"]);

        $this->assertSame(2, $this->runRegex($arguments));
    }

    /**
     * @return iterable<string, array{configuration: string, arguments: list<string>}>
     */
    public static function provideUnreadablePhpVersionsInTheConfiguration(): iterable
    {
        $lint = ['lint', 'src', '--jobs=1'];

        yield 'a constraint without a lowest version' => ['configuration' => '{"phpVersion": "<8.4"}', 'arguments' => $lint];
        yield 'a PHP_VERSION_ID inside a constraint' => ['configuration' => '{"phpVersion": ">=80100"}', 'arguments' => $lint];
        yield 'an object without max' => ['configuration' => '{"phpVersion": {"min": "8.1"}}', 'arguments' => $lint];
        yield 'an object with another key' => ['configuration' => '{"phpVersion": {"min": "8.1", "max": "8.6", "x": 1}}', 'arguments' => $lint];
        yield 'min above max' => ['configuration' => '{"phpVersion": {"min": "8.6", "max": "8.1"}}', 'arguments' => $lint];
        // A constraint no PHP satisfies is refused as min above max is.
        yield 'a hyphen range written backwards' => ['configuration' => '{"phpVersion": "8.6 - 8.1"}', 'arguments' => $lint];
        yield 'an upper bound below the lowest version' => ['configuration' => '{"phpVersion": ">=8.4 <8.2"}', 'arguments' => $lint];
        yield 'an upper bound below the lowest version, comma-separated' => ['configuration' => '{"phpVersion": ">=8.1,<8.0"}', 'arguments' => $lint];
        yield 'two exact versions ANDed' => ['configuration' => '{"phpVersion": "8.1 8.2"}', 'arguments' => $lint];
        // A version past PHP 9.99, refused alone ("past PHP 9.99").
        yield 'a constraint from PHP 10' => ['configuration' => '{"phpVersion": ">=10.0"}', 'arguments' => $lint];
        yield 'a constraint from PHP 99.1' => ['configuration' => '{"phpVersion": ">=99.1"}', 'arguments' => $lint];
        yield 'a constraint from a major past every integer' => ['configuration' => '{"phpVersion": ">=99999999999999999999.1"}', 'arguments' => $lint];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideCommandsReadingTheConfiguration')]
    public function test_a_configuration_that_cannot_be_read_exits_2(array $arguments): void
    {
        $this->enterProject(['regex.json' => '{']);

        $this->assertSame(2, $this->runRegex($arguments));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideCommandsReadingTheConfiguration(): iterable
    {
        yield 'debug' => [['debug', '/a/']];
        yield 'lint' => [['lint', '.', '--jobs=1']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('providePatternProblems')]
    public function test_a_pattern_with_a_problem_exits_1(array $arguments): void
    {
        self::assertEngineReadings($arguments);
        $this->enterProject(['src/a.php' => self::BAD_PHP_FILE]);

        $this->assertSame(1, $this->runRegex($arguments));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function providePatternProblems(): iterable
    {
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph', 'format'] as $command) {
            yield $command.' on a pattern that does not parse' => [[$command, '/(/']];
        }

        yield 'the bare pattern on one that does not parse' => [['/(/']];
        yield 'analyze on an invalid pattern' => [['analyze', '/a{5,3}/']];
        yield 'analyze on an invalid pattern, as JSON' => [['analyze', '/a{5,3}/', '--format=json']];
        yield 'parse --validate on an invalid pattern' => [['parse', '/a{5,3}/', '--validate']];
        yield 'validate on an invalid pattern' => [['validate', '/a{5,3}/']];
        yield 'analyze on a confirmed ReDoS' => [['analyze', '/(a+)+$/', '--redos-mode=confirmed']];
        yield 'debug on a confirmed ReDoS' => [['debug', '/(a+)+$/', '--redos-mode=confirmed']];
        yield 'debug on a confirmed ReDoS, as JSON' => [['debug', '/(a+)+$/', '--redos-mode=confirmed', '--format=json']];
        yield 'compare on a pattern that does not parse' => [['compare', '/(/', '/b/']];
        yield 'compare on patterns that intersect' => [['compare', '/a/', '/a|b/']];
        yield 'compare on patterns that differ' => [['compare', '/a/', '/b/', '--method=equivalence']];
        yield 'lint on a file with an invalid pattern' => [['lint', 'src', '--jobs=1', '--format=json']];
        yield 'analyze on a pattern that does not parse, as JSON' => [['analyze', '/(/', '--format=json']];
        yield 'debug on a pattern without delimiters, as JSON' => [['debug', '[unclosed', '--format=json']];
        yield 'transpile on a pattern that does not parse, as JSON' => [['transpile', '/(/', '--format=json']];
        yield 'format on a pattern that does not parse, as JSON' => [['format', '/(/', '--format=json']];
        yield 'compare on a pattern with a backreference' => [['compare', '/(a)\\1/', '/a/']];
        yield 'compare --method=subset on a pattern that is no subset' => [['compare', '/a|b/', '/a/', '--method=subset']];
        yield 'graph on a pattern it cannot draw' => [['graph', '/a\\1/']];
        // PHP 8.1 compiles "\K" in a lookbehind and PHP 8.5 refuses it: a
        // later version of the range that refuses the pattern is a problem
        // with it, the floor's output printed or not.
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'redos', 'format'] as $command) {
            yield $command.' on a pattern a later PHP of the --php-version range refuses' => [['--php-version=>=8.1', $command, '/(?<=a\\Kb)c/']];
        }
        // "a{2,1}" is refused by every PCRE2: the benchmark cannot compile
        // it, at the floor of a range as at a single version.
        yield 'redos on a pattern the target refuses' => [['redos', '/a{2,1}/']];
        yield 'redos on a pattern the floor of a --php-version range refuses' => [['--php-version=>=8.1', 'redos', '/a{2,1}/']];
        yield 'validate on a pattern a later PHP of the --php-version range refuses, as JSON' => [['--php-version=>=8.1', 'validate', '/(?<=a\\Kb)c/', '--format=json']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideSuccesses')]
    public function test_a_command_that_finds_nothing_wrong_exits_0(array $arguments): void
    {
        self::assertEngineReadings($arguments);
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', 'abc');\n", 'empty/b.php' => "<?php\n"]);

        $this->assertSame(0, $this->runRegex($arguments));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideSuccesses(): iterable
    {
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph', 'format'] as $command) {
            yield $command.' on a valid pattern' => [[$command, '/a/']];
        }

        yield 'the bare pattern' => [['/a/']];
        yield 'an option before the pattern' => [['diagram', '--format=svg', '/a/']];
        yield 'a pattern after the end of the options' => [['validate', '--', '/a/']];
        yield 'parse --validate on a valid pattern' => [['parse', '/a/', '--validate']];
        yield 'analyze on a theoretical ReDoS finding' => [['analyze', '/(a+)+$/']];
        yield 'debug on a theoretical ReDoS finding' => [['debug', '/(a+)+$/']];
        yield 'compare on disjoint patterns' => [['compare', '/a/', '/b/']];
        yield 'help' => [['help']];
        yield 'help for a command' => [['help', 'analyze']];
        yield 'version' => [['version']];
        yield 'lint on valid files' => [['lint', 'src', '--jobs=1', '--format=json']];
        yield 'lint on files without patterns' => [['lint', 'empty', '--jobs=1']];
        yield 'lint over a --php-version range' => [['--php-version=>=8.1', 'lint', 'src', '--jobs=1', '--format=json']];
        yield 'lint over a --php-version hyphen range' => [['--php-version=8.1 - 8.6', 'lint', 'src', '--jobs=1', '--format=json']];
        // A single version, and the running PHP, stay what the commands
        // that read one pattern take.
        yield 'validate with a single --php-version' => [['--php-version=8.1', 'validate', '/a/']];
        yield 'validate with --php-version=runtime' => [['--php-version=runtime', 'validate', '/a/']];
        yield 'analyze with a single --php-version' => [['--php-version=8.1', 'analyze', '/a/']];
        yield 'analyze with --php-version=runtime' => [['--php-version=runtime', 'analyze', '/a/']];
        // A range is judged at every version: a pattern each accepts is fine.
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph', 'format'] as $command) {
            yield $command.' over a --php-version range' => [['--php-version=>=8.1', $command, '/a/']];
            yield $command.' over a --php-version hyphen range' => [['--php-version=8.1 - 8.6', $command, '/a/']];
        }
        yield 'compare over a --php-version range' => [['--php-version=>=8.1', 'compare', '/a/', '/b/']];
        yield 'compare over a --php-version hyphen range' => [['--php-version=8.1 - 8.6', 'compare', '/a/', '/b/']];
        // A later version that reads the pattern otherwise is a warning.
        yield 'validate on a pattern a later PHP of the range parses differently' => [['--php-version=>=8.1', 'validate', '/a{,3}/']];
        // A pattern the floor refuses keeps the exit code these commands
        // give it at a single version: they print what they read of it.
        foreach (['explain', 'parse', 'diagram', 'format', 'highlight', 'graph'] as $command) {
            yield $command.' on a pattern the target refuses' => [[$command, '/a{2,1}/']];
            yield $command.' on a pattern the floor of a --php-version range refuses' => [['--php-version=>=8.1', $command, '/a{2,1}/']];
        }
        yield 'format on a pattern the floor of a --php-version range refuses, as JSON' => [['--php-version=>=8.1', 'format', '/a{2,1}/', '--format=json']];
        yield 'compare --method=subset on a subset' => [['compare', '/a/', '/a|b/', '--method=subset']];
        yield 'compare --method=equivalence on equivalent patterns' => [['compare', '/a/', '/a/', '--method=equivalence']];
        yield 'diagram written to a file' => [['diagram', '/a/', '--output=a.txt']];
        yield 'graph written to a file' => [['graph', '/a/', '--output=a.dot']];
        yield 'transpile as JSON' => [['transpile', '/a/', '--format=json']];
        yield 'format as JSON' => [['format', '/a/', '--format=json']];
        yield 'format --compact' => [['format', '/a/', '--compact']];
        // A refusal is an answer, not a problem with the pattern.
        yield 'format on a pattern it refuses' => [['format', '/a{ 1,2 }(?:bcdefghij)/', '--width=10']];
        // A byte that is not UTF-8 is a valid pattern byte without the u
        // flag, and the JSON report spells it \xNN.
        yield 'analyze on a pattern with a byte that is not UTF-8, as JSON' => [['analyze', "/\xff/", '--format=json']];
        yield 'debug on a pattern with a byte that is not UTF-8, as JSON' => [['debug', "/\xff/", '--format=json']];
        yield 'redos on a pattern with a byte that is not UTF-8, as JSON' => [['redos', "/\xff/", '--format=json']];
    }

    /**
     * @param list<string> $arguments
     */
    private function runRegex(array $arguments): int
    {
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);

        $application = ApplicationFactory::create(new Output(false, false, '#', '-', $errors));

        ob_start();

        try {
            return $application->run(['regex', '--no-ansi', '--no-visuals', ...$arguments]);
        } finally {
            ob_end_clean();
            fclose($errors);
        }
    }

    /**
     * The rows whose exit code depends on what a pattern means are read
     * first on the running engine: "\K" in a lookbehind compiles up to PHP
     * 8.4 and is refused from 8.5, "a{2,1}" is refused by every PCRE2, and
     * "a{,3}" is a quantifier from PCRE2 10.43, text before.
     *
     * @param list<string> $arguments
     */
    private static function assertEngineReadings(array $arguments): void
    {
        if (\in_array('/(?<=a\\Kb)c/', $arguments, true)) {
            self::assertEngineKeepInLookbehind();
        }
        if (\in_array('/a{2,1}/', $arguments, true)) {
            self::assertEngineRefuses('/a{2,1}/');
        }
        if (\in_array('/a{,3}/', $arguments, true)) {
            self::assertEngineReadsQuantifierWithoutMin();
        }
    }

    private static function assertEngineRefuses(string $pattern): void
    {
        self::assertFalse(@preg_match($pattern, 'aa'), 'PCRE2 '.\PCRE_VERSION);
    }
}
