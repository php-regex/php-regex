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

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A generator declared function &name() yields its values by reference:
 * the foreach reading it may write the variable it yields, which then
 * holds what the caller gave it. PHP 8.4 prints ["\/(\/"] for $re below.
 */
final class ByReferenceGeneratorTest extends TestCase
{
    use ExtractsWithBothStrategies;

    #[Test]
    public function test_a_variable_yielded_by_reference_is_not_read(): void
    {
        $this->assertExtractedByBoth([
            ['pattern' => '/control/', 'at' => "'/control/'", 'location' => 'via $control'],
        ], <<<'PHP'
            <?php
            function &gen()
            {
                $re = '/a/';
                yield $re;
                preg_match($re, 'x');
            }

            final class Lines
            {
                public function &each(): \Generator
                {
                    $re = '/b/';
                    yield 'key' => $re;
                    preg_match($re, 'x');
                }
            }

            function plain()
            {
                $control = '/control/';
                yield $control;
                preg_match($control, 'x');
            }

            PHP);
    }
}
