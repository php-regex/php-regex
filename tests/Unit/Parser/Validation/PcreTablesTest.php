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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\Validation\PcreTables;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PcreTablesTest extends TestCase
{
    /**
     * Each character of a caseless set finds the whole set, and a character
     * of one other case finds none. The map is built once per process, so
     * it is dropped first to be built here.
     */
    #[Test]
    public function test_each_character_of_a_caseless_set_finds_its_set(): void
    {
        (new \ReflectionProperty(PcreTables::class, 'caselessSets'))->setValue(null, null);

        $sets = PcreTables::caselessSets();

        $this->assertSame([0x004B, 0x006B, 0x212A], $sets[0x212A] ?? null);
        $this->assertSame([0x0345, 0x0399, 0x03B9, 0x1FBE], $sets[0x0345] ?? null);
        $this->assertArrayNotHasKey(0x0041, $sets);
        $this->assertCount(\count(PcreTables::CASELESS_SETS, \COUNT_RECURSIVE) - \count(PcreTables::CASELESS_SETS), $sets);
    }
}
