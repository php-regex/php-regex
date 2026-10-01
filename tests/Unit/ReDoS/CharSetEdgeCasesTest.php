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

namespace PhpRegex\Tests\Unit\ReDoS;

use PhpRegex\Parser\Analysis\ByteCharSet;
use PHPUnit\Framework\TestCase;

final class CharSetEdgeCasesTest extends TestCase
{
    public function test_union_returns_empty_for_two_empty_sets(): void
    {
        $result = ByteCharSet::empty()->union(ByteCharSet::empty());

        $this->assertTrue($result->isEmpty());
    }

    public function test_sample_char_returns_null_when_range_is_missing(): void
    {
        $ref = new \ReflectionClass(ByteCharSet::class);
        $set = $ref->newInstanceWithoutConstructor();

        $ref->getProperty('ranges')->setValue($set, [[]]);
        $ref->getProperty('unknown')->setValue($set, false);

        $this->assertNull($set->sampleChar());
    }
}
