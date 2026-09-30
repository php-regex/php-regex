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

namespace RegexParser\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RegexParser\Lint\RegexAnalysisService;
use RegexParser\Lint\RegexLintRequest;
use RegexParser\Lint\RegexLintService;
use RegexParser\Lint\RegexPatternSourceCollection;
use RegexParser\ReDoS\ReDoSAnalysis;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\RegexParser;
use RegexParser\Severity;
use RegexParser\ValidationResult;

final class RegexLintServiceEdgeCasesTest extends TestCase
{
    public function test_filter_issues_respects_validation_toggle(): void
    {
        $service = $this->makeService();
        $method = $this->getPrivateMethod($service, 'filterIssuesByRequest');

        $issues = [
            ['validation' => new ValidationResult(false, 'error', 0)],
        ];
        $request = new RegexLintRequest(
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
        $this->assertInstanceOf(Severity::class, $issueSeverity);
        $this->assertSame('error', $issueSeverity->value);

        $mapRedosSeverity = $this->getPrivateMethod($service, 'mapRedosSeverity');

        $analysis = new ReDoSAnalysis(ReDoSSeverity::HIGH, 10, null, [], null, null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(Severity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $analysis = new ReDoSAnalysis(ReDoSSeverity::MEDIUM, 10, null, [], null, null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(Severity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $analysis = new ReDoSAnalysis(ReDoSSeverity::UNKNOWN, 10, null, [], null, null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(Severity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $strip = $this->getPrivateMethod($service, 'stripSnippetFromMessage');
        $this->assertSame('message', $strip->invoke($service, 'message', null));
        $this->assertSame('message', $strip->invoke($service, 'message', 'snippet'));
    }

    private function makeService(): RegexLintService
    {
        $analysis = new RegexAnalysisService(RegexParser::create());
        $sources = new RegexPatternSourceCollection([]);

        return new RegexLintService($analysis, $sources);
    }

    private function getPrivateMethod(object $object, string $method): \ReflectionMethod
    {
        $ref = new \ReflectionClass($object);

        return $ref->getMethod($method);
    }
}
