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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\TestCase;

final class RegexLintServiceEdgeCasesTest extends TestCase
{
    public function test_filter_issues_respects_validation_toggle(): void
    {
        $service = $this->makeService();
        $method = $this->getPrivateMethod($service, 'filterIssuesByRequest');

        $issues = [
            ['validation' => new ValidationResult(false, 'error', 0)],
        ];
        $request = new LintRequest(
            paths: [],
            excludePaths: [],
            minSavings: 1,
            checkValidation: false,
            checkRedos: true,
            checkOptimizations: true,
        );

        $filtered = $method->invoke($service, $issues, $request);

        $this->assertSame([], $filtered);
    }

    public function test_should_ignore_issue_handles_line_and_read_errors(): void
    {
        $service = $this->makeService();
        $method = $this->getPrivateMethod($service, 'shouldIgnoreIssue');

        $issue = [
            'file' => __FILE__,
            'line' => 1,
        ];
        $this->assertFalse($method->invoke($service, $issue));

        $tempFile = tempnam(sys_get_temp_dir(), 'regex-lint');
        if (false === $tempFile) {
            $this->markTestSkipped('Unable to create temp file.');
        }
        copy(__DIR__.'/../Fixtures/Lint/multiline.txt', $tempFile);
        chmod($tempFile, 0o200);

        try {
            $issue = [
                'file' => $tempFile,
                'line' => 3,
            ];
            $this->assertFalse($method->invoke($service, $issue));
        } finally {
            chmod($tempFile, 0o600);
            @unlink($tempFile);
        }
    }

    public function test_severity_mapping_and_snippet_stripping(): void
    {
        $service = $this->makeService();

        $mapIssueSeverity = $this->getPrivateMethod($service, 'mapIssueSeverity');
        $issueSeverity = $mapIssueSeverity->invoke($service, 'error');
        $this->assertInstanceOf(LintSeverity::class, $issueSeverity);
        $this->assertSame('error', $issueSeverity->value);

        $mapRedosSeverity = $this->getPrivateMethod($service, 'mapRedosSeverity');

        $analysis = new RedosAnalysis(RedosSeverity::High, 10, null, [], null, null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(LintSeverity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $analysis = new RedosAnalysis(RedosSeverity::Medium, 10, null, [], null, null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(LintSeverity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $analysis = new RedosAnalysis(RedosSeverity::Unknown, 10, null, [], null, null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(LintSeverity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        // The message a validation hands over holds no snippet to strip: the
        // snippet travels apart, and the problem carries both.
        $validation = RegexParser::create()->validate('/a(/');
        $this->assertStringNotContainsString("\n", (string) $validation->error);
        $this->assertStringStartsWith('Line 1: a(', (string) $validation->caretSnippet);
        $this->assertSame('Expected ) at end of input (found eof)', $validation->error);
    }

    private function makeService(): LintService
    {
        $analysis = new AnalysisService(RegexParser::create());
        $sources = new PatternSourceCollection([]);

        return new LintService($analysis, $sources);
    }

    private function getPrivateMethod(object $object, string $method): \ReflectionMethod
    {
        $ref = new \ReflectionClass($object);

        return $ref->getMethod($method);
    }
}
