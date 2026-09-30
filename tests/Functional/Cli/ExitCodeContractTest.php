<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Functional\Cli;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Cli\ApplicationFactory;
use RegexParser\Cli\Output;
use RegexParser\Tests\Support\TemporaryProject;

/**
 * Every command of the binary exits with one of three codes: 0 when it did
 * what it was asked and found nothing wrong, 1 when the patterns or files it
 * judged have a problem, 2 when the command line or the configuration cannot
 * be used.
 */
final class ExitCodeContractTest extends TestCase
{
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
        yield 'clear-cache with an invalid --php-version' => [['--php-version=abc', 'clear-cache']];

        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph'] as $command) {
            yield $command.' without a pattern' => [[$command]];
            yield $command.' with an unknown option' => [[$command, '/a/', '--bogus']];
            yield $command.' with an invalid --php-version' => [['--php-version=abc', $command, '/a/']];
        }

        foreach (['analyze', 'debug', 'diagram', 'highlight', 'explain', 'transpile', 'redos', 'graph'] as $command) {
            yield $command.' with an unknown --format' => [[$command, '/a/', '--format=xml']];
        }

        foreach (['analyze', 'debug', 'diagram', 'highlight', 'explain', 'redos'] as $command) {
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
        yield 'diagram with an --output it cannot write' => [['diagram', '/a/', '--output=missing/dir/a.txt']];
        yield 'diagram with an SVG --output it cannot write' => [['diagram', '/a/', '--format=svg', '--output=missing/dir/a.svg']];
        yield 'graph with an --output it cannot write' => [['graph', '/a/', '--output=missing/dir/a.dot']];
        yield 'redos with an --input-file that is a directory' => [['redos', '/a/', '--input-file=.']];
        yield 'parse with --validate and an unknown option' => [['parse', '/a/', '--validate', '--bogus']];
        yield 'compare without its second pattern' => [['compare', '/a/']];
        yield 'compare with an unknown option' => [['compare', '/a/', '/b/', '--bogus']];
        yield 'compare with an unknown --method' => [['compare', '/a/', '/b/', '--method=bogus']];
        yield 'compare with an unknown --minimizer' => [['compare', '/a/', '/b/', '--minimizer=bogus']];
        yield 'compare with an invalid --php-version' => [['--php-version=abc', 'compare', '/a/', '/b/']];
        yield 'lint with an unknown option' => [['lint', '--bogus']];
        yield 'lint with an unknown --format' => [['lint', '--format=xml']];
        yield 'lint with an invalid --php-version' => [['--php-version=abc', 'lint', '.', '--jobs=1']];
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
        $this->enterProject(['src/a.php' => self::BAD_PHP_FILE]);

        $this->assertSame(1, $this->runRegex($arguments));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function providePatternProblems(): iterable
    {
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph'] as $command) {
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
        yield 'analyze on a pattern a JSON report cannot hold' => [['analyze', "/\xff/", '--format=json']];
        yield 'debug on a pattern a JSON report cannot hold' => [['debug', "/\xff/", '--format=json']];
        yield 'redos on a pattern a JSON report cannot hold' => [['redos', "/\xff/", '--format=json']];
        yield 'compare on a pattern with a lookaround' => [['compare', '/(?=a)/', '/a/']];
        yield 'compare --method=subset on a pattern that is no subset' => [['compare', '/a|b/', '/a/', '--method=subset']];
        yield 'graph on a pattern it cannot draw' => [['graph', '/a\\1/']];
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideSuccesses')]
    public function test_a_command_that_finds_nothing_wrong_exits_0(array $arguments): void
    {
        $this->enterProject(['src/a.php' => "<?php\n\npreg_match('/a/', 'abc');\n", 'empty/b.php' => "<?php\n"]);

        $this->assertSame(0, $this->runRegex($arguments));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function provideSuccesses(): iterable
    {
        foreach (['analyze', 'debug', 'diagram', 'highlight', 'parse', 'validate', 'explain', 'transpile', 'redos', 'graph'] as $command) {
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
        yield 'clear-cache' => [['clear-cache']];
        yield 'lint on valid files' => [['lint', 'src', '--jobs=1', '--format=json']];
        yield 'lint on files without patterns' => [['lint', 'empty', '--jobs=1']];
        yield 'compare --method=subset on a subset' => [['compare', '/a/', '/a|b/', '--method=subset']];
        yield 'compare --method=equivalence on equivalent patterns' => [['compare', '/a/', '/a/', '--method=equivalence']];
        yield 'diagram written to a file' => [['diagram', '/a/', '--output=a.txt']];
        yield 'graph written to a file' => [['graph', '/a/', '--output=a.dot']];
        yield 'transpile as JSON' => [['transpile', '/a/', '--format=json']];
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
}
