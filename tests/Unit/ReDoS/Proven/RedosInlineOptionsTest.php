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

namespace PHPRegex\Tests\Unit\ReDoS\Proven;

use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The verdict reads every inline option as PCRE reads it: an option set
 * inside one alternative holds in the alternatives after it, up to the end
 * of the group around them; "r" (caseless restrict) and the ASCII options
 * a, aD, aS, aW, aP and aT hold per scope; "(?^)" clears r and keeps U and
 * the ASCII options; "(?-a)" clears every ASCII option.
 *
 * Each row carries its engine fact, measured in the same test: the number
 * of steps one match attempt needs (pcre.jit 0, "(*NO_START_OPT)", the
 * smallest pcre.backtrack_limit the call passes under) on 4 then 8 pump
 * units and the suffix. Exponential rows grow 16 times or more (64 -> 1 024,
 * 95 -> 1 535, 25 -> 385, 68 -> 3 194), linear ones less than twice (12 ->
 * 20, 17 -> 29). Engine: PHP 8.4.26, PCRE2 10.49.
 *
 * PHP before 8.4 refuses the /r modifier and PCRE2 before 10.43 refuses
 * "(?r)" and the ASCII options: a row such an engine cannot compile has no
 * verdict to check, and says so.
 */
final class RedosInlineOptionsTest extends TestCase
{
    private const KELVIN_SIGN = "\u{212A}";

    /**
     * U+0663 ARABIC-INDIC DIGIT THREE: "\d" under /u, not under (?aD).
     */
    private const ARABIC_THREE = "\u{0663}";

    private const NO_BREAK_SPACE = "\u{A0}";

    private const LONG_S = "\u{17F}";

    private const DOTLESS_I = "\u{131}";

    private const E_ACUTE = "\u{E9}";

    /**
     * The engine-step ratio, 8 units over 4, above which a row is exponential.
     */
    private const EXPONENTIAL_RATIO = 3;

    /**
     * An option set inside one alternative holds in the alternatives after
     * it: today each of these is "safe (proven)", while the engine blows up.
     */
    #[Test]
    #[DataProvider('provideCarriedOptionRows')]
    public function test_an_option_set_inside_an_alternative_holds_in_the_next_ones(string $pattern, string $unit, string $suffix, bool $exponential): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertEngineGrowth($pattern, $unit, $suffix, $exponential);
        $this->assertVerdict($pattern, $exponential);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, suffix: string, exponential: bool}>
     */
    public static function provideCarriedOptionRows(): iterable
    {
        // Engine: 95 -> 1 535 -> 24 575 steps on "a"{4, 8, 12}."!".
        yield 'i set at the end of an alternative' => ['pattern' => '/^(?:z(?i)|a|A)*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        // Engine: 95 -> 1 535 -> 24 575.
        yield 'i set at the start of an alternative' => ['pattern' => '/^(?:(?i)z|a|A)*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        // Engine: 65 -> 1 025 -> 16 385.
        yield 'i carried across the top-level alternation' => ['pattern' => '/x(?i)|^(?:a|A)*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        // Engine: 25 -> 385 -> 6 145; a limit of 1 000 000 is exhausted at 24.
        yield 's carried into the next alternative' => ['pattern' => '/x(?s)|(?:.*\n.*\n)+x/', 'unit' => "\n", 'suffix' => '', 'exponential' => true];
        // Engine: 95 -> 1 535 -> 24 575 on "k" and on the Kelvin sign.
        yield 'r turned off carried into the next alternatives' => ['pattern' => '/^(?:z(?-r)|k|\x{212A})*$/iur', 'unit' => self::KELVIN_SIGN, 'suffix' => '!', 'exponential' => true];
        // Engine: 65 -> 1 025 -> 16 385.
        yield 'r turned off carried across the top level' => ['pattern' => '/x(?-r)|^(?:k|\x{212A})*$/iur', 'unit' => 'k', 'suffix' => '!', 'exponential' => true];
        // Engine: 17 -> 29 -> 41: under r the k and the Kelvin sign part.
        yield 'r turned on carried into the next alternatives' => ['pattern' => '/^(?:z(?r)|k|\x{212A})*$/iu', 'unit' => 'k', 'suffix' => '!', 'exponential' => false];
        // Engine: 95 -> 1 535 -> 24 575: the caret clears r.
        yield 'caret reset carried into the next alternatives' => ['pattern' => '/^(?:z(?^i)|k|\x{212A})*$/ur', 'unit' => 'k', 'suffix' => '!', 'exponential' => true];
        // Engine: 17 -> 29 -> 41: \d reads ASCII digits only from there on.
        yield 'aD carried into the next alternatives' => ['pattern' => '/^(?:z(?aD)|\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'suffix' => '!', 'exponential' => false];
        // Engine: 13 -> 21 -> 29.
        yield 'aD carried across the top level' => ['pattern' => '/x(?aD)|^(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'suffix' => '!', 'exponential' => false];
        // Engine: 17 -> 29 -> 41.
        yield 'i turned off carried into the next alternatives' => ['pattern' => '/^(?:z(?-i)|a|A)*$/i', 'unit' => 'a', 'suffix' => '!', 'exponential' => false];
        // Engine: 95 -> 1 535 -> 24 575: under x, "a " is "a".
        yield 'x carried into the next alternatives' => ['pattern' => '/^(?:z(?x)|a|a )*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        // The same carry through other constructs. Engine: 95 -> 1 535,
        // 188 -> 3 068 with the captures.
        yield 'i carried across a branch reset' => ['pattern' => '/^(?|z(?i)|a|A)*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        yield 'i carried across a branch reset with captures' => ['pattern' => '/^(?|(z)(?i)|(a)|(A))*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        yield 'i carried after a comment' => ['pattern' => '/^(?:(?#c)z(?i)|a|A)*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        yield 'i carried before a quoted run' => ['pattern' => '/^(?:z(?i)\Q.\E|a|A)*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        yield 'i carried under x' => ['pattern' => '/^(?:z(?i)|a|A)*$/x', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        yield 'i carried into classes' => ['pattern' => '/^(?:z(?i)|[a]|[A])*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        yield 'i carried into hex escapes' => ['pattern' => '/^(?:z(?i)|\x41|\x61)*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        yield 'i carried into escaped class ranges' => ['pattern' => '/^(?:z(?i)|[\x41]|[\x61-\x61])*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
        // Kept as guards. Engine: 95 -> 1 535.
        yield 'i carried into posix classes' => ['pattern' => '/^(?:z(?i)|[[:lower:]]|[[:upper:]])*$/', 'unit' => 'a', 'suffix' => '!', 'exponential' => true];
    }

    /**
     * An atomic alternation commits to its first match, a possessive loop
     * to its run: carried or not, the engine is linear. The verdict may stay
     * "no risk found (heuristic)": once "(?i)" is carried, the committed
     * alternation of one-character branches "z|a|A" is not read as a union
     * of sets yet. That is sound, only less precise; reading it as a union
     * (proven linear) is listed for later, and a proven linear verdict is
     * accepted here as soon as it lands. Anything flagged, or proven
     * anything but linear, is wrong.
     */
    #[Test]
    #[DataProvider('provideCommittedCarriedOptionRows')]
    public function test_an_option_carried_into_a_committed_alternation_finds_no_risk(string $pattern, string $unit, string $suffix): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertEngineGrowth($pattern, $unit, $suffix, false);

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = \sprintf('%s is "%s", the engine says linear', $pattern, $analysis->headline());

        $this->assertSame(RedosSeverity::Safe, $analysis->severity, $message);
        if (RedosProof::Proven === $analysis->proof) {
            $this->assertProvenSafe($analysis, $message);

            return;
        }

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $message);
        $this->assertSame('no risk found (heuristic)', $analysis->headline(), $message);
        $this->assertFalse($analysis->isProvenSafe(), $message);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, suffix: string}>
     */
    public static function provideCommittedCarriedOptionRows(): iterable
    {
        // Engine: 18 -> 30.
        yield 'i carried inside an atomic alternation' => ['pattern' => '/^(?>z(?i)|a|A)*$/', 'unit' => 'a', 'suffix' => '!'];
        // Engine: 13 -> 21.
        yield 'i carried into a possessive loop' => ['pattern' => '/^(?:z(?i)|a|A)*+$/', 'unit' => 'a', 'suffix' => '!'];
    }

    /**
     * The end of a group, a lookaround included, restores the options set
     * inside it: these verdicts are right today and stay right.
     */
    #[Test]
    #[DataProvider('provideRestoredOptionRows')]
    public function test_a_group_end_restores_the_options_set_inside_it(string $pattern, string $subjectPrefix, string $unit, string $suffix, bool $exponential): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertEngineGrowth($pattern, $unit, $suffix, $exponential, $subjectPrefix);
        $this->assertVerdict($pattern, $exponential);
    }

    /**
     * @return iterable<string, array{pattern: string, subjectPrefix: string, unit: string, suffix: string, exponential: bool}>
     */
    public static function provideRestoredOptionRows(): iterable
    {
        // Engine: preg_match('/(?:x(?i)|y)A/', 'a') is 0; 13 -> 21 -> 29 steps.
        yield 'i restored at the end of a non-capturing group' => ['pattern' => '/^(?:x(?i)|y)(?:a|A)*$/', 'subjectPrefix' => 'y', 'unit' => 'a', 'suffix' => '!', 'exponential' => false];
        // Engine: 27 -> 47 -> 67.
        yield 'i restored at the end of a lookahead inside the loop' => ['pattern' => '/^(?:(?=x(?i))|a|A)*$/', 'subjectPrefix' => '', 'unit' => 'a', 'suffix' => '!', 'exponential' => false];
        // Engine: preg_match('/^(?=x(?i)|.)A/', 'a') is 0; 4 steps whatever n.
        yield 'i restored at the end of a lookahead before the loop' => ['pattern' => '/^(?=x(?i)|y)(?:a|A)*$/', 'subjectPrefix' => 'y', 'unit' => 'a', 'suffix' => '!', 'exponential' => false];
        // Engine: 65 -> 1 025 -> 16 385: the group end takes aD back off.
        yield 'aD restored at the end of a group' => ['pattern' => '/^(?:x(?aD)|)(?:\d|٣)*$/u', 'subjectPrefix' => '', 'unit' => self::ARABIC_THREE, 'suffix' => '!', 'exponential' => true];
        // Engine: preg_match('/(?=(?aD))\d/u', "\u{663}") is 1; 65 -> 1 025.
        yield 'aD restored at the end of a lookahead' => ['pattern' => '/^(?=(?aD))(?:\d|٣)*$/u', 'subjectPrefix' => '', 'unit' => self::ARABIC_THREE, 'suffix' => '!', 'exponential' => true];
        // Engine: 22 -> 38: the atomic group's end takes i back off.
        yield 'i restored at the end of an atomic group' => ['pattern' => '/^(?:(?>z(?i))|a|A)*$/', 'subjectPrefix' => '', 'unit' => 'a', 'suffix' => '!', 'exponential' => false];
        // Engine: 64 -> 1 024 -> 16 384.
        yield 'r restored at the end of a scoped group' => ['pattern' => '/^(?r:z)?(?:k|\x{212A})*$/iu', 'subjectPrefix' => '', 'unit' => 'k', 'suffix' => '!', 'exponential' => true];
    }

    /**
     * Engine facts, PCRE2 10.49: "/(?i)k/ur" on the Kelvin sign is 0,
     * "/(?^i)k/ur" 1, "/(?i-r)k/ur" 1, "/(?i-r:k)/ur" 1, "/(?ir)k/u" 0,
     * "/x(?-r)|(?i)k/ur" 1. The first three rows are "safe (proven)"
     * today, the last three "Exponential backtracking (proven)".
     */
    #[Test]
    #[DataProvider('provideCaselessRestrictRows')]
    public function test_caseless_restrict_follows_inline_options(string $pattern, string $unit, string $suffix, bool $exponential): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertEngineGrowth($pattern, $unit, $suffix, $exponential);
        $this->assertVerdict($pattern, $exponential);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, suffix: string, exponential: bool}>
     */
    public static function provideCaselessRestrictRows(): iterable
    {
        // Engine: 64 -> 1 024 -> 16 384 on "k" and on the Kelvin sign.
        yield 'caret reset clears a global r' => ['pattern' => '/^(?^i)(?:k|\x{212A})*$/ur', 'unit' => self::KELVIN_SIGN, 'suffix' => '!', 'exponential' => true];
        // Engine: 64 -> 1 024 -> 16 384.
        yield 'minus r clears a global r' => ['pattern' => '/^(?-r)(?:k|\x{212A})*$/iur', 'unit' => 'k', 'suffix' => '!', 'exponential' => true];
        // Engine: 68 -> 3 194 -> 150 050 on (k, Kelvin sign) then "x".
        yield 'scoped i-r clears a global r' => ['pattern' => '/(?i-r:(?:k+\x{212A})+)$/ur', 'unit' => 'k'.self::KELVIN_SIGN, 'suffix' => 'x', 'exponential' => true];
        // Engine: 12 -> 20 -> 28.
        yield 'inline r on a caseless pattern' => ['pattern' => '/^(?r)(?:k|\x{212A})*$/iu', 'unit' => 'k', 'suffix' => '!', 'exponential' => false];
        // Engine: 12 -> 20 -> 28: r set in the first alternative reaches the second.
        yield 'inline r inside the first alternative' => ['pattern' => '/^(?:(?r)k|\x{212A})*$/iu', 'unit' => self::KELVIN_SIGN, 'suffix' => '!', 'exponential' => false];
        // Engine: 6 -> 10 -> 14.
        yield 'inline ir on a unicode pattern' => ['pattern' => '/(?ir)(?:k+\x{212A})+$/u', 'unit' => 'k'.self::KELVIN_SIGN, 'suffix' => 'x', 'exponential' => false];
        // The long s folds to "s" under /iu ("/(?i)s/u" on U+017F is 1) and
        // not under r ("/(?ir)s/u" is 0). Engine: 12 -> 20, and 64 -> 1 024.
        yield 'inline r on the long s' => ['pattern' => '/^(?r)(?:s|\x{17F})*$/iu', 'unit' => self::LONG_S, 'suffix' => '!', 'exponential' => false];
        yield 'minus r on the long s' => ['pattern' => '/^(?-r)(?:s|\x{17F})*$/iur', 'unit' => self::LONG_S, 'suffix' => '!', 'exponential' => true];
        // The Turkish dotless i folds with nothing, r or not ("/(?i)i/u" on
        // U+0131 is 0): 12 -> 20 either way, right today and kept.
        yield 'inline r on the dotless i' => ['pattern' => '/^(?r)(?:i|\x{131})*$/iu', 'unit' => self::DOTLESS_I, 'suffix' => '!', 'exponential' => false];
    }

    /**
     * Engine facts, PCRE2 10.49, /u: (?aD) and (?a) make "\d" refuse
     * U+0663 and leave "\s", "\w", POSIX classes alone; (?aS) makes "\s"
     * refuse U+00A0; (?aW) makes "\w" refuse U+00E9 and leaves
     * "[[:alpha:]]" and "[[:word:]]" alone; (?aP) makes every POSIX class
     * ASCII, "[[:digit:]]" included; (?aT) makes "[[:digit:]]" and
     * "[[:xdigit:]]" ASCII and leaves "[[:alpha:]]" alone; "(?aDS)" does not
     * compile, "(?aDaS)" does. A prover blind to these options calls every
     * row below "Exponential backtracking (proven)", the linear ones too.
     */
    #[Test]
    #[DataProvider('provideAsciiOptionRows')]
    public function test_ascii_options_are_read_per_scope(string $pattern, string $unit, bool $exponential): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertEngineGrowth($pattern, $unit, '!', $exponential);
        $this->assertVerdict($pattern, $exponential);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, exponential: bool}>
     */
    public static function provideAsciiOptionRows(): iterable
    {
        // Linear on the engine: 12 -> 20 -> 28 steps.
        yield 'aD on digits' => ['pattern' => '/^(?aD)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => false];
        yield 'a on digits' => ['pattern' => '/^(?a)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => false];
        yield 'scoped aD on digits' => ['pattern' => '/^(?aD:(?:\d|٣)*)$/u', 'unit' => self::ARABIC_THREE, 'exponential' => false];
        yield 'aS on spaces' => ['pattern' => '/^(?aS)(?:\s|\x{A0})*$/u', 'unit' => self::NO_BREAK_SPACE, 'exponential' => false];
        yield 'a on spaces' => ['pattern' => '/^(?a)(?:\s|\x{A0})*$/u', 'unit' => self::NO_BREAK_SPACE, 'exponential' => false];
        yield 'aW on word characters' => ['pattern' => '/^(?aW)(?:\w|é)*$/u', 'unit' => self::E_ACUTE, 'exponential' => false];
        yield 'a on word characters' => ['pattern' => '/^(?a)(?:\w|é)*$/u', 'unit' => self::E_ACUTE, 'exponential' => false];
        yield 'aP on a posix letter class' => ['pattern' => '/^(?aP)(?:[[:alpha:]]|é)*$/u', 'unit' => self::E_ACUTE, 'exponential' => false];
        yield 'aP on the posix digit class' => ['pattern' => '/^(?aP)(?:[[:digit:]]|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => false];
        yield 'aT on the posix digit class' => ['pattern' => '/^(?aT)(?:[[:digit:]]|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => false];
        yield 'aD and aS set together' => ['pattern' => '/^(?aDaS)(?:\s|\x{A0})*$/u', 'unit' => self::NO_BREAK_SPACE, 'exponential' => false];
        // Exponential on the engine: 64 -> 1 024 -> 16 384 steps. Each
        // option leaves the other classes as they were.
        yield 'aS leaves digits alone' => ['pattern' => '/^(?aS)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
        yield 'aD leaves word characters alone' => ['pattern' => '/^(?aD)(?:\w|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
        yield 'aD leaves the posix digit class alone' => ['pattern' => '/^(?aD)(?:[[:digit:]]|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
        yield 'aW leaves the posix letter class alone' => ['pattern' => '/^(?aW)(?:[[:alpha:]]|é)*$/u', 'unit' => self::E_ACUTE, 'exponential' => true];
        yield 'aT leaves the posix letter class alone' => ['pattern' => '/^(?aT)(?:[[:alpha:]]|é)*$/u', 'unit' => self::E_ACUTE, 'exponential' => true];
        yield 'aP leaves digits alone' => ['pattern' => '/^(?aP)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
        // Engine: preg_match('/^(?aD)(?-a)\d$/u', "\u{663}") is 1.
        yield 'minus a clears aD' => ['pattern' => '/^(?aD)(?-a)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
        // Without /u, "\d" is ASCII already: the option changes nothing.
        yield 'aD without unicode' => ['pattern' => '/^(?aD)(?:\d|3)*$/', 'unit' => '3', 'exponential' => true];
    }

    /**
     * A word boundary reads "\w" as the scope it stands in reads it: under
     * /u, "/(?aW)\b/u" on U+00E9 is 0 while "/\b/u" is 1. Two boundaries
     * under different ASCII scopes are outside the model, so the verdict is
     * not proven either way ("Potential backtracking (heuristic)" today).
     * The engine is linear on this row (2 steps on "x", "a" x 4, 8, 16 and
     * "!": the plain "\b" after "x" refuses an "a"), and the same pattern
     * with both boundaries in one scope, "/\bx\b(?:a|a)*$/u", is "safe
     * (proven)": the row guards that a boundary is not read as a plain one
     * whatever its scope, not that this verdict is wrong.
     */
    #[Test]
    public function test_word_boundaries_under_different_ascii_scopes_are_out_of_model(): void
    {
        $pattern = '/(?aW:\b)x\b(?:a|a)*$/u';
        if (!self::compilesHere($pattern)) {
            return;
        }

        $scoped = preg_match('/(?aW)\b/u', self::E_ACUTE);
        $plain = preg_match('/\b/u', self::E_ACUTE);
        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = \sprintf('%s is "%s"', $pattern, $analysis->headline());

        $this->assertSame(0, $scoped, 'Oracle: (?aW) no longer changes the word boundary.');
        $this->assertSame(1, $plain, 'Oracle: a plain word boundary no longer sees U+00E9 as a word character.');
        $this->assertNotSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertFalse($analysis->isProvenSafe(), $message);
    }

    /**
     * Engine facts, PCRE2 10.49: "(?^)" clears r ("/(?r)(?^i)k/u" on the
     * Kelvin sign is 1), keeps U ("/^(?U)(?^)a+/" on "aaa" matches "a"),
     * and keeps every ASCII option: "/^(?aD)(?^)\d$/u", "/^(?aD)(?^:\d)$/u",
     * "/^(?a)(?^)\s$/u" and "/^(?aW)(?^)\w$/u" all refuse their non-ASCII
     * character (0). One could expect the caret to clear the ASCII
     * options too: the engine says it does not.
     */
    #[Test]
    #[DataProvider('provideCaretResetRows')]
    public function test_caret_reset_clears_r_and_keeps_u_and_the_ascii_options(string $pattern, string $unit, bool $exponential): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertEngineGrowth($pattern, $unit, '!', $exponential);
        $this->assertVerdict($pattern, $exponential);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, exponential: bool}>
     */
    public static function provideCaretResetRows(): iterable
    {
        // Engine: 64 -> 1 024 -> 16 384.
        yield 'caret clears an inline r' => ['pattern' => '/^(?r)(?^i)(?:k|\x{212A})*$/u', 'unit' => 'k', 'exponential' => true];
        // Engine: 12 -> 20 -> 28: aD survives the caret.
        yield 'caret keeps aD' => ['pattern' => '/^(?aD)(?^)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => false];
        // Engine: 12 -> 20 -> 28.
        yield 'caret with i keeps aW' => ['pattern' => '/^(?aW)(?^i)(?:\w|é)*$/u', 'unit' => self::E_ACUTE, 'exponential' => false];
        // Engine: 12 -> 20 -> 28.
        yield 'scoped caret keeps a' => ['pattern' => '/^(?a)(?^:(?:\s|\x{A0})*)$/u', 'unit' => self::NO_BREAK_SPACE, 'exponential' => false];
    }

    /**
     * The caret keeps U: the engine matches "a" with "/^(?U)(?^)a+/" on
     * "aaa", as with "/^a+/U". No verdict class turns on U alone on a
     * failing attempt, so the row asks that "(?U)(?^)" read as "(?U)"
     * does, on a loop whose verdict U changes, and not as "(?U)(?-U)".
     */
    #[Test]
    public function test_caret_reset_keeps_ungreedy(): void
    {
        $this->assertSame(1, preg_match('/^(?U)(?^)a+/', 'aaa', $matches));
        $this->assertSame(['a'], $matches);
        $this->assertSame(1, preg_match('/^(?U)(?-U)a+/', 'aaa', $matches));
        $this->assertSame(['aaa'], $matches);

        $analyzer = new RedosAnalyzer();
        $loop = '(?:x*?.*?(?:a+)+)*';
        $ungreedy = $analyzer->analyze('/(?U)'.$loop.'/');
        $greedy = $analyzer->analyze('/(?U)(?-U)'.$loop.'/');
        $this->assertNotSame($greedy->headline(), $ungreedy->headline(), 'The row no longer turns on U.');

        $caret = $analyzer->analyze('/(?U)(?^)'.$loop.'/');
        $this->assertSame($ungreedy->headline(), $caret->headline());
        $this->assertSame($ungreedy->proof, $caret->proof);
    }

    /**
     * The options in force reach every atom the loop reads, after "\A" and
     * "\G" as after "^", in classes as in literals: i, s and x come off at
     * "(?^)", "a" with "-aD" keeps aS and aW, and aT with i folds the Kelvin
     * sign into a class.
     */
    #[Test]
    #[DataProvider('provideOptionsReachingEveryAtomRows')]
    public function test_the_options_in_force_reach_every_atom_of_the_loop(string $pattern, string $unit, bool $exponential): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertEngineGrowth($pattern, $unit, '!', $exponential);
        $this->assertVerdict($pattern, $exponential);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, exponential: bool}>
     */
    public static function provideOptionsReachingEveryAtomRows(): iterable
    {
        // Engine: 95 -> 1 535 with a carried i, 17 -> 29 with i turned off.
        yield 'start of subject anchor, i carried' => ['pattern' => '/\A(?:z(?i)|a|A)*$/', 'unit' => 'a', 'exponential' => true];
        yield 'continuation anchor, i carried' => ['pattern' => '/\G(?:z(?i)|a|A)*$/', 'unit' => 'a', 'exponential' => true];
        yield 'start of subject anchor, i turned off' => ['pattern' => '/\A(?:z(?-i)|a|A)*$/i', 'unit' => 'a', 'exponential' => false];
        yield 'continuation anchor, i turned off' => ['pattern' => '/\G(?:z(?-i)|a|A)*$/i', 'unit' => 'a', 'exponential' => false];
        // Engine: 12 -> 20: without i the two classes share nothing, read
        // from their members or from the engine. Under (?i) the POSIX pair
        // is 64 -> 1 024.
        yield 'classes without i' => ['pattern' => '/^(?:[a]|[A])*$/', 'unit' => 'a', 'exponential' => false];
        yield 'posix classes without i' => ['pattern' => '/^(?:[[:lower:]]|[[:upper:]])*$/', 'unit' => 'a', 'exponential' => false];
        // Engine: 64 -> 1 024: "/(?iaT)[k]/u" matches the Kelvin sign.
        yield 'aT with i folds the Kelvin sign into a class' => ['pattern' => '/^(?iaT)(?:[[:digit:]k]|[\x{212A}])*$/u', 'unit' => self::KELVIN_SIGN, 'exponential' => true];
        // Engine: 12 -> 20; 14 -> 22 on "\n" where "/^(?s)(?:.|\n)*x/" is
        // 96 -> 1 536; 12 -> 20 where "/^(?m)(?:\n$|\n)*x/" is 48 -> 768;
        // 12 -> 20.
        yield 'caret clears i' => ['pattern' => '/^(?i)(?^)(?:a|A)*$/', 'unit' => 'a', 'exponential' => false];
        yield 'caret clears s' => ['pattern' => '/^(?s)(?^)(?:.|\n)*x/', 'unit' => "\n", 'exponential' => false];
        yield 'caret clears m' => ['pattern' => '/^(?m)(?^)(?:\n$|\n)*x/', 'unit' => "\n", 'exponential' => false];
        yield 'caret clears x' => ['pattern' => '/^(?x)(?^)(?:a|a )*$/', 'unit' => 'a', 'exponential' => false];
        // Engine: 64 -> 1 024: the caret sets no option, ASCII ones included.
        yield 'caret sets no ascii option' => ['pattern' => '/^(?^)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
        // Engine: 12 -> 20 on U+00A0, aS still holding; 64 -> 1 024 on
        // U+0663, aD gone.
        yield 'a minus aD in one setting keeps aS' => ['pattern' => '/^(?a-aD)(?:\s|\x{A0})*$/u', 'unit' => self::NO_BREAK_SPACE, 'exponential' => false];
        yield 'minus aD after a keeps aS' => ['pattern' => '/^(?a)(?-aD)(?:\s|\x{A0})*$/u', 'unit' => self::NO_BREAK_SPACE, 'exponential' => false];
        yield 'a minus aD in one setting clears aD' => ['pattern' => '/^(?a-aD)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
        yield 'minus aD after a clears aD' => ['pattern' => '/^(?a)(?-aD)(?:\d|٣)*$/u', 'unit' => self::ARABIC_THREE, 'exponential' => true];
    }

    /**
     * Each distinct class scan under /u costs 12 000 of the 250 000 steps:
     * scopes that repeat the same options scan their atom once, and 25
     * copies of "(?aD:\d+)x" stay within the budget, proven linear. The
     * engine fails such a subject at once (2 steps).
     */
    #[Test]
    #[DataProvider('provideRepeatedScopeRows')]
    public function test_repeated_scopes_with_the_same_options_stay_within_the_budget(string $pattern): void
    {
        if (!self::compilesHere($pattern)) {
            return;
        }

        $this->assertLessThan(10, self::steps($pattern, '1111x1x1x1!'));
        $this->assertProvenSafe((new RedosAnalyzer())->analyze($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRepeatedScopeRows(): iterable
    {
        yield 'scoped aD repeated' => ['pattern' => '/^'.str_repeat('(?aD:\d+)x', 25).'$/u'];
        yield 'aD set again and again' => ['pattern' => '/^'.str_repeat('(?aD)\d+x', 25).'$/u'];
        yield 'scoped ir repeated' => ['pattern' => '/^'.str_repeat('(?ir:k+)x', 25).'$/u'];
    }

    private function assertVerdict(string $pattern, bool $exponential): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = \sprintf('%s is "%s", the engine says %s', $pattern, $analysis->headline(), $exponential ? 'exponential' : 'linear');

        if ($exponential) {
            $this->assertFalse($analysis->isProvenSafe(), $message);
            $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
            $this->assertSame(RedosComplexity::Exponential, $analysis->complexity, $message);

            return;
        }

        $this->assertProvenSafe($analysis, $message);
    }

    private function assertProvenSafe(RedosAnalysis $analysis, string $message): void
    {
        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $message);
        $this->assertTrue($analysis->isProvenSafe(), $message);
    }

    private function assertEngineGrowth(string $pattern, string $unit, string $suffix, bool $exponential, string $subjectPrefix = ''): void
    {
        $short = self::steps($pattern, $subjectPrefix.str_repeat($unit, 4).$suffix);
        $long = self::steps($pattern, $subjectPrefix.str_repeat($unit, 8).$suffix);

        $this->assertSame(
            $exponential,
            $long / $short > self::EXPONENTIAL_RATIO,
            \sprintf('Oracle disagrees with the row %s: %d -> %d steps.', $pattern, $short, $long),
        );
    }

    /**
     * Whether this PHP and PCRE2 compile the row. A refusal is accepted
     * only where it is expected: /r before PHP 8.4, "(?r)" and the ASCII
     * options before PCRE2 10.43.
     */
    private static function compilesHere(string $pattern): bool
    {
        if (false !== @preg_match($pattern, '')) {
            return true;
        }

        $modifiers = substr($pattern, (int) strrpos($pattern, '/') + 1);
        $expected = (\PHP_VERSION_ID < 80400 && str_contains($modifiers, 'r'))
            || version_compare(explode(' ', \PCRE_VERSION)[0], '10.43', '<');
        self::assertTrue($expected, $pattern.' does not compile on PHP '.\PHP_VERSION.' / PCRE2 '.\PCRE_VERSION);

        return false;
    }

    /**
     * The smallest backtrack limit one match attempt runs under: JIT off,
     * start optimizations off.
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
