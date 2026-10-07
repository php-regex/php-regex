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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Edges of the loop shapes the nested-loop rules leave alone, and of the
 * groups a relative subroutine call runs again. Every verdict is the
 * engine's: steps are the smallest backtrack limit a match attempt runs
 * under, JIT off, start optimizations off (PCRE2 10.49). Doubling the
 * subject doubles a linear count and at least quadruples a quadratic one.
 */
final class NestedLoopEdgeCaseTest extends TestCase
{
    private const NESTED = 'regex.lint.quantifier.nested';

    private const DOT_STAR = 'regex.lint.dotstar.nested';

    private const OVERLAP = 'regex.lint.overlap.charset';

    private const LAZY_END = 'regex.lint.quantifier.lazyEnd';

    private const USELESS_BACKREF = 'regex.lint.backref.useless';

    /**
     * "(a+){1,2}$" is left alone: the run of one literal character before
     * the end stays linear. The i flag keeps it one literal, both cases
     * included (26 -> 50 -> 98 steps for n 8 -> 16 -> 32, as without i),
     * and so does the Kelvin sign "K" that "k" matches under iu. A class
     * of two characters is no literal and goes quadratic (55 -> 171 -> 595),
     * with or without i.
     *
     * @return iterable<string, array{pattern: string, unit: string, reported: bool}>
     */
    public static function provideBoundedOuterLoops(): iterable
    {
        yield 'literal under i, both cases in the subject' => ['pattern' => '/(a+){1,2}$/i', 'unit' => 'aA', 'reported' => false];
        yield 'literal under inline (?i)' => ['pattern' => '/(?i)(a+){1,2}$/', 'unit' => 'aA', 'reported' => false];
        yield 'literal under iu, Kelvin sign in the subject' => ['pattern' => '/(k+){1,2}$/iu', 'unit' => "k\u{212A}K", 'reported' => false];
        yield 'literal before \z' => ['pattern' => '/(a+){1,2}\z/', 'unit' => 'a', 'reported' => false];
        yield 'class of two' => ['pattern' => '/([ab]+){1,2}$/', 'unit' => 'ab', 'reported' => true];
        yield 'class of two under i' => ['pattern' => '/([ab]+){1,2}$/i', 'unit' => 'aB', 'reported' => true];
        yield 'class of two, one character in the subject' => ['pattern' => '/([ab]+){1,2}$/', 'unit' => 'a', 'reported' => true];
        yield 'class of two in a group' => ['pattern' => '/((?:[ab])+){1,2}$/', 'unit' => 'a', 'reported' => true];
        // The other exclusions of the shape, each quadratic on a{n}c
        // (n 16 -> 32): under m (155 -> 563), a lazy run (171 -> 595), a
        // lookahead in place of the end (274 -> 1 058).
        yield 'literal under m' => ['pattern' => '/(a+){1,2}$/m', 'unit' => 'a', 'reported' => true];
        yield 'lazy literal' => ['pattern' => '/(a+?){1,2}$/', 'unit' => 'a', 'reported' => true];
        yield 'literal before a lookahead' => ['pattern' => '/(?:a+){1,2}(?=b)/', 'unit' => 'a', 'reported' => true];
        // The shape is linear only for a greedy run, "$"
        // matching at the very end and auto-possessification on. Under U
        // the run is lazy, "$" also matches before a final "\n", and
        // (*NO_AUTO_POSSESS) or a newline verb changes how it is compiled:
        // each is quadratic (55 -> 171, 47 -> 155 for n 8 -> 16).
        yield 'literal under U' => ['pattern' => '/(a+){1,2}$/U', 'unit' => 'a', 'reported' => true];
        yield 'literal under inline (?U)' => ['pattern' => '/(?U)(a+){1,2}$/', 'unit' => 'a', 'reported' => true];
        yield 'run of newlines' => ['pattern' => '/(\n+){1,2}$/', 'unit' => "\n", 'reported' => true];
        yield 'literal under (*NO_AUTO_POSSESS)' => ['pattern' => '/(*NO_AUTO_POSSESS)(a+){1,2}$/', 'unit' => 'a', 'reported' => true];
        yield 'run of carriage returns under (*CR)' => ['pattern' => '/(*CR)(\r+){1,2}$/', 'unit' => "\r", 'reported' => true];
        // An m set in an earlier alternative carries into the later ones.
        yield 'literal under an m set in an earlier alternative' => ['pattern' => '/x(?m)|(a+){1,2}$/', 'unit' => 'a', 'reported' => true];
        // Vertical whitespace written as an escape: "$" also matches before
        // it, as before "\n" (155 -> 563 for n 16 -> 32).
        yield 'run of escaped vertical tabs' => ['pattern' => '/(\x0B+){1,2}$/', 'unit' => "\x0B", 'reported' => true];
        yield 'run of escaped NEL under u' => ['pattern' => '/(\x{85}+){1,2}$/u', 'unit' => "\u{85}", 'reported' => true];
        yield 'run of escaped line separators under u' => ['pattern' => '/(\x{2028}+){1,2}$/u', 'unit' => "\u{2028}", 'reported' => true];
        // "(?^)" resets i, m, n, s and x only; the U of the
        // modifiers stays (PCRE2 10.49: "/(?^)a+/U" matches "a"), so the run
        // is lazy and quadratic as under U alone (154 -> 562 for n 16 -> 32,
        // 1 327 -> 20 302 for n 50 -> 200).
        yield 'literal under U, after (?^)' => ['pattern' => '/(?^)(?:a+){1,2}$/U', 'unit' => 'a', 'reported' => true];
    }

    /**
     * Any start-of-pattern verb takes the shape out of the exemption,
     * whether or not it changes how the run is matched. These two stay
     * linear on a{n}c (51 -> 99 and 50 -> 98 for n 16 -> 32, and on the
     * other tails tried), so their report is a conservative one.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideStartVerbsBeforeAShortRun(): iterable
    {
        yield 'newline verb in the first alternative of the root' => ['pattern' => '/(*CR)x|(a+){1,2}$/'];
        yield 'UTF verb' => ['pattern' => '/(*UTF)(a+){1,2}$/'];
    }

    #[Test]
    #[DataProvider('provideStartVerbsBeforeAShortRun')]
    public function test_a_start_verb_keeps_a_short_run_reported(string $pattern): void
    {
        $short = self::steps($pattern, str_repeat('a', 16).'c');
        $long = self::steps($pattern, str_repeat('a', 32).'c');
        $this->assertLessThanOrEqual(3, $long / $short, \sprintf('The engine went superlinear: %d -> %d steps.', $short, $long));

        $this->assertContains(self::NESTED, self::lint($pattern));
    }

    #[Test]
    #[DataProvider('provideBoundedOuterLoops')]
    public function test_bounded_outer_loop_is_reported_only_when_the_engine_goes_quadratic(string $pattern, string $unit, bool $reported): void
    {
        $short = self::steps($pattern, self::repeatUnit($unit, 16).'c');
        $long = self::steps($pattern, self::repeatUnit($unit, 32).'c');
        $this->assertSame($reported, $long / $short > 3, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($reported, \in_array(self::NESTED, self::lint($pattern), true), $pattern);
    }

    /**
     * A separator keeps the iterations of a loop apart only when the inner
     * loop can never take its characters. Under i that compares the sets
     * case-folded: "A" separates "a+" without i (6 -> 10 steps for n 8 ->
     * 16) and does not with it (68 -> 3 194). The item before the inner loop
     * is no separator when what follows the inner loop can take its
     * characters: in ",a*a*" the two runs share every "a" of an iteration
     * (78 -> 1 278), while ",a*" alone stays linear (15 -> 27).
     *
     * @return iterable<string, array{pattern: string, unit: string, reported: bool}>
     */
    public static function provideSeparators(): iterable
    {
        yield 'separator of the same letter under i' => ['pattern' => '/(?:a+A)+$/i', 'unit' => 'a', 'reported' => true];
        yield 'separator of the same letter under inline (?i)' => ['pattern' => '/(?i)(?:a+A)+$/', 'unit' => 'a', 'reported' => true];
        yield 'separator of the same letter under i, both cases in the subject' => ['pattern' => '/(?:a+A)+$/i', 'unit' => 'aA', 'reported' => true];
        yield 'separator of the other case without i' => ['pattern' => '/(?:a+A)+$/', 'unit' => 'aA', 'reported' => false];
        yield 'hyphen separator under i' => ['pattern' => '/^\w+(?:-\w+)*$/i', 'unit' => 'a-', 'reported' => false];
        yield 'separator before two runs of the same letter' => ['pattern' => '/(?:,a*a*)*$/', 'unit' => ',a', 'reported' => true];
        yield 'separator before one run' => ['pattern' => '/(?:,a*)*$/', 'unit' => ',a', 'reported' => false];
        // What follows the run is read past the items that may match
        // nothing: behind "b?" (78 -> 1 278) or an optional lookahead
        // (138 -> 2 298) the second run still takes the "a" of the first.
        yield 'second run behind an optional item' => ['pattern' => '/(?:,a*b?a*)*$/', 'unit' => ',a', 'reported' => true];
        yield 'second run behind an optional lookahead' => ['pattern' => '/(?:,a*(?=x)?a*)*$/', 'unit' => ',a', 'reported' => true];
        yield 'optional item after the only run' => ['pattern' => '/(?:,a*b?)*$/', 'unit' => ',a', 'reported' => false];
        // The tail is read past what matches no character: a word
        // boundary, a comment, a standalone flag group (each 78 -> 1 278).
        // Past them, a separator the run cannot take still keeps the
        // iterations apart (8 -> 13 and 5 -> 8).
        yield 'second run behind a word boundary' => ['pattern' => '/(?:,a*\ba*)*$/', 'unit' => ',a', 'reported' => true];
        yield 'second run behind a comment' => ['pattern' => '/(?:,a*(?#c)a*)*$/', 'unit' => ',a', 'reported' => true];
        yield 'second run behind a flag group' => ['pattern' => '/(?:,a*(?i)a*)*$/', 'unit' => ',a', 'reported' => true];
        yield 'separator behind a word boundary' => ['pattern' => '/(?:,a*\b;)*$/', 'unit' => ',a;', 'reported' => false];
        // The run is read into a group of the tail: an assertion there
        // takes no character, above ASCII or not (11 -> 19).
        yield 'separator then an assertion, in a group' => ['pattern' => '/(?:,[^,;]*(?:;\B))*$/', 'unit' => ',a;', 'reported' => false];
        yield 'separator behind a comment' => ['pattern' => '/(?:,a*(?#c);)*$/', 'unit' => ',a;', 'reported' => false];
        // i turned on inside the loop folds the separator into the run
        // (68 -> 3 194 on a{n}!).
        yield 'i turned on inside the loop' => ['pattern' => '/(?:a+(?i)A)+$/', 'unit' => 'a', 'reported' => true];
        yield 'i scoped to the separator' => ['pattern' => '/(?:a+(?i:A))+$/', 'unit' => 'a', 'reported' => true];
        // An i set where nothing follows it inside the loop folds nothing:
        // the next iteration is compiled without it. "(?i)" closing the
        // iteration (4 -> 7 -> 12 steps for n 8 -> 16 -> 32) and an empty
        // "(?i:)" after the run (9 -> 17 -> 33) stay linear.
        yield 'i turned on at the end of the loop' => ['pattern' => '/(?:a+A(?i))+$/', 'unit' => 'aaA', 'reported' => false];
        yield 'empty scoped i after the run' => ['pattern' => '/(?:Aa+(?i:))+$/', 'unit' => 'Aaa', 'reported' => false];
        // Under iu the Kelvin sign is a "k" (68 -> 3 194); without i it is
        // a separator (2 steps).
        yield 'Kelvin sign separator under iu' => ['pattern' => "/(?:k+\u{212A})+\$/iu", 'unit' => 'k', 'reported' => true];
        yield 'Kelvin sign separator without i' => ['pattern' => "/(?:k+\u{212A})+\$/u", 'unit' => 'k', 'reported' => false];
        // The separator check alone decides these: "b*" after the separator
        // keeps the iteration from being one fixed run. Without i, "A"
        // keeps "a+" apart (6 -> 10), and an i turned off does not fold it.
        // An i turned on inside the loop, in a quantified group (68 ->
        // 3 194) or in an alternation (122 -> 5 777), folds it into "a".
        yield 'separator of the other case before a run' => ['pattern' => '/(?:a+Ab*)+$/', 'unit' => 'aA', 'reported' => false];
        yield 'separator of the other case with i turned off' => ['pattern' => '/(?:a+A(?-i)b*)+$/', 'unit' => 'aA', 'reported' => false];
        yield 'separator under i in a quantified group' => ['pattern' => '/(?:a+(?i:A){1}b*)+$/', 'unit' => 'a', 'reported' => true];
        yield 'separator under i in an alternation' => ['pattern' => '/(?:a+(?:(?i)A|B)b*)+$/', 'unit' => 'a', 'reported' => true];
        yield 'separator under a standalone i before a run' => ['pattern' => '/(?:a+(?i)Ab*)+$/', 'unit' => 'a', 'reported' => true];
        // An i set in an earlier alternative carries into
        // the later ones (69 -> 3 195); under (*NUL) the dot also takes
        // "\n", so it no longer separates "\n+" (68 -> 3 194).
        yield 'separator under an i set in an earlier alternative' => ['pattern' => '/x(?i)|(?:a+A)+$/', 'unit' => 'a', 'reported' => true];
        yield 'dot separator under (*NUL)' => ['pattern' => '/(*NUL)(?:\n+.)+x/', 'unit' => "\n", 'reported' => true];
        // An empty scoped group "(?-i:)" changes no flag
        // after it, unlike the standalone "(?-i)": i stays on and folds "A"
        // into the run (68 -> 3 194; 2 -> 2 with the standalone form).
        yield 'separator of the same letter under i, after an empty scoped (?-i:)' => ['pattern' => '/(?-i:)(?:a+A)+$/i', 'unit' => 'a', 'reported' => true];
    }

    /**
     * A separator keeps the iterations of a dot loop apart
     * only when the dot can never take it. Without s the dot stops at "\n"
     * alone; every byte above ASCII it takes, with or without u, so a
     * separator holding one ("é", "[é]", "ſ") is no separator for it. Each
     * row goes exponential on the separator repeated, then "!" (n 6 -> 12):
     * 34 -> 610 under u, 54 -> 986 without u, 610 -> 196 418 for "[é]"
     * under s, 192 -> 12 288 for ".*[é]" before "\z", 54 -> 986 for "ſ".
     * A newline verb from after a casing setting still reaches the dot
     * (34 -> 610). The "\n" control without s stays linear (9 -> 15).
     *
     * @return iterable<string, array{pattern: string, unit: string, reported: bool}>
     */
    public static function provideSeparatorsTheDotMayTake(): iterable
    {
        yield 'é after a dot run under u' => ['pattern' => '/(?:.+é)+$/u', 'unit' => 'é', 'reported' => true];
        yield 'é after a dot run without u' => ['pattern' => '/(?:.+é)+$/', 'unit' => 'é', 'reported' => true];
        yield 'class of é after a dot run under s' => ['pattern' => '/(?:.+[é])+$/s', 'unit' => 'é', 'reported' => true];
        yield 'class of é after a dot star under su, before \z' => ['pattern' => '/(?:.*[é])+\z/su', 'unit' => 'é', 'reported' => true];
        yield 'long s after a dot run without u' => ['pattern' => '/(?:.+ſ)+x/', 'unit' => 'ſ', 'reported' => true];
        yield 'newline verb after (*CASELESS_RESTRICT)' => ['pattern' => '/(*CASELESS_RESTRICT)(*CR)(?:.+\n)+$/', 'unit' => "\n", 'reported' => true];
        // Escapes as well: "\x80" after a negated class run (191 -> 12 287),
        // "\x{e9}" after a dot run under u (34 -> 610).
        yield 'escaped byte 0x80 after a negated class run' => ['pattern' => '/(?:[^,]+\x80)+$/', 'unit' => "\x80", 'reported' => true];
        yield 'escaped U+00E9 after a dot run under u' => ['pattern' => '/(?:.+\x{e9})+$/u', 'unit' => 'é', 'reported' => true];
        yield 'ASCII newline after a dot star, no s' => ['pattern' => '/(?:.*\n)+x/', 'unit' => "\n", 'reported' => false];
    }

    #[Test]
    #[DataProvider('provideSeparatorsTheDotMayTake')]
    public function test_a_separator_the_dot_may_take_does_not_keep_the_iterations_apart(string $pattern, string $unit, bool $reported): void
    {
        // A row only a newer engine can run — "(*CASELESS_RESTRICT)" is
        // PCRE2 10.43 — has no oracle steps where that engine refuses it.
        if (false === @preg_match($pattern, '')) {
            $this->markTestSkipped(sprintf('%s does not run on PCRE2 %s.', $pattern, \PCRE_VERSION));
        }

        $short = self::steps($pattern, self::repeatUnit($unit, 6).'!');
        $long = self::steps($pattern, self::repeatUnit($unit, 12).'!');
        $this->assertSame($reported, $long / $short > 3, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($reported, [] !== array_intersect([self::NESTED, self::DOT_STAR], self::lint($pattern)), $pattern);
    }

    /**
     * The first and the last byte above ASCII count as such: under i a set
     * holding one is no separator, the decided rule for every byte above
     * ASCII, whose case partner depends on the locale's tables (Latin-1
     * folds "\xE9" with "\xC9", ISO-8859-9 "\xDD" with "i"). With PCRE's own
     * tables these two shapes stay linear; the rows pin the rule's bounds,
     * 0x80 and 0xFF included. Without i the same bytes do separate. The
     * locale is left alone: once LC_CTYPE has been set, PHP builds PCRE
     * character tables from it for the rest of the process.
     *
     * @return iterable<string, array{pattern: string, reported: bool}>
     */
    public static function provideHighRangeBounds(): iterable
    {
        yield 'byte 0x80 under i' => ['pattern' => "/(?:a+\x80b*)+\$/i", 'reported' => true];
        yield 'byte 0xFF under i' => ['pattern' => "/(?:a+\xFFb*)+\$/i", 'reported' => true];
        yield 'byte 0x80 without i' => ['pattern' => "/(?:a+\x80b*)+\$/", 'reported' => false];
    }

    #[Test]
    #[DataProvider('provideHighRangeBounds')]
    public function test_a_byte_at_either_end_of_the_high_range_under_i_is_no_separator(string $pattern, bool $reported): void
    {
        $this->assertLessThan(10, self::steps($pattern, str_repeat('a', 16).'!'));

        $this->assertSame($reported, \in_array(self::NESTED, self::lint($pattern), true));
    }

    /**
     * An anchor is read past as well: it matches no character. Here the
     * report is a conservative one, "$" inside the iteration never holds
     * before another "a" and the engine stays at 3 steps.
     */
    #[Test]
    public function test_second_run_behind_an_anchor_is_reported_conservatively(): void
    {
        $this->assertLessThan(10, self::steps('/(?:,a*$a*)*$/', str_repeat(',a', 12).'!'));

        $this->assertContains(self::NESTED, self::lint('/(?:,a*$a*)*$/'));
    }

    /**
     * Without /u a byte above ASCII folds with the tables of the locale PHP
     * runs under: none in the C locale, "\xE9" and "\xC9" under a Latin-1
     * LC_CTYPE, where "(?:\xE9+\xC9)+$" goes exponential (68 -> 3 194 ->
     * 150 050 steps for n 8 -> 16 -> 24). A set holding such a byte under i
     * is therefore no separator. The Latin-1 half of the oracle runs on the
     * first such locale the system has. Once LC_CTYPE has been set, PHP
     * builds PCRE character tables from it for the rest of the process,
     * even after the locale is restored, so the test runs on its own.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_a_byte_above_ascii_under_i_is_no_separator(): void
    {
        $pattern = '/(?:\xE9+\xC9)+$/i';
        $previous = (string) setlocale(\LC_CTYPE, '0');

        try {
            setlocale(\LC_CTYPE, 'C');
            $this->assertSame(0, preg_match('/\xC9/i', "\xE9"));

            foreach (['fr_FR.ISO8859-1', 'fr_FR.ISO-8859-1', 'fr_FR.iso88591', 'de_DE.ISO8859-1', 'de_DE.iso88591', 'en_US.ISO8859-1', 'en_US.iso88591'] as $locale) {
                if (false === setlocale(\LC_CTYPE, $locale) || 1 !== self::matchUnderLocale('/\xC9/i', "\xE9")) {
                    continue;
                }

                $short = self::steps($pattern, str_repeat("\xE9", 8).'!');
                $long = self::steps($pattern, str_repeat("\xE9", 16).'!');
                $this->assertGreaterThan(3, $long / $short, \sprintf('Under %s: %d -> %d steps.', $locale, $short, $long));

                break;
            }
        } finally {
            setlocale(\LC_CTYPE, $previous);
        }

        $this->assertContains(self::NESTED, self::lint($pattern));
    }

    #[Test]
    #[DataProvider('provideSeparators')]
    public function test_nested_loop_separator_must_keep_the_iterations_apart(string $pattern, string $unit, bool $reported): void
    {
        $short = self::steps($pattern, self::repeatUnit($unit, 8).'!');
        $long = self::steps($pattern, self::repeatUnit($unit, 16).'!');
        $this->assertSame($reported, $long / $short > 3, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($reported, \in_array(self::NESTED, self::lint($pattern), true), $pattern);
    }

    /**
     * A loop that ends the pattern is left alone when it may stop after one
     * iteration: the first way through is the match ("(a+){1,}" takes 6
     * steps on a{n}b whatever n).
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideTrailingLoopsFromOneIteration(): iterable
    {
        yield 'minimum one' => ['pattern' => '/(a+){1,}/'];
        yield 'minimum zero' => ['pattern' => '/(a+){0,}/'];
    }

    #[Test]
    #[DataProvider('provideTrailingLoopsFromOneIteration')]
    public function test_trailing_loop_from_one_iteration_is_not_reported(string $pattern): void
    {
        $this->assertLessThan(10, self::steps($pattern, str_repeat('a', 32).'b'));

        $this->assertNotContains(self::NESTED, self::lint($pattern));
    }

    /**
     * With a minimum above one, a run too short to reach it is split every
     * way before the loop gives up: the steps double with each unit of the
     * minimum, (a+){m,} on a{m-1}b takes 4, 49, 769, 12 289, 196 609 steps
     * for m = 2, 6, 10, 14, 18, and (a+){20,} 786 433 on a 20-byte subject.
     */
    #[Test]
    public function test_trailing_loop_with_a_large_minimum_is_reported(): void
    {
        $this->assertGreaterThan(500_000, self::steps('/(a+){20,}/', str_repeat('a', 19).'b'));

        $this->assertContains(self::NESTED, self::lint('/(a+){20,}/'));
    }

    /**
     * Deliberate: the rule cuts at a minimum of one rather than weigh the
     * minimum, so "(a+){2,}" stays reported although the engine runs it in
     * 4 steps per start. The engine fact the cut rests on is the growth
     * with the minimum, pinned here; the report on {2,} itself is a
     * conservative one.
     */
    #[Test]
    public function test_trailing_loop_with_a_minimum_of_two_stays_reported(): void
    {
        $this->assertLessThan(10, self::steps('/(a+){2,}/', 'ab'));
        $this->assertGreaterThanOrEqual(8 * self::steps('/(a+){10,}/', str_repeat('a', 9).'b'), self::steps('/(a+){14,}/', str_repeat('a', 13).'b'));

        $this->assertContains(self::NESTED, self::lint('/(a+){2,}/'));
    }

    /**
     * Without s the dot stops at "\n" and "(?:.*\n)+" splits its text one way
     * only; an inline (?s) that reaches the loop makes the dot cross it and
     * the loop exponential.
     *
     * @return iterable<string, array{pattern: string, reported: bool}>
     */
    public static function provideInlineDotAllAroundALoop(): iterable
    {
        // The scoped (?s:...) ends before the loop.
        yield 'scoped s before the loop' => ['pattern' => '/(?s:a)(?:.*\n)+x/', 'reported' => false];
        // Set then cancelled before the loop.
        yield 's cancelled before the loop' => ['pattern' => '/(?s)a(?-s)(?:.*\n)+x/', 'reported' => false];
        // Set mid-pattern, before the loop.
        yield 's set mid-pattern before the loop' => ['pattern' => '/a(?s)(?:.*\n)+x/', 'reported' => true];
        // An s set in an earlier alternative carries into
        // the later ones (513 -> 131 073 on "\n"{n}).
        yield 's set in an earlier alternative' => ['pattern' => '/x(?s)|(?:.*\n)+x/', 'reported' => true];
        // A newline verb other than (*LF) makes "\n" an ordinary character
        // the dot takes (512 -> 131 072; 68 -> 3 194 for ".+"). (*LF), no
        // verb, (*ANYCRLF) and (*ANY) keep "\n" a newline (10 -> 18).
        yield 'newline verb (*CR)' => ['pattern' => '/(*CR)(?:.*\n)+x/', 'reported' => true];
        yield 'newline verb (*CRLF)' => ['pattern' => '/(*CRLF)(?:.*\n)+x/', 'reported' => true];
        yield 'newline verb (*NUL)' => ['pattern' => '/(*NUL)(?:.*\n)+x/', 'reported' => true];
        yield 'newline verb (*CR), dot plus' => ['pattern' => '/(*CR)(?:.+\n)+x/', 'reported' => true];
        yield 'newline verb (*LF)' => ['pattern' => '/(*LF)(?:.*\n)+x/', 'reported' => false];
        yield 'newline verb (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)(?:.*\n)+x/', 'reported' => false];
        yield 'newline verb (*ANY)' => ['pattern' => '/(*ANY)(?:.*\n)+x/', 'reported' => false];
    }

    #[Test]
    #[DataProvider('provideInlineDotAllAroundALoop')]
    public function test_inline_dotall_reaching_a_nested_dot_star_loop(string $pattern, bool $reported): void
    {
        $short = self::steps($pattern, 'a'.str_repeat("\n", 8));
        $long = self::steps($pattern, 'a'.str_repeat("\n", 16));
        $this->assertSame($reported, $long / $short > 3, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($reported, \in_array(self::DOT_STAR, self::lint($pattern), true), $pattern);
    }

    /**
     * Every rule reads the dot under the s flag in force where it stands:
     * the pattern's modifiers, an inline (?s), a scoped (?s:...), or an s
     * set in an earlier alternative. ".{1,9}" under s takes "\n", so
     * "(?:.{1,9}\n)+x" splits its text many ways (715 -> 159 823 steps on
     * "a\n"{8} -> "a\n"{16}, 10 -> 18 without s); ".a" under s takes "\na",
     * so "(?:.a|\na)+x" has two ways through each "\na" (1 023 -> 262 143
     * on "\na"{8} -> "\na"{16}, 19 -> 35 without s). An s that ends, or is
     * turned off, before the dot leaves it stopping at "\n"; an s set after
     * the dot does not reach it on the next iteration either.
     *
     * @return iterable<string, array{pattern: string, unit: string, rule: string, reported: bool}>
     */
    public static function provideDotAllInForceAtTheDot(): iterable
    {
        yield 'nested: s flag' => ['pattern' => '/(?:.{1,9}\n)+x/s', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: inline s at the start' => ['pattern' => '/(?s)(?:.{1,9}\n)+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: s set in an earlier alternative' => ['pattern' => '/x(?s)|(?:.{1,9}\n)+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: scoped s around the loop' => ['pattern' => '/(?s:(?:.{1,9}\n)+x)/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: inline s inside the loop before the dot' => ['pattern' => '/(?:(?s).{1,9}\n)+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: scoped s on the inner loop atom' => ['pattern' => '/(?:(?s:.){1,9}\n)+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: scoped i on the inner loop atom' => ['pattern' => '/(?:(?i:a){1,9}A)+x/', 'unit' => 'aA', 'rule' => self::NESTED, 'reported' => true];
        // The scoped i one group further down still folds the atom (47 ->
        // 715 on "aA"{4} -> "aA"{8}); without it "A" separates (10 -> 18).
        yield 'nested: scoped i inside a group around the inner loop atom' => ['pattern' => '/(?:(?:(?i:a)){1,9}A)+x/', 'unit' => 'aA', 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: scoped i inside a capture around the inner loop atom' => ['pattern' => '/(?:((?i:a)){1,9}A)+x/', 'unit' => 'aA', 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: no i inside a group around the inner loop atom' => ['pattern' => '/(?:(?:a){1,9}A)+x/', 'unit' => 'aA', 'rule' => self::NESTED, 'reported' => false];
        // A standalone s inside a quantified separator (89 -> 4 181 on
        // "\n"{n}), inside a scoped s around an optional group with its own
        // i (143 -> 6 764), and a scoped s under u (943 -> 210 686): the dot
        // takes "\n". A separator alternation holding an i but no "a" keeps
        // the iterations apart (3 -> 3).
        yield 'nested: inline s inside a quantified separator' => ['pattern' => '/(?:\n+(?:(?s).){1})+x/', 'unit' => "\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: scoped s around a group with its own i' => ['pattern' => '/(?:\n+(?s:(?:(?i)b)?.))+x/', 'unit' => "\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: scoped s on the inner loop atom under u' => ['pattern' => '/(?:(?s:.){1,9}\n)+x/u', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: separator alternation with an i, no overlap' => ['pattern' => '/(?:a+(?:(?i)B|C))+$/', 'unit' => 'a', 'rule' => self::NESTED, 'reported' => false];
        yield 'overlap: scoped s in the alternative of the dot under u' => ['pattern' => '/(?:(?s:.)a|\na)+x/u', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => true];
        yield 'nested: no s' => ['pattern' => '/(?:.{1,9}\n)+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => false];
        yield 'nested: scoped s before the loop' => ['pattern' => '/(?s:a)(?:.{1,9}\n)+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => false];
        yield 'nested: s reset by (?^) before the loop' => ['pattern' => '/(?s)(?^)(?:.{1,9}\n)+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => false];
        yield 'nested: inline s at the end of the loop' => ['pattern' => '/(?:.{1,9}\n(?s))+x/', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => false];
        yield 'overlap: s flag' => ['pattern' => '/(?:.a|\na)+x/s', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => true];
        yield 'overlap: inline s at the start' => ['pattern' => '/(?s)(?:.a|\na)+x/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => true];
        yield 'overlap: s set in an earlier alternative' => ['pattern' => '/x(?s)|(?:.a|\na)+x/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => true];
        yield 'overlap: scoped s around the loop' => ['pattern' => '/(?s:(?:.a|\na)+x)/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => true];
        yield 'overlap: inline s in the alternative of the dot' => ['pattern' => '/(?:(?s).a|\na)+x/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => true];
        yield 'overlap: inline s carried into the alternative of the dot' => ['pattern' => '/(?:\na(?s)|.a)+x/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => true];
        yield 'overlap: no s' => ['pattern' => '/(?:.a|\na)+x/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => false];
        yield 'overlap: s set then turned off' => ['pattern' => '/(?s)(?-s)(?:.a|\na)+x/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => false];
        yield 'overlap: inline s in the alternative after the dot' => ['pattern' => '/(?:.a|(?s)\na)+x/', 'unit' => "\na", 'rule' => self::OVERLAP, 'reported' => false];
        // An empty scoped group "(?-s:)" turns s off inside
        // itself only; the loop after it still reads s (715 -> 159 823,
        // 767 -> 196 607 for ".+\n" before "$"), in the same alternative or
        // after an earlier one (716 -> 159 824).
        yield 'nested: empty scoped (?-s:) before the loop' => ['pattern' => '/(?-s:)(?:.{1,9}\n)+x/s', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: empty scoped (?-s:) before a dot plus loop' => ['pattern' => '/(?-s:)(?:.+\n)+$/s', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: empty scoped (?-s:) in an earlier alternative' => ['pattern' => '/x(?-s:)|(?:.{1,9}\n)+x/s', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => true];
        yield 'nested: standalone (?-s) before the loop' => ['pattern' => '/(?-s)(?:.{1,9}\n)+x/s', 'unit' => "a\n", 'rule' => self::NESTED, 'reported' => false];
    }

    /**
     * Above ASCII the character sets say nothing, so a byte both sides may
     * take joins them although their ASCII parts are apart. "[,\x80]" after
     * "[^,]+" holds "\x80", which the run takes too (13 -> 89 steps on
     * "\x80"{n}! for n 4 -> 8). After the run of an iteration that opens
     * with ",", "[;\x80]" between two runs gives each "\x80\x80" two ways
     * through (154 -> 2 554 on (",\x80\x80"){n}, for n 4 -> 8, then ","),
     * and "[;é]" under u likewise.
     *
     * @return iterable<string, array{pattern: string, unit: string, tail: string}>
     */
    public static function provideItemsSharingOnlyBytesAboveAscii(): iterable
    {
        yield 'separator class holding 0x80 after a negated class run' => ['pattern' => '/(?:[^,]+[,\x80])+$/', 'unit' => "\x80", 'tail' => '!'];
        yield 'class holding 0x80 between two negated class runs' => ['pattern' => '/(?:,[^,;]*[;\x80][^,;]*)*$/', 'unit' => ",\x80\x80", 'tail' => ','];
        yield 'class holding é between two negated class runs under u' => ['pattern' => '/(?:,[^,;]*[;é][^,;]*)*$/u', 'unit' => ',éé', 'tail' => ','];
    }

    #[Test]
    #[DataProvider('provideItemsSharingOnlyBytesAboveAscii')]
    public function test_items_sharing_only_bytes_above_ascii_are_not_apart(string $pattern, string $unit, string $tail): void
    {
        $short = self::steps($pattern, str_repeat($unit, 4).$tail);
        $long = self::steps($pattern, str_repeat($unit, 8).$tail);
        $this->assertGreaterThan(3, $long / $short, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertContains(self::NESTED, self::lint($pattern));
    }

    /**
     * LazyEnd reads the U flag in force at the quantifier.
     * An empty scoped "(?U:)" sets nothing after it ("/x(?U:)|a+/" takes
     * "aaa"), "(?^)" keeps U ("/(?U)(?^)a+/" takes "a"), and a standalone
     * "(?U)" in an earlier alternative carries into the next one ("a").
     *
     * @return iterable<string, array{pattern: string, subject: string, match: string, reported: bool}>
     */
    public static function provideLazyEndsUnderInlineFlags(): iterable
    {
        yield 'empty scoped (?U:) in an earlier alternative' => ['pattern' => '/x(?U:)|a+/', 'subject' => 'aaa', 'match' => 'aaa', 'reported' => false];
        yield 'standalone (?U) in an earlier alternative' => ['pattern' => '/x(?U)|a+/', 'subject' => 'aaa', 'match' => 'a', 'reported' => true];
        yield '(?U) then (?^)' => ['pattern' => '/(?U)(?^)a+/', 'subject' => 'aaa', 'match' => 'a', 'reported' => true];
        yield 'U modifier then (?^)' => ['pattern' => '/(?^)a+/U', 'subject' => 'aaa', 'match' => 'a', 'reported' => true];
    }

    #[Test]
    #[DataProvider('provideLazyEndsUnderInlineFlags')]
    public function test_lazy_end_reads_the_ungreedy_flag_in_force(string $pattern, string $subject, string $match, bool $reported): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches));
        $this->assertSame($match, $matches[0]);

        $this->assertSame($reported, \in_array(self::LAZY_END, self::lint($pattern), true), $pattern);
    }

    #[Test]
    #[DataProvider('provideDotAllInForceAtTheDot')]
    public function test_dotall_in_force_at_the_dot_decides_the_loop_report(string $pattern, string $unit, string $rule, bool $reported): void
    {
        $short = self::steps($pattern, str_repeat($unit, 8).'!');
        $long = self::steps($pattern, str_repeat($unit, 16).'!');
        $this->assertSame($reported, $long / $short > 3, \sprintf('Oracle disagrees with the row: %d -> %d steps.', $short, $long));

        $this->assertSame($reported, \in_array($rule, self::lint($pattern), true), $pattern);
    }

    /**
     * A trailing loop a relative call runs before the "z" is followed by
     * something there: 641 -> 163 841 steps on a{8} -> a{16}.
     */
    #[Test]
    public function test_trailing_loop_a_relative_call_reaches_first_is_reported(): void
    {
        $this->assertGreaterThan(100_000, self::steps('/(?+1)z((a+)+)/', str_repeat('a', 16)));

        $this->assertContains(self::NESTED, self::lint('/(?+1)z((a+)+)/'));
    }

    /**
     * A relative call counts the groups opened before it: (?+1) is the next
     * one, (?-1) the last one opened.
     *
     * @return iterable<string, array{pattern: string, subject: string, match: string, reported: bool}>
     */
    public static function provideLazyEndsAndRelativeCalls(): iterable
    {
        // The called instance takes "aaa" before the "x".
        yield 'forward call to the group' => ['pattern' => '/(?+1)x(a+?)/', 'subject' => 'aaaxa', 'match' => 'aaaxa', 'reported' => false];
        yield 'forward call, \g<+1> form' => ['pattern' => '/\g<+1>x(a+?)/', 'subject' => 'aaaxa', 'match' => 'aaaxa', 'reported' => false];
        yield "forward call, \\g'+1' form" => ['pattern' => "/\\g'+1'x(a+?)/", 'subject' => 'aaaxa', 'match' => 'aaaxa', 'reported' => false];
        yield 'forward call from another branch' => ['pattern' => '/(?:x(?+1)y|(a+?))/', 'subject' => 'xaay', 'match' => 'xaay', 'reported' => false];
        yield 'forward call over one group' => ['pattern' => '/(?+2)x(a)(b+?)/', 'subject' => 'bbbxab', 'match' => 'bbbxab', 'reported' => false];
        // The call reaches another group: "b+?" only runs at the end.
        yield 'backward call to an earlier group' => ['pattern' => '/(b)(?-1)x(a+?)/', 'subject' => 'bbxaaa', 'match' => 'bbxa', 'reported' => true];
        yield 'forward call to the group before' => ['pattern' => '/(?+1)x(a)(b+?)/', 'subject' => 'axabbb', 'match' => 'axab', 'reported' => true];
    }

    #[Test]
    #[DataProvider('provideLazyEndsAndRelativeCalls')]
    public function test_lazy_end_reads_relative_calls(string $pattern, string $subject, string $match, bool $reported): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $matches));
        $this->assertSame($match, $matches[0]);

        $this->assertSame($reported, \in_array(self::LAZY_END, self::lint($pattern), true), $pattern);
    }

    /**
     * A backreference in a group a relative call runs again matters when
     * the call reaches it, and is useless when the call reaches another
     * group. The oracle compares the pattern with the backreference made
     * to fail, over every subject of up to four letters a and b.
     *
     * @return iterable<string, array{pattern: string, withoutBackref: string, reported: bool}>
     */
    public static function provideBackrefsAndRelativeCalls(): iterable
    {
        yield '(?-1) runs the group holding \1' => ['pattern' => '/^(?:(a)|(\1))(?-1)$/', 'withoutBackref' => '/^(?:(a)|((?!)))(?-1)$/', 'reported' => false];
        yield '\g<-1> runs the group holding \1' => ['pattern' => '/^(?:(a)|(\1))\g<-1>$/', 'withoutBackref' => '/^(?:(a)|((?!)))\g<-1>$/', 'reported' => false];
        yield '(?-2) runs the group \1 reads' => ['pattern' => '/^(?:(a)|(\1))(?-2)$/', 'withoutBackref' => '/^(?:(a)|((?!)))(?-2)$/', 'reported' => true];
    }

    #[Test]
    #[DataProvider('provideBackrefsAndRelativeCalls')]
    public function test_useless_backref_reads_relative_calls(string $pattern, string $withoutBackref, bool $reported): void
    {
        $this->assertSame($reported, self::sameLanguage($pattern, $withoutBackref), 'Oracle disagrees with the row.');

        $this->assertSame($reported, \in_array(self::USELESS_BACKREF, self::lint($pattern), true), $pattern);
    }

    /**
     * Through a call: the result depends on the locale, which static
     * analysis cannot see.
     */
    private static function matchUnderLocale(string $pattern, string $subject): int|false
    {
        return preg_match($pattern, $subject);
    }

    /**
     * @return list<string>
     */
    private static function lint(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        return array_values(array_map(static fn ($issue): string => $issue->id, $linter->getIssues()));
    }

    /**
     * The unit repeated until the subject holds $length characters of it.
     */
    private static function repeatUnit(string $unit, int $length): string
    {
        $characters = mb_str_split($unit);

        return implode('', array_map(static fn (int $i): string => $characters[$i % \count($characters)], range(0, $length - 1)));
    }

    private static function sameLanguage(string $left, string $right): bool
    {
        $subjects = [''];
        for ($length = 1, $layer = ['']; $length <= 4; $length++) {
            $next = [];
            foreach ($layer as $prefix) {
                $next[] = $prefix.'a';
                $next[] = $prefix.'b';
            }
            $layer = $next;
            array_push($subjects, ...$layer);
        }

        foreach ($subjects as $subject) {
            if (preg_match($left, $subject) !== preg_match($right, $subject)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The smallest backtrack limit the match attempt runs under: the
     * engine's count of steps for one start, JIT off, start optimizations
     * off.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        $unoptimized = $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);

        try {
            $low = 1;
            $high = 1 << 22;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($unoptimized, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
    }
}
