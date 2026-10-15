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

namespace PHPRegex\Tests\Unit\Lsp\Converter;

use PHPRegex\LanguageServer\Converter\DiagnosticConverter;
use PHPRegex\LanguageServer\Converter\PatternSpan;
use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPRegex\LanguageServer\Document\RegexOccurrence;
use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Parser\ErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The span used throughout: "'/abcdefghijklmnopqrst/i'" on line 1 from
 * column 10, so the quote is at 10, the delimiter at 11, the body from 12
 * to 32 and the closing delimiter at 32.
 */
final class DiagnosticConverterTest extends TestCase
{
    private DiagnosticConverter $converter;

    private PatternSpan $span;

    protected function setUp(): void
    {
        $this->converter = new DiagnosticConverter();
        $this->span = self::span("'/abcdefghijklmnopqrst/i'");
    }

    #[Test]
    public function test_convert_creates_diagnostic_with_correct_structure(): void
    {
        $issue = new RuleViolation(
            id: 'regex.lint.test',
            message: 'Test message',
            offset: 5,
            severity: LintSeverity::Warning,
        );

        $diagnostic = $this->converter->convert($issue, $this->span);

        $this->assertArrayHasKey('range', $diagnostic);
        $this->assertArrayHasKey('severity', $diagnostic);
        $this->assertArrayHasKey('code', $diagnostic);
        $this->assertArrayHasKey('source', $diagnostic);
        $this->assertArrayHasKey('message', $diagnostic);
    }

    #[Test]
    public function test_convert_calculates_range_from_offset(): void
    {
        $issue = new RuleViolation(
            id: 'regex.lint.test',
            message: 'Test',
            offset: 5,
            severity: LintSeverity::Warning,
        );

        $diagnostic = $this->converter->convert($issue, $this->span);

        /** @var array{start: array{line: int, character: int}, end: array{line: int, character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(1, $range['start']['line']);
        $this->assertSame(17, $range['start']['character']); // 12 + 5
        $this->assertSame(1, $range['end']['line']);
        $this->assertSame(18, $range['end']['character']); // one character, the rule giving no end
    }

    #[Test]
    public function test_convert_spans_the_node_the_rule_names(): void
    {
        $issue = new RuleViolation('regex.lint.test', 'Test', 2, end: 6);

        $this->assertSame(
            ['start' => ['line' => 1, 'character' => 14], 'end' => ['line' => 1, 'character' => 18]],
            $this->converter->convert($issue, $this->span)['range'],
        );
    }

    #[Test]
    public function test_convert_sets_correct_source(): void
    {
        $issue = new RuleViolation('test', 'message');
        $diagnostic = $this->converter->convert($issue, $this->span);

        $this->assertSame('php-regex', $diagnostic['source']);
    }

    #[Test]
    public function test_convert_sets_correct_code(): void
    {
        $issue = new RuleViolation(
            id: 'regex.lint.unicode.shorthandWithoutU',
            message: 'Test',
        );

        $diagnostic = $this->converter->convert($issue, $this->span);

        $this->assertSame('regex.lint.unicode.shorthandWithoutU', $diagnostic['code']);
    }

    #[Test]
    #[DataProvider('provideSeverityMapping')]
    public function test_convert_maps_severity_correctly(LintSeverity $inputSeverity, int $expectedLspSeverity): void
    {
        $issue = new RuleViolation(
            id: 'test',
            message: 'Test',
            severity: $inputSeverity,
        );

        $diagnostic = $this->converter->convert($issue, $this->span);

        $this->assertSame($expectedLspSeverity, $diagnostic['severity']);
    }

    /**
     * @return iterable<string, array{inputSeverity: LintSeverity, expectedLspSeverity: int}>
     */
    public static function provideSeverityMapping(): iterable
    {
        yield 'Critical -> Error (1)' => ['inputSeverity' => LintSeverity::Critical, 'expectedLspSeverity' => 1];
        yield 'Error -> Error (1)' => ['inputSeverity' => LintSeverity::Error, 'expectedLspSeverity' => 1];
        yield 'Warning -> Warning (2)' => ['inputSeverity' => LintSeverity::Warning, 'expectedLspSeverity' => 2];
        yield 'Style -> Information (3)' => ['inputSeverity' => LintSeverity::Style, 'expectedLspSeverity' => 3];
        yield 'Perf -> Information (3)' => ['inputSeverity' => LintSeverity::Perf, 'expectedLspSeverity' => 3];
        yield 'Info -> Information (3)' => ['inputSeverity' => LintSeverity::Info, 'expectedLspSeverity' => 3];
    }

    #[Test]
    public function test_convert_clamps_offset_to_pattern_bounds(): void
    {
        $issue = new RuleViolation(
            id: 'test',
            message: 'Test',
            offset: 100, // Beyond pattern length
        );

        $diagnostic = $this->converter->convert($issue, $this->span);

        // Clamped to the end of the body: the closing delimiter, never the
        // modifiers or the quote.
        /** @var array{start: array{character: int}, end: array{character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(32, $range['start']['character']);
        $this->assertSame(33, $range['end']['character']);
    }

    #[Test]
    public function test_from_parse_error_creates_error_diagnostic(): void
    {
        $diagnostic = $this->converter->fromParseError(
            'Parse error',
            ErrorCode::GroupUnclosed,
            $this->span,
            3,
        );

        $this->assertSame(1, $diagnostic['severity']); // Error
        $this->assertSame('regex.group.unclosed', $diagnostic['code']);
        $this->assertSame('Parse error', $diagnostic['message']);
        /** @var array{start: array{character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(15, $range['start']['character']); // 12 + 3
    }

    #[Test]
    public function test_from_validation_error_creates_error_diagnostic(): void
    {
        $diagnostic = $this->converter->fromValidationError(
            'Validation error',
            ErrorCode::LookbehindUnbounded,
            $this->span,
            5,
        );

        $this->assertSame(1, $diagnostic['severity']); // Error
        $this->assertSame('regex.lookbehind.unbounded', $diagnostic['code']);
        $this->assertSame('Validation error', $diagnostic['message']);
        $this->assertSame(
            ['start' => ['line' => 1, 'character' => 17], 'end' => ['line' => 1, 'character' => 18]],
            $diagnostic['range'],
        );
    }

    #[Test]
    public function test_a_limit_and_a_redos_verdict_with_no_part_cover_the_body(): void
    {
        $body = ['start' => ['line' => 1, 'character' => 12], 'end' => ['line' => 1, 'character' => 32]];

        $this->assertSame($body, $this->converter->fromLimit('Too deep.', ErrorCode::NestingTooDeep, $this->span)['range']);
        $this->assertSame($body, $this->converter->fromRedos('regex.lint.redos', 'Risky.', [], false, $this->span)['range']);
        $this->assertSame(
            ['start' => ['line' => 1, 'character' => 14], 'end' => ['line' => 1, 'character' => 16]],
            $this->converter->fromRedos('regex.lint.redos', 'Risky.', [], true, $this->span, 2, 4)['range'],
        );
    }

    #[Test]
    public function test_handles_null_offset(): void
    {
        $issue = new RuleViolation(
            id: 'test',
            message: 'Test',
            offset: null,
        );

        $diagnostic = $this->converter->convert($issue, $this->span);

        // Null offset should default to 0
        /** @var array{start: array{character: int}} $range */
        $range = $diagnostic['range'];
        $this->assertSame(12, $range['start']['character']);
    }

    /**
     * The span of a literal written on line 1 from column 10.
     */
    private static function span(string $literal): PatternSpan
    {
        $content = "<?php\n".str_repeat(' ', 10).$literal.";\n";
        $positions = new PositionConverter($content);
        $offset = \strlen("<?php\n") + 10;

        return PatternSpan::of(new RegexOccurrence(
            pattern: (string) PhpStringLiteral::decode($literal),
            start: $positions->offsetToPosition($offset),
            end: $positions->offsetToPosition($offset + \strlen($literal)),
            byteOffset: $offset,
            literal: $literal,
        ), $positions);
    }
}
