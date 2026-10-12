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

namespace PHPRegex\Tests\Unit\Packages;

use PHPRegex\Parser\DelimitedPattern;
use PHPRegex\Toolkit\AnalysisReport;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A property is named for what it holds, and a value is built and spelled
 * one way.
 */
final class ResultNamesTest extends TestCase
{
    #[Test]
    public function test_the_analysis_report_names_its_parts_as_nouns(): void
    {
        $report = Regex::create(['cache' => null])->analyze('/a+/');
        $properties = array_map(static fn (\ReflectionProperty $property): string => $property->getName(), (new \ReflectionClass(AnalysisReport::class))->getProperties());

        $this->assertSame('/a+/', $report->optimization->original);
        $this->assertNotSame('', $report->explanation);
        $this->assertNotContains('explain', $properties);
        $this->assertNotContains('optimizations', $properties);
    }

    #[Test]
    public function test_a_delimited_pattern_is_built_and_spelled_one_way(): void
    {
        $pattern = new DelimitedPattern('foo', 'i', '#');

        $this->assertSame('#foo#i', (string) $pattern);
        $this->assertFalse((new \ReflectionClass(DelimitedPattern::class))->hasMethod('fromRaw'));
        $this->assertFalse((new \ReflectionClass(DelimitedPattern::class))->hasMethod('toString'));
    }
}
