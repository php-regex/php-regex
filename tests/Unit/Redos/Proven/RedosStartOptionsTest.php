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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Tests\Support\PcreAttemptSteps;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The options a pattern opens with, each read by what it changes.
 *
 * Most change no match one attempt makes: (*NO_JIT), the limits,
 * (*NOTEMPTY) and (*NOTEMPTY_ATSTART) (the proof already tries the attempt
 * that refuses an empty match, as PHP retries one), (*NO_AUTO_POSSESS)
 * (the model reads every loop without auto-possession), (*NO_START_OPT)
 * and (*NO_DOTSTAR_ANCHOR) (they move where attempts start, not what one
 * costs), (*BSR_ANYCRLF) and (*BSR_UNICODE) ("\R" is outside the model),
 * (*LF), the convention the model reads anyway, (*UTF) under u, and
 * (*CASELESS_RESTRICT) and (*TURKISH_CASING) where nothing is caseless.
 * (*NOTEMPTY) changes one with "\K", which sets the start of the match the
 * engine reports: a match that reads "(?:a|a)+" then "\K" is empty and
 * refused, and the attempt gives back every way through the loop.
 * Another newline convention changes only the dot, "\N", the anchors that
 * read a newline and where a comment ends under x, which the parser reads
 * as PCRE does: the pattern keeps its verdict when it holds none of the
 * others.
 *
 * Under a newline convention the dot and "\N" are asked of the engine:
 * (*CR) leaves out a carriage return, (*NUL) a NUL, (*ANYCRLF) both CR and
 * LF, (*ANY) them, the vertical tab, the form feed, the next line and,
 * under u, U+2028 and U+2029 (PHP 8.4, PCRE2 10.49, every byte and those
 * code points). (*CASELESS_RESTRICT) is the r modifier: "(?-r)" and "(?^)"
 * take it off.
 *
 * Kept outside the model: the dot and "\N" under (*CRLF), which take a
 * carriage return unless a line feed follows it ("/(*CRLF)a..b/" finds no
 * match in "a\r\nb", one in "a\n\rb"); "$" but under D alone, "\Z", and
 * "^" and "$" under m, under a convention other than LF; (*UTF) without
 * u, under which PHP hands the engine the subject unchecked ('/(*UTF)./'
 * matches "\xFF", where '/./u' fails on it with PREG_BAD_UTF8_ERROR): the
 * model reads code points only; and caseless matching under
 * (*TURKISH_CASING), which folds "i" with U+0130.
 *
 * Each row carries its engine fact, measured in the same test: the steps
 * one match attempt needs in the interpreter, auto-possession and start
 * optimizations off (PcreAttemptSteps), on 8 then 16 pump units and a "!".
 * PHP 8.4.28, PCRE2 10.49: 35 -> 67 steps for "(?:a|ab)+c", 46 -> 154 for
 * "^a*a*b", 1 023 -> 262 143 for "^(?:a|a)+b", behind every option alike.
 */
final class RedosStartOptionsTest extends TestCase
{
    use PcreGates;

    /**
     * The options that change nothing the shapes read, and the modifiers
     * each needs to compile.
     */
    private const NEUTRAL_OPTIONS = [
        'no JIT' => ['(*NO_JIT)', ''],
        'a match limit' => ['(*LIMIT_MATCH=10000000)', ''],
        'a depth limit' => ['(*LIMIT_DEPTH=10000000)', ''],
        'a heap limit' => ['(*LIMIT_HEAP=10000000)', ''],
        'a recursion limit, the old name of the depth limit' => ['(*LIMIT_RECURSION=10000000)', ''],
        'no empty match' => ['(*NOTEMPTY)', ''],
        'no empty match at the start' => ['(*NOTEMPTY_ATSTART)', ''],
        'no auto-possession' => ['(*NO_AUTO_POSSESS)', ''],
        'no start-up optimization' => ['(*NO_START_OPT)', ''],
        'no dot-star anchoring' => ['(*NO_DOTSTAR_ANCHOR)', ''],
        'CR and LF line breaks' => ['(*BSR_ANYCRLF)', ''],
        'Unicode line breaks' => ['(*BSR_UNICODE)', ''],
        'LF newlines' => ['(*LF)', ''],
        'CR newlines' => ['(*CR)', ''],
        'CRLF newlines' => ['(*CRLF)', ''],
        'CR or LF newlines' => ['(*ANYCRLF)', ''],
        'any newline' => ['(*ANY)', ''],
        'NUL newlines' => ['(*NUL)', ''],
        'UTF under u' => ['(*UTF)', 'u'],
        'UTF8 under u' => ['(*UTF8)', 'u'],
        'caseless restrict, nothing caseless' => ['(*CASELESS_RESTRICT)', ''],
        'Turkish casing, nothing caseless' => ['(*TURKISH_CASING)', 'u'],
        'UCP' => ['(*UCP)', ''],
        'several in a row' => ['(*NO_JIT)(*LIMIT_MATCH=10000000)(*NOTEMPTY)(*NO_AUTO_POSSESS)', ''],
        'CR then UCP' => ['(*CR)(*UCP)', ''],
        'UTF then no JIT under u' => ['(*UTF)(*NO_JIT)', 'u'],
    ];

    /**
     * Shapes that hold no dot, no anchor reading a newline, no comment and
     * nothing caseless: their pump unit and class.
     */
    private const SHAPES = [
        'branches a and ab' => ['(?:a|ab)+c', 'ab', RedosComplexity::Linear, null],
        'branches a and ab beside another alternative' => ['(?:a|ab)+c|x', 'ab', RedosComplexity::Linear, null],
        'two runs of a' => ['^a*a*b', 'a', RedosComplexity::Polynomial, 2],
        'two equal branches' => ['^(?:a|a)+b', 'a', RedosComplexity::Exponential, null],
    ];

    #[Test]
    #[DataProvider('provideNeutralOptions')]
    public function test_a_start_option_that_changes_no_match_keeps_the_verdict_of_the_bare_pattern(string $options, string $shape, string $flags, string $unit, RedosComplexity $complexity, ?int $degree): void
    {
        $pattern = '/'.$options.$shape.'/'.$flags;
        $this->requiresCompile($pattern);
        $this->assertSame($complexity, self::engineClass($pattern, $unit), 'Oracle disagrees with the row '.$pattern);

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $bare = $analyzer->analyze('/'.$shape.'/'.$flags);
        $message = \sprintf('%s is "%s", the engine says %s', $pattern, $analysis->headline(), $complexity->value);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame($complexity, $analysis->complexity, $message);
        $this->assertSame($degree, $analysis->degree, $message);
        $this->assertSame([$bare->proof, $bare->complexity, $bare->degree], [$analysis->proof, $analysis->complexity, $analysis->degree], $message);
    }

    /**
     * @return iterable<string, array{options: string, shape: string, flags: string, unit: string, complexity: RedosComplexity, degree: int|null}>
     */
    public static function provideNeutralOptions(): iterable
    {
        foreach (self::NEUTRAL_OPTIONS as $option => [$options, $flags]) {
            foreach (self::SHAPES as $name => [$shape, $unit, $complexity, $degree]) {
                yield $option.', '.$name => ['options' => $options, 'shape' => $shape, 'flags' => $flags, 'unit' => $unit, 'complexity' => $complexity, 'degree' => $degree];
            }
        }
    }

    /**
     * (*NOTEMPTY) and (*NOTEMPTY_ATSTART) refuse the empty match an attempt
     * at the search start may find first, and the engine goes on: the
     * attempt that refuses it is the one PHP makes again after an empty
     * match without $matches, which the proof already reads. The verdict is
     * the bare pattern's, and the engine's steps with $matches follow it.
     */
    #[Test]
    #[DataProvider('provideEmptyMatchRefusals')]
    public function test_a_start_option_refusing_the_empty_match_keeps_the_verdict_the_proof_reads(string $options, string $shape, string $unit, RedosComplexity $complexity): void
    {
        $pattern = '/'.$options.$shape.'/';
        $this->assertSame($complexity, self::engineClass($pattern, $unit), 'Oracle disagrees with the row '.$pattern);

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $bare = $analyzer->analyze('/'.$shape.'/');
        $message = \sprintf('%s is "%s", the engine says %s', $pattern, $analysis->headline(), $complexity->value);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame($complexity, $analysis->complexity, $message);
        $this->assertSame([$bare->proof, $bare->complexity, $bare->degree], [$analysis->proof, $analysis->complexity, $analysis->degree], $message);
    }

    /**
     * @return iterable<string, array{options: string, shape: string, unit: string, complexity: RedosComplexity}>
     */
    public static function provideEmptyMatchRefusals(): iterable
    {
        foreach (['(*NOTEMPTY)', '(*NOTEMPTY_ATSTART)'] as $options) {
            yield $options.', an empty branch tried first' => ['options' => $options, 'shape' => '(?:|(?:a|a)*b)', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
            yield $options.', an optional loop tried last' => ['options' => $options, 'shape' => '(?:(?:a|a)*b)??', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
            yield $options.', a lazy loop' => ['options' => $options, 'shape' => '(?:a|ab)*?', 'unit' => 'ab', 'complexity' => RedosComplexity::Linear];
        }
    }

    /**
     * Under (*NOTEMPTY) a match that reads nothing after its last "\K" is
     * empty and refused: the attempt gives back every way through the loop
     * before it, where the bare pattern matches at once. A character read
     * after the last "\K" leaves the match non-empty, and under
     * (*NOTEMPTY_ATSTART) the start "\K" sets past the search start is not
     * the search start: those attempts succeed.
     *
     * When the attempt succeeds once the loop gives a character back to
     * read after "\K", whatever the loop reads on makes that success sure a
     * character later: the engine abandons no longer run inside the loop,
     * and the attempt is proven linear, as "^(?:a|a)+." is.
     */
    #[Test]
    #[DataProvider('provideKeepUnderEmptyMatchRefusals')]
    public function test_a_start_option_refusing_the_empty_match_refuses_the_match_keep_leaves_empty(string $options, string $shape, RedosComplexity $complexity): void
    {
        $pattern = '/'.$options.$shape.'/';
        $bare = '/'.$shape.'/';
        $this->assertSame($complexity, self::engineClass($pattern, 'a'), 'Oracle disagrees with the row '.$pattern);
        $this->assertSame(RedosComplexity::Linear, self::engineClass($bare, 'a'), 'Oracle: '.$bare.' is not linear.');

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $message = \sprintf('%s is "%s", the engine says %s', $pattern, $analysis->headline(), $complexity->value);
        $this->assertSame([RedosProof::Proven, $complexity], [$analysis->proof, $analysis->complexity], $message);
        if (RedosComplexity::Linear !== $complexity) {
            $this->assertTrue($analyzer->analyze($pattern, RedosSeverity::Low, RedosMode::Confirmed)->replayed, $pattern.': the engine does not replay the witness.');
        }

        $bareAnalysis = $analyzer->analyze($bare);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$bareAnalysis->proof, $bareAnalysis->complexity], $bare);
    }

    /**
     * @return iterable<string, array{options: string, shape: string, complexity: RedosComplexity}>
     */
    public static function provideKeepUnderEmptyMatchRefusals(): iterable
    {
        yield '\K at the end' => ['options' => '(*NOTEMPTY)', 'shape' => '^(?:a|a)+\K', 'complexity' => RedosComplexity::Exponential];
        yield '\K at the end, unanchored' => ['options' => '(*NOTEMPTY)', 'shape' => '(?:a|a)+\K', 'complexity' => RedosComplexity::Exponential];
        yield '\K before an optional item' => ['options' => '(*NOTEMPTY)', 'shape' => '^(?:a|a)+\Kb?', 'complexity' => RedosComplexity::Exponential];
        yield '\K before the loop' => ['options' => '(*NOTEMPTY)', 'shape' => '^\K(?:a|a)+', 'complexity' => RedosComplexity::Linear];
        yield '\K at the end, empty match refused at the search start' => ['options' => '(*NOTEMPTY_ATSTART)', 'shape' => '^(?:a|a)+\K', 'complexity' => RedosComplexity::Linear];
        // The character after "\K" reads the "!", or the last "a" once the
        // loop gives it back.
        yield 'a character read after \K' => ['options' => '(*NOTEMPTY)', 'shape' => '^(?:a|a)+\K.?', 'complexity' => RedosComplexity::Linear];
        // The last iteration takes the branch without "\K" once the loop
        // gives one back: the "\K" of the iteration before stands before the
        // last character.
        yield '\K in one branch of the loop' => ['options' => '(*NOTEMPTY)', 'shape' => '^(?:a\K|a)+', 'complexity' => RedosComplexity::Linear];
    }

    /**
     * A newline convention reads the dot and "\N" as the engine does, and
     * (*CASELESS_RESTRICT) reads caseless matching as r does: the proof
     * stands on what each makes the atoms match.
     */
    #[Test]
    #[DataProvider('provideOptionsThatChangeAMatch')]
    public function test_a_start_option_that_changes_a_match_is_read_where_it_holds(string $pattern, string $unit, RedosComplexity $complexity): void
    {
        $this->requiresCompile($pattern);
        $this->assertSame($complexity, self::engineClass($pattern, $unit), 'Oracle disagrees with the row '.$pattern);

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = \sprintf('%s is "%s", the engine says %s', $pattern, $analysis->headline(), $complexity->value);

        $this->assertSame(RedosProof::Proven, $analysis->proof, $message);
        $this->assertSame($complexity, $analysis->complexity, $message);
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, complexity: RedosComplexity}>
     */
    public static function provideOptionsThatChangeAMatch(): iterable
    {
        yield 'the dot takes a line feed under (*CR)' => ['pattern' => '/(*CR)^(?:.|\n)+b/', 'unit' => "\n", 'complexity' => RedosComplexity::Exponential];
        yield 'the dot leaves a carriage return under (*CR)' => ['pattern' => '/(*CR)^(?:.|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        yield '\N takes a line feed under (*CR)' => ['pattern' => '/(*CR)^(?:\N|\n)+b/', 'unit' => "\n", 'complexity' => RedosComplexity::Exponential];
        yield '\N leaves a carriage return under (*CR)' => ['pattern' => '/(*CR)^(?:\N|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        yield '\N leaves a carriage return under (*CR) and s' => ['pattern' => '/(*CR)^(?:\N|\r)+b/s', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        yield 'the dot takes a line feed under (*NUL)' => ['pattern' => '/(*NUL)^(?:.|\n)+b/', 'unit' => "\n", 'complexity' => RedosComplexity::Exponential];
        yield 'the dot leaves NUL under (*NUL)' => ['pattern' => '/(*NUL)^(?:.|\x00)+b/', 'unit' => "\0", 'complexity' => RedosComplexity::Linear];
        yield 'the dot leaves a carriage return under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)^(?:.|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        yield 'the dot leaves a line feed under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)^(?:.|\n)+b/', 'unit' => "\n", 'complexity' => RedosComplexity::Linear];
        yield 'the dot takes the next line under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)^(?:.|\x85)+b/', 'unit' => "\x85", 'complexity' => RedosComplexity::Exponential];
        yield 'the dot leaves the next line under (*ANY)' => ['pattern' => '/(*ANY)^(?:.|\x85)+b/', 'unit' => "\x85", 'complexity' => RedosComplexity::Linear];
        yield 'the dot leaves a form feed under (*ANY)' => ['pattern' => '/(*ANY)^(?:.|\f)+b/', 'unit' => "\f", 'complexity' => RedosComplexity::Linear];
        yield 'the dot leaves the line separator under (*ANY) and u' => ['pattern' => '/(*ANY)^(?:.|\x{2028})+b/u', 'unit' => "\u{2028}", 'complexity' => RedosComplexity::Linear];
        yield 'the dot takes the line separator under (*CR) and u' => ['pattern' => '/(*CR)^(?:.|\x{2028})+b/u', 'unit' => "\u{2028}", 'complexity' => RedosComplexity::Exponential];
        yield 'the dot takes every character under (*ANY) and s' => ['pattern' => '/(*ANY)^(?:.|\x85)+b/s', 'unit' => "\x85", 'complexity' => RedosComplexity::Exponential];
        yield 'the last convention wins, LF after CR' => ['pattern' => '/(*CR)(*LF)^(?:.|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Exponential];
        yield 'the last convention wins, CR after LF' => ['pattern' => '/(*LF)(*CR)^(?:.|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        yield 'a convention beside UCP' => ['pattern' => '/(*UCP)(*CR)^(?:.|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        yield 'a convention beside another option' => ['pattern' => '/(*LIMIT_MATCH=10000000)(*CR)^(?:.|\n)+b/', 'unit' => "\n", 'complexity' => RedosComplexity::Exponential];
        yield 'a dollar under D reads no newline' => ['pattern' => '/(*CR)^(?:.|\n)+b$/D', 'unit' => "\n", 'complexity' => RedosComplexity::Exponential];
        yield 'a dollar under D, the dot leaving a carriage return' => ['pattern' => '/(*CR)^(?:.|\r)+b$/D', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        yield 'a convention before a top-level alternation' => ['pattern' => '/(*CR)^(?:.|\r)+b|x/', 'unit' => "\r", 'complexity' => RedosComplexity::Linear];
        // A comment under x ends at the convention's newline, as the parser
        // reads it: the item after it counts.
        yield 'a comment under x and (*CR), ended by a carriage return' => ['pattern' => "/(*CR)^(?:a|a)+#\r b/x", 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield 'a comment under an inline x and (*ANYCRLF)' => ['pattern' => "/(*ANYCRLF)^(?x)(?:a|a)+#\rb/", 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield 'a comment under x and (*CR), running past a line feed' => ['pattern' => "/(*CR)^(?:a|a)+#\nb/x", 'unit' => 'a', 'complexity' => RedosComplexity::Linear];
        yield 'the Kelvin sign stays apart from k under (*CASELESS_RESTRICT)' => ['pattern' => '/(*CASELESS_RESTRICT)^(?:k|\x{212A})+b/iu', 'unit' => "\u{212A}", 'complexity' => RedosComplexity::Linear];
        yield 'k still meets K under (*CASELESS_RESTRICT)' => ['pattern' => '/(*CASELESS_RESTRICT)^(?:k|K)+b/iu', 'unit' => 'K', 'complexity' => RedosComplexity::Exponential];
        yield '(?-r) takes (*CASELESS_RESTRICT) off' => ['pattern' => '/(*CASELESS_RESTRICT)^(?-r)(?:k|\x{212A})+b/iu', 'unit' => "\u{212A}", 'complexity' => RedosComplexity::Exponential];
        yield '(?^i) takes (*CASELESS_RESTRICT) off' => ['pattern' => '/(*CASELESS_RESTRICT)^(?^i)(?:k|\x{212A})+b/u', 'unit' => "\u{212A}", 'complexity' => RedosComplexity::Exponential];
    }

    /**
     * A limit the pattern sets lowers the one PHP runs it under, and the
     * engine stops sooner: the checks the proof asks of the engine, a
     * witness through a lookaround, a bounded repeat read as unbounded, run
     * under PHP's default limits, so the verdict is the bare pattern's.
     */
    #[Test]
    #[DataProvider('provideLimitsTheEngineChecksLeaveOut')]
    public function test_a_limit_option_leaves_the_engine_checks_of_the_proof_to_the_default_limits(string $options, string $shape, string $subject): void
    {
        $bare = '/'.$shape.'/';
        $pattern = '/'.$options.$shape.'/';
        $this->assertSame(0, preg_match(PcreInterpreter::pattern($bare), $subject), 'Oracle: '.$bare.' fails on the subject under the default limits.');
        $this->assertFalse(@preg_match(PcreInterpreter::pattern($pattern), $subject), 'Oracle: '.$pattern.' stops at its own limit on the subject.');

        $analyzer = new RedosAnalyzer();
        $analysis = $analyzer->analyze($pattern);
        $expected = $analyzer->analyze($bare);
        $message = \sprintf('%s is "%s", %s is "%s"', $pattern, $analysis->headline(), $bare, $expected->headline());

        $this->assertSame([$expected->proof, $expected->complexity, $expected->degree, $expected->severity], [$analysis->proof, $analysis->complexity, $analysis->degree, $analysis->severity], $message);
    }

    /**
     * @return iterable<string, array{options: string, shape: string, subject: string}>
     */
    public static function provideLimitsTheEngineChecksLeaveOut(): iterable
    {
        // The witness of an exponential loop before a lookaround, which the
        // engine must fail: proven exponential.
        yield 'a match limit before a lookahead witness' => ['options' => '(*LIMIT_MATCH=10)', 'shape' => '(a+)+(?=b)', 'subject' => str_repeat('a', 12).'c'];
        yield 'a match limit before a lookbehind witness' => ['options' => '(*LIMIT_MATCH=10)', 'shape' => '(?<=\[)(?:\*|\*)+\n', 'subject' => '['.str_repeat('*', 12)."!\n"];
        // A pump through a bounded repeat the default limit stops within:
        // proven exponential.
        yield 'a depth limit before a bounded repeat' => ['options' => '(*LIMIT_DEPTH=10)', 'shape' => '^(?:a|a){1,40}$', 'subject' => str_repeat('a', 12).'!'];
        yield 'a match limit and no JIT before a bounded repeat' => ['options' => '(*NO_JIT)(*LIMIT_MATCH=100)', 'shape' => '^(?:a|a){1,40}$', 'subject' => str_repeat('a', 12).'!'];
        // A pump through a bounded repeat the default limit never stops
        // within: the heuristics decide.
        yield 'a match limit before a short bounded repeat' => ['options' => '(*LIMIT_MATCH=100)', 'shape' => '^(?:a|a){1,16}$', 'subject' => str_repeat('a', 16).'!'];
    }

    /**
     * What a start option changes and the model does not read keeps the
     * pattern outside the model, as before: never proven, and a pattern
     * the engine runs in exponential steps stays flagged.
     */
    #[Test]
    #[DataProvider('provideOptionsKeptOutsideTheModel')]
    public function test_a_start_option_keeps_out_of_the_model_what_the_model_does_not_read(string $pattern, string $unit, RedosComplexity $complexity): void
    {
        $this->requiresCompile($pattern);
        $this->assertSame($complexity, self::engineClass($pattern, $unit), 'Oracle disagrees with the row '.$pattern);

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $message = \sprintf('%s is "%s", the engine says %s', $pattern, $analysis->headline(), $complexity->value);

        $this->assertSame(RedosProof::Heuristic, $analysis->proof, $message);
        if (RedosComplexity::Linear !== $complexity) {
            $this->assertFalse($analysis->isSafe(), $message);
        }
    }

    /**
     * @return iterable<string, array{pattern: string, unit: string, complexity: RedosComplexity}>
     */
    public static function provideOptionsKeptOutsideTheModel(): iterable
    {
        yield 'the dot under (*CRLF), beside a line feed' => ['pattern' => '/(*CRLF)^(?:.|\n)+b/', 'unit' => "\n", 'complexity' => RedosComplexity::Exponential];
        yield 'the dot under (*CRLF), beside a carriage return' => ['pattern' => '/(*CRLF)^(?:.|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Exponential];
        yield '\N under (*CRLF)' => ['pattern' => '/(*CRLF)^(?:\N|\r)+b/', 'unit' => "\r", 'complexity' => RedosComplexity::Exponential];
        yield 'a dollar under (*CR)' => ['pattern' => '/(*CR)^(?:a|a)+$/', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield '\Z under (*CR)' => ['pattern' => '/(*CR)^(?:a|a)+\Z/', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield 'a dollar under m and (*NUL)' => ['pattern' => '/(*NUL)^(?:a|a)+$/m', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield 'a dollar under m and D, (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)^(?:a|a)+$/mD', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield 'a caret under an inline m and (*ANY)' => ['pattern' => '/(*ANY)(?m)^(?:a|a)+b/', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield '(*UTF) without u' => ['pattern' => '/(*UTF)^(?:a|a)+b/', 'unit' => 'a', 'complexity' => RedosComplexity::Exponential];
        yield '(*UTF) without u, branches a and ab' => ['pattern' => '/(*UTF)(?:a|ab)+c/', 'unit' => 'ab', 'complexity' => RedosComplexity::Linear];
        yield '(*UTF) and (*UCP) without u' => ['pattern' => '/(*UTF)(*UCP)(?:a|ab)+c/', 'unit' => 'ab', 'complexity' => RedosComplexity::Linear];
        yield 'caseless matching under (*TURKISH_CASING)' => ['pattern' => '/(*TURKISH_CASING)^(?:i|\x{130})+b/iu', 'unit' => "\u{130}", 'complexity' => RedosComplexity::Exponential];
        yield 'an inline caseless scope under (*TURKISH_CASING)' => ['pattern' => '/(*TURKISH_CASING)^(?i:i|\x{130})+b/u', 'unit' => "\u{130}", 'complexity' => RedosComplexity::Exponential];
    }

    /**
     * The class of the engine's steps on 8 then 16 pump units and a "!":
     * linear when they less than double and a half, exponential from
     * sixteen times as many, polynomial in between.
     */
    private static function engineClass(string $pattern, string $unit): RedosComplexity
    {
        $counted = PcreAttemptSteps::counted($pattern[0].'(*NO_START_OPT)'.substr($pattern, 1));
        $short = PcreAttemptSteps::steps($counted, str_repeat($unit, 8).'!');
        $long = PcreAttemptSteps::steps($counted, str_repeat($unit, 16).'!');

        return match (true) {
            $long >= 16 * $short => RedosComplexity::Exponential,
            2 * $long >= 5 * $short => RedosComplexity::Polynomial,
            default => RedosComplexity::Linear,
        };
    }
}
