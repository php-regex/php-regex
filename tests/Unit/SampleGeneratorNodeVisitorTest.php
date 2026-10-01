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

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class SampleGeneratorNodeVisitorTest extends TestCase
{
    public function test_sample_generator_produces_matching_text(): void
    {
        $regex = Regex::create();
        $ast = $regex->parse('/a[bc]/');
        $visitor = new SampleGenerator();
        $visitor->setSeed(123);

        $sample = $ast->accept($visitor);

        $this->assertMatchesRegularExpression('/a[bc]/', $sample);
    }
}
