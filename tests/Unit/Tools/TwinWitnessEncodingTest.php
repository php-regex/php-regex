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

namespace PHPRegex\Tests\Unit\Tools;

use PHPRegex\Build\Verify\Witness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The witness of the twin's verification writes a tree as deep as its
 * reading of it goes: two hundred levels of nodes, each two or three levels
 * of JSON, past the 512 json_encode() takes by default: a hundred nested
 * groups reach it. A pattern nested
 * that deep anywhere under tests/ is one the twin's verification reads.
 */
final class TwinWitnessEncodingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once \dirname(__DIR__, 3).'/build/verify/Witness.php';
    }

    #[Test]
    public function test_the_witness_of_a_deeply_nested_pattern_is_written(): void
    {
        // A hundred levels: PCRE nests 250 parentheses at most.
        $pattern = '/'.str_repeat('(?:a', 100).str_repeat(')', 100).'/';
        $this->assertSame(0, preg_match($pattern, 'a'), 'Oracle: PCRE compiles the pattern.');

        $witness = Witness::of($pattern, ['php' => \PHP_VERSION_ID, 'pcre' => explode(' ', \PCRE_VERSION)[0], 'locale' => 'C']);

        $this->assertSame(array_keys($witness), array_keys(Witness::hashes($witness)));
        $this->assertJson(Witness::encode($witness['parse']));
    }
}
