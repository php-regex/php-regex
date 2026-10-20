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

use PHPRegex\Linter\Formatter\GithubFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Tests\Support\LintIssueFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GithubFormatterTest extends TestCase
{
    private GithubFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new GithubFormatter();
    }

    public function test_format_empty_report(): void
    {
        $report = new LintReport([], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertSame('', $output);
    }

    public function test_format_with_error_problem(): void
    {
        $problem = LintIssueFixtures::invalid('Invalid regex pattern');

        $result = [
            'file' => '/path/to/file.php',
            'line' => 10,
            'column' => 7,
            'source' => 'preg_match',
            'pattern' => '/test/',
            'location' => 'in function call',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        // The column of the issue in the file, not the offset in the pattern.
        $this->assertStringContainsString('::error file=/path/to/file.php,line=10,col=7,title=Syntax ('.LintIssueFixtures::INVALID_CODE.')::', $output);
        $this->assertStringContainsString('Invalid regex pattern', $output);
        $this->assertStringContainsString('Location: in function call', $output);
        $this->assertStringContainsString('Line 1: a**', $output);
        $this->assertStringContainsString('Suggestion: '.LintIssueFixtures::INVALID_HINT, $output);
    }

    public function test_format_with_warning_problem(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Warning, 'Nested quantifier detected', 'regex.lint.quantifier.nested');

        $result = [
            'file' => 'file with spaces.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        // No column known: none is invented.
        $this->assertStringContainsString('::warning file=file with spaces.php,line=1,title=Lint (regex.lint.quantifier.nested)::', $output);
        $this->assertStringContainsString('Nested quantifier detected', $output);
    }

    public function test_format_with_info_problem(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Info, 'Info message');

        $result = [
            'file' => 'info.php',
            'line' => 3,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::notice file=info.php,line=3,title=Lint::', $output);
        $this->assertStringContainsString('Info message', $output);
    }

    public function test_format_with_critical_problem(): void
    {
        $problem = LintIssueFixtures::redos(LintSeverity::Critical, 'Critical security issue', 'regex.redos');

        $result = [
            'file' => 'critical.php',
            'line' => 8,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        // The position in the pattern is not a column in the file.
        $this->assertStringContainsString('::error file=critical.php,line=8,title=Security (regex.redos)::', $output);
        $this->assertStringContainsString('Critical security issue', $output);
        $this->assertStringContainsString(LintIssueFixtures::REDOS_EVIDENCE, $output);
        $this->assertStringContainsString('Suggestion: '.LintIssueFixtures::redosAdvice(), $output);
    }

    public function test_format_with_multiple_problems(): void
    {
        $problem1 = LintIssueFixtures::invalid('Error 1');
        $problem2 = LintIssueFixtures::finding(LintSeverity::Warning, 'Warning 1');

        $result1 = [
            'file' => 'file1.php',
            'line' => 1,
            'pattern' => '/test1/',
            'optimizations' => [],
            'issues' => [$problem1],
        ];

        $result2 = [
            'file' => 'file2.php',
            'line' => 2,
            'pattern' => '/test2/',
            'optimizations' => [],
            'issues' => [$problem2],
        ];

        $report = new LintReport([$result1, $result2], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $lines = explode("\n", trim($output));
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('::error', $lines[0]);
        $this->assertStringContainsString('::warning', $lines[1]);
    }

    #[Test]
    public function test_format_leaves_the_column_property_out_when_the_key_is_absent(): void
    {
        $problem = LintIssueFixtures::invalid('Invalid regex pattern');

        // As the JUnit build writes it: no column key at all.
        $result = [
            'file' => '/path/to/file.php',
            'line' => 10,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('file=/path/to/file.php,line=10,', $output);
        $this->assertStringNotContainsString('col=', $output);
    }

    public function test_format_with_empty_file(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test');

        $result = [
            'file' => '',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::error title=Lint::', $output);
        $this->assertStringNotContainsString('file=', $output);
        $this->assertStringNotContainsString('line=', $output);
        $this->assertStringNotContainsString('col=', $output);
    }

    public function test_format_problem_title_with_code(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test message', 'regex.lint.test');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('title=Lint (regex.lint.test)', $output);
    }

    public function test_format_problem_title_without_code(): void
    {
        $problem = LintIssueFixtures::redos(LintSeverity::Error, 'Test message', null);

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('title=Security', $output);
    }

    public function test_format_escapes_properties(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test message');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('file=test.php', $output);
    }

    /**
     * A ":" or "," in a property value would end it early: both are
     * escaped there, in the file and in the title, and kept as they are in
     * the message.
     */
    #[Test]
    #[DataProvider('providePropertySeparators')]
    public function test_format_escapes_property_separators(string $file, ?string $code, string $expected): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Message: a, b', $code);
        $report = new LintReport([[
            'file' => $file,
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ]], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $this->assertSame($expected."\n", $this->formatter->format($report));
    }

    /**
     * @return iterable<string, array{file: string, code: string|null, expected: string}>
     */
    public static function providePropertySeparators(): iterable
    {
        yield 'a colon and a comma in the file' => [
            'file' => 'C:/src/a,b.php',
            'code' => null,
            'expected' => '::error file=C%3A/src/a%2Cb.php,line=1,title=Lint::Message: a, b',
        ];
        yield 'a colon and a comma in the title' => [
            'file' => 'test.php',
            'code' => 'x:y,z',
            'expected' => '::error file=test.php,line=1,title=Lint (x%3Ay%2Cz)::Message: a, b',
        ];
    }

    public function test_format_escapes_data(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, "Message with\nnewlines and\r\ncrlf");

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('Message with%0Anewlines and%0D%0Acrlf', $output);
    }

    public function test_format_escapes_title(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Error, 'Test message', 'code with % and \n');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 1, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('title=Lint (code with %25 and \n)', $output);
    }

    public function test_format_escapes_percent_in_data(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Warning, 'Matches 100% of the inputs', null, '50%0A');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'column' => 3,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $output = $this->formatter->format(new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]));

        // "%" first, so a literal "%0A" in the data does not read as a newline.
        $this->assertStringContainsString('::warning file=test.php,line=1,col=3,title=Lint::Matches 100%25 of the inputs%0ASuggestion: 50%250A', $output);
    }

    public function test_format_error(): void
    {
        $message = 'Test error message';

        $output = $this->formatter->formatError($message);

        $this->assertSame('::error::Test error message', $output);
    }

    public function test_format_with_minimal_problem(): void
    {
        $problem = LintIssueFixtures::finding(LintSeverity::Info, 'Simple message');

        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/test/',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 0, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $this->assertStringContainsString('::notice file=test.php,line=1,title=Lint::Simple message', $output);
    }

    public function test_format_with_location_and_snippet(): void
    {
        $problem = LintIssueFixtures::invalid('Warning message', LintSeverity::Warning);

        $result = [
            'file' => 'test.php',
            'line' => 5,
            'pattern' => '/test/',
            'location' => 'inside preg_match call',
            'optimizations' => [],
            'issues' => [$problem],
        ];

        $report = new LintReport([$result], ['errors' => 0, 'warnings' => 1, 'optimizations' => 0]);

        $output = $this->formatter->format($report);

        $expected = '::warning file=test.php,line=5,title=Syntax ('.LintIssueFixtures::INVALID_CODE.')::Warning message%0ALocation: inside preg_match call%0ALine 1: a**';
        $this->assertStringContainsString($expected, $output);
        $this->assertStringEndsWith('%0ASuggestion: '.LintIssueFixtures::INVALID_HINT."\n", $output);
    }

    /**
     * A finding the docs explain names its page on the last line of its
     * message; an invalid pattern does not.
     */
    #[Test]
    public function test_format_ends_the_message_of_a_documented_finding_with_its_docs(): void
    {
        $result = [
            'file' => 'test.php',
            'line' => 1,
            'pattern' => '/a{1}/',
            'optimizations' => [],
            'issues' => [
                LintIssueFixtures::finding(LintSeverity::Warning, 'Useless quantifier', 'regex.lint.quantifier.useless'),
                LintIssueFixtures::invalid(),
            ],
        ];

        $output = $this->formatter->format(new LintReport([$result], ['errors' => 1, 'warnings' => 1, 'optimizations' => 0]));

        $this->assertStringContainsString("::warning file=test.php,line=1,title=Lint (regex.lint.quantifier.useless)::Useless quantifier%0ARead more: https://php-regex.com/reference/rules/#useless-quantifier\n", $output);
        $this->assertSame(1, substr_count($output, 'Read more'));
    }
}
