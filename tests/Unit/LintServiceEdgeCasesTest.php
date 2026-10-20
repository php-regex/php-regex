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
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\TestCase;

final class LintServiceEdgeCasesTest extends TestCase
{
    public function test_filter_issues_respects_validation_toggle(): void
    {
        $service = $this->makeService();
        $method = $this->getPrivateMethod($service, 'filterIssuesByRequest');

        $issues = [
            new LintIssue(LintSeverity::Error, 'error', 'a.php', 1, validation: new ValidationResult(false, 'error', 0)),
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

    public function test_severity_mapping_and_snippet_stripping(): void
    {
        // A verdict not confirmed is a warning, whatever its severity.
        $mapRedosSeverity = new \ReflectionMethod(AnalysisService::class, 'redosSeverity');
        $service = null;

        $analysis = new RedosAnalysis(RedosSeverity::Critical, 10, null, [], null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(LintSeverity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $analysis = new RedosAnalysis(RedosSeverity::High, 10, null, [], null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(LintSeverity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $analysis = new RedosAnalysis(RedosSeverity::Medium, 10, null, [], null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(LintSeverity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        $analysis = new RedosAnalysis(RedosSeverity::Unknown, 10, null, [], null);
        $redosSeverity = $mapRedosSeverity->invoke($service, $analysis);
        $this->assertInstanceOf(LintSeverity::class, $redosSeverity);
        $this->assertSame('warning', $redosSeverity->value);

        // A low verdict reported is a warning too, as the stats count it.
        $analysis = new RedosAnalysis(RedosSeverity::Low, 10, null, [], null);
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
