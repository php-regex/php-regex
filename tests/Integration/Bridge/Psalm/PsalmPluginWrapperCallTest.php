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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Linter\Exception\UnsupportedTargetException;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The plugin reads the static methods of the wrapper libraries as it reads
 * the preg_* functions, through the presets `regex lint` and the PHPStan
 * extension read them through: composer/pcre, nette/utils, spatie/regex,
 * Laravel's Str and the assertion libraries. A constant pattern the target
 * refuses is an InvalidRegexPattern on its argument, in the words of a
 * preg_* call; with <redos>true</redos>, a ReDoS finding is the one of the
 * preg function the method runs, the loop of one that looks for every match
 * included.
 *
 * Psalm reads the classes of the libraries from stubs: it analyses no
 * argument of a call on a class it does not know, and no library need be
 * installed where the fixtures are read.
 */
final class PsalmPluginWrapperCallTest extends TestCase
{
    use PcreRule;
    use TemporaryProject;

    private const LIBRARIES = 'Wrappers/libraries.phpstub';

    private const CALLS = 'Wrappers/wrapper_calls.php';

    private const REDOS = 'Wrappers/wrapper_redos.php';

    private const FILES = [self::CALLS, self::REDOS];

    private const INVALID = '~//\s*InvalidRegexPattern:\s*(\S+)\s*$~';

    private const ATTEMPT = '~//\s*attempt\s*$~';

    private const LOOP = '~//\s*loop:\s*(\w+)\s*$~';

    /**
     * The first pattern a line of a fixture writes, between single quotes.
     */
    private const LITERAL = '~\'(/(?:[^\'\\\\]|\\\\.)*/[a-zA-Z]*)\'~';

    /**
     * The patterns of the arguments that are not the pattern as the call
     * runs it: one held by a name, and one nette/utils appends u to.
     */
    private const HELD = [
        '$pattern' => '/(k/',
        'Patterns::OPEN' => '/(s/',
        "'/\\x{100}(/'" => '/\x{100}(/u',
    ];

    #[Test]
    #[DataProvider('provideInvalidPatterns')]
    public function test_plugin_reports_an_invalid_pattern_of_a_wrapper_call_on_its_argument(int $line, string $argument, string $pattern): void
    {
        $this->assertFalse(PsalmRun::runningEngineCompiles($pattern), 'Oracle: the running engine compiles '.$pattern.'.');

        // Psalm on PHP 8.4, judged with the PCRE2 the rule gives it.
        $validation = RegexParser::create(['php_version' => '8.4', 'pcre_version' => self::judgedPcre(80400)])->validate($pattern);
        $this->assertFalse($validation->isValid, $pattern.' is valid for PHP 8.4: the row is wrong.');
        $this->assertNotNull($validation->error);
        $this->assertNotNull($validation->offset);

        $reported = array_values(array_filter(
            self::reported(self::CALLS, []),
            static fn (array $issue): bool => 'InvalidRegexPattern' === $issue[1] && $issue[0] === $line,
        ));

        $this->assertCount(1, $reported, \sprintf('Line %d: one InvalidRegexPattern expected.', $line));
        $this->assertSame($argument, $reported[0][3], 'The issue points at the pattern.');
        // The words of a preg_* call: the first line of the library's
        // message, its final period moved past the offset.
        $reason = rtrim(explode("\n", $validation->error)[0], '.');
        $this->assertSame(\sprintf('Regex pattern is invalid for PHP 8.4 with PCRE2 %s: %s (offset %d).', self::judgedPcre(80400), $reason, $validation->offset), $reported[0][2]);
    }

    /**
     * Every other line of the fixture is analysed and carries nothing: a
     * call the plugin does not read, a valid pattern, and a pattern that
     * nette/utils reads with the u it appends under utf8: true.
     */
    #[Test]
    public function test_plugin_reports_no_other_line_of_the_wrapper_calls(): void
    {
        $this->assertFalse(PsalmRun::runningEngineCompiles('/\x{100}/'), 'Oracle: without u, the running engine compiles a code point past 255.');
        $this->assertTrue(PsalmRun::runningEngineCompiles('/\x{100}/u'), 'Oracle: with u, the running engine refuses a code point past 255.');
        $this->assertIsInt(key(['7' => 'x']), 'Oracle: PHP stores the key "7" as a string.');

        $lines = [];
        foreach (self::provideInvalidPatterns() as $row) {
            $lines[] = $row['line'];
        }
        $this->assertNotSame([], $lines);

        // Psalm stops reading a function at a call it cannot analyse: no
        // issue of its own means it read each of them to its end.
        $others = array_values(array_filter(
            self::reported(self::CALLS, []),
            static fn (array $issue): bool => !('InvalidRegexPattern' === $issue[1] && \in_array($issue[0], $lines, true)),
        ));

        $this->assertSame([], $others);
    }

    /**
     * Each finding of a wrapper call is the one of the preg call it runs,
     * word for word: the verdict on one attempt, or the cost of the loop of
     * the function that looks for every match, which a limit the call
     * spells out bounds.
     */
    #[Test]
    public function test_plugin_words_the_redos_finding_of_a_wrapper_call_as_the_one_of_the_preg_call_it_runs(): void
    {
        $exponential = PcreInterpreter::pattern('/^(\w+\s?)+$/');
        $this->assertFalse(@preg_match($exponential, str_repeat('0', 40).'!'), 'Oracle: the attack does not exhaust the backtrack limit.');
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), 'Oracle: '.preg_last_error_msg());
        $retry = PcreInterpreter::pattern('/(?:a+\d)?/');
        $this->assertSame(1002, preg_match_all($retry, str_repeat('a', 1000).'!'), 'Oracle: preg_match_all() stops before the end of the run.');
        $this->assertSame(1, preg_match($retry, str_repeat('a', 1000).'!'), 'Oracle: preg_match() goes on.');

        $expected = self::expectedFindings();
        $found = self::findingsByLine();

        // The preg calls of the fixture give the words.
        $words = [];
        foreach ($expected as $line => [$type, $function, $pattern, $text]) {
            $issues = $found[$line] ?? [];
            if (str_starts_with(ltrim($text), 'preg_')) {
                $this->assertCount(1, $issues, 'Line '.$line.': '.trim($text));
                $this->assertSame($type, $issues[0][1], 'Line '.$line);
                $words[$function][$pattern] = $issues[0][2];
            }
        }
        $this->assertCount(6, $words, 'One attempt and the loop of five functions.');

        $lazy = PatternFunctionRegistry::defaults()->lookupMethod('Nette\Utils\Strings', 'matchAll')?->decidedBy(1)->everyMatch;
        $checked = 0;
        foreach ($expected as $line => [$type, $function, $pattern, $text, $literal]) {
            $issues = $found[$line] ?? [];
            if ('lazy' === $function) {
                // What the preset prices the loop nette/utils runs itself as.
                $this->assertCount(null === $lazy ? 0 : 1, $issues, 'Line '.$line.': '.trim($text));

                continue;
            }

            $this->assertArrayHasKey($pattern, $words[$function] ?? [], 'Line '.$line.': no preg call of the fixture runs '.$pattern.' through '.$function.'.');
            $this->assertCount(1, $issues, 'Line '.$line.': '.trim($text));
            $this->assertSame($type, $issues[0][1], 'Line '.$line.': '.trim($text));
            $this->assertSame($words[$function][$pattern], $issues[0][2], 'Line '.$line.': '.trim($text));
            $this->assertSame("'".$literal."'", $issues[0][3], 'Line '.$line.': the issue points at the pattern.');
            $checked++;
        }
        $this->assertGreaterThan(30, $checked);

        $this->assertSame([], array_values(array_diff(array_keys($found), array_keys($expected))), 'A line of the fixture that expects no finding carries one.');
    }

    /**
     * self:: and static:: name the class a call is written in, parent:: the
     * one it extends, as the name of the class does: a method of a library
     * that calls another one of its own, and a class of the project that
     * extends one. The classes are written in a project of their own, read
     * once: Psalm keeps what it read of a class, and of a method it found
     * nothing in, from one run of a process to the next.
     */
    #[Test]
    public function test_plugin_reads_a_call_that_names_its_class_by_self_static_or_parent(): void
    {
        foreach (['/(a/', '/(b/', '/(c/'] as $pattern) {
            $this->assertFalse(PsalmRun::runningEngineCompiles($pattern), 'Oracle: the running engine compiles '.$pattern.'.');
        }

        // beberlei/assert takes the value first, the pattern second.
        $directory = $this->makeProject(['Assertion.php' => <<<'PHP'
            <?php

            namespace Assert;

            class Assertion
            {
                public static function regex(string $value, string $pattern): bool
                {
                    return '' !== $value.$pattern;
                }

                public static function opens(string $value): bool
                {
                    return self::regex($value, '/(a/') || static::regex($value, '/(b/');
                }
            }

            final class Checks extends Assertion
            {
                public static function closes(string $value): bool
                {
                    return parent::regex($value, '/(c/') && self::regex($value, '/(d/');
                }
            }

            PHP]);

        $reported = [];
        foreach (PsalmRun::reported(PsalmRun::issues(['Assertion.php'], '8.4', [], [], false, $directory)) as [$line, $type, , $selected]) {
            if ('InvalidRegexPattern' === $type) {
                $reported[] = [$line, $selected];
            }
        }
        sort($reported);

        // self:: in the class that extends the library names that class,
        // which is none of a library: '/(d/' is not read.
        $this->assertSame([[14, "'/(a/'"], [14, "'/(b/'"], [22, "'/(c/'"]], $reported);
    }

    /**
     * A PHP that ships PCRE1 is refused at a wrapper call as at a preg_*
     * call: the run stops, as regex lint does, where no preg_* call stands
     * before it.
     */
    #[Test]
    public function test_plugin_refuses_a_php_on_pcre1_at_a_wrapper_call(): void
    {
        $directory = $this->makeProject(['calls.php' => <<<'CODE'
            <?php

            function f(string $s): void
            {
                \PHPUnit\Framework\Assert::assertMatchesRegularExpression('/a/', $s);
            }

            CODE]);

        try {
            PsalmRun::issues(['calls.php'], '7.2', [], [], false, $directory);
            $this->fail('A PHP on PCRE1 was judged.');
        } catch (UnsupportedTargetException $e) {
            $this->assertSame('Psalm phpVersion names PHP 7.2: PHP 7.2 and earlier ship PCRE1, which this library does not judge. Name a PHP version of 7.3 or later to judge for.', $e->getMessage());
        }
    }

    /**
     * A target the library cannot read otherwise, a config.platform.lib-pcre
     * that names no PCRE2 release, leaves a wrapper call unread as it leaves
     * a preg_* call: nothing is reported, and the run goes on.
     */
    #[Test]
    public function test_plugin_reads_no_wrapper_call_under_a_lib_pcre_that_names_no_pcre2_release(): void
    {
        $this->assertFalse(PsalmRun::runningEngineCompiles('/(/'), 'Oracle: the running engine compiles /(/.');
        $this->assertFalse(PsalmRun::runningEngineCompiles('/(a/'), 'Oracle: the running engine compiles /(a/.');

        $directory = $this->makeProject([
            'composer.json' => '{"config": {"platform": {"lib-pcre": "8.45"}}}',
            'calls.php' => <<<'CODE'
                <?php

                function f(string $s): void
                {
                    preg_match('/(/', $s);
                    \PHPUnit\Framework\Assert::assertMatchesRegularExpression('/(a/', $s);
                }

                CODE,
        ]);

        $this->assertSame([], PsalmRun::reported(PsalmRun::inFile(PsalmRun::issues(['calls.php'], '8.4', [], [], false, $directory), 'calls.php', $directory)));
    }

    /**
     * The check is off unless <redos> turns it on, for a wrapper as for a
     * preg_* call.
     */
    #[Test]
    public function test_plugin_reports_no_redos_finding_of_a_wrapper_call_unless_asked(): void
    {
        $this->assertSame([], self::reported(self::REDOS, []));
    }

    /**
     * @return iterable<string, array{line: int, argument: string, pattern: string}>
     */
    public static function provideInvalidPatterns(): iterable
    {
        foreach (file(PsalmRun::FIXTURES.'/'.self::CALLS, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $text) {
            if (1 !== preg_match(self::INVALID, $text, $marker)) {
                continue;
            }

            $argument = $marker[1];
            $call = 1 === preg_match('~([\w\\\\]+::\w+|preg_\w+)\(~', $text, $name) ? $name[1] : 'array item';

            yield \sprintf('line %d %s %s', $index + 1, $call, $argument) => ['line' => $index + 1, 'argument' => $argument, 'pattern' => self::HELD[$argument] ?? substr($argument, 1, -1)];
        }
    }

    /**
     * What each marked line of the ReDoS fixture expects: the issue, the
     * preg function whose words it takes ("attempt" for one attempt,
     * "lazy" for what the preset says), the pattern as the call runs it,
     * the line itself and the pattern as the line writes it.
     *
     * @return array<int, array{string, string, string, string, string}>
     */
    private static function expectedFindings(): array
    {
        $expected = [];
        foreach (file(PsalmRun::FIXTURES.'/'.self::REDOS, \FILE_IGNORE_NEW_LINES) ?: [] as $index => $text) {
            $loop = 1 === preg_match(self::LOOP, $text, $marker) ? $marker[1] : null;
            if (null === $loop && 1 !== preg_match(self::ATTEMPT, $text)) {
                continue;
            }

            if (1 !== preg_match(self::LITERAL, $text, $literal)) {
                throw new \LogicException(\sprintf('Line %d of %s writes no pattern.', $index + 1, self::REDOS));
            }

            $pattern = $literal[1].(str_contains($text, 'utf8: true') ? 'u' : '');
            $expected[$index + 1] = [null === $loop ? 'RegexRedos' : 'RegexRedosSearch', $loop ?? 'attempt', $pattern, $text, $literal[1]];
        }

        return $expected;
    }

    /**
     * The plugin's findings of the ReDoS fixture under <redos>, at
     * threshold medium, by line: each with its line, its issue, its message
     * and the text it selects. Any other issue of the file fails: Psalm
     * stops reading a function at a call it cannot analyse.
     *
     * @return array<int, list<array{int, string, string, string}>>
     */
    private static function findingsByLine(): array
    {
        $found = [];
        foreach (self::reported(self::REDOS, ['redos' => 'true', 'redosThreshold' => 'medium']) as $issue) {
            self::assertContains($issue[1], ['RegexRedos', 'RegexRedosSearch'], implode(' ', $issue));
            $found[$issue[0]][] = $issue;
        }

        return $found;
    }

    /**
     * Each issue of a fixture but a Trace, in order: its line, its type,
     * its message and the text it selects.
     *
     * @param array<string, string> $options the options of the plugin
     *
     * @return list<array{int, string, string, string}>
     */
    private static function reported(string $file, array $options): array
    {
        return PsalmRun::reported(PsalmRun::inFile(PsalmRun::issues(self::FILES, '8.4', $options, stubs: [self::LIBRARIES]), $file));
    }
}
