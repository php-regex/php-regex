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

namespace RegexParser\Tests\Unit\Lsp\Converter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\ErrorCode;
use RegexParser\LintIssue;
use RegexParser\Lsp\Converter\DiagnosticConverter;
use RegexParser\Severity;

final class DiagnosticConverterTest extends TestCase
{
    private DiagnosticConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new DiagnosticConverter();
    }

    #[Test]
    public function test_convert_creates_diagnostic_with_correct_structure(): void
    {
        $issue = new LintIssue(
            id: 'regex.lint.test',
            message: 'Test message',
            offset: 5,
            severity: Severity::Warning,
        );

        $start = ['line' => 1, 'character' => 10];
        $diagnostic = $this->converter->convert($issue, $start, 20);

        $this->assertArrayHasKey('range', $diagnostic);
        $this->assertArrayHasKey('severity', $diagnostic);
        $this->assertArrayHasKey('code', $diagnostic);
        $this->assertArrayHasKey('source', $diagnostic);
        $this->assertArrayHasKey('message', $diagnostic);
    }

    #[Test]
    public function test_convert_calculates_range_from_offset(): void
    {
        $issue = new LintIssue(
            id: 'regex.lint.test',
            message: 'Test',
            offset: 5,
            severity: Severity::Warning,
        );

        $start = ['line' => 1, 'character' => 10];
        $diagnostic = $this->converter->convert($issue, $start, 20);

        /** @var array{start: array{line: int, character: int}, end: array{line: int, character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(1, $range['start']['line']);
        $this->assertSame(15, $range['start']['character']); // 10 + 5
        $this->assertSame(1, $range['end']['line']);
        $this->assertSame(16, $range['end']['character']); // 10 + 5 + 1
    }

    #[Test]
    public function test_convert_sets_correct_source(): void
    {
        $issue = new LintIssue('test', 'message');
        $diagnostic = $this->converter->convert($issue, ['line' => 0, 'character' => 0], 10);

        $this->assertSame('regex-parser', $diagnostic['source']);
    }

    #[Test]
    public function test_convert_sets_correct_code(): void
    {
        $issue = new LintIssue(
            id: 'regex.lint.unicode.shorthandWithoutU',
            message: 'Test',
        );

        $diagnostic = $this->converter->convert($issue, ['line' => 0, 'character' => 0], 10);

        $this->assertSame('regex.lint.unicode.shorthandWithoutU', $diagnostic['code']);
    }

    #[Test]
    #[DataProvider('provideSeverityMapping')]
    public function test_convert_maps_severity_correctly(Severity $inputSeverity, int $expectedLspSeverity): void
    {
        $issue = new LintIssue(
            id: 'test',
            message: 'Test',
            severity: $inputSeverity,
        );

        $diagnostic = $this->converter->convert($issue, ['line' => 0, 'character' => 0], 10);

        $this->assertSame($expectedLspSeverity, $diagnostic['severity']);
    }

    /**
     * @return iterable<string, array{Severity, int}>
     */
    public static function provideSeverityMapping(): iterable
    {
        yield 'Critical -> Error (1)' => [Severity::Critical, 1];
        yield 'Error -> Error (1)' => [Severity::Error, 1];
        yield 'Warning -> Warning (2)' => [Severity::Warning, 2];
        yield 'Style -> Information (3)' => [Severity::Style, 3];
        yield 'Perf -> Information (3)' => [Severity::Perf, 3];
        yield 'Info -> Hint (4)' => [Severity::Info, 4];
    }

    #[Test]
    public function test_convert_clamps_offset_to_pattern_bounds(): void
    {
        $issue = new LintIssue(
            id: 'test',
            message: 'Test',
            offset: 100, // Beyond pattern length
        );

        $diagnostic = $this->converter->convert($issue, ['line' => 0, 'character' => 0], 10);

        // Offset should be clamped to pattern length
        /** @var array{start: array{character: int}, end: array{character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(10, $range['start']['character']);
        $this->assertSame(10, $range['end']['character']);
    }

    #[Test]
    public function test_from_parse_error_creates_error_diagnostic(): void
    {
        $diagnostic = $this->converter->fromParseError(
            'Parse error',
            ErrorCode::GroupUnclosed,
            ['line' => 1, 'character' => 5],
            15,
            3,
        );

        $this->assertSame(1, $diagnostic['severity']); // Error
        $this->assertSame('regex.group.unclosed', $diagnostic['code']);
        $this->assertSame('Parse error', $diagnostic['message']);
        /** @var array{start: array{character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(8, $range['start']['character']); // 5 + 3
    }

    #[Test]
    public function test_from_validation_error_creates_error_diagnostic(): void
    {
        $diagnostic = $this->converter->fromValidationError(
            'Validation error',
            ErrorCode::LookbehindUnbounded,
            ['line' => 2, 'character' => 10],
            20,
            5,
        );

        $this->assertSame(1, $diagnostic['severity']); // Error
        $this->assertSame('regex.lookbehind.unbounded', $diagnostic['code']);
        $this->assertSame('Validation error', $diagnostic['message']);
        $this->assertSame(
            ['start' => ['line' => 2, 'character' => 15], 'end' => ['line' => 2, 'character' => 30]],
            $diagnostic['range'],
        );
    }

    #[Test]
    public function test_handles_null_offset(): void
    {
        $issue = new LintIssue(
            id: 'test',
            message: 'Test',
            offset: null,
        );

        $diagnostic = $this->converter->convert($issue, ['line' => 0, 'character' => 5], 10);

        // Null offset should default to 0
        /** @var array{start: array{character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(5, $range['start']['character']);
    }
}
