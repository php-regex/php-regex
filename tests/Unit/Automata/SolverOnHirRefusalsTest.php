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

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One refusal taxonomy, from the normalized form: what the Hir ladder
 * refuses and the exact message it refuses it with. The AST-side ladder's
 * parallel messages ("Unsupported regex feature in automata conversion.",
 * "Unsupported regex node in automata conversion.", "Unsupported group
 * type: ...", "Unsupported anchor: ...") disappear with it.
 */
final class SolverOnHirRefusalsTest extends TestCase
{
    /**
     * The merged message list. One message per Hir reason: an opaque
     * construct, a conditional, a lookaround, an atomic group, a refused
     * assertion kind, an engine-refused surrogate atom, an unsupported
     * flag. The atomic message and the flags message are today's, kept
     * word for word; the others replace the validator's generic ones.
     *
     * @return iterable<string, array{pattern: string, expected: string}>
     */
    public static function provideRefusalRows(): iterable
    {
        $opaque = 'Backreferences, subroutines, callouts and control verbs carry match state the automata solver cannot read as a pure language.';
        $conditional = 'Conditional groups branch on match state the automata solver cannot read as a pure language.';
        $lookaround = 'Lookaround assertions match context instead of characters, which the automata solver cannot read as a pure language.';
        $atomic = 'Atomic groups commit to their first match and never retry, which is ordered behaviour the solver cannot read as a pure language.';
        $assertion = 'Word boundaries, \K, \G and anchors away from the edges of an alternative are zero-width conditions the automata solver cannot read as a pure language.';
        $surrogate = 'PCRE refuses any pattern that names a surrogate code point, which the automata solver cannot read as a pure language.';

        // "\g{1}" is the unambiguous spelling of the backreference: the
        // parser reads a plain "\1" as the octal escape \x{01}, and PCRE
        // agrees with that reading, so only "\g{1}" names the group here.
        yield 'backreference' => ['pattern' => '/(a)\g{1}/', 'expected' => $opaque];
        yield 'subroutine' => ['pattern' => '/(a)(?1)/', 'expected' => $opaque];
        yield 'callout' => ['pattern' => '/(?C)a/', 'expected' => $opaque];
        yield 'verb in the middle' => ['pattern' => '/a(*COMMIT)b/', 'expected' => $opaque];

        yield 'conditional group' => ['pattern' => '/(?(1)a|b)/', 'expected' => $conditional];

        yield 'lookahead' => ['pattern' => '/(?=a)a/', 'expected' => $lookaround];
        yield 'negative lookbehind' => ['pattern' => '/(?<!a)b/', 'expected' => $lookaround];

        yield 'atomic group' => ['pattern' => '/(?>a+)b/', 'expected' => $atomic];

        yield 'word boundary' => ['pattern' => '/a\b/', 'expected' => $assertion];
        yield 'not a word boundary' => ['pattern' => '/a\B/', 'expected' => $assertion];
        yield 'keep' => ['pattern' => '/a\Kb/', 'expected' => $assertion];
        yield 'subject start inside' => ['pattern' => '/a\Ab/', 'expected' => $assertion];
        yield 'subject end inside' => ['pattern' => '/a\zb/', 'expected' => $assertion];
        yield 'match start' => ['pattern' => '/\Gab/', 'expected' => $assertion];

        // Oracle: PCRE refuses to COMPILE a /u pattern that names a surrogate
        // code point — as a literal, a class member or a range endpoint
        // (preg_match('/[a\x{D800}]/u', 'a') is false, "Compilation failed:
        // disallowed Unicode code point (>= 0xd800 && <= 0xdfff)"); only a
        // range straddling the block with both endpoints outside compiles.
        // The engine-refused atom is refused, never approximated.
        yield 'surrogate literal' => ['pattern' => '/\x{D800}/u', 'expected' => $surrogate];
        yield 'surrogate in a class' => ['pattern' => '/[a\x{D800}]/u', 'expected' => $surrogate];
        yield 'surrogate as a range endpoint' => ['pattern' => '/[\x{D800}-\x{E000}]/u', 'expected' => $surrogate];

        // The accepted flag set stays {i, s, u}: patterns without anchors
        // under /m, extended-mode patterns and the caseless-restrict /r are
        // refused by the flag check, kept word for word. Anchors are kept
        // out of these rows so only the flag check can refuse them.
        yield 'm flag' => ['pattern' => '/ab/m', 'expected' => 'Unsupported regex flags for automata: m.'];
        yield 'x flag' => ['pattern' => '/a b/x', 'expected' => 'Unsupported regex flags for automata: x.'];
        yield 'r flag' => ['pattern' => '/a/r', 'expected' => 'Unsupported regex flags for automata: r.'];
    }

    #[Test]
    #[DataProvider('provideRefusalRows')]
    public function test_the_hir_ladder_refuses_with_one_message_per_reason(string $pattern, string $expected): void
    {
        $solver = new LanguageSolver();

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage($expected);

        $solver->intersection($pattern, '/a/', $this->fullMatchOptions());
    }

    /**
     * Oracle: "/^b++(?i:B)$/" accepts "bB" (1) and refuses "bb" and "bbb"
     * (0, 0): the caseless group also takes a "b", but the possessive
     * quantifier never gives one back. A follower-first-set probe that
     * ignores the group's own caseless flag sees only {B}, disjoint from
     * {b}, and wrongly calls the possessive safe — so the gate must read
     * fold-aware first sets from the normalized form and refuse.
     */
    #[Test]
    public function test_possessive_gate_is_fold_aware_through_inline_flags(): void
    {
        $solver = new LanguageSolver();

        $this->assertFalse((bool) @\preg_match('/^b++(?i:B)$/', 'bb'));
        $this->assertTrue((bool) @\preg_match('/^b++(?i:B)$/', 'bB'));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage('Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.');

        $solver->intersection('/b++(?i:B)/', '/bB/', $this->fullMatchOptions());
    }

    /**
     * Oracle: "/^(?i:a)b$/" accepts "ab" and "Ab" (1, 1) and refuses "aB"
     * and "AB" (0, 0) — the inline caseless flag ends where its group ends,
     * exactly the language of "/[aA]b/". The AST-side ladder refused the
     * inline-flags group outright ("Unsupported group type:
     * inline_flags."); the normalized form carries the flag on the branch,
     * so the pair becomes answerable.
     */
    #[Test]
    public function test_inline_flag_groups_are_answered(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue((bool) @\preg_match('/^(?i:a)b$/', 'ab'));
        $this->assertTrue((bool) @\preg_match('/^(?i:a)b$/', 'Ab'));
        $this->assertFalse((bool) @\preg_match('/^(?i:a)b$/', 'aB'));

        $result = $solver->equivalent('/(?i:a)b/', '/[aA]b/', $this->fullMatchOptions());

        $this->assertTrue($result->isEquivalent);
        $this->assertNull($result->leftOnlyExample);
        $this->assertNull($result->rightOnlyExample);
    }

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
