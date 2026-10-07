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

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\ResourceLimitException;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Internal\Backtrack\BacktrackProver;
use PHPRegex\Redos\Internal\SearchCostProver;
use PHPRegex\Redos\Internal\SearchCostReplayer;
use PHPRegex\Redos\RedosAnalysis;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosOptions;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSearchCost;
use PHPRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The search cost: one attempt is linear, but an unanchored search retries it
 * at every start position inside a run, so the search is quadratic in PCRE2's
 * interpreter. The verdict is a witness, a run word repeated n times then a
 * breaking suffix; RedosSearchCostEngineTest replays every row on the engine.
 *
 * Oracle: PHP 8.4.26, PCRE2 10.49; anchoring facts read with pcre2test 10.49
 * ("-i"); per-attempt step counts in RedosSearchCostEngineTest.
 */
final class RedosSearchCostTest extends TestCase
{
    #[Test]
    #[DataProvider('provideQuadraticSearches')]
    public function test_search_cost_witness_is_reported_for_an_unanchored_linear_attempt(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $cost = $analysis->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(2, $cost->degree, $pattern);
        $this->assertNotSame('', $cost->run, $pattern);
        // Without confirmation nothing ran on the engine.
        $this->assertNull($cost->replayed, $pattern);
        $this->assertArrayNotHasKey('jit_linear', $cost->toArray(), $pattern);
    }

    /**
     * One attempt stays linear and proven: isProvenSafe(), headline() and the
     * severity speak of one attempt and do not move.
     */
    #[Test]
    #[DataProvider('provideQuadraticSearches')]
    public function test_search_cost_leaves_the_per_attempt_verdict_unchanged(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertInstanceOf(RedosSearchCost::class, $analysis->searchCost, $pattern);
        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertSame(RedosSeverity::Safe, $analysis->severity, $pattern);
        $this->assertTrue($analysis->isProvenSafe(), $pattern);
        $this->assertSame('safe (proven)', $analysis->headline(), $pattern);
        $this->assertNull($analysis->witness, $pattern);
    }

    /**
     * Each row: a run of n characters every attempt inside it consumes, then
     * a suffix it fails on. RedosSearchCostEngineTest measures the steps.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideQuadraticSearches(): iterable
    {
        yield 'trailing whitespace' => ['pattern' => '/\s+$/'];
        yield 'class run then a digit' => ['pattern' => '/[a-z]+\d/'];
        yield 'literal run then a literal' => ['pattern' => '/a+b/'];
        yield 'lazy loop' => ['pattern' => '/a+?b/'];
        yield 'optional item before the loop' => ['pattern' => '/x?a+b/'];
        yield 'two-character run word' => ['pattern' => '/(?:ab)+c/'];
        yield 'alternation inside the loop' => ['pattern' => '/(?:a|b)+c/'];
        yield 'multibyte run under u' => ['pattern' => '/[é]+x/u'];
        yield 'literal prefix inside the run' => ['pattern' => '/a\w+;/'];
        yield 'capture around the loop' => ['pattern' => '/(\w+)\s*=/'];
        yield 'counted loop' => ['pattern' => '/a{2,}b/'];
        yield 'D modifier' => ['pattern' => '/\s+$/D'];
        yield 'm modifier on the dollar' => ['pattern' => '/\s+$/m'];
        yield 'x modifier' => ['pattern' => '/ a+ b /x'];
        yield 'U modifier' => ['pattern' => '/a+b/U'];
        yield 'i modifier' => ['pattern' => '/a+b/i'];
        // A dot-star after the start is not anchored: it jumps to the end
        // under s, then backtracks over the run looking for the class.
        yield 'dot-star after a literal under s' => ['pattern' => '/a.*[xy]/s'];
        // A caret under m holds at every line start, and a newline run makes
        // every position one.
        yield 'caret under m, newline run' => ['pattern' => '/^\s+x/m'];
        // A leading dot-star without s is anchored at line starts only
        // (pcre2test: "First code unit at start or follows newline"): a
        // newline run makes every position one.
        yield 'leading dot-star without s, newline run' => ['pattern' => '/.*\n+x/'];
        // The trim regex: the first attempt matches the bare run, a prefix
        // takes it away (743 / 2970 ms on "x"." "x10k/20k."x", pcre.jit=0).
        yield 'trim regex' => ['pattern' => '/^\s+|\s+$/'];
        yield 'trim regex under u' => ['pattern' => '/^[\s\x{200c}]+|[\s\x{200c}]+$/u'];
        yield 'trim regex with \A and \z' => ['pattern' => '/\A\s+|\s+\z/'];
        // A star loop before a literal of two characters or more: the
        // breaker holds only PCRE2's last required code unit.
        yield 'star loop before a fat arrow' => ['pattern' => '/\s*=>/'];
        yield 'star loop before a word' => ['pattern' => '/\w*Exception/'];
        yield 'star loop before a unit' => ['pattern' => '/\d*px/'];
        yield 'star loop before an extension' => ['pattern' => '/\S*\.png/'];
        // A word boundary before a loop over word and non-word characters:
        // the run word mixes both, so the boundary holds once per word.
        yield 'e-mail' => ['pattern' => '/\b[\w.%+-]+@[\w.-]+\.[a-z]{2,}\b/i'];
        yield 'word boundary before a non-space run' => ['pattern' => '/\b\S+\.png/'];
        // A possessive any-character repeat jumps to the end, but the run is
        // read by another loop.
        yield 'possessive dot-star after the run' => ['pattern' => '/(\w+)\s*=\s*(.*+)/s'];
        yield 'possessive dot-star after a literal' => ['pattern' => '/\s+x.*+/s'];
        yield 'possessive dot-star in another alternative' => ['pattern' => '/\s+$|x.*+y/s'];
        // Under u, \s and \S are Unicode properties: PCRE2 does not anchor
        // the leading [\s\S]* (pcre2test 10.49, utf and ucp: no "anchored").
        yield 'leading [\s\S]* under u' => ['pattern' => '/[\s\S]*\d$/u'];
        // The dollar holds before a final newline, which \n then reads.
        yield 'dollar then a newline after digits' => ['pattern' => '/\d+$\n/'];
        yield 'dollar then a newline after whitespace' => ['pattern' => '/\s+$\n/'];
        // Under m the dollar only looks at the next character: a run of
        // newlines ends the subject (12.8 / 199.8 ms on 1,500 / 6,000).
        yield 'dollar under m after a newline run' => ['pattern' => '/\s*.+$/m'];
        // Under i the run "A" holds the required "a" for PCRE2, and an "a"
        // after the breaker would complete a match (15.0 / 239.9 ms).
        yield 'caseless run holding the required code unit' => ['pattern' => '/-?(a+)\b/ix'];
        // The same with the last ASCII letter: "Z" holds the required "z".
        yield 'caseless run holding the last letter' => ['pattern' => '/-?(z+)\b/ix'];
        // An anchored alternative leaves the others unanchored.
        yield 'trailing whitespace or an anchored alternative' => ['pattern' => '/\s+$|^x/'];
        // A dot loop with a lower bound is no leading dot-star: a newline
        // after the run breaks every attempt.
        yield 'dot loop with a lower bound' => ['pattern' => '/.+;/'];
        yield 'leading [\s\S]* under u before a whitespace loop' => ['pattern' => '/[\s\S]*\s+/u'];
        yield 'leading [\p{Any}]* in one alternative only' => ['pattern' => '/(?:[\p{Any}]*|y)\d$/u'];
        // PCRE2 anchors no dot-star behind an empty group (154.0 / 615.8 /
        // 2462.1 ms on "!"x5k/10k/20k, pcre.jit=0), nor one behind an item.
        yield 'empty group before a dot-star under s' => ['pattern' => '/(?:).*[xy]/s'];
        yield 'option group around the first item' => ['pattern' => '/(?i:a).*[xy]/'];
        yield 'version numbers' => ['pattern' => '/(\.\d+)+$/'];
        yield 'line breaks at the end' => ['pattern' => '~(<br\s*/?>)+$~u'];
        yield 'words on lines under m' => ['pattern' => '/(?:^\w+\n?)+!/m'];
        yield 'caret under m, whitespace before the end' => ['pattern' => '/^\s*x$/m'];
        yield 'caret under m before a loop of word and non-word characters' => ['pattern' => '/^(?:\w+\W)+\.png/m'];
        // Friedl's unrolled string literal: the run word is a quote then an
        // escaped character, the quote escaped in the next word.
        yield 'string literal, unrolled loop' => ['pattern' => '/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/'];
        yield 'lazy dot-star before the dollar' => ['pattern' => '/a.*?$/'];
        yield 'alternation of words before a word' => ['pattern' => '/(?:foo|bar)*baz/'];
        // The run word avoids the characters that complete another
        // alternative: "bc", not "ac"; "ad", not "ac" nor "bd".
        yield 'run word kept from another alternative' => ['pattern' => '/(?:[ab]c)+d|ac/'];
        yield 'two-step run word kept from two alternatives' => ['pattern' => '/(?:[ab][cd])+e|ac|bd/'];
        // A bounded possessive repeat of any character does not move to the
        // end of the subject: the run goes through it (78.7 / 316.0 / 1268.3
        // ms on "aaaa"x5k/10k/20k."!!!!;", pcre.jit=0).
        yield 'possessive any character a fixed number of times in the loop' => ['pattern' => '/(?:a.{3}+)+;/s'];
        // PCRE2 anchors a leading ".*" at line starts, not a class that
        // reads the same characters: 156.3 / 625.2 / 2502.4 ms on
        // "!"x5k/10k/20k against 0.1 / 0.2 / 0.3 ms for /.*\d$/ (pcre.jit=0).
        yield 'leading [^\n]*, not the dot' => ['pattern' => '/[^\n]*\d$/'];
        // Nor a bounded leading repeat of any character: 493.6 / 1987.3 /
        // 7982.3 ms on " "x5k/10k/20k."!".
        yield 'bounded leading repeat of any character under s' => ['pattern' => '/.{0,3}\s+$/s'];
        // trim()'s characters, the NUL among them: the prefix is none of them
        // (118.0 / 464.6 / 1875.4 ms on "!"." "x5k/10k/20k."!").
        yield 'trim regex with the characters of trim()' => ['pattern' => '/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/'];
        // John Gruber's liberal URL regex: the run word mixes a word
        // character and a hyphen under \b.
        yield 'liberal URL regex' => ['pattern' => '/\b((?:[a-z][\w\-]+:(?:\/{1,3}|[a-z0-9%])|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}\/)(?:[^\s()<>]|\((?:[^\s()<>]|(?:\([^\s()<>]+\)))*\))+(?:\((?:[^\s()<>]|(?:\([^\s()<>]+\)))*\)|[^\s`!()\[\]{};:\'".,<>?\x{00ab}\x{00bb}\x{201c}\x{201d}\x{2018}\x{2019}]))/iu'];
        yield 'word boundary before a non-space run and a word' => ['pattern' => '/\b\S+Exception/'];
        // Every alternative ends with the same character, the last code unit
        // PCRE2 requires (pcre2test 10.49): the breaker carries it. Without
        // it the search fails before any attempt (0.0 ms on "a"x20k); with
        // it, 6.2 / 23.9 / 97.9 ms on "a"x5k/10k/20k."!b" (pcre.jit=0).
        yield 'alternatives ending with the same literal' => ['pattern' => '/a+b|cb/'];
        yield 'alternatives ending with the same sign' => ['pattern' => '/\s+=|x=/'];
        yield 'alternatives of classes ending with the same literal' => ['pattern' => '/[a-z]+;|\d+;/'];
        yield 'group of alternatives ending with the same literal' => ['pattern' => '/a+(?:b|cb)/'];
        // Without u, a caseless "k" is a required code unit: 115.8 / 463.6 /
        // 1860.4 ms on "A"x5k/10k/20k."!k". Under u it matches the Kelvin
        // sign too and PCRE2 requires nothing: 154.1 / 621.0 / 2465.4 ms on
        // "A"x5k/10k/20k alone.
        yield 'caseless alternatives ending with a k' => ['pattern' => '/[a-z]+k|\d+k/i'];
        yield 'caseless alternatives ending with a k under u' => ['pattern' => '/[a-z]+k|\d+k/iu'];
        // A class of every character but the surrogates is no universe for
        // PCRE2, which reads its ranges over every code point: not anchored
        // (pcre2test 10.49, utf and ucp), 153.7 / 623.7 / 2457.8 ms on
        // "!"x5k/10k/20k (pcre.jit=0).
        yield 'leading class of every character but the surrogates under u' => ['pattern' => '/[\x00-\x{D7FF}\x{E000}-\x{10FFFF}]*\d$/u'];
        // Nor a class of a property and its negation (212.8 / 850.5 / 3402.6
        // ms on "!"x5k/10k/20k).
        yield 'leading class of a property and its negation under u' => ['pattern' => '/[\pL\PL]*\d$/u'];
    }

    /**
     * The attack is a valid witness: the search matches nowhere on it,
     * whatever the number of run words.
     */
    #[Test]
    #[DataProvider('provideQuadraticSearches')]
    public function test_search_cost_witness_is_matched_nowhere_by_the_search(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);

        foreach ([1, 2, 3, 100] as $words) {
            $this->assertSame(0, preg_match($pattern, $cost->build($words)), \sprintf('%s on %d run words', $pattern, $words));
        }
    }

    /**
     * The first attempt of the search matches the bare run: the witness
     * opens with a prefix it fails on, and the attempts inside the run read
     * to its end.
     */
    #[Test]
    #[DataProvider('provideSearchesNeedingAPrefix')]
    public function test_search_cost_witness_opens_with_a_prefix_when_the_bare_run_matches(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertNotSame('', $cost->prefix, $pattern);
        $this->assertSame(1, preg_match($pattern, str_repeat($cost->run, 100).$cost->breaker), 'control: the bare run matches');
        $this->assertSame(0, preg_match($pattern, $cost->build(100)), $pattern);
        $this->assertSame($cost->prefix.str_repeat($cost->run, 3).$cost->breaker, $cost->build(3));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSearchesNeedingAPrefix(): iterable
    {
        yield 'trim regex' => ['pattern' => '/^\s+|\s+$/'];
        yield 'trim regex under u' => ['pattern' => '/^[\s\x{200c}]+|[\s\x{200c}]+$/u'];
        yield 'trim regex with \A and \z' => ['pattern' => '/\A\s+|\s+\z/'];
        // The NUL is one of the trimmed characters: the prefix is not.
        yield 'trim regex with the characters of trim()' => ['pattern' => '/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/'];
    }

    /**
     * PCRE2 gives up on a subject without the last code unit every match
     * requires (pcre2test 10.49, "Last code unit"), and only on that one:
     * the breaker is that code unit, not the whole literal.
     */
    #[Test]
    #[DataProvider('provideLastRequiredCodeUnits')]
    public function test_search_cost_breaker_is_the_last_required_code_unit(string $pattern, string $breaker): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame($breaker, $cost->breaker, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, breaker: string}>
     */
    public static function provideLastRequiredCodeUnits(): iterable
    {
        yield 'fat arrow' => ['pattern' => '/\s*=>/', 'breaker' => '>'];
        yield 'word' => ['pattern' => '/\w*Exception/', 'breaker' => 'n'];
        yield 'unit' => ['pattern' => '/\d*px/', 'breaker' => 'x'];
        yield 'extension' => ['pattern' => '/\S*\.png/', 'breaker' => 'g'];
    }

    /**
     * A word boundary holds only where the word class changes: a run of one
     * class gives one long attempt, a run word of word and non-word
     * characters one per word.
     */
    #[Test]
    #[DataProvider('provideBoundaryRuns')]
    public function test_search_cost_run_word_mixes_classes_under_a_word_boundary(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertGreaterThanOrEqual(2, \strlen($cost->run), $pattern);
        $this->assertLessThanOrEqual(4, \strlen($cost->run), $pattern);
        $this->assertSame(1, preg_match('/\w/', $cost->run), $pattern.': the run holds a word character');
        $this->assertSame(1, preg_match('/\W/', $cost->run), $pattern.': the run holds a non-word character');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBoundaryRuns(): iterable
    {
        yield 'e-mail' => ['pattern' => '/\b[\w.%+-]+@[\w.-]+\.[a-z]{2,}\b/i'];
        yield 'word boundary before a non-space run' => ['pattern' => '/\b\S+\.png/'];
    }

    /**
     * Every match of /.++;/ needs a ";" (its last required code unit) after
     * a run the possessive loop never gives back, and /[a-z]+$x/m an "x"
     * after a "$" that, under m, holds only before a newline: neither can
     * match, yet PCRE2 still requires the code unit. A witness carries it,
     * or there is none. Without it PCRE2 fails the search before any attempt
     * (/[a-z]+$x/m: 0.0 ms on "a"x20k, 2436.2 ms on "a"x20k."x", pcre.jit=0).
     */
    #[Test]
    #[DataProvider('providePatternsThatCannotMatch')]
    public function test_search_cost_breaker_carries_the_required_code_unit_of_a_pattern_that_cannot_match(string $pattern, string $unit): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;

        $this->assertTrue(null === $cost || str_contains($cost->build(1), $unit), \sprintf('the witness of %s lacks the required "%s"', $pattern, $unit));
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string}>
     */
    public static function providePatternsThatCannotMatch(): iterable
    {
        yield 'possessive loop over the required character' => ['pattern' => '/.++;/', 'unit' => ';'];
        yield 'dollar under m before a letter of the run' => ['pattern' => '/[a-z]+$x/m', 'unit' => 'x'];
    }

    /**
     * PCRE2 requires the last code unit every alternative ends with, as it
     * does the last literal of one alternative (pcre2test 10.49, "Last code
     * unit"): the witness holds it, or the search fails before any attempt.
     */
    #[Test]
    #[DataProvider('provideCodeUnitsEveryAlternativeEndsWith')]
    public function test_search_cost_witness_holds_the_code_unit_every_alternative_ends_with(string $pattern, string $unit): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertStringContainsString($unit, $cost->build(1), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string}>
     */
    public static function provideCodeUnitsEveryAlternativeEndsWith(): iterable
    {
        yield 'same literal' => ['pattern' => '/a+b|cb/', 'unit' => 'b'];
        yield 'same sign' => ['pattern' => '/\s+=|x=/', 'unit' => '='];
        yield 'classes before the same literal' => ['pattern' => '/[a-z]+;|\d+;/', 'unit' => ';'];
        yield 'group of alternatives' => ['pattern' => '/a+(?:b|cb)/', 'unit' => 'b'];
        yield 'caseless k without u' => ['pattern' => '/[a-z]+k|\d+k/i', 'unit' => 'k'];
    }

    /**
     * The breakers come shortest first: one character after the run when
     * one character fails every attempt.
     */
    #[Test]
    #[DataProvider('provideOneCharacterBreakers')]
    public function test_search_cost_breaker_is_one_character_when_one_suffices(string $pattern): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(1, \strlen($cost->breaker), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideOneCharacterBreakers(): iterable
    {
        yield 'trailing whitespace' => ['pattern' => '/\s+$/'];
        yield 'literal run before the end' => ['pattern' => '/a+$/'];
    }

    /**
     * Under u, a class of every character mixing properties with other
     * items is undecided for PCRE2's anchoring: the search proof gives up on
     * it (a known gap: /[\s\Sd]*\d$/u costs 223.2 / 943.0 / 3410.4 ms on
     * "!"x5k/10k/20k, pcre.jit=0), and the attempt stays proven linear.
     */
    #[Test]
    public function test_search_cost_leaves_the_attempt_proven_when_the_start_is_undecided(): void
    {
        $analysis = (new RedosAnalyzer())->analyze('/[\s\Sd]*\d$/u');

        $this->assertSame(RedosProof::Proven, $analysis->proof);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity);
    }

    /**
     * A bounded repeat above the unrolling cutoff is read as unbounded; on
     * the engine each attempt reads at most its bound (/\s{1,100}$/: 10 / 20
     * ms on " "x10k/20k."x", /\s{1,300}$/: 15 / 30 / 60 ms on 10k/20k/40k,
     * pcre.jit=0, linear). No witness through it, in either mode.
     */
    #[Test]
    #[DataProvider('provideBoundedRepeats')]
    public function test_search_cost_is_not_reported_through_a_bounded_repeat_read_as_unbounded(string $pattern): void
    {
        $analyzer = new RedosAnalyzer();

        $theoretical = $analyzer->analyze($pattern);
        $this->assertTrue($theoretical->isProvenSafe(), $pattern);
        $this->assertNull(self::searchCostOf($theoretical), $pattern);
        $this->assertNull(self::searchCostOf($analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideBoundedRepeats(): iterable
    {
        yield 'just above the cutoff' => ['pattern' => '/\s{1,17}$/'];
        yield 'a hundred' => ['pattern' => '/\s{1,100}$/'];
        yield 'above the replay subject' => ['pattern' => '/\s{1,300}$/'];
    }

    /**
     * \G holds wherever an attempt is pinned, so a pinned replay would
     * count the \G alternative at every offset; the search tries it only at
     * its start. Not confirmed, not reported.
     */
    #[Test]
    public function test_search_cost_is_not_confirmed_by_a_pinned_replay_of_a_continuation_anchor(): void
    {
        $analyzer = new RedosAnalyzer();

        $this->assertNull(self::searchCostOf($analyzer->analyze('/\G\s+$|(?<=y)\s+z/')));
        $this->assertNull(self::searchCostOf($analyzer->analyze('/\G\s+$|(?<=y)\s+z/', RedosSeverity::Low, RedosMode::Confirmed)));
    }

    /**
     * A possessive or atomic loop never backtracks, yet still reads the run
     * at every start: the search is quadratic all the same. The step counter
     * does not see inside them (PcreAttemptSteps), so these rows rest on the
     * clock: 24.0 / 94.2 ms for /a++b/ on "a"x10k/20k."cb", 24.4 / 95.2 ms
     * for /(?>a+)+$/ on "a"x10k/20k."x", 16.1 / 62.9 / 252.9 ms for
     * /(?>.*)[xy]/ on "!"x5k/10k/20k (without s, the atomic dot-star stops at
     * each line end), pcre.jit=0 (measured, not asserted).
     */
    #[Test]
    #[DataProvider('provideNonBacktrackingLoops')]
    public function test_search_cost_is_reported_through_a_loop_that_never_backtracks(string $pattern, string $run): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertTrue($analysis->isProvenSafe(), $pattern);
        $cost = $analysis->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(2, $cost->degree, $pattern);
        $this->assertSame($run, $cost->run, $pattern);
        $this->assertSame(0, preg_match($pattern, $cost->build(100)), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, run: string}>
     */
    public static function provideNonBacktrackingLoops(): iterable
    {
        yield 'possessive loop' => ['pattern' => '/a++b/', 'run' => 'a'];
        yield 'atomic loop repeated' => ['pattern' => '/(?>a+)+$/', 'run' => 'a'];
        yield 'atomic dot-star without s' => ['pattern' => '/(?>.*)[xy]/', 'run' => '!'];
        // Under u PCRE2 reads these classes one character at a time, not as
        // its any character, which moves to the end in one step: 96.4 /
        // 385.8 / 1550.0 ms and 29.8 / 119.6 / 475.4 ms on "a"x5k/10k/20k."b".
        yield 'possessive [\s\S]* under u' => ['pattern' => '/a[\s\S]*+b/u', 'run' => 'a'];
        yield 'possessive class of every character but the surrogates under u' => ['pattern' => '/a[\x00-\x{D7FF}\x{E000}-\x{10FFFF}]*+b/u', 'run' => 'a'];
    }

    /**
     * The run word is read from the loop of the automaton, a word and not
     * only one character, a prefix that lies in the run included.
     */
    #[Test]
    #[DataProvider('provideRunWords')]
    public function test_search_cost_run_is_the_word_of_the_loop(string $pattern, string $run): void
    {
        $cost = (new RedosAnalyzer())->analyze($pattern)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame($run, $cost->run, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, run: string}>
     */
    public static function provideRunWords(): iterable
    {
        yield 'literal loop' => ['pattern' => '/a+b/', 'run' => 'a'];
        yield 'lazy loop' => ['pattern' => '/a+?b/', 'run' => 'a'];
        yield 'optional item before the loop' => ['pattern' => '/x?a+b/', 'run' => 'a'];
        yield 'counted loop' => ['pattern' => '/a{2,}b/', 'run' => 'a'];
        yield 'U modifier' => ['pattern' => '/a+b/U', 'run' => 'a'];
        yield 'two-character run word' => ['pattern' => '/(?:ab)+c/', 'run' => 'ab'];
        // Raw bytes: the two UTF-8 bytes of U+00E9.
        yield 'multibyte character under u' => ['pattern' => '/[é]+x/u', 'run' => 'é'];
        yield 'newline run under m' => ['pattern' => '/^\s+x/m', 'run' => "\n"];
        yield 'newline run after a leading dot-star' => ['pattern' => '/.*\n+x/', 'run' => "\n"];
    }

    /**
     * The attack as the per-attempt witness writes it: each part a PHP
     * double-quoted literal, the run "x n", an empty prefix or breaker left
     * out.
     */
    #[Test]
    public function test_search_cost_renders_the_attack_like_the_attempt_witness(): void
    {
        $this->assertSame('"x" . " " x n . "!"', (new RedosSearchCost(2, 'x', ' ', '!', false))->render());
        $this->assertSame('" " x n . "!"', (new RedosSearchCost(2, '', ' ', '!', false))->render());
        $this->assertSame('"\u{E9}" x n', (new RedosSearchCost(2, '', 'é', '', true))->render());
    }

    #[Test]
    public function test_search_cost_builds_the_run_repeated_then_the_breaker(): void
    {
        $cost = (new RedosAnalyzer())->analyze('/(?:ab)+c/')->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertSame('ababab'.$cost->breaker, $cost->build(3));
        $this->assertSame($cost->breaker, $cost->build(0));
        // A negative count is no run at all, never an error.
        $this->assertSame($cost->breaker, $cost->build(-1));
    }

    /**
     * Every attempt is tied to the search start: an unanchored search makes
     * one long attempt, not n. Proven linear per attempt, so the null is a
     * claim and not a fallback.
     */
    #[Test]
    #[DataProvider('provideAnchoredSearches')]
    public function test_search_cost_is_not_reported_when_every_attempt_is_tied_to_the_search_start(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertNull(self::searchCostOf($analysis), $pattern);
    }

    /**
     * pcre2test 10.49 reports "anchored" for the caret, \A, \G, A and the
     * leading dot-star under s rows, and "First code unit at start or follows
     * newline" for the caret under m and the leading dot-star without s.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAnchoredSearches(): iterable
    {
        yield 'caret without m' => ['pattern' => '/^a+b/'];
        yield '\A' => ['pattern' => '/\Aa+b/'];
        yield '\G' => ['pattern' => '/\Ga+b/'];
        yield 'A modifier' => ['pattern' => '/a+b/A'];
        yield 'leading dot-star under s' => ['pattern' => '/.*[xy]/s'];
        yield 'leading lazy dot-star under s' => ['pattern' => '/.*?[xy]/s'];
        yield 'leading possessive dot-star under s' => ['pattern' => '/.*+[xy]/s'];
        yield 'leading dot-star in a capture under s, no back reference' => ['pattern' => '/(.*)[xy]/s'];
        // The run cannot hold a newline: one line start inside it.
        yield 'caret under m, newline-free run' => ['pattern' => '/^[a-z]+\d/m'];
        yield 'leading dot-star without s, newline-free run' => ['pattern' => '/.*[xy]/'];
        yield 'caret then a run, alternatives both anchored' => ['pattern' => '/^a+b|\Ac+d/'];
        // Without u, a class of every character is PCRE2's any character:
        // the leading [\s\S]* is anchored like ".*" under s (pcre2test 10.49:
        // "anchored"; not under utf and ucp, see the quadratic rows).
        yield 'leading [\s\S]* without u' => ['pattern' => '/[\s\S]*\d$/'];
        // Each row below takes 0.1 / 0.2 / 0.3 ms or less on "!"x5k/10k/20k
        // with pcre.jit=0: one long attempt.
        yield 'dot-star or caret' => ['pattern' => '/.*=|^x/'];
        yield 'dot-star or caret, under s' => ['pattern' => '/.*=|^x/s'];
        yield 'leading dot-star in a group under s' => ['pattern' => '/(?:.*)\w+/s'];
        yield 'leading dot-star in a named group under s' => ['pattern' => '/(?<n>.*)\w+/s'];
        yield 'leading dot-star in a branch reset group under s' => ['pattern' => '/(?|.*)\w+/s'];
        yield 'leading dot-star in an option group setting s' => ['pattern' => '/(?s:.*)\w+/'];
        yield 'comment before a leading dot-star under s' => ['pattern' => '/(?#c).*[xy]/s'];
        yield 'leading \N* under m, newline-free run' => ['pattern' => '/\N*\s+/m'];
        yield 'leading [\p{Any}]* under u' => ['pattern' => '/[\p{Any}]*\d$/u'];
        // A class whose ranges cover every code point, the surrogates
        // included, is PCRE2's any character under u, whatever else it holds
        // (pcre2test 10.49, utf and ucp: "anchored"); so is a negated class
        // of nothing, and under i a class whose gaps the other cases fill.
        // Each row takes 0.1 / 0.1 / 0.2 ms on "!"x5k/10k/20k (pcre.jit=0).
        yield 'leading class of every code point under u' => ['pattern' => '/[\x00-\x{10FFFF}]*\d$/u'];
        yield 'leading class of every code point in braces under u' => ['pattern' => '/[\x{0}-\x{10FFFF}]*[xy]/u'];
        yield 'leading class of every code point and a property under u' => ['pattern' => '/[\x00-\x{10FFFF}\d]*\d$/u'];
        yield 'leading class of every horizontal space and the rest under u' => ['pattern' => '/[\h\H]*\d$/u'];
        yield 'leading class of every vertical space and the rest under u' => ['pattern' => '/[\v\V]*\d$/u'];
        yield 'leading class of every code point and a property, the range first, under u' => ['pattern' => '/[\x00-\x{10FFFF}\pL]*\d$/u'];
        // A POSIX class is not read: undecided, no witness.
        yield 'leading class of POSIX classes under u' => ['pattern' => '/[[:^ascii:][:ascii:]]*\d$/u'];
        yield 'leading negated class of no character under u' => ['pattern' => '/[^\P{Any}]*\d$/u'];
        yield 'leading caseless class whose gap the other case fills under u' => ['pattern' => '/[\x00-\x40\x42-\x{10FFFF}]*\d$/iu'];
    }

    /**
     * No breaking suffix makes every attempt inside the run fail after
     * consuming it: the loop accepts inside the run, the only character that
     * breaks the run is the one the pattern requires, or the run must be
     * entered by something else than itself.
     */
    #[Test]
    #[DataProvider('provideSearchesWithoutWitness')]
    public function test_search_cost_is_not_reported_without_a_breaking_suffix(string $pattern): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $analysis->complexity, $pattern);
        $this->assertNull(self::searchCostOf($analysis), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSearchesWithoutWitness(): iterable
    {
        // The attempt accepts after one character.
        yield 'loop alone' => ['pattern' => '/a+/'];
        yield 'class loop alone' => ['pattern' => '/[0-9]+/'];
        // Only " breaks [^"]+, and it completes the match; without a " the
        // required-code-unit check fails every attempt in one step.
        yield 'negated class closed by its own complement' => ['pattern' => '/[^"]+"/'];
        // Only the attempt at "=" enters the run.
        yield 'run entered by another character' => ['pattern' => '/=\s+$/'];
        // Only the attempt at the last "a" reaches the "b": 0.1 / 0.2 / 0.4
        // ms on "a"x5k/10k/20k."b" (pcre.jit=0).
        yield 'literal pair the run does not repeat' => ['pattern' => '/ab\w+/'];
        // A run holding a newline gives $ a match before it; a run without
        // one has a single line start: at most two long attempts.
        yield 'caret and dollar under m' => ['pattern' => '/^\s+$/m'];
        // PCRE2 moves a greedy any-character repeat under s to the end in one
        // step; atomic, nothing backtracks into it. Not anchored, yet each
        // attempt is constant: 0.4 ms / 0.7 ms on a×10k / a×20k with
        // pcre.jit=0 (measured, not asserted), against 62.8 / 251.2 ms for
        // the same pattern without s.
        yield 'atomic leading dot-star under s' => ['pattern' => '/(?>.*)[xy]/s'];
        // PCRE2 requires the "b" every alternative ends with, and every
        // subject holding one matches through the second alternative.
        yield 'alternative matching the required code unit alone' => ['pattern' => '/(.*)(?:ab)+|b/'];
        yield 'lazy dot-star alternative matching the required code unit alone' => ['pattern' => '/.*?(?:ab)+|b/s'];
        // A class of every code point is PCRE2's any character under u: the
        // possessive repeat moves to the end in one step (0.2 / 0.3 / 0.5 ms
        // on "a"x5k/10k/20k."b", pcre.jit=0).
        yield 'possessive class of every code point under u' => ['pattern' => '/a[\x00-\x{10FFFF}]*+b/u'];
    }

    /**
     * The search cost is computed only when one attempt is proven linear: a
     * worse per-attempt verdict already covers the search, and an unproven
     * one gives nothing to build on. Null means "no witness found", never "a
     * linear search".
     */
    #[Test]
    #[DataProvider('provideAttemptsNotProvenLinear')]
    public function test_search_cost_is_computed_only_on_an_attempt_proven_linear(string $pattern, RedosMode $mode, RedosProof $proof): void
    {
        $analysis = (new RedosAnalyzer())->analyze($pattern, null, $mode);

        $this->assertSame($proof, $analysis->proof, $pattern);
        $this->assertNull(self::searchCostOf($analysis), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, mode: RedosMode, proof: RedosProof}>
     */
    public static function provideAttemptsNotProvenLinear(): iterable
    {
        yield 'quadratic attempt' => ['pattern' => '/a*a*$/', 'mode' => RedosMode::Theoretical, 'proof' => RedosProof::Proven];
        yield 'exponential attempt' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Theoretical, 'proof' => RedosProof::Proven];
        yield 'back reference, heuristic' => ['pattern' => '/(a)\1+/', 'mode' => RedosMode::Theoretical, 'proof' => RedosProof::Heuristic];
        yield 'analysis off' => ['pattern' => '/\s+$/', 'mode' => RedosMode::Off, 'proof' => RedosProof::NotAnalyzed];
        // PCRE2 does not anchor it (pcre2test 10.49 lists no "anchored") and
        // each attempt is linear: the search is quadratic on the engine. A
        // start verb takes the attempt out of the model today, so no witness
        // is looked for: a known gap, not a proof of a linear search.
        yield 'dot-star under s with (*NO_DOTSTAR_ANCHOR)' => ['pattern' => '/(*NO_DOTSTAR_ANCHOR).*[xy]/s', 'mode' => RedosMode::Theoretical, 'proof' => RedosProof::Heuristic];
    }

    /**
     * A witness through a lookaround the model does not read is reported
     * only once the engine replay confirms it: never in the theoretical mode,
     * with replayed true in the confirmed one.
     */
    #[Test]
    #[DataProvider('provideLookaroundSearches')]
    public function test_lookaround_witness_is_reported_only_once_the_engine_replay_confirms_it(string $pattern): void
    {
        $analyzer = new RedosAnalyzer();

        $this->assertNull(self::searchCostOf($analyzer->analyze($pattern)), $pattern);

        $cost = $analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $cost, $pattern);
        $this->assertSame(2, $cost->degree, $pattern);
        $this->assertTrue($cost->replayed, $pattern);
    }

    /**
     * Interpreter: 990 / 3960 ms and 962 / 3844 ms at n = 10k / 20k
     * (pcre.jit=0); the step counts are in RedosSearchCostEngineTest.
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLookaroundSearches(): iterable
    {
        yield 'lookahead at the end' => ['pattern' => '/\s+(?=x$)/'];
        yield 'lookahead on a digit' => ['pattern' => '/[a-z]+(?=\d)/'];
    }

    #[Test]
    public function test_confirmed_mode_replays_the_search_cost_witness(): void
    {
        $cost = (new RedosAnalyzer())->analyze('/\s+$/', RedosSeverity::Low, RedosMode::Confirmed)->searchCost;

        $this->assertInstanceOf(RedosSearchCost::class, $cost);
        $this->assertTrue($cost->replayed);
        // The JIT is not measured, with or without it.
        $this->assertArrayNotHasKey('jit_linear', $cost->toArray());
    }

    #[Test]
    public function test_search_cost_json_is_degree_witness_and_replayed(): void
    {
        $json = self::encode((new RedosAnalyzer())->analyze('/a+b/'));

        $this->assertArrayHasKey('search_cost', $json);
        $cost = $json['search_cost'];
        $this->assertIsArray($cost);
        $this->assertSame(['degree', 'witness', 'replayed'], array_keys($cost));
        $this->assertSame(2, $cost['degree']);
        $this->assertIsArray($cost['witness']);
        $this->assertSame(['prefix', 'run', 'breaker'], array_keys($cost['witness']));
        $this->assertSame('', $cost['witness']['prefix']);
        $this->assertSame('a', $cost['witness']['run']);
        $this->assertIsString($cost['witness']['breaker']);
        $this->assertNull($cost['replayed']);
        $this->assertArrayNotHasKey('jit_linear', $cost);

        // Additive: the cache key of a verdict does not move before 2.0.0.
        $this->assertSame('1', RedosAnalyzer::ANALYSIS_VERSION);
        $this->assertSame('1', $json['analysis_version']);
    }

    /**
     * The witness parts are the inside of PHP double-quoted literals, like
     * the per-attempt witness: a multibyte character is escaped.
     */
    #[Test]
    public function test_search_cost_json_escapes_the_witness_like_the_attempt_witness(): void
    {
        $json = self::encode((new RedosAnalyzer())->analyze('/[é]+x/u'));

        $cost = $json['search_cost'] ?? null;
        $this->assertIsArray($cost);
        $this->assertIsArray($cost['witness']);
        $this->assertSame('\u{E9}', $cost['witness']['run']);
    }

    /**
     * The key is always there, null when no witness was found.
     */
    #[Test]
    #[DataProvider('provideAnalysesWithoutSearchCost')]
    public function test_search_cost_json_key_is_null_without_a_witness(string $pattern, RedosMode $mode): void
    {
        $json = self::encode((new RedosAnalyzer())->analyze($pattern, null, $mode));

        $this->assertArrayHasKey('search_cost', $json);
        $this->assertNull($json['search_cost']);
    }

    /**
     * @return iterable<string, array{pattern: string, mode: RedosMode}>
     */
    public static function provideAnalysesWithoutSearchCost(): iterable
    {
        yield 'anchored' => ['pattern' => '/^a+b/', 'mode' => RedosMode::Theoretical];
        yield 'exponential' => ['pattern' => '/(a+)+$/', 'mode' => RedosMode::Theoretical];
        yield 'heuristic' => ['pattern' => '/(a)\1+/', 'mode' => RedosMode::Theoretical];
        yield 'not analyzed' => ['pattern' => '/\s+$/', 'mode' => RedosMode::Off];
    }

    #[Test]
    public function test_search_cost_defaults_to_null_on_a_hand_built_analysis(): void
    {
        $analysis = new RedosAnalysis(RedosSeverity::Safe, 0, complexity: RedosComplexity::Linear, proof: RedosProof::Proven);

        $this->assertNull(self::searchCostOf($analysis));
        $this->assertNull($analysis->jsonSerialize()['search_cost']);
    }

    /**
     * The search proof spends what the per-attempt proof left of the budget
     * and never takes from it: the smallest maxSteps that proves one attempt
     * linear is the one measured before the search cost existed
     * (2026-10-07), and an exhausted budget gives no witness, never an error.
     */
    #[Test]
    #[DataProvider('provideBudgetThresholds')]
    public function test_search_cost_does_not_raise_the_budget_of_the_per_attempt_proof(string $pattern, int $firstProvenAt): void
    {
        $below = (new RedosAnalyzer(options: new RedosOptions(maxSteps: $firstProvenAt - 1)))->analyze($pattern);
        $this->assertSame(RedosProof::BudgetExceeded, $below->proof, $pattern);
        $this->assertNull(self::searchCostOf($below), $pattern);

        $at = (new RedosAnalyzer(options: new RedosOptions(maxSteps: $firstProvenAt)))->analyze($pattern);
        $this->assertSame(RedosProof::Proven, $at->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $at->complexity, $pattern);
        $this->assertTrue(null === $at->searchCost || 2 === $at->searchCost->degree, $pattern);

        $this->assertInstanceOf(RedosSearchCost::class, (new RedosAnalyzer())->analyze($pattern)->searchCost, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, firstProvenAt: int}>
     */
    public static function provideBudgetThresholds(): iterable
    {
        yield 'trailing whitespace' => ['pattern' => '/\s+$/', 'firstProvenAt' => 73];
        yield 'literal run' => ['pattern' => '/a+b/', 'firstProvenAt' => 40];
        yield 'two-character run word' => ['pattern' => '/(?:ab)+c/', 'firstProvenAt' => 56];
    }

    /**
     * The search proof pays its own steps out of the shared budget: one step
     * short of what it needs (measured 2026-10-07), one attempt stays proven
     * linear and no witness comes, never an error; with them, the witness.
     */
    #[Test]
    #[DataProvider('provideSearchBudgetThresholds')]
    public function test_search_cost_is_charged_to_the_shared_budget(string $pattern, int $firstFoundAt): void
    {
        $below = (new RedosAnalyzer(options: new RedosOptions(maxSteps: $firstFoundAt - 1)))->analyze($pattern);
        $this->assertSame(RedosProof::Proven, $below->proof, $pattern);
        $this->assertSame(RedosComplexity::Linear, $below->complexity, $pattern);
        $this->assertNull(self::searchCostOf($below), $pattern);

        $at = (new RedosAnalyzer(options: new RedosOptions(maxSteps: $firstFoundAt)))->analyze($pattern);
        $this->assertInstanceOf(RedosSearchCost::class, $at->searchCost, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, firstFoundAt: int}>
     */
    public static function provideSearchBudgetThresholds(): iterable
    {
        yield 'trailing whitespace' => ['pattern' => '/\s+$/', 'firstFoundAt' => 109];
        yield 'literal run' => ['pattern' => '/a+b/', 'firstFoundAt' => 79];
        yield 'two-character run word' => ['pattern' => '/(?:ab)+c/', 'firstFoundAt' => 114];
    }

    /**
     * A library exception inside the search proof says nothing of one
     * attempt: the search proof gives no witness and RedosAnalyzer keeps the
     * per-attempt verdict it proved, never "not analyzed". The replay is
     * where the proof meets the running engine; a replayer that throws a
     * library exception stands for any such failure there.
     */
    #[Test]
    public function test_search_cost_is_null_when_its_proof_throws_a_library_exception(): void
    {
        $this->assertInstanceOf(RedosSearchCost::class, self::findSearchCost('/\s+$/', new SearchCostReplayer()), 'control: the witness is found and replayed');

        $failing = new class extends SearchCostReplayer {
            public bool $called = false;

            public function replays(string $pattern, string $prefix, string $run, string $breaker): bool
            {
                $this->called = true;

                throw new ResourceLimitException('The replay ran out of its limits.', ErrorCode::PatternTooLong);
            }
        };

        $this->assertNull(self::findSearchCost('/\s+$/', $failing));
        $this->assertTrue($failing->called, 'The failure was met inside the search proof.');
    }

    /**
     * Anything else is a bug: it surfaces.
     */
    #[Test]
    public function test_search_cost_proof_lets_an_exception_from_outside_the_library_through(): void
    {
        $failing = new class extends SearchCostReplayer {
            public function replays(string $pattern, string $prefix, string $run, string $breaker): bool
            {
                throw new \LogicException('A bug in the replay.');
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A bug in the replay.');

        self::findSearchCost('/\s+$/', $failing);
    }

    /**
     * Confirmed mode replays the search cost only when its severity, medium,
     * reaches the threshold, like the per-attempt replay; below it the
     * witness stays as the model found it.
     */
    #[Test]
    public function test_confirmed_mode_replays_the_search_cost_only_from_its_threshold(): void
    {
        $analyzer = new RedosAnalyzer();

        $replayed = $analyzer->analyze('/\s+$/', RedosSeverity::Medium, RedosMode::Confirmed)->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $replayed);
        $this->assertTrue($replayed->replayed);

        $kept = $analyzer->analyze('/\s+$/', RedosSeverity::High, RedosMode::Confirmed)->searchCost;
        $this->assertInstanceOf(RedosSearchCost::class, $kept);
        $this->assertNull($kept->replayed);
        $this->assertArrayNotHasKey('jit_linear', $kept->toArray());
    }

    /**
     * What the search proof finds after a fresh per-attempt proof, replayed
     * by the replayer given.
     */
    private static function findSearchCost(string $pattern, SearchCostReplayer $replayer): ?RedosSearchCost
    {
        $ast = RegexParser::create()->parse($pattern);
        $options = new RedosOptions();
        $prover = new BacktrackProver($options->maxStates, $options->maxSteps, $options->boundedRepeatCutoff);
        self::assertSame(RedosComplexity::Linear, $prover->prove($ast)->complexity, $pattern);

        return (new SearchCostProver($replayer))->find($pattern, $ast, $prover, true);
    }

    /**
     * @return array<mixed>
     */
    private static function encode(RedosAnalysis $analysis): array
    {
        $decoded = json_decode(json_encode($analysis, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The search cost of an analysis, failing when RedosAnalysis has no such
     * property: reading a missing one gives null with a warning, and a null
     * is what the negative rows expect.
     */
    private static function searchCostOf(RedosAnalysis $analysis): ?RedosSearchCost
    {
        self::assertObjectHasProperty('searchCost', $analysis, 'RedosAnalysis has no searchCost property.');

        return $analysis->searchCost;
    }
}
