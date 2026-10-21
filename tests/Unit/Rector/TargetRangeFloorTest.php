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

namespace PHPRegex\Tests\Unit\Rector;

use PHPRegex\Rector\PregCall;
use PHPRegex\Rector\TargetRange;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Rector takes a PHP target from 5.3 on, which the library names no
 * release for: such a range is judged from PHP 7.0, the oldest PHP a
 * "php_version" names.
 */
final class TargetRangeFloorTest extends TestCase
{
    #[Test]
    public function test_a_target_below_php_7_is_judged_from_php_7(): void
    {
        $range = TargetRange::from(50600);

        $this->assertSame(TargetRange::from(70000), $range);
        $this->assertTrue($range->diff('/a/')->from->validation->isValid);
    }

    /**
     * The parser a rule reads the target's patterns with takes the same
     * floor: PregSplitToExplodeRector and PregReplaceToStrReplaceRector run
     * on a project that targets PHP 5.6.
     */
    #[Test]
    public function test_the_target_parser_of_a_php_below_7_judges_php_7(): void
    {
        $parser = PregCall::targetParser(50600);

        $this->assertSame(70000, $parser->target()->phpVersionId);
        $this->assertTrue($parser->validate('/a/')->isValid);
    }
}
