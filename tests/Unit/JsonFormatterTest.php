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
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintSeverity;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    public function test_json_formatter_outputs_report_payload(): void
    {
        $formatter = new JsonFormatter();

        $report = new LintReport(
            results: [[
                'file' => './test.php',
                'line' => 10,
                'pattern' => '/a+/',
                'issues' => [],
                'optimizations' => [],
                'problems' => [
                    new Diagnostic(DiagnosticType::Lint, LintSeverity::Warning, 'noise'),
                ],
            ]],
            stats: ['errors' => 0, 'warnings' => 0, 'optimizations' => 0],
        );

        $payload = $formatter->format($report);
        /** @var array{stats: array{errors: int, warnings: int, optimizations: int}, results: array<int, array<string, mixed>>} $decoded */
        $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame(['errors' => 0, 'warnings' => 0, 'optimizations' => 0, 'redos' => 0], $decoded['stats']);
        $this->assertSame('/a+/', $decoded['results'][0]['pattern']);
        $this->assertArrayNotHasKey('problems', $decoded['results'][0]);
    }

    public function test_json_formatter_formats_errors_as_json(): void
    {
        $formatter = new JsonFormatter();

        $payload = $formatter->formatError('boom');
        /** @var array{error: string} $decoded */
        $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);

        $this->assertSame('boom', $decoded['error']);
    }
}
