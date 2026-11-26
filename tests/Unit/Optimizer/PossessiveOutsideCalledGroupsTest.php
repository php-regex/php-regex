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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2's JIT misses matches its interpreter finds in a pattern that holds
 * a possessive quantifier inside a group a call runs: "/(a++)c(?1)d/"
 * finds no match in "acacad" with the JIT, from PCRE2 10.34 to 10.49,
 * where "/(a+)c(?1)d/" matches "acad". PHP runs the JIT by default: the
 * possessive rewrite leaves every repetition greedy inside a group a call
 * runs, a group a DEFINE holds included, and everywhere in a pattern that
 * calls itself whole, though nothing after it takes back what it matched.
 * A repetition outside every group a call runs is read as before.
 */
final class PossessiveOutsideCalledGroupsTest extends TestCase
{
    use PcreGates;

    /**
     * The characters of the subjects each pair is run on, every string of
     * them up to five long.
     */
    private const ALPHABET = ['a', 'b', 'c', 'd', 'x', 'y'];

    /**
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideRepetitionsInsideCalledGroups')]
    public function test_a_repetition_inside_a_group_a_call_runs_stays_greedy(string $pattern, string $possessive, ?string $witness, string $optimized, array $changes): void
    {
        if (null !== $witness) {
            $this->requiresJit($possessive);

            $this->assertSame(1, self::matchedInAFreshProcess($pattern, $witness), \sprintf('Oracle: %s finds no match in "%s" with the JIT.', $pattern, $witness));
            $this->assertSame(0, self::matchedInAFreshProcess($possessive, $witness), \sprintf('Oracle: %s finds a match in "%s" with the JIT.', $possessive, $witness));
        }

        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));

        $rewriter = new Rewriter(possessive: true);
        $this->assertSame($optimized, Regex::create(['cache' => null])->parse($pattern)->accept($rewriter)->accept(new PatternPrinter()));
        $this->assertNotContains(Rewriter::POSSESSIVE, $rewriter->changes(), 'The rewrite offers no possessive.');

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, witness: string|null, optimized: string, changes: list<string>}>
     */
    public static function provideRepetitionsInsideCalledGroups(): iterable
    {
        yield 'a repetition alone in a group called by number' => ['pattern' => '/(a+)c(?1)d/', 'possessive' => '/(a++)c(?1)d/', 'witness' => 'acacad', 'optimized' => '/(a+)c(?1)d/', 'changes' => []];
        yield 'a star alone in a group called by number' => ['pattern' => '/(a*)c(?1)d/', 'possessive' => '/(a*+)c(?1)d/', 'witness' => 'ccd', 'optimized' => '/(a*)c(?1)d/', 'changes' => []];
        yield 'a repetition ending a group called at most once' => ['pattern' => '/(xa+)c(?1)?d/', 'possessive' => '/(xa++)c(?1)?d/', 'witness' => 'xacxacd', 'optimized' => '/(xa+)c(?1)?d/', 'changes' => []];
        yield 'a repetition in a group called by number' => ['pattern' => '/(a+b?)c(?1)d/', 'possessive' => '/(a++b?)c(?1)d/', 'witness' => null, 'optimized' => '/(a+b?)c(?1)d/', 'changes' => []];
        yield 'a repetition in a group called by name' => ['pattern' => '/(?<n>a+b?)c(?&n)d/', 'possessive' => '/(?<n>a++b?)c(?&n)d/', 'witness' => null, 'optimized' => '/(?<n>a+b?)c(?&n)d/', 'changes' => []];
        yield 'a repetition in a group inside a called group' => ['pattern' => '/(x(a+)b?)c(?1)d/', 'possessive' => '/(x(a++)b?)c(?1)d/', 'witness' => null, 'optimized' => '/(x(a+)b?)c(?1)d/', 'changes' => []];
        yield 'a repetition in a group a DEFINE holds' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>a+b?))/', 'possessive' => '/(?&n)c(?(DEFINE)(?<n>a++b?))/', 'witness' => null, 'optimized' => '/(?&n)c(?(DEFINE)(?<n>a+b?))/', 'changes' => []];
        yield 'a repetition in a group a DEFINE holds, under an option' => ['pattern' => '/(?&n)(?i)c(?(DEFINE)(?<n>a+b?))/', 'possessive' => '/(?&n)(?i)c(?(DEFINE)(?<n>a++b?))/', 'witness' => null, 'optimized' => '/(?&n)(?i)c(?(DEFINE)(?<n>a+b?))/', 'changes' => []];
        yield 'a repetition in a group a scoped caseless option holds, called before another letter' => ['pattern' => '/(?i:(?(DEFINE)(?<n>A+b?)))(?&n)c/', 'possessive' => '/(?i:(?(DEFINE)(?<n>A++b?)))(?&n)c/', 'witness' => null, 'optimized' => '/(?i:(?(DEFINE)(?<n>A+b?)))(?&n)c/', 'changes' => []];
        yield 'a repetition in a group that calls itself' => ['pattern' => '/(x(?1)?ca+b?)c/', 'possessive' => '/(x(?1)?ca++b?)c/', 'witness' => null, 'optimized' => '/(x(?1)?ca+b?)c/', 'changes' => []];
        yield 'a repetition in a group a DEFINE holds that calls itself' => ['pattern' => '/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca+b?))/', 'possessive' => '/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca++b?))/', 'witness' => null, 'optimized' => '/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca+b?))/', 'changes' => []];
        yield 'a repetition in a pattern that calls itself whole' => ['pattern' => '/x(?R)?ca+b?()/', 'possessive' => '/x(?R)?ca++b?()/', 'witness' => null, 'optimized' => '/x(?R)?ca+b?()/', 'changes' => []];
        // The other rewrites ship, outside the group: one that rewrites the
        // group, which repeats, ships nowhere (see RepeatInCalledGroupTest).
        yield 'a class beside a group a DEFINE holds' => ['pattern' => '/(?&n)[0-9](?(DEFINE)(?<n>a+))/', 'possessive' => '/(?&n)[0-9](?(DEFINE)(?<n>a++))/', 'witness' => null, 'optimized' => '/(?&n)\d(?(DEFINE)(?<n>a+))/', 'changes' => [Rewriter::DIGITS]];
    }

    /**
     * @param list<string> $changes
     */
    #[Test]
    #[DataProvider('provideRepetitionsOutsideCalledGroups')]
    public function test_a_repetition_outside_every_group_a_call_runs_is_made_possessive(string $pattern, string $optimized, array $changes): void
    {
        $this->assertSame(self::matchedOnEverySubject($pattern), self::matchedOnEverySubject($optimized), \sprintf('Oracle: %s and %s differ.', $pattern, $optimized));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, changes: list<string>}>
     */
    public static function provideRepetitionsOutsideCalledGroups(): iterable
    {
        yield 'a repetition before a group a call runs' => ['pattern' => '/a+;(x)(?1)/', 'optimized' => '/a++;(x)(?1)/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a repetition in a group no call runs' => ['pattern' => '/(a+b?)c(x)(?2)/', 'optimized' => '/(a++b?)c(x)(?2)/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a repetition in a group a DEFINE holds, never called' => ['pattern' => '/(?(DEFINE)(?<n>a+b?))a/', 'optimized' => '/(?(DEFINE)(?<n>a++b?))a/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a repetition before a group that calls itself' => ['pattern' => '/a+b(x(?1)?y)/', 'optimized' => '/a++b(x(?1)?y)/', 'changes' => [Rewriter::POSSESSIVE]];
    }

    /**
     * What preg_match() answers in a PHP of its own, the JIT on: the suite
     * keeps compiled patterns, some compiled with pcre.jit off, and a
     * pattern compiled once is not compiled again when pcre.jit changes.
     */
    private static function matchedInAFreshProcess(string $pattern, string $subject): int|false|null
    {
        $code = 'echo json_encode(preg_match($argv[1], $argv[2]));';
        $process = proc_open([\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcre.jit=1', '-r', $code, '--', $pattern, $subject], ProcessPipes::descriptors(), $pipes);
        self::assertIsResource($process);
        [$output] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $answer = json_decode($output, true);

        return \is_int($answer) || false === $answer ? $answer : null;
    }

    /**
     * What preg_match() answers on every string of the alphabet up to five
     * characters long, with the text and the offset of the match and of
     * each group.
     *
     * @return list<array{int|false, array<array-key, mixed>}>
     */
    private static function matchedOnEverySubject(string $pattern): array
    {
        $answers = [];
        $subjects = [''];
        for ($length = 0; $length <= 5; $length++) {
            $longer = [];
            foreach ($subjects as $subject) {
                $answers[] = self::matched($pattern, $subject);
                foreach (self::ALPHABET as $character) {
                    $longer[] = $subject.$character;
                }
            }

            $subjects = $longer;
        }

        return $answers;
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        return [$result, $matches];
    }
}
