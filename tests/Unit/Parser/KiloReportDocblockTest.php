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

use PHPRegex\Parser\Syntax\PcreVerb;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The examples the docblocks give say what the engine does; when an
 * example is wrong, a maintainer reads the wrong thing into the code.
 * These tests pin the examples the Kilo report flagged.
 */
final class KiloReportDocblockTest extends TestCase
{
    #[Test]
    public function test_the_overflowing_number_example_reads_as_octal_then_text(): void
    {
        // "\214748364": the engine reads \214 (octal, chr(0x8C)) then
        // "748364" as text — the example in the docblock says the whole
        // number overflows, which misleads: the digits are not all octal
        $this->assertSame(
            1,
            (int) @preg_match('/\\214748364/', \chr(0x8C).'748364'),
            'the engine reads three octal digits, then text',
        );
    }

    #[Test]
    public function test_the_c_escape_xors_any_character_with_0x40(): void
    {
        // PCRE2 10.49 accepts any character after \c and computes
        // chr(ord(c) ^ 0x40), not only the letters and @[\]^_
        $this->assertSame(1, (int) @preg_match('/\\c0/', 'p'));
        $this->assertSame(1, (int) @preg_match('/\\c!/', 'a'));
    }

    #[Test]
    public function test_fail_takes_an_argument_as_far_as_the_engine_reads_it(): void
    {
        // (*FAIL:x) compiles and matches nothing — the engine accepts the
        // spelling, so the verb list says it takes one. The unknown verb
        // is its mirror: the engine refuses (*UNKNOWN:x), and the verb
        // test in the lexer suite pins that refusal at its own place.
        $this->assertSame(0, (int) @preg_match('/(*FAIL:x)/', ''));
        $this->assertTrue(PcreVerb::takesArgument('FAIL'));
        $this->assertTrue(PcreVerb::takesArgument('F'));
    }
}
