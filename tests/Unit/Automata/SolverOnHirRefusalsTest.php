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
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
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
    use PcreGates;
    use PhpUnitForwardCompat;

    /**
     * The merged message list. One message per Hir reason: an opaque
     * construct, a conditional, a lookaround, an atomic group, a refused
     * assertion kind, an engine-refused surrogate atom, an unsupported
     * flag. The atomic message and the flags message are today's, kept
     * word for word; the others replace the validator's generic ones.
     *
     * A row may name what the engine reads apart: on the subject, the
     * first pattern finds another match than the second.
     *
     * @return iterable<string, array{pattern: string, expected: string, engine?: array{string, string, string}}>
     */
    public static function provideRefusalRows(): iterable
    {
        $opaque = 'Backreferences, subroutines, callouts and control verbs carry match state the automata solver cannot read as a pure language.';
        $conditional = 'Conditional groups branch on match state the automata solver cannot read as a pure language.';
        $nested = 'A lookaround inside a lookaround is beyond what the automata solver reads.';
        $anchored = 'An anchor inside a lookaround is beyond what the automata solver reads.';
        $atomic = 'Atomic groups commit to their first match and never retry, which is ordered behaviour the solver cannot read as a pure language.';
        $assertion = '\K and \G read where a match starts or stood before, which the automata solver cannot read as a pure language.';
        $surrogate = 'PCRE refuses any pattern that names a surrogate code point, which the automata solver cannot read as a pure language.';

        // "\g{1}" is the unambiguous spelling of the backreference: the
        // parser reads a plain "\1" as the octal escape \x{01}, and PCRE
        // agrees with that reading, so only "\g{1}" names the group here.
        yield 'backreference' => ['pattern' => '/(a)\g{1}/', 'expected' => $opaque];
        yield 'subroutine' => ['pattern' => '/(a)(?1)/', 'expected' => $opaque];
        yield 'callout' => ['pattern' => '/(?C)a/', 'expected' => $opaque];
        yield 'verb in the middle' => ['pattern' => '/a(*COMMIT)b/', 'expected' => $opaque];

        yield 'conditional group' => ['pattern' => '/(?(1)a|b)/', 'expected' => $conditional];

        yield 'lookahead inside a lookahead' => ['pattern' => '/(?=a(?=b))a/', 'expected' => $nested];
        yield 'anchor inside a negative lookbehind' => ['pattern' => '/(?<!^a)b/', 'expected' => $anchored];
        // Oracle: preg_match('/(?:x|(?=a)){0}\w/', 'b') is 0 where
        // preg_match('/\w/', 'b') is 1, and preg_match('/(?:x|^){0}b/', 'ab')
        // is 0 where preg_match('/b/', 'ab') is 1: PCRE2 reads the group
        // repeated no time to choose where it tries a match.
        yield 'lookahead in a group repeated no time' => ['pattern' => '/(?:x|(?=a)){0}\w/', 'expected' => 'An anchor, an assertion or a lookaround in a group repeated no time still moves where PCRE2 tries a match, which the automata solver does not read.'];
        yield 'start anchor in a group repeated no time' => ['pattern' => '/(?:x|^){0}b/', 'expected' => 'An anchor, an assertion or a lookaround in a group repeated no time still moves where PCRE2 tries a match, which the automata solver does not read.'];

        // Oracle: on "abc", the anchored atomic spelling finds no match,
        // where the plain group backtracks into "ab": the atomic group
        // commits to "a".
        yield 'atomic group' => ['pattern' => '/(?>a|ab)c/', 'expected' => $atomic, 'engine' => ['/^(?>a|ab)c$/', '/^(?:a|ab)c$/', 'abc']];
        // Oracle: preg_match('/^(?:a|ab){1}+c$/', 'abc') is 0, where the
        // greedy "{1}" takes it: once is still possessive.
        yield 'possessive count of one' => ['pattern' => '/(?:a|ab){1}+c/', 'expected' => 'Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.', 'engine' => ['/^(?:a|ab){1}+c$/', '/^(?:a|ab){1}c$/', 'abc']];
        yield 'possessive count of one to one' => ['pattern' => '/(?:a|ab){1,1}+c/', 'expected' => 'Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.', 'engine' => ['/^(?:a|ab){1,1}+c$/', '/^(?:a|ab){1,1}c$/', 'abc']];

        yield 'match start after a character' => ['pattern' => '/a\G/', 'expected' => $assertion];
        yield 'word boundary inside a lookahead' => ['pattern' => '/(?=\b)a/', 'expected' => 'An anchor inside a lookaround is beyond what the automata solver reads.'];
        yield 'keep' => ['pattern' => '/a\Kb/', 'expected' => $assertion];
        yield 'keep at the start' => ['pattern' => '/\Ka/', 'expected' => $assertion];
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

        // Oracle: preg_match('/a/A', 'ba') is 0, the anchored flag pins a
        // search to the subject's start. It is not read, so the flag check
        // refuses it word for word.
        yield 'A flag' => ['pattern' => '/a/A', 'expected' => 'Unsupported regex flags for automata: A.'];
        // Oracle: under (*CRLF) the dot takes a "\r" unless a "\n" follows it,
        // so in "a\r\n" no "a" stands before a ".\n", where one does once the
        // dot takes all. The dot looks ahead for it, here inside a lookahead.
        yield 'a dot in a lookahead under (*CRLF)' => ['pattern' => '/(*CRLF)a(?=.\n)/m', 'expected' => 'Under (*CRLF) the dot reads a "\r" alone but not one before a "\n", which the automata solver does not read inside a lookaround.', 'engine' => ['/(*CRLF)a(?=.\n)/s', '/(*CRLF)a(?=.\n)/m', "a\r\n"]];
        // Without the JIT the option refuses the empty match "/a?/" finds
        // at 0; the JIT ignores it: test_the_jit_decides_what_an_empty_match_option_refuses().
        yield 'not empty' => ['pattern' => '/(*NOTEMPTY)a?/', 'expected' => '(*NOTEMPTY) and (*NOTEMPTY_ATSTART) refuse', 'engine' => ['/(*NO_JIT)(*NOTEMPTY)a?/', '/a?/', 'b']];
        yield 'not empty at start' => ['pattern' => '/(*NOTEMPTY_ATSTART)a?/', 'expected' => '(*NOTEMPTY) and (*NOTEMPTY_ATSTART) refuse', 'engine' => ['/(*NO_JIT)(*NOTEMPTY_ATSTART)a?/', '/a?/', 'b']];
        yield 'not empty without the JIT' => ['pattern' => '/(*NO_JIT)(*NOTEMPTY)a?/', 'expected' => '(*NOTEMPTY) and (*NOTEMPTY_ATSTART) refuse', 'engine' => ['/(*NO_JIT)(*NOTEMPTY)a?/', '/(*NO_JIT)a?/', 'b']];
    }

    /**
     * @param array{string, string, string}|null $engine
     */
    #[Test]
    #[DataProvider('provideRefusalRows')]
    public function test_the_hir_ladder_refuses_with_one_message_per_reason(string $pattern, string $expected, ?array $engine = null): void
    {
        if (null !== $engine) {
            [$read, $apart, $subject] = $engine;
            $this->assertNotSame(self::firstMatch($apart, $subject), self::firstMatch($read, $subject), $read.' against '.$apart);
        }

        // A target that reads every flag of the rows below, whatever engine
        // runs the suite.
        $solver = new LanguageSolver(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44']));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains($expected);

        $solver->intersection($pattern, '/a/', $this->fullMatchOptions());
    }

    /**
     * The engine the rows of an empty match option stand on, where the
     * interpreter runs, whatever the JIT does: on "b", "/a?/" finds "" at 0.
     * Under pcre.jit=0, or behind (*NO_JIT), (*NOTEMPTY) finds no match and
     * (*NOTEMPTY_ATSTART) "" at 1. Where PHP is built without the JIT, it
     * has no pcre.jit, and the interpreter runs all the same. A pattern
     * stays in PHP's cache as compiled under the setting of its first use,
     * so each setting reads the patterns under a delimiter of its own.
     */
    #[Test]
    public function test_the_interpreter_honours_an_empty_match_option(): void
    {
        $this->assertSame([['', 0]], self::firstMatch('/a?/', 'b'));
        $this->assertSame([], self::firstMatch('/(*NO_JIT)(*NOTEMPTY)a?/', 'b'));
        $this->assertSame([['', 1]], self::firstMatch('/(*NO_JIT)(*NOTEMPTY_ATSTART)a?/', 'b'));

        $jit = \ini_get('pcre.jit');

        try {
            ini_set('pcre.jit', '0');
            $this->assertSame([], self::firstMatch("\x01(*NOTEMPTY)a?\x01", 'b'));
            $this->assertSame([['', 1]], self::firstMatch("\x01(*NOTEMPTY_ATSTART)a?\x01", 'b'));
        } finally {
            if (false !== $jit) {
                ini_set('pcre.jit', $jit);
            }
        }
    }

    /**
     * The JIT ignores both options and finds "" at 0 on "b", where the
     * interpreter does not: test_the_interpreter_honours_an_empty_match_option().
     */
    #[Test]
    public function test_the_jit_decides_what_an_empty_match_option_refuses(): void
    {
        $this->requiresJit('An empty match option the JIT ignores');

        $jit = (string) \ini_get('pcre.jit');

        try {
            ini_set('pcre.jit', '1');
            $this->assertSame([['', 0]], self::firstMatch("\x02(*NOTEMPTY)a?\x02", 'b'));
            $this->assertSame([['', 0]], self::firstMatch("\x02(*NOTEMPTY_ATSTART)a?\x02", 'b'));
        } finally {
            ini_set('pcre.jit', $jit);
        }
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
        $this->expectExceptionMessageIsOrContains('Possessive quantifiers never give back what they matched, which is ordered behaviour the solver cannot read as a pure language.');

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

    /**
     * Oracle: "/(?:x|y){0}\w/" and "/\w/" find the same match in "\tb",
     * "b", "xb" and "\t" (1, 1, 1, 0 each): a group repeated no time that
     * holds no anchor, assertion or lookaround moves nothing.
     */
    #[Test]
    public function test_a_group_repeated_no_time_without_an_assertion_is_answered(): void
    {
        foreach (["\tb", 'b', 'xb', "\t"] as $subject) {
            $this->assertSame(preg_match('/\w/', $subject, $plain), preg_match('/(?:x|y){0}\w/', $subject, $repeated), bin2hex($subject));
            $this->assertSame($plain, $repeated);
        }

        $this->assertTrue((new LanguageSolver())->equivalent('/(?:x|y){0}\w/', '/\w/', $this->fullMatchOptions())->isEquivalent);
        $this->assertTrue((new LanguageSolver())->equivalent('/(?:x|y){0}\w/', '/\w/', new SolverOptions(matchMode: MatchMode::Partial))->isEquivalent);
    }

    /**
     * The first match and its offset, none where there is none.
     *
     * @return array<array{string, int}>
     */
    private static function firstMatch(string $pattern, string $subject): array
    {
        preg_match($pattern, $subject, $match, \PREG_OFFSET_CAPTURE);

        return $match;
    }

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
