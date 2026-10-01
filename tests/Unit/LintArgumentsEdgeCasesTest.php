<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit;

use PhpRegex\Linter\Config\LintArguments;
use PHPUnit\Framework\TestCase;

final class LintArgumentsEdgeCasesTest extends TestCase
{
    public function test_from_defaults_normalizes_min_savings_and_jobs(): void
    {
        $arguments = LintArguments::fromDefaults([
            'paths' => ['src'],
            'exclude' => [],
            'minSavings' => ['invalid'],
            'jobs' => ['invalid'],
        ]);

        $this->assertSame(1, $arguments->minSavings);
        $this->assertSame(-1, $arguments->jobs);
    }

    public function test_from_defaults_reads_a_quantifier_count_written_as_digits(): void
    {
        $arguments = LintArguments::fromDefaults([
            'optimizations' => ['minQuantifierCount' => '5', 'digits' => true],
        ]);

        $this->assertSame(['minQuantifierCount' => 5, 'digits' => true], $arguments->optimizations);
    }

    public function test_from_defaults_drops_a_quantifier_count_that_is_no_number(): void
    {
        // "\xB2" is a superscript two in Latin-1: no digit, whatever the locale.
        $arguments = LintArguments::fromDefaults([
            'optimizations' => ['minQuantifierCount' => "5\xB2"],
        ]);

        $this->assertSame([], $arguments->optimizations);
    }
}
