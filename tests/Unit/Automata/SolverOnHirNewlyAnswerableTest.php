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
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the solver reads from the one engine oracle: POSIX classes, Unicode
 * properties and extended classes are asked of the running PCRE through the
 * normalized form, so they are answered instead of refused.
 *
 * Every row in this file throws ComplexityException today: the AST-side
 * ladder refuses PosixClassNode, UnicodePropNode and ExtendedCharClassNode
 * before any automaton is built ("Unsupported regex feature in automata
 * conversion." / "Unsupported regex node in automata conversion.").
 */
final class SolverOnHirNewlyAnswerableTest extends TestCase
{
    #[Test]
    #[DataProvider('provideEquivalenceRows')]
    public function test_equivalence_answers_the_row(
        string $left,
        string $right,
        bool $expectedEquivalent,
        ?string $witnessSide,
    ): void {
        // A target that reads the rows below, the extended classes among
        // them (PCRE2 10.45), whatever engine runs the suite. The class sets
        // are still measured on that engine: a row holding an extended class
        // is judged only where the engine reads one.
        $solver = new LanguageSolver(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.45']));
        if (str_contains($left, '(?[') && false === @preg_match($left, '')) {
            $this->markTestSkipped(\sprintf('%s does not run on PCRE2 %s.', $left, \PCRE_VERSION));
        }

        try {
            $result = $solver->equivalent($left, $right, $this->fullMatchOptions());
        } catch (ComplexityException $e) {
            $this->fail(sprintf('%s must be answered, the solver refused it: %s', $left.' <=> '.$right, $e->getMessage()));
        }

        $this->assertSame($expectedEquivalent, $result->isEquivalent, sprintf('%s <=> %s', $left, $right));

        // Where the languages differ, the counter example is pinned against
        // the running engine on both sides, the way the soundness suite does.
        if ('left' === $witnessSide) {
            $this->assertNotNull($result->leftOnlyExample, $left.' <=> '.$right);
            $this->assertTrue($this->matchesFull($left, $result->leftOnlyExample ?? ''), var_export($result->leftOnlyExample, true).' must be matched by '.$left);
            $this->assertFalse($this->matchesFull($right, $result->leftOnlyExample ?? ''), var_export($result->leftOnlyExample, true).' must not be matched by '.$right);
        }
    }

    #[Test]
    #[DataProvider('provideSubsetRows')]
    public function test_subset_answers_the_row(string $left, string $right, bool $expectedSubset): void
    {
        $solver = new LanguageSolver();

        try {
            $result = $solver->subsetOf($left, $right, $this->fullMatchOptions());
        } catch (ComplexityException $e) {
            $this->fail(sprintf('%s <= %s must be answered, the solver refused it: %s', $left, $right, $e->getMessage()));
        }

        $this->assertSame($expectedSubset, $result->isSubset, sprintf('%s <= %s', $left, $right));
    }

    #[Test]
    #[DataProvider('provideIntersectionRows')]
    public function test_intersection_answers_the_row(string $left, string $right, bool $expectedEmpty): void
    {
        $solver = new LanguageSolver();

        try {
            $result = $solver->intersection($left, $right, $this->fullMatchOptions());
        } catch (ComplexityException $e) {
            $this->fail(sprintf('%s n %s must be answered, the solver refused it: %s', $left, $right, $e->getMessage()));
        }

        $this->assertSame($expectedEmpty, $result->isEmpty, sprintf('%s n %s', $left, $right));

        if (!$expectedEmpty) {
            $this->assertNotNull($result->example, $left.' n '.$right);
            $this->assertTrue($this->matchesFull($left, $result->example ?? ''), var_export($result->example, true).' must be matched by '.$left);
            $this->assertTrue($this->matchesFull($right, $result->example ?? ''), var_export($result->example, true).' must be matched by '.$right);
        }
    }

    /**
     * POSIX alpha under /u is wider than the ASCII letters on this engine and
     * the verdict is engine-relative by design: the set is asked of the
     * running PCRE, so the row asserts the solver agrees with whatever the
     * engine says about the same character.
     */
    #[Test]
    public function test_unicode_posix_alpha_follows_the_running_engine(): void
    {
        $left = '/[[:alpha:]]/u';
        $right = '/[a-zA-Z]/u';
        $wider = $this->matchesFull($left, "\u{E9}") && !$this->matchesFull($right, "\u{E9}");

        $solver = new LanguageSolver();

        try {
            $result = $solver->equivalent($left, $right, $this->fullMatchOptions());
        } catch (ComplexityException $e) {
            $this->fail('The POSIX class under /u must be answered, the solver refused it: '.$e->getMessage());
        }

        $this->assertSame(!$wider, $result->isEquivalent);

        if ($wider) {
            $this->assertNotNull($result->leftOnlyExample);
            $this->assertTrue($this->matchesFull($left, $result->leftOnlyExample ?? ''));
            $this->assertFalse($this->matchesFull($right, $result->leftOnlyExample ?? ''));
        }
    }

    /**
     * "\A" at the start and "\z" at the end of an alternative carry no
     * information a whole-string match does not already have, so they read
     * as epsilon the way "^" and "$" at the edges already do. Today the
     * AST-side ladder refuses any "\A" at all ("Unsupported anchor: \A.").
     */
    #[Test]
    public function test_edge_subject_anchors_are_answered(): void
    {
        $solver = new LanguageSolver();

        try {
            $result = $solver->equivalent('/\Aab\z/', '/ab/', $this->fullMatchOptions());
        } catch (ComplexityException $e) {
            $this->fail('An edge "\A"/"\z" must be answered, the solver refused it: '.$e->getMessage());
        }

        $this->assertTrue($result->isEquivalent);
    }

    /**
     * The pattern opens with a verb that changes what the classes mean, and
     * the oracle honours it: byte-mode "(*UCP)\w" matches the single byte
     * \xE9 (preg_match("/(*UCP)^\w$/", "\xE9") is 1 on this engine), where
     * plain "\w" in byte mode does not. The engine oracle reads start
     * verbs, so the solver answers instead of refusing the verb outright.
     */
    #[Test]
    public function test_a_start_verb_that_widens_the_classes_is_answered(): void
    {
        $solver = new LanguageSolver();

        try {
            $result = $solver->intersection('/(*UCP)\w/', "/\xE9/", $this->fullMatchOptions());
        } catch (ComplexityException $e) {
            $this->fail('A start verb must be answered, the solver refused it: '.$e->getMessage());
        }

        $this->assertFalse($result->isEmpty);
        $this->assertTrue($this->matchesFull('/(*UCP)\w/', $result->example ?? ''));
    }

    /**
     * Under /u the sets exclude the surrogate block D800-DFFF: a real
     * subject never contains those code points, so a negated class reads
     * the universe minus the surrogates, not the whole code point range.
     * Today the complement runs over 0-10FFFF and the DFA range
     * "0062-10FFFF" of "/[^a]/u" covers the surrogates.
     */
    #[Test]
    public function test_unicode_sets_exclude_the_surrogates(): void
    {
        $dfa = (new LanguageSolver())->compile('/[^a]/u', $this->fullMatchOptions());

        $overlaps = [];
        foreach ($dfa->states as $state) {
            foreach ($state->ranges as [$start, $end]) {
                if ($start <= 0xDFFF && $end >= 0xD800) {
                    $overlaps[] = sprintf('%04X-%04X', $start, $end);
                }
            }
        }

        $this->assertSame([], $overlaps, 'No DFA range of a /u set may cover D800-DFFF.');
    }

    /**
     * @return iterable<string, array{left: string, right: string, expectedEquivalent: bool, witnessSide: string|null}>
     */
    public static function provideEquivalenceRows(): iterable
    {
        // Oracle: scanning every byte 0-255, "/^[[:alpha:]]$/" and
        // "/^[a-zA-Z]$/" accept exactly the same bytes (checked on PCRE2
        // 10.49; POSIX classes in byte mode are ASCII-only, always).
        yield 'posix alpha is the ascii letters in byte mode' => [
            'left' => '/[[:alpha:]]/',
            'right' => '/[a-zA-Z]/',
            'expectedEquivalent' => true,
            'witnessSide' => null,
        ];

        // Oracle: "/^[[:^digit:]]$/" and "/^[^0-9]$/" accept the same 256
        // bytes: the negated POSIX digit is everything but 0-9, including
        // every byte above 0x7F.
        yield 'negated posix digit is the byte complement' => [
            'left' => '/[[:^digit:]]/',
            'right' => '/[^0-9]/',
            'expectedEquivalent' => true,
            'witnessSide' => null,
        ];

        // Oracle: "/^[[:alnum:][:space:]]$/" accepts exactly the bytes
        // 09-0D, 20, 30-39, 41-5A, 61-7A (10.49): both POSIX names in one
        // class are the union of their sets.
        yield 'posix alnum and space union in one class' => [
            'left' => '/[[:alnum:][:space:]]/',
            'right' => '/[\x09-\x0D 0-9A-Za-z]/',
            'expectedEquivalent' => true,
            'witnessSide' => null,
        ];

        // Oracle: "/^\C$/" accepts the bytes 0x00 and 0xE9 alike (1, 1):
        // "\C" is one code unit, the whole byte alphabet without /u.
        yield 'one code unit is every byte without /u' => [
            'left' => '/\C/',
            'right' => '/[\x00-\xFF]/',
            'expectedEquivalent' => true,
            'witnessSide' => null,
        ];

        // Oracle (PCRE2 10.45+): "/(?[ [a-c] & [b-d] ])/" matches b and c,
        // not a, not d. The single "&" is the intersection operator; the
        // double "&&" is refused by the engine. Operands must be bracketed.
        yield 'extended class set intersection' => [
            'left' => '/(?[ [a-c] & [b-d] ])/',
            'right' => '/[bc]/',
            'expectedEquivalent' => true,
            'witnessSide' => null,
        ];

        // Oracle: byte-mode "/^\p{L}$/" accepts 41-5A, 61-7A, AA, B5, BA,
        // C0-D6, D8-F6, F8-FF (10.49): the Latin-1 supplement letters make
        // it strictly wider than the ASCII letters.
        yield 'byte p{L} is wider than the ascii letters' => [
            'left' => '/\p{L}/',
            'right' => '/[a-zA-Z]/',
            'expectedEquivalent' => false,
            'witnessSide' => 'left',
        ];

        // Oracle: "/^\p{L}$/u" accepts é (1) where "/^[a-zA-Z]$/u" does not
        // (0): under /u the property spans every Unicode letter.
        yield 'unicode p{L} is wider than the ascii letters' => [
            'left' => '/\p{L}/u',
            'right' => '/[a-zA-Z]/u',
            'expectedEquivalent' => false,
            'witnessSide' => 'left',
        ];
    }

    /**
     * @return iterable<string, array{left: string, right: string, expectedSubset: bool}>
     */
    public static function provideSubsetRows(): iterable
    {
        // Oracle: the byte sets above, a-zA-Z (41-5A, 61-7A) inside
        // 41-5A, 61-7A, AA, B5, BA, C0-D6, D8-F6, F8-FF.
        yield 'ascii letters are a subset of byte p{L}' => [
            'left' => '/[a-zA-Z]/',
            'right' => '/\p{L}/',
            'expectedSubset' => true,
        ];

        // Oracle: "/^\p{L}$/u" on "a" and on "é" are both 1.
        yield 'ascii letters are a subset of unicode p{L}' => [
            'left' => '/[a-zA-Z]/u',
            'right' => '/\p{L}/u',
            'expectedSubset' => true,
        ];

        // Oracle: "/^\p{Greek}$/u" on α, β and γ is 1 (10.49).
        yield 'greek sample is a subset of the greek script' => [
            'left' => '/[αβγ]/u',
            'right' => '/\p{Greek}/u',
            'expectedSubset' => true,
        ];
    }

    /**
     * @return iterable<string, array{left: string, right: string, expectedEmpty: bool}>
     */
    public static function provideIntersectionRows(): iterable
    {
        // Oracle: "/^\p{Greek}$/u" on "0" is 0: no ASCII digit is a Greek
        // letter in any Unicode version.
        yield 'the greek script has no ascii digits' => [
            'left' => '/\p{Greek}/u',
            'right' => '/[0-9]/u',
            'expectedEmpty' => true,
        ];

        // Oracle: "/^\P{L}$/u" on "0" is 1 and on "a" is 0: the negated
        // property keeps the digits and loses the letters.
        yield 'non letters intersect the digits' => [
            'left' => '/\P{L}/u',
            'right' => '/[0-9]/u',
            'expectedEmpty' => false,
        ];

        yield 'non letters do not intersect the letters' => [
            'left' => '/\P{L}/u',
            'right' => '/[a-c]/u',
            'expectedEmpty' => true,
        ];

        if (!PcreTarget::runtime()->supports(PcreFeature::ExtendedCharClass)) {
            // Before PCRE2 10.45 "(?[" is not pattern syntax at all, so the
            // engine-relative rows it grounds cannot be asked of the solver.
            return;
        }

        // Oracle (PCRE2 10.45+): "/(?[ \p{L} - [aeiou] ])/u" on "a" is 0 and
        // on "b", "Z" and "é" is 1: the vowels are subtracted from the
        // letters, the rest of the letters stays.
        yield 'extended class subtracts the vowels' => [
            'left' => '/(?[ \p{L} - [aeiou] ])/u',
            'right' => '/a/u',
            'expectedEmpty' => true,
        ];

        yield 'extended class keeps the non vowel letters' => [
            'left' => '/(?[ \p{L} - [aeiou] ])/u',
            'right' => '/é/u',
            'expectedEmpty' => false,
        ];
    }

    /**
     * The oracle: whether the running engine accepts the subject as a match
     * of the whole pattern, the same reading the soundness suite uses.
     */
    private function matchesFull(string $pattern, string $subject): bool
    {
        $matches = [];
        $result = @\preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);
        if (1 !== $result) {
            return false;
        }

        return $matches[0][0] === $subject && 0 === $matches[0][1];
    }

    private function fullMatchOptions(): SolverOptions
    {
        return new SolverOptions(matchMode: MatchMode::Full);
    }
}
