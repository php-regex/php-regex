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
use RegexParser\Lint\RegexPatternOccurrence;
use RegexParser\ReDoS\ReDoSSeverity;
use RegexParser\Regex;

final class RegexAnalysisServiceClassTest extends TestCase
{
    public function test_regex_analysis_service_exposes_its_parser(): void
    {
        $regex = Regex::create();
        $service = new RegexAnalysisService($regex->parser());

        $this->assertSame($regex->parser(), $service->getParser());
    }

    public function test_regex_analysis_service_lints_patterns_without_issues(): void
    {
        $regex = Regex::create();
        $service = new RegexAnalysisService(
            $regex->parser(),
            null,  // extractor
            50,    // warningThreshold
            ReDoSSeverity::HIGH->value,
            [],     // ignoredPatterns
            [],     // redosIgnoredPatterns
            false,    // ignoreParseErrors
        );

        $occurrence = new RegexPatternOccurrence('/a+/', 'test.php', 1, 'preg_match');
        $issues = $service->lint([$occurrence]);

        $this->assertSame([], $issues);
    }
}
