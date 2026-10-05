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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\ConsoleFormatter;
use PHPRegex\Linter\Formatter\OutputConfiguration;
use PHPRegex\Linter\LintReport;
use PHPRegex\Optimizer\OptimizationResult;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The console report reads the delimiter and the flags as PHP does: PHP
 * skips the leading bytes C's isspace() accepts (space, \t, \n, \v, \f, \r)
 * and refuses a NUL delimiter ("Delimiter must not be alphanumeric,
 * backslash, or NUL byte").
 *
 * Under /x a rewrite is shown line by line, its change block marked "---";
 * any other rewrite is shown on one line, its newlines escaped.
 */
final class ConsoleDelimiterConformanceTest extends TestCase
{
    private const LINE_DIFF_MARKER = "\n         ---\n";

    /**
     * @return iterable<string, array{lead: string}>
     */
    public static function provideLeadingWhitespace(): iterable
    {
        yield 'space' => ['lead' => ' '];
        yield 'tab' => ['lead' => "\t"];
        yield 'newline' => ['lead' => "\n"];
        yield 'carriage return' => ['lead' => "\r"];
        yield 'vertical tab' => ['lead' => "\x0B"];
        yield 'form feed' => ['lead' => "\x0C"];
    }

    #[Test]
    #[DataProvider('provideLeadingWhitespace')]
    public function test_extended_mode_is_read_after_the_whitespace_php_skips(string $lead): void
    {
        $original = $lead."/a{1}\n b/x";
        $optimized = $lead."/a\n b/x";

        // Oracle: the delimiter is found after the whitespace, and /x drops
        // the newline and the space of the body, so "ab" matches.
        $this->assertSame(1, preg_match($original, 'ab'));

        $output = self::formatter()->format(self::reportWithRewrite($original, $optimized));

        $this->assertStringContainsString(self::LINE_DIFF_MARKER, $output);
    }

    #[Test]
    public function test_a_pattern_behind_a_nul_byte_has_no_flags(): void
    {
        $original = self::nulByte()."/a{1}\n b/x";
        $optimized = self::nulByte()."/a\n b/x";

        // Oracle: PHP refuses NUL as a delimiter, whatever follows it.
        $warning = '';
        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $this->assertFalse(preg_match($original, 'ab'));
        } finally {
            restore_error_handler();
        }
        $this->assertStringContainsString('Delimiter must not be alphanumeric, backslash, or NUL', $warning);

        $output = self::formatter()->format(self::reportWithRewrite($original, $optimized));

        $this->assertStringNotContainsString(self::LINE_DIFF_MARKER, $output);
        $this->assertStringContainsString('         - \x00/a{1}\n b/x'.\PHP_EOL, $output);
    }

    #[Test]
    #[DataProvider('provideLeadingWhitespace')]
    public function test_is_extended_mode_pattern_skips_what_php_skips(string $lead): void
    {
        $this->assertSame(1, preg_match($lead.'/a b/x', 'ab'));

        $this->assertTrue(self::invoke(self::formatter(), 'isExtendedModePattern', $lead.'/a b/x'));
    }

    #[Test]
    public function test_is_extended_mode_pattern_refuses_a_nul_byte(): void
    {
        $pattern = self::nulByte().'/a b/x';
        $this->assertFalse(@preg_match($pattern, 'ab'));

        $this->assertFalse(self::invoke(self::formatter(), 'isExtendedModePattern', $pattern));
    }

    /**
     * The NUL guard of the splitter cannot be reached through format(): the
     * text is escaped first, so a NUL arrives as "\x00" and its backslash is
     * refused already. It is pinned on the splitter itself.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNoDelimiter(): iterable
    {
        yield 'NUL delimiter' => ['pattern' => "\0a\0"];
        yield 'NUL delimiter with flags' => ['pattern' => "\0a\0i"];
        yield 'backslash' => ['pattern' => '\\a\\'];
        yield 'letter' => ['pattern' => 'aba'];
        yield 'leading space, the text not being trimmed' => ['pattern' => ' /a/'];
    }

    #[Test]
    #[DataProvider('provideNoDelimiter')]
    public function test_split_pattern_with_flags_refuses_what_php_refuses(string $pattern): void
    {
        $this->assertNull(self::invoke(self::formatter(), 'splitPatternWithFlags', $pattern));
    }

    #[Test]
    public function test_a_nul_delimited_pattern_is_not_coloured_as_a_pattern(): void
    {
        $this->assertFalse(@preg_match(self::nulByte().'a+'.self::nulByte(), 'a'));

        $formatter = new ConsoleFormatter(new AnalysisService(RegexParser::create(['cache' => null])), new OutputConfiguration(ansi: true));
        $output = $formatter->format(self::reportWithIssue("\0a+\0"));

        // The text after the arrow is printed as it is, no colour around it:
        // the NUL bytes shown escaped, as four characters each.
        $shown = '\x00a+\x00';
        $this->assertSame(10, \strlen($shown));
        $this->assertStringContainsString(\sprintf("\e[0m%s\n", $shown), $output);

        // Control: the same body between "#" gets its delimiters coloured.
        $coloured = $formatter->format(self::reportWithIssue('#a+#'));
        $this->assertStringContainsString("\e[36m\e[1m#\e[0m", $coloured);
    }

    /**
     * Through a call, so that static analysis does not compile the patterns
     * PHP refuses.
     */
    private static function nulByte(): string
    {
        return "\0";
    }

    private static function formatter(): ConsoleFormatter
    {
        return new ConsoleFormatter(null, new OutputConfiguration(ansi: false));
    }

    private static function reportWithRewrite(string $original, string $optimized): LintReport
    {
        return new LintReport([[
            'file' => 'f.php',
            'line' => 1,
            'pattern' => $original,
            'issues' => [],
            'optimizations' => [[
                'file' => 'f.php',
                'line' => 1,
                'optimization' => new OptimizationResult($original, $optimized, ['Optimized pattern.']),
                'savings' => 3,
            ]],
            'problems' => [],
        ]], ['errors' => 0, 'warnings' => 0, 'optimizations' => 1]);
    }

    private static function reportWithIssue(string $pattern): LintReport
    {
        return new LintReport([[
            'file' => 'f.php',
            'line' => 1,
            'pattern' => $pattern,
            'issues' => [['type' => 'warning', 'message' => 'm', 'file' => 'f.php', 'line' => 1]],
            'optimizations' => [],
            'problems' => [],
        ]], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);
    }

    private static function invoke(ConsoleFormatter $formatter, string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod($formatter, $method))->invoke($formatter, ...$arguments);
    }
}
