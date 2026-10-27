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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Linter\Diagnostic;
use PHPRegex\Linter\DiagnosticType;
use PHPRegex\Linter\LintSeverity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DiagnosticTest extends TestCase
{
    #[Test]
    public function test_construct_with_minimal_parameters(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Syntax,
            LintSeverity::Error,
            'Test message',
        );

        $this->assertSame(DiagnosticType::Syntax, $problem->type);
        $this->assertSame(LintSeverity::Error, $problem->severity);
        $this->assertSame('Test message', $problem->message);
        $this->assertNull($problem->code);
        $this->assertNull($problem->snippet);
        $this->assertNull($problem->suggestion);
    }

    #[Test]
    public function test_construct_with_all_parameters(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Security,
            LintSeverity::Critical,
            'Critical security issue',
            'regex.redos',
            'vulnerable pattern',
            'Use atomic groups',
        );

        $this->assertSame(DiagnosticType::Security, $problem->type);
        $this->assertSame(LintSeverity::Critical, $problem->severity);
        $this->assertSame('Critical security issue', $problem->message);
        $this->assertSame('regex.redos', $problem->code);
        $this->assertSame('vulnerable pattern', $problem->snippet);
        $this->assertSame('Use atomic groups', $problem->suggestion);
    }

    #[Test]
    public function test_construct_with_empty_strings(): void
    {
        $problem = new Diagnostic(
            DiagnosticType::Semantic,
            LintSeverity::Error,
            'Semantic error',
            '',
            '',
            '',
        );

        $this->assertSame('', $problem->code);
        $this->assertSame('', $problem->snippet);
        $this->assertSame('', $problem->suggestion);
    }

    #[Test]
    public function test_construct_with_multiline_message(): void
    {
        $message = "Line 1\nLine 2\nLine 3";
        $problem = new Diagnostic(
            DiagnosticType::Lint,
            LintSeverity::Warning,
            $message,
        );

        $this->assertSame($message, $problem->message);
    }
}
