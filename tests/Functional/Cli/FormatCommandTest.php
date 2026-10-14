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

use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\RunsRegexCli;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * regex format <pattern> [--compact] [--width=N] [--json]: the pattern, laid
 * out or compacted, alone on stdout, so the output can be piped or pasted
 * back as it is.
 */
final class FormatCommandTest extends TestCase
{
    use RunsRegexCli;
    use TemporaryProject;

    private const URL = '#^(?<scheme>https?)://(?:(?<user>[^:@/]+)(?::(?<pass>[^@/]*))?@)?(?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)(?::(?<port>\d+))?(?<path>/[^?\#]*)?(?:\?(?<query>[^\#]*))?(?:\#(?<fragment>.*))?$#i';

    private const URL_FORMATTED = <<<'REGEX'
        #
            ^
            (?<scheme>https?)
            ://
            (?:
                (?<user>[^:@/]+)
                (?::(?<pass>[^@/]*))?
                @
            )?
            (?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)
            (?::(?<port>\d+))?
            (?<path>/[^?\#]*)?
            (?:\?(?<query>[^\#]*))?
            (?:\#(?<fragment>.*))?
            $
        #ix
        REGEX;

    /**
     * 47 bytes: it fits the default width and breaks at 24.
     */
    private const DATE = '/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/';

    private const DATE_AT_24 = <<<'REGEX'
        /
            ^
            (?<year>\d{4})
            -
            (?<month>\d{2})
            -
            (?<day>\d{2})
            $
        /x
        REGEX;

    private const LAID_OUT = <<<'REGEX'
        /
            (?<year>\d{4})   # year
            -
            (?<month>\d{2})  # month
        /x
        REGEX;

    private const LAID_OUT_COMPACTED = '/(?<year>\d{4})-(?<month>\d{2})/';

    /**
     * 91 bytes, not under x: too long to fit, and a{ 1,2 } is a quantifier
     * from PCRE2 10.43 on but a literal before it, so x cannot be added.
     */
    private const SPACED_QUANTIFIER = '/a{ 1,2 }bcdefghijklmnopqrstuvwxyz0123456789abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJ/';

    #[Test]
    public function test_format_prints_a_long_pattern_laid_out(): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', self::URL]);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(self::URL_FORMATTED."\n", $stdout);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_format_prints_a_fitting_pattern_unchanged(): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', self::DATE]);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(self::DATE."\n", $stdout);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_format_breaks_at_the_width_asked_for(): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', self::DATE, '--width=24']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(self::DATE_AT_24."\n", $stdout);
    }

    #[Test]
    public function test_format_accepts_a_width_of_one(): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', '/ab/', '--width=1']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame("/\n    ab\n/x\n", $stdout);
    }

    #[Test]
    public function test_format_compact_prints_a_laid_out_pattern_on_one_line(): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', self::LAID_OUT, '--compact']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(self::LAID_OUT_COMPACTED."\n", $stdout);
        $this->assertSame('', $stderr);
    }

    #[Test]
    public function test_format_compact_undoes_format(): void
    {
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['format', '--compact', self::URL_FORMATTED]);

        $this->assertSame(0, $exitCode, $stdout);
        $this->assertSame(self::URL."\n", $stdout);
    }

    /**
     * @param list<string>                                                                   $arguments
     * @param array{pattern: string, formatted: string, changed: bool, refusal: string|null} $expected
     */
    #[Test]
    #[DataProvider('provideJsonRuns')]
    public function test_format_json_reports_the_input_the_output_and_what_happened(array $arguments, array $expected): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', ...$arguments]);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('format', $document);
        $this->assertSame($expected, $document);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, expected: array{pattern: string, formatted: string, changed: bool, refusal: string|null}}>
     */
    public static function provideJsonRuns(): iterable
    {
        yield 'a long pattern, --json' => [
            'arguments' => [self::URL, '--json'],
            'expected' => ['pattern' => self::URL, 'formatted' => self::URL_FORMATTED, 'changed' => true, 'refusal' => null],
        ];
        yield 'a fitting pattern, --format=json' => [
            'arguments' => [self::DATE, '--format=json'],
            'expected' => ['pattern' => self::DATE, 'formatted' => self::DATE, 'changed' => false, 'refusal' => null],
        ];
        yield 'a fitting pattern at --width=24, --json first' => [
            'arguments' => ['--json', '--width=24', self::DATE],
            'expected' => ['pattern' => self::DATE, 'formatted' => self::DATE_AT_24, 'changed' => true, 'refusal' => null],
        ];
        yield 'a laid-out pattern, --compact --json' => [
            'arguments' => [self::LAID_OUT, '--compact', '--json'],
            'expected' => ['pattern' => self::LAID_OUT, 'formatted' => self::LAID_OUT_COMPACTED, 'changed' => true, 'refusal' => null],
        ];
        yield 'a refused pattern, --json' => [
            'arguments' => [self::SPACED_QUANTIFIER, '--json'],
            'expected' => ['pattern' => self::SPACED_QUANTIFIER, 'formatted' => self::SPACED_QUANTIFIER, 'changed' => false, 'refusal' => 'spaced_quantifier'],
        ];
    }

    #[Test]
    public function test_format_prints_a_refused_pattern_as_it_came_with_one_note_on_stderr(): void
    {
        // The pattern compiles: the refusal is the formatter's, not PCRE's.
        $this->assertSame(0, preg_match(self::SPACED_QUANTIFIER, ''));
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', self::SPACED_QUANTIFIER]);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(self::SPACED_QUANTIFIER."\n", $stdout);
        $this->assertNotSame('', trim($stderr), 'A refusal says so.');
        $this->assertSame(1, substr_count(rtrim($stderr, "\n"), "\n") + 1, 'One note line, not more: '.$stderr);
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataProvider('provideRefusalNotes')]
    public function test_format_names_the_refusal_in_its_note(array $arguments, string $named): void
    {
        $this->assertSame(0, preg_match($arguments[0], ''), 'The pattern compiles.');
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', ...$arguments]);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame($arguments[0]."\n", $stdout);
        $this->assertStringContainsString($named, $stderr);
    }

    /**
     * @return iterable<string, array{arguments: list<string>, named: string}>
     */
    public static function provideRefusalNotes(): iterable
    {
        yield 'a # comment under (*CR)' => ['arguments' => ["/(*CR)a # c\nb/x"], 'named' => '(*CR)'];
        yield 'x turned off at the top level' => ['arguments' => ['/(?-x)abcdefghijklmnop(?:q)/x', '--width=10'], 'named' => '(?-x)'];
        yield 'a spaced quantifier' => ['arguments' => [self::SPACED_QUANTIFIER], 'named' => 'a{ 1,2 }'];
    }

    /**
     * The result is what the command prints: --quiet silences status lines
     * only, as it does for the reports of lint.
     */
    #[Test]
    public function test_format_prints_its_result_under_quiet(): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', self::DATE, '--width=24', '--quiet']);

        $this->assertSame(0, $exitCode, $stdout.$stderr);
        $this->assertSame(self::DATE_AT_24."\n", $stdout);
    }

    #[Test]
    #[DataProvider('providePatternsThatDoNotParse')]
    public function test_format_exits_1_on_a_pattern_that_does_not_parse(string $pattern): void
    {
        $this->assertFalse(@preg_match($pattern, ''));
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', $pattern]);

        $this->assertSame(1, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('Expected ) at end of input', $stdout.$stderr);
        $this->assertStringNotContainsString($pattern."\n", $stdout, 'An invalid pattern is not printed back as if it had been formatted.');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatternsThatDoNotParse(): iterable
    {
        yield 'an unclosed group' => ['pattern' => '/(a/'];
    }

    #[Test]
    public function test_format_exits_1_on_a_pattern_that_does_not_parse_as_json(): void
    {
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['format', '/(a/', '--json']);

        $this->assertSame(1, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        JsonContract::assertShape('error', $document);
        $this->assertIsString($document['error']);
        $this->assertStringContainsString('Expected ) at end of input', (string) $document['error']);
    }

    #[Test]
    #[DataProvider('provideInvalidWidths')]
    public function test_format_exits_2_on_a_width_it_cannot_use(string $option): void
    {
        $this->enterProject();

        [$exitCode, $stdout, $stderr] = $this->runRegex(['format', self::DATE, $option]);

        $this->assertSame(2, $exitCode, $stdout.$stderr);
        $this->assertStringContainsString('--width', $stdout.$stderr);
        $this->assertStringNotContainsString(self::DATE, $stdout, 'Nothing is formatted when the command line cannot be used.');
    }

    /**
     * @return iterable<string, array{option: string}>
     */
    public static function provideInvalidWidths(): iterable
    {
        yield 'zero' => ['option' => '--width=0'];
        yield 'negative' => ['option' => '--width=-1'];
        yield 'not a number' => ['option' => '--width=abc'];
        yield 'not an integer' => ['option' => '--width=1.5'];
        yield 'empty' => ['option' => '--width='];
    }

    #[Test]
    public function test_format_reports_a_width_it_cannot_use_as_the_json_envelope(): void
    {
        $this->enterProject();

        [$exitCode, $stdout] = $this->runRegex(['format', self::DATE, '--width=0', '--json']);

        $this->assertSame(2, $exitCode, $stdout);
        $document = JsonContract::decodeDocument($stdout);
        $this->assertSame('usage', $document['stage'] ?? null);
        $this->assertIsString($document['error'] ?? null);
        $this->assertStringContainsString('--width', (string) $document['error']);
    }
}
