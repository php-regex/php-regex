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
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class RegexAnalysisServiceClassTest extends TestCase
{
    public function test_regex_analysis_service_exposes_its_parser(): void
    {
        $regex = Regex::create();
        $service = new AnalysisService($regex->parser());

        $this->assertSame($regex->parser(), $service->getParser());
    }

    public function test_regex_analysis_service_lints_patterns_without_issues(): void
    {
        $regex = Regex::create();
        $service = new AnalysisService(
            $regex->parser(),
            null,  // extractor
            50,    // warningThreshold
            RedosSeverity::High->value,
            [],     // ignoredPatterns
            [],     // redosIgnoredPatterns
            false,    // ignoreParseErrors
        );

        $occurrence = new PatternOccurrence('/a+/', 'test.php', 1, 'preg_match');
        $issues = $service->lint([$occurrence]);

        $this->assertSame([], $issues);
    }
}
