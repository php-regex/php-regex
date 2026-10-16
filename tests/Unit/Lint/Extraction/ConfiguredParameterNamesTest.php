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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PHPRegex\Linter\Extraction\PatternFunction;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A helper configured with --pattern-function or extraction.functions is
 * read under the names pattern:, patterns: and regex: when the spec names
 * no parameter, as before the names were read from declarations; a
 * "$name" suffix reads that name instead, and that name alone.
 */
final class ConfiguredParameterNamesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_spec_naming_no_parameter_reads_the_usual_names(): void
    {
        $registry = PatternFunctionRegistry::native()->withCustomFunctions(['App\grep#1', 'App\Str::matches']);

        $this->assertSame(['pattern', 'patterns', 'regex'], $registry->lookupFunction('App\grep')?->parameterNames);
        $this->assertSame(['pattern', 'patterns', 'regex'], $registry->lookupMethod('App\Str', 'matches')?->parameterNames);
    }

    #[Test]
    public function test_a_spec_naming_its_parameter_reads_that_name(): void
    {
        $function = PatternFunctionRegistry::native()->withCustomFunctions(['App\grep#1$expression'])->lookupFunction('App\grep');
        $this->assertInstanceOf(PatternFunction::class, $function);

        $this->assertSame(['expression'], $function->parameterNames);
        $this->assertSame(1, $function->argumentIndex);
    }

    #[Test]
    public function test_a_configured_helper_is_read_under_each_usual_name(): void
    {
        $this->assertExtractedByBoth(
            [
                ['pattern' => '/one/', 'at' => "'/one/'"],
                ['pattern' => '/two/', 'at' => "'/two/'"],
                ['pattern' => '/three/', 'at' => "'/three/'"],
                ['pattern' => '/four/', 'at' => "'/four/'"],
                ['pattern' => '/five/', 'at' => "'/five/'"],
            ],
            <<<'PHP'
                <?php

                namespace App;

                grep([], pattern: '/one/');
                grep([], patterns: '/two/');
                grep(subject: [], regex: '/three/');
                grep([], '/four/');
                grep([], expression: '/not-read/');
                Str::matches(regex: '/five/');

                PHP,
            PatternFunctionRegistry::native()->withCustomFunctions(['App\grep#1', 'App\Str::matches']),
        );
    }

    #[Test]
    public function test_a_configured_helper_naming_its_parameter_is_read_under_that_name_alone(): void
    {
        $this->assertExtractedByBoth(
            [
                ['pattern' => '/named/', 'at' => "'/named/'"],
                ['pattern' => '/by-position/', 'at' => "'/by-position/'"],
            ],
            <<<'PHP'
                <?php

                namespace App;

                grep([], expression: '/named/');
                grep([], '/by-position/');
                grep([], pattern: '/not-read/');

                PHP,
            PatternFunctionRegistry::native()->withCustomFunctions(['App\grep#1$expression']),
        );
    }
}
