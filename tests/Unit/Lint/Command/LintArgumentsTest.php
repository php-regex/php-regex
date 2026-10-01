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

namespace PhpRegex\Tests\Unit\Lint\Command;

use PhpRegex\Linter\Config\LintArguments;
use PhpRegex\Linter\Formatter\OutputConfiguration;
use PHPUnit\Framework\TestCase;

final class LintArgumentsTest extends TestCase
{
    public function test_from_defaults_normalizes_types_and_bounds(): void
    {
        $arguments = LintArguments::fromDefaults([
            'paths' => ['src', '', 123],
            'exclude' => 'vendor',
            'minSavings' => '0',
            'verbosity' => '',
            'format' => '',
            'quiet' => 'no',
            'checkRedos' => 'no',
            'checkValidation' => 'no',
            'checkOptimizations' => 'no',
            'jobs' => '0',
        ]);

        $this->assertSame(['src'], $arguments->paths);
        $this->assertSame([], $arguments->exclude);
        $this->assertSame(1, $arguments->minSavings);
        $this->assertSame(OutputConfiguration::VERBOSITY_NORMAL, $arguments->verbosity);
        $this->assertSame('console', $arguments->format);
        $this->assertFalse($arguments->quiet);
        $this->assertFalse($arguments->checkRedos); // ReDoS is disabled by default
        $this->assertTrue($arguments->checkValidation);
        $this->assertTrue($arguments->checkOptimizations);
        $this->assertSame(-1, $arguments->jobs);
    }

    public function test_from_defaults_converts_numeric_strings(): void
    {
        $arguments = LintArguments::fromDefaults([
            'minSavings' => '4',
            'jobs' => '2',
        ]);

        $this->assertSame(4, $arguments->minSavings);
        $this->assertSame(2, $arguments->jobs);
    }
}
