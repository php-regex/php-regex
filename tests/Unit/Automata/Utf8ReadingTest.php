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

namespace PHPRegex\Tests\Unit\Automata;

use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Solver\Utf8Reading;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Utf8ReadingTest extends TestCase
{
    /**
     * "é" is the bytes C3 A9: an automaton over bytes that reads them and
     * nothing else reads U+00E9 and nothing else, a character at a time. A
     * state that would read a continuation byte first reads no character.
     */
    #[Test]
    public function test_an_automaton_over_bytes_reads_each_character_as_its_utf8_form(): void
    {
        $this->assertSame('c3a9', bin2hex("\u{E9}"));
        $bytes = new Dfa(0, [
            0 => new DfaState(0, [0xC3 => 1], false),
            1 => new DfaState(1, [0xA9 => 2], false),
            2 => new DfaState(2, [], true),
        ]);

        $characters = Utf8Reading::of($bytes);

        $this->assertSame([0, 0x10FFFF], [$characters->minCodePoint, $characters->maxCodePoint]);
        $this->assertSame([[0xE9, 0xE9, 2]], $characters->getState(0)->ranges());
        $this->assertSame([], $characters->getState(1)->ranges());
        $this->assertSame([false, false, true], [$characters->getState(0)->isAccepting, $characters->getState(1)->isAccepting, $characters->getState(2)->isAccepting]);
        $this->assertSame([[0, 0xE8], [0xE9, 0xE9], [0xEA, 0x10FFFF]], $characters->alphabetRanges);
    }

    /**
     * Any byte read any number of times reads every character but the
     * surrogates: their UTF-8 forms, an overlong form and a form above
     * U+10FFFF are no character to PCRE, which refuses them under /u.
     */
    #[Test]
    public function test_every_byte_reads_every_character_but_the_surrogates(): void
    {
        foreach (["\xED\xA0\x80", "\xC0\x80", "\xF4\x90\x80\x80"] as $form) {
            $this->assertFalse(@preg_match('/\A.\z/u', $form), bin2hex($form));
        }
        $this->assertSame(1, preg_match('/\A.\z/u', "\u{10FFFF}"));

        $characters = Utf8Reading::of(new Dfa(0, [0 => new DfaState(0, array_fill(0, 256, 0), true)]));

        $this->assertSame([[0, 0xD7FF, 0], [0xE000, 0x10FFFF, 0]], $characters->getState(0)->ranges());
        $this->assertSame([[0, 0xD7FF], [0xD800, 0xDFFF], [0xE000, 0x10FFFF]], $characters->alphabetRanges);
    }

    /**
     * "€" is E2 82 AC and "₮" E2 82 AE: the last byte goes to one state or
     * the other, and each character to the state its last byte reaches.
     */
    #[Test]
    public function test_a_three_byte_form_reads_each_character_its_last_byte_tells_apart(): void
    {
        $this->assertSame(['e282ac', 'e282ae'], [bin2hex("\u{20AC}"), bin2hex("\u{20AE}")]);
        $bytes = new Dfa(0, [
            0 => new DfaState(0, [0xE2 => 1], false),
            1 => new DfaState(1, [0x82 => 2], false),
            2 => new DfaState(2, [0xAC => 3, 0xAE => 4], false),
            3 => new DfaState(3, [], true),
            4 => new DfaState(4, [], true),
        ]);

        $characters = Utf8Reading::of($bytes);

        $this->assertSame([[0x20AC, 0x20AC, 3], [0x20AE, 0x20AE, 4]], $characters->getState(0)->ranges());
        $this->assertSame([[0, 0x20AB], [0x20AC, 0x20AC], [0x20AD, 0x20AD], [0x20AE, 0x20AE], [0x20AF, 0x10FFFF]], $characters->alphabetRanges);
    }

    /**
     * Two states that read the same character cut the alphabet at the same
     * code points, once.
     */
    #[Test]
    public function test_states_that_read_the_same_character_cut_the_alphabet_once(): void
    {
        $bytes = new Dfa(0, [
            0 => new DfaState(0, [0x61 => 1], false),
            1 => new DfaState(1, [0x61 => 2], false),
            2 => new DfaState(2, [], true),
        ]);

        $characters = Utf8Reading::of($bytes);

        $this->assertSame([[0x61, 0x61, 1]], $characters->getState(0)->ranges());
        $this->assertSame([[0x61, 0x61, 2]], $characters->getState(1)->ranges());
        $this->assertSame([[0, 0x60], [0x61, 0x61], [0x62, 0x10FFFF]], $characters->alphabetRanges);
    }
}
