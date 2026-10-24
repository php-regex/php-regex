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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The frozen limit of a mixed alternation: (a|\d) holds the constant and
 * every digit string, which no small finite set stands for, so the values
 * read as null and the scalar facts carry only what holds for both arms.
 * When the shape one day unions per-arm facts, this test moves with it.
 */
final class CaptureShapeMixedAlternationLimitTest extends TestCase
{
    #[Test]
    public function test_a_mixed_alternation_reads_no_values_and_the_shared_facts_only(): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(
            RegexParser::create()->parse('/(a|\d)/'),
        );

        $group = $shape->groups[1];

        self::assertNull($group->values, 'the digit arm holds infinitely many strings');
        self::assertFalse($group->digitsOnly, 'the constant arm is not digits');
        self::assertFalse($group->nonFalsy, 'the digit arm may read "0"');
        self::assertSame(1, $group->minLength);
    }
}
