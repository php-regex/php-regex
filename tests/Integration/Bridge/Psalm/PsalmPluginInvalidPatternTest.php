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

use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Analyzer\IssueData;

/**
 * The plugin reports the constant pattern of every global preg_* call the
 * PHP version Psalm analyses for refuses, as an InvalidRegexPattern issue on
 * the pattern argument: the library's message and the offset in the
 * pattern, judged with the PCRE2 that PHP version bundles. Psalm itself does
 * not read patterns, so the plugin reports what the running engine refuses
 * too.
 */
final class PsalmPluginInvalidPatternTest extends TestCase
{
    private const FILE = 'Invalid/invalid_patterns.php';

    private const MARKER = '~//\s*InvalidRegexPattern:\s*(\S+)\s*$~';

    /**
     * The patterns of the arguments that are not a literal.
     */
    private const HELD = ['$pattern' => '/(/'];

    #[Test]
    #[DataProvider('provideInvalidPatterns')]
    public function test_plugin_reports_an_invalid_pattern_on_its_argument(int $line, string $argument, string $pattern): void
    {
        $validation = RegexParser::create(['php_version' => '8.4'])->validate($pattern);
        $this->assertFalse($validation->isValid, $pattern.' is valid for PHP 8.4: the row is wrong.');
        $this->assertNotNull($validation->error);
        $this->assertNotNull($validation->offset);

        $reported = array_values(array_filter(
            PsalmRun::inFile(self::issues(), self::FILE),
            static fn (IssueData $issue): bool => 'InvalidRegexPattern' === $issue->type && $issue->line_from === $line,
        ));

        $this->assertCount(1, $reported, \sprintf('Line %d: one InvalidRegexPattern expected.', $line));
        $issue = $reported[0];
        $this->assertSame($argument, $issue->selected_text, 'The issue points at the pattern argument.');
        $this->assertStringStartsWith('Regex pattern is invalid for PHP 8.4 with PCRE2 10.44: ', $issue->message);
        $this->assertStringContainsString(rtrim($validation->error, '.'), (string) $issue->message);
        $this->assertStringContainsString('offset '.$validation->offset, (string) $issue->message);
        // As the docs print it: the first line of the library's message, its
        // final period moved past the offset.
        $reason = rtrim(explode("\n", $validation->error)[0], '.');
        $this->assertSame(\sprintf('Regex pattern is invalid for PHP 8.4 with PCRE2 10.44: %s (offset %d).', $reason, $validation->offset), $issue->message);
    }

    #[Test]
    public function test_plugin_reports_no_other_line(): void
    {
        $lines = [];
        foreach (self::provideInvalidPatterns() as $row) {
            $lines[] = $row['line'];
        }

        $others = array_values(array_filter(
            PsalmRun::inFile(self::issues(), self::FILE),
            static fn (IssueData $issue): bool => !\in_array($issue->type, ['Trace', 'CheckType'], true)
                && !('InvalidRegexPattern' === $issue->type && \in_array($issue->line_from, $lines, true)),
        ));

        $this->assertSame([], array_map(PsalmRun::describe(...), $others));
    }

    #[Test]
    #[DataProvider('provideTypeChecks')]
    public function test_plugin_asserts_nothing_on_a_pattern_it_reports(string $file, int $from, int $to, string $variable, string $expected): void
    {
        $issues = array_values(array_filter(
            PsalmRun::inFile(self::issues(), $file),
            static fn (IssueData $issue): bool => $issue->line_from >= $from && $issue->line_from <= $to + 1,
        ));
        $traces = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'Trace' === $issue->type && str_starts_with($issue->message, $variable.': ')));
        $failures = array_values(array_filter($issues, static fn (IssueData $issue): bool => 'CheckType' === $issue->type || 'InvalidDocblock' === $issue->type));

        $this->assertNotSame([], $traces, \sprintf('%s:%d: Psalm never reached the check of %s.', $file, $from, $variable));
        $this->assertSame([], array_map(PsalmRun::describe(...), $failures), \sprintf('%s:%d expects %s = %s; Psalm traced %s.', $file, $from, $variable, $expected, $traces[0]->message));
    }

    /**
     * @return iterable<string, array{line: int, argument: string, pattern: string}>
     */
    public static function provideInvalidPatterns(): iterable
    {
        $lines = file(PsalmRun::FIXTURES.'/'.self::FILE, \FILE_IGNORE_NEW_LINES) ?: [];
        foreach ($lines as $index => $text) {
            if (1 !== preg_match(self::MARKER, $text, $marker)) {
                continue;
            }

            $argument = $marker[1];
            $pattern = self::HELD[$argument] ?? substr($argument, 1, -1);
            $function = preg_match_all('~(preg_\w+)\(~', $text, $calls) > 0 ? end($calls[1]) : 'array key';

            yield \sprintf('line %d %s %s', $index + 1, $function, $argument) => ['line' => $index + 1, 'argument' => $argument, 'pattern' => $pattern];
        }
    }

    /**
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string}>
     */
    public static function provideTypeChecks(): iterable
    {
        return PsalmRun::typeChecks([self::FILE]);
    }

    /**
     * @return list<IssueData>
     */
    private static function issues(): array
    {
        return PsalmRun::issues([self::FILE]);
    }
}
