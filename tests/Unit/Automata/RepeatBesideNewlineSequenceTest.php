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
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Tests\Support\JitInAFreshProcess;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a dot or "\N" repeated some number of times possessive before
 * "\R", and "\R" repeated before a dot or "\N", as if the two shared no
 * character. Yet a dot takes every newline of "\R" that is not the one the
 * convention sets, "\r" by default: the repeat keeps the character "\R"
 * needed, and the match is lost. "(*NO_AUTO_POSSESS)" brings it back.
 */
final class RepeatBesideNewlineSequenceTest extends TestCase
{
    use PhpUnitForwardCompat;

    private const MESSAGE = 'PCRE2 makes a repeat of the dot or "\N" possessive before "\R", and one of "\R" before a dot or "\N", as if "\R" took no character the dot takes, which the automata solver does not read.';

    /**
     * The engine finds no match in the witness, with the JIT and without,
     * and finds one once it makes no repeat possessive: the pattern is no
     * pure language, and is refused.
     */
    #[Test]
    #[DataProvider('provideRepeatsMadePossessive')]
    public function test_a_repeat_made_possessive_beside_a_newline_sequence_is_refused(string $pattern, string $witness): void
    {
        $this->assertSame(0, JitInAFreshProcess::matched($pattern, $witness));
        $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($pattern, 1), $witness));
        $this->assertSame(1, preg_match('/(*NO_AUTO_POSSESS)'.substr($pattern, 1), $witness));

        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessageIsOrContains(self::MESSAGE);

        (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Full));
    }

    /**
     * Where the repeat is counted once, where a character stands between,
     * where the newline sequence comes first, and under (*ANY), whose dot
     * takes no newline of "\R", the engine matches what the pattern says:
     * every string up to a length, with the JIT and without, as the
     * automaton does.
     */
    #[Test]
    #[DataProvider('provideRepeatsReadAsWritten')]
    public function test_a_repeat_that_gives_back_nothing_a_newline_sequence_needs_is_read(string $pattern): void
    {
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Full));

        foreach (self::subjects(['a', 'x', "\r", "\n", "\x0B"], 4) as $subject) {
            $engine = 1 === preg_match($pattern, $subject);
            $this->assertSame($engine, 1 === preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), $pattern.' on '.json_encode($subject).', without the JIT.');
            $this->assertSame($engine, 1 === preg_match('/(*NO_AUTO_POSSESS)'.substr($pattern, 1), $subject), $pattern.' on '.json_encode($subject).', no repeat made possessive.');
            $this->assertSame($engine, self::accepts($dfa, $subject), $pattern.' on '.json_encode($subject));
        }
    }

    /**
     * A dot repeated before "\R" runs unlike a class of the same characters
     * repeated there, which PCRE2 leaves as written: the engine tells them
     * apart on the witness, and so do the questions a rewrite is checked
     * through.
     */
    #[Test]
    #[DataProvider('providePatternsReadApart')]
    public function test_a_repeat_beside_a_newline_sequence_reads_like_no_other(string $left, string $right, string $witness): void
    {
        $this->assertSame(0, JitInAFreshProcess::matched($left, $witness));
        $this->assertSame(1, JitInAFreshProcess::matched($right, $witness));
        $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($left, 1), $witness));
        $this->assertSame(1, preg_match('/(*NO_JIT)'.substr($right, 1), $witness));

        $solver = new LanguageSolver();

        $this->assertFalse($solver->readAlike($left, $right));
        $this->assertFalse($solver->readAlike($right, $left));
        $this->assertFalse($solver->rewritesAlikeInPlace($left, $right));
        $this->assertFalse($solver->rewritesAlikeInPlace($right, $left));
        $this->assertNotSame([], $solver->runsOfEveryCall($left, $right));
    }

    /**
     * Where PCRE2 makes no repeat possessive beside "\R", a dot and "\N"
     * read alike as before: the engine finds the same matches with both.
     */
    #[Test]
    #[DataProvider('providePatternsStillReadAlike')]
    public function test_patterns_no_repeat_stands_beside_a_newline_sequence_in_still_read_alike(string $left, string $right): void
    {
        foreach (self::subjects(['a', 'x', "\r", "\n"], 4) as $subject) {
            $found = preg_match($left, $subject, $matches);
            $this->assertSame([$found, $matches], [preg_match($right, $subject, $other), $other], $left.' and '.$right.' on '.json_encode($subject));
            $this->assertSame([$found, $matches], [preg_match('/(*NO_JIT)'.substr($left, 1), $subject, $other), $other], $left.' on '.json_encode($subject).', without the JIT.');
            $this->assertSame([$found, $matches], [preg_match('/(*NO_JIT)'.substr($right, 1), $subject, $other), $other], $right.' on '.json_encode($subject).', without the JIT.');
        }

        $solver = new LanguageSolver();

        $this->assertTrue($solver->rewritesAlikeInPlace($left, $right));
        $this->assertSame([], $solver->runsOfEveryCall($left, $right));
    }

    /**
     * Spliced into a larger pattern, as a router splices a requirement, a
     * pattern may meet the other one in the text around it: a dot repeated
     * at its end before a "\R" that follows it, or a dot at its start after
     * a "\R" repeated before it. The engine tells the two spellings apart
     * there, and they no longer read alike.
     */
    #[Test]
    #[DataProvider('providePatternsReadApartWhereSpliced')]
    public function test_a_pattern_that_may_meet_a_newline_sequence_where_spliced_reads_like_no_other(string $left, string $right, string $before, string $after, string $witness): void
    {
        $splice = static fn (string $pattern): string => '/^'.$before.'(?P<a>'.substr($pattern, 1, -1).')'.$after.'$/D';
        $this->assertSame(1, JitInAFreshProcess::matched($splice($left), $witness));
        $this->assertSame(0, JitInAFreshProcess::matched($splice($right), $witness));
        $this->assertSame(1, preg_match('/(*NO_JIT)'.substr($splice($left), 1), $witness));
        $this->assertSame(0, preg_match('/(*NO_JIT)'.substr($splice($right), 1), $witness));

        $solver = new LanguageSolver();

        $this->assertFalse($solver->readAlike($left, $right));
        $this->assertFalse($solver->readAlike($right, $left));
    }

    /**
     * A pattern that neither ends with such a repeat nor starts with a dot
     * meets nothing where it is spliced, and reads alike as before.
     */
    #[Test]
    public function test_a_pattern_that_meets_no_newline_sequence_where_spliced_still_reads_alike(): void
    {
        foreach (['a1b', "a\rb", "a\nb", 'ab'] as $subject) {
            $this->assertSame(preg_match('/^\R?(?P<a>a.b)\R$/D', $subject."\r"), preg_match('/^\R?(?P<a>a\Nb)\R$/D', $subject."\r"), 'On '.json_encode($subject).'.');
        }

        $solver = new LanguageSolver();

        $this->assertTrue($solver->readAlike('/a.b/', '/a\Nb/'));
        $this->assertTrue($solver->readAlike('/a.{2}b/', '/a\N{2}b/'));
        $this->assertTrue($solver->readAlike('/[0-9]+/', '/\d+/'));
    }

    /**
     * @return iterable<string, array{pattern: string, witness: string}>
     */
    public static function provideRepeatsMadePossessive(): iterable
    {
        // A dot or "\N", some number of times, right before "\R".
        yield 'any number of dots' => ['pattern' => '/^.*\R\z/', 'witness' => "a\r"];
        yield 'one dot or more' => ['pattern' => '/^.+\R\z/', 'witness' => "a\r"];
        yield 'a dot or none' => ['pattern' => '/^.?\R\z/', 'witness' => "\r"];
        yield 'as few dots as may be' => ['pattern' => '/^.*?\R\z/', 'witness' => "\r\r"];
        yield 'up to two dots' => ['pattern' => '/^.{0,2}\R\z/', 'witness' => "\r"];
        yield 'one dot at least' => ['pattern' => '/^.{1,}\R\z/', 'witness' => "a\r"];
        yield 'no newline, any number of times' => ['pattern' => '/^\N*\R\z/', 'witness' => "a\r"];
        yield 'no newline under s' => ['pattern' => '/^\N*\R\z/s', 'witness' => "a\r"];
        yield 'dots before several newline sequences' => ['pattern' => '/^.*\R+\z/', 'witness' => "a\r"];
        // What reads nothing between the two changes nothing.
        yield 'a comment between' => ['pattern' => '/^.*(?#c)\R\z/', 'witness' => "a\r"];
        yield 'an option set between' => ['pattern' => '/^.*(?i)\R\z/', 'witness' => "a\r"];
        yield 'an empty group between' => ['pattern' => '/^.*()\R\z/', 'witness' => "a\r"];
        yield 'a group of a comment and an option between' => ['pattern' => '/^.*(?:(?#c)(?i))\R\z/', 'witness' => "a\r"];
        // PCRE2 reads past the end of the group the repeat stands in, and
        // into the group that follows.
        yield 'dots at the end of a capture' => ['pattern' => '/^(.*)\R\z/', 'witness' => "a\r"];
        yield 'dots at the end of a group' => ['pattern' => '/^(?:.*)\R\z/', 'witness' => "a\r"];
        yield 'a newline sequence in a capture' => ['pattern' => '/^.*(\R)\z/', 'witness' => "a\r"];
        yield 'a newline sequence in a group' => ['pattern' => '/^.*(?:\R)\z/', 'witness' => "a\r"];
        yield 'dots at the end of a named group' => ['pattern' => '/^(?<n>.*)\R\z/', 'witness' => "a\r"];
        yield 'dots at the end of a group that sets an option' => ['pattern' => '/^(?i:.*)\R\z/', 'witness' => "a\r"];
        yield 'dots at the end of a branch reset' => ['pattern' => '/^(?|.*)\R\z/', 'witness' => "a\r"];
        yield 'dots at the end of a group that may be skipped' => ['pattern' => '/^(?:x.*)?\R\z/', 'witness' => "xa\r"];
        // Each branch that follows starts with "\R", or with the end.
        yield 'a newline sequence in the last branch' => ['pattern' => '/^.*(?:\zx|\Rx)\z/', 'witness' => "a\rx"];
        yield 'a newline sequence in the first branch' => ['pattern' => '/^.*(?:\Rx|\zx)\z/', 'witness' => "a\rx"];
        // A group counted twice is written twice: the first copy ends
        // before the second, the second before what follows the group.
        yield 'dots at the end of a group counted twice' => ['pattern' => '/^(?:x.*){2}\R\z/', 'witness' => "xxa\r"];
        yield 'dots before the next turn of a group counted twice' => ['pattern' => '/^(?:\Rx.*){2}\z/', 'witness' => "\nxa\rx"];
        // "\R" some number of times, right before a dot or "\N".
        yield 'a newline sequence or none before a dot' => ['pattern' => '/^\R?.\z/', 'witness' => "\r"];
        yield 'any number of newline sequences before a dot' => ['pattern' => '/^\R*.\z/', 'witness' => "\r"];
        yield 'a newline sequence or none before no newline' => ['pattern' => '/^\R?\N\z/', 'witness' => "\r"];
        yield 'a newline sequence or none before a dot in a capture' => ['pattern' => '/^\R?(.)\z/', 'witness' => "\r"];
        yield 'a newline sequence or none in a capture' => ['pattern' => '/^(\R?).\z/', 'witness' => "\r"];
        yield 'a letter that may be skipped between' => ['pattern' => '/^\R?a?.\z/', 'witness' => "\r"];
        // Every convention whose dot takes a newline of "\R".
        yield 'dots under (*CRLF)' => ['pattern' => '/(*CRLF)^.*\R\z/', 'witness' => "a\n"];
        yield 'dots under (*CR)' => ['pattern' => '/(*CR)^.*\R\z/', 'witness' => "a\n"];
        yield 'dots under (*ANYCRLF)' => ['pattern' => '/(*ANYCRLF)^.*\R\z/', 'witness' => "a\x0B"];
        yield 'dots under (*NUL)' => ['pattern' => '/(*NUL)^.*\R\z/', 'witness' => "a\n"];
        yield 'dots under u' => ['pattern' => '/^.*\R\z/u', 'witness' => "a\r"];
        yield 'a newline sequence or none before a dot under u' => ['pattern' => '/^\R?.\z/u', 'witness' => "\r"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRepeatsReadAsWritten(): iterable
    {
        yield 'two dots' => ['pattern' => '/^.{2}\R\z/'];
        yield 'one dot' => ['pattern' => '/^.\R\z/'];
        yield 'a letter between' => ['pattern' => '/^.*a\R\z/'];
        yield 'a group that reads a letter between' => ['pattern' => '/^.*(?:a?x)\R\z/'];
        yield 'the newline sequence first' => ['pattern' => '/^\Rx.*\z/'];
        // What a lookaround holds ends where the lookaround ends, and PCRE2
        // does not read a lookaround for what comes next.
        yield 'dots inside a lookahead' => ['pattern' => '/^(?=.*)\Rx\z/'];
        yield 'dots inside a negative lookahead' => ['pattern' => '/^(?!.*x)\Ra\z/'];
        yield 'dots before a lookahead' => ['pattern' => '/^.*(?=\R)[\s\S]\z/'];
        yield 'no newline sequence' => ['pattern' => '/^.*x\z/'];
        // Under (*ANY) the dot takes no newline of "\R".
        yield 'dots under (*ANY)' => ['pattern' => '/(*ANY)^.*\R\z/'];
    }

    /**
     * @return iterable<string, array{left: string, right: string, witness: string}>
     */
    public static function providePatternsReadApart(): iterable
    {
        yield 'dots and a class of the same characters' => ['left' => '/x.*\R/', 'right' => '/x[^\n]*\R/', 'witness' => "x\r"];
        yield 'dots and a class before a letter' => ['left' => '/x.*\Ra/', 'right' => '/x[^\n]*\Ra/', 'witness' => "x\ra"];
        yield 'no newline and a class after a newline sequence' => ['left' => '/x\R?\N/', 'right' => '/x\R?[^\n]/', 'witness' => "x\r"];
    }

    /**
     * @return iterable<string, array{left: string, right: string}>
     */
    public static function providePatternsStillReadAlike(): iterable
    {
        yield 'a dot counted once' => ['left' => '/x.\R/', 'right' => '/x\N\R/'];
        yield 'dots counted twice' => ['left' => '/x.{2}\R/', 'right' => '/x\N{2}\R/'];
        yield 'a letter after a newline sequence that may be skipped' => ['left' => '/x\R?a./', 'right' => '/x\R?a\N/'];
        yield 'two newline sequences before a dot' => ['left' => '/x\R{2}./', 'right' => '/x\R{2}\N/'];
        yield 'dots before a lookahead' => ['left' => '/x.*(?=\R)/', 'right' => '/x\N*(?=\R)/'];
        yield 'dots under (*ANY)' => ['left' => '/(*ANY)x.*\R/', 'right' => '/(*ANY)x\N*\R/'];
    }

    /**
     * @return iterable<string, array{left: string, right: string, before: string, after: string, witness: string}>
     */
    public static function providePatternsReadApartWhereSpliced(): iterable
    {
        yield 'dots in a group, before a newline sequence' => ['left' => '/(?:.)*/', 'right' => '/.*/', 'before' => '', 'after' => '(?P<b>\R)', 'witness' => "a\r"];
        yield 'a class and no newline, before a newline sequence' => ['left' => '/x[^\n]*/', 'right' => '/x\N*/', 'before' => '', 'after' => '(?P<b>\R)', 'witness' => "xa\r"];
        yield 'a newline sequence in a group, before a dot' => ['left' => '/(?:\R)?/', 'right' => '/\R?/', 'before' => '', 'after' => '(?P<b>.)', 'witness' => "\r"];
        yield 'a class and a dot, after a newline sequence' => ['left' => '/[^\n]x/', 'right' => '/.x/', 'before' => '(?P<b>\R?)', 'after' => '', 'witness' => "\rx"];
    }

    private static function accepts(Dfa $dfa, string $subject): bool
    {
        $state = $dfa->startState;
        foreach ('' === $subject ? [] : str_split($subject) as $byte) {
            $next = $dfa->getState($state)->transitionFor(\ord($byte));
            if (null === $next) {
                return false;
            }
            $state = $next;
        }

        return $dfa->getState($state)->isAccepting;
    }

    /**
     * @param list<string> $letters
     *
     * @return list<string>
     */
    private static function subjects(array $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach ($letters as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            array_push($all, ...$next);
            $layer = $next;
        }

        return $all;
    }
}
