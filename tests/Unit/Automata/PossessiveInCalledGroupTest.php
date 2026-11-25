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
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\ProcessPipes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2's JIT misses matches its interpreter finds in a pattern that holds
 * a possessive quantifier inside a group a call runs: "/(a++)c(?1)d/" finds
 * no match in "acacad" with the JIT, from PCRE2 10.34 to 10.49, where the
 * interpreter and "/(a+)c(?1)d/" match "acad". PHP runs the JIT by default,
 * and the match solver reads the interpreter: it proves nothing of a
 * pattern holding a possessive quantifier inside a group a call runs, or
 * anywhere in a pattern that calls itself whole, though the possessive
 * gives nothing back there.
 */
final class PossessiveInCalledGroupTest extends TestCase
{
    use PcreGates;

    #[Test]
    #[DataProvider('providePossessivesInCalledGroups')]
    public function test_a_possessive_inside_a_group_a_call_runs_is_never_proven(string $greedy, string $possessive, ?string $witness): void
    {
        $solver = new LanguageSolver();

        $this->assertNull($solver->runsOfEveryCall($greedy, $possessive), 'No run proves the pair.');

        try {
            $solver->matchEquivalent($greedy, $possessive);

            $this->fail(\sprintf('%s and %s are proven alike.', $greedy, $possessive));
        } catch (ComplexityException $refusal) {
            $this->assertStringContainsString('PCRE2\'s JIT', $refusal->getMessage());
        }

        if (null === $witness) {
            return;
        }

        $this->requiresJit($possessive);

        $this->assertSame(1, self::matchedInAFreshProcess($greedy, $witness, true), \sprintf('Oracle: %s finds no match in "%s" with the JIT.', $greedy, $witness));
        $this->assertSame(0, self::matchedInAFreshProcess($possessive, $witness, true), \sprintf('Oracle: %s finds a match in "%s" with the JIT.', $possessive, $witness));
        $this->assertSame(1, self::matchedInAFreshProcess($possessive, $witness, false), \sprintf('Oracle: %s finds no match in "%s" without the JIT.', $possessive, $witness));
    }

    /**
     * @return iterable<string, array{greedy: string, possessive: string, witness: string|null}>
     */
    public static function providePossessivesInCalledGroups(): iterable
    {
        yield 'a possessive alone in a group called by number' => ['greedy' => '/(a+)c(?1)d/', 'possessive' => '/(a++)c(?1)d/', 'witness' => 'acacad'];
        yield 'a possessive star alone in a group called by number' => ['greedy' => '/(a*)c(?1)d/', 'possessive' => '/(a*+)c(?1)d/', 'witness' => 'ccd'];
        yield 'a possessive ending a group called at most once' => ['greedy' => '/(xa+)c(?1)?d/', 'possessive' => '/(xa++)c(?1)?d/', 'witness' => 'xacxacd'];
        yield 'a possessive ending a group called by name in a loop' => ['greedy' => '/(?<n>xa+)c(?&n)*d/', 'possessive' => '/(?<n>xa++)c(?&n)*d/', 'witness' => 'xacxacd'];
        yield 'a possessive star ending a group called in a loop' => ['greedy' => '/(xa*)c(?1)+d/', 'possessive' => '/(xa*+)c(?1)+d/', 'witness' => 'xcxcxd'];
        yield 'a possessive in a group called by number' => ['greedy' => '/(a+b?)c(?1)d/', 'possessive' => '/(a++b?)c(?1)d/', 'witness' => null];
        yield 'a possessive in a group called back' => ['greedy' => '/(a+b?)c(?-1)d/', 'possessive' => '/(a++b?)c(?-1)d/', 'witness' => null];
        yield 'a possessive in a group called forward' => ['greedy' => '/(?+1)c(a+b?)d/', 'possessive' => '/(?+1)c(a++b?)d/', 'witness' => null];
        yield 'a possessive in a group called by name' => ['greedy' => '/(?<n>a+b?)c(?&n)d/', 'possessive' => '/(?<n>a++b?)c(?&n)d/', 'witness' => null];
        yield 'a possessive in a group a DEFINE holds' => ['greedy' => '/(?&n)c(?(DEFINE)(?<n>a+b?))/', 'possessive' => '/(?&n)c(?(DEFINE)(?<n>a++b?))/', 'witness' => null];
        yield 'a possessive in a group a DEFINE holds, called by number' => ['greedy' => '/(?1)c(?(DEFINE)(a+b?))/', 'possessive' => '/(?1)c(?(DEFINE)(a++b?))/', 'witness' => null];
        yield 'a possessive in a group inside a called group' => ['greedy' => '/(x(a+)b?)c(?1)d/', 'possessive' => '/(x(a++)b?)c(?1)d/', 'witness' => null];
        yield 'a possessive in a group that calls itself' => ['greedy' => '/(x(?1)?ca+b?)c/', 'possessive' => '/(x(?1)?ca++b?)c/', 'witness' => null];
        yield 'a possessive in a group a DEFINE holds that calls itself' => ['greedy' => '/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca+b?))/', 'possessive' => '/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca++b?))/', 'witness' => null];
        yield 'a possessive in a pattern that calls itself whole' => ['greedy' => '/x(?R)?ca+b?()/', 'possessive' => '/x(?R)?ca++b?()/', 'witness' => null];
        yield 'a possessive at the end of a pattern that calls itself by number' => ['greedy' => '/x(?0)?ca+b?/', 'possessive' => '/x(?0)?ca++b?/', 'witness' => null];
        yield 'a possessive before a call of the whole pattern' => ['greedy' => '/a+(?R)b|ac/', 'possessive' => '/a++(?R)b|ac/', 'witness' => null];
        yield 'a possessive in a group named R' => ['greedy' => '/(?<R>a+b?)c(?&R)d/', 'possessive' => '/(?<R>a++b?)c(?&R)d/', 'witness' => null];
        yield 'a possessive in a group called with \g' => ['greedy' => '/(?<n>a+b?)c\g<n>d/', 'possessive' => '/(?<n>a++b?)c\g<n>d/', 'witness' => null];
        yield 'a possessive in a group called the Python way' => ['greedy' => '/(?<n>a+b?)c(?P>n)d/', 'possessive' => '/(?<n>a++b?)c(?P>n)d/', 'witness' => null];
        yield 'a possessive in a group called in a loop' => ['greedy' => '/(a+b?)(?:c(?1))+d/', 'possessive' => '/(a++b?)(?:c(?1))+d/', 'witness' => null];
        yield 'a possessive in a group repeated no time' => ['greedy' => '/(?:(a+b?)){0}c(?1)d/', 'possessive' => '/(?:(a++b?)){0}c(?1)d/', 'witness' => null];
        yield 'a possessive in a group a DEFINE holds, called from another it holds' => ['greedy' => '/(?&o)c(?(DEFINE)(?<o>d(?&i))(?<i>a+b?))/', 'possessive' => '/(?&o)c(?(DEFINE)(?<o>d(?&i))(?<i>a++b?))/', 'witness' => null];
        yield 'a possessive in a group a DEFINE holds, inside a group another DEFINE holds' => ['greedy' => '/(?&b)c(?(DEFINE)(?<a>x(?(DEFINE)(?<b>a+b?))))/', 'possessive' => '/(?&b)c(?(DEFINE)(?<a>x(?(DEFINE)(?<b>a++b?))))/', 'witness' => null];
        yield 'a possessive group inside a group that calls itself' => ['greedy' => '/(x(?1)?c(a+)b?)c/', 'possessive' => '/(x(?1)?c(a++)b?)c/', 'witness' => null];
        yield 'a possessive in a group that calls itself, called again where it stands' => ['greedy' => '/(x(?1)?ca+)(?1)/', 'possessive' => '/(x(?1)?ca++)(?1)/', 'witness' => null];
        yield 'a possessive in a group that calls itself, called twice' => ['greedy' => '/(?&n){2}c(?(DEFINE)(?<n>x(?&n)?ca+b?))/', 'possessive' => '/(?&n){2}c(?(DEFINE)(?<n>x(?&n)?ca++b?))/', 'witness' => null];
        yield 'a possessive in a group that calls itself at its end, called in a loop' => ['greedy' => '/(?&n)+(?(DEFINE)(?<n>ca+(?&n)?))/', 'possessive' => '/(?&n)+(?(DEFINE)(?<n>ca++(?&n)?))/', 'witness' => null];
        yield 'a possessive in a group called by a group inside it' => ['greedy' => '/(x(y(?1)?a+b?)?c)/', 'possessive' => '/(x(y(?1)?a++b?)?c)/', 'witness' => null];
        yield 'a possessive in a group that calls the group holding it' => ['greedy' => '/(?&g)c(?(DEFINE)(?<h>x(?<g>a+(?&h)?b?)))/', 'possessive' => '/(?&g)c(?(DEFINE)(?<h>x(?<g>a++(?&h)?b?)))/', 'witness' => null];
        // The possessive both sides hold is read alike, and is not read
        // either.
        yield 'a possessive both sides hold' => ['greedy' => '/(a++)c(?1)[0-9]/', 'possessive' => '/(a++)c(?1)\d/', 'witness' => null];
    }

    /**
     * A possessive quantifier outside every group a call runs, in a pattern
     * that does not call itself whole, is read as before.
     */
    #[Test]
    #[DataProvider('providePossessivesOutsideCalledGroups')]
    public function test_a_possessive_outside_every_group_a_call_runs_is_proven(string $greedy, string $possessive, string $letters): void
    {
        foreach (self::subjects($letters, 6) as $subject) {
            $this->assertSame(self::matchesOf($greedy, $subject), self::matchesOf($possessive, $subject), \sprintf('Oracle: %s and %s differ on "%s".', $greedy, $possessive, $subject));
        }

        $this->assertTrue((new LanguageSolver())->matchEquivalent($greedy, $possessive)->isEquivalent);
    }

    /**
     * @return iterable<string, array{greedy: string, possessive: string, letters: string}>
     */
    public static function providePossessivesOutsideCalledGroups(): iterable
    {
        yield 'no call' => ['greedy' => '/(a+b?)c/', 'possessive' => '/(a++b?)c/', 'letters' => 'abc'];
        yield 'a possessive before a call' => ['greedy' => '/a+(?1)c(xb)/', 'possessive' => '/a++(?1)c(xb)/', 'letters' => 'abcx'];
        yield 'a possessive in a group no call runs' => ['greedy' => '/(a+b?)c(x)(?2)/', 'possessive' => '/(a++b?)c(x)(?2)/', 'letters' => 'abcx'];
        // Read through every call of a group that calls itself.
        yield 'a possessive before a group that calls itself' => ['greedy' => '/a+b(x(?1)?y)/', 'possessive' => '/a++b(x(?1)?y)/', 'letters' => 'abxy'];
        yield 'a possessive after a group that calls itself' => ['greedy' => '/(x(?1)?y)a+b/', 'possessive' => '/(x(?1)?y)a++b/', 'letters' => 'abxy'];
        yield 'a possessive before a call of a group a DEFINE holds that calls itself' => ['greedy' => '/a+b(?&n)(?(DEFINE)(?<n>x(?&n)?y))/', 'possessive' => '/a++b(?&n)(?(DEFINE)(?<n>x(?&n)?y))/', 'letters' => 'abxy'];
    }

    /**
     * What preg_match() answers in a PHP of its own, the JIT on or off: the
     * suite keeps compiled patterns, some compiled with the JIT and some
     * without, and a pattern compiled once is not compiled again when
     * pcre.jit changes.
     */
    private static function matchedInAFreshProcess(string $pattern, string $subject, bool $jit): int|false|null
    {
        $code = 'echo json_encode(preg_match($argv[1], $argv[2]));';
        $process = proc_open([\PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcre.jit='.($jit ? '1' : '0'), '-r', $code, '--', $pattern, $subject], ProcessPipes::descriptors(), $pipes);
        self::assertIsResource($process);
        [$output] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $answer = json_decode($output, true);

        return \is_int($answer) || false === $answer ? $answer : null;
    }

    /**
     * @return array<array-key, mixed>|false
     */
    private static function matchesOf(string $pattern, string $subject): array|false
    {
        $matches = [];

        return false === preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE | \PREG_UNMATCHED_AS_NULL) ? false : $matches;
    }

    /**
     * Every string over the letters, up to the length.
     *
     * @return list<string>
     */
    private static function subjects(string $letters, int $length): array
    {
        $all = [''];
        $layer = [''];
        for ($size = 1; $size <= $length; $size++) {
            $next = [];
            foreach ($layer as $prefix) {
                foreach (str_split($letters) as $letter) {
                    $next[] = $prefix.$letter;
                }
            }
            $all = [...$all, ...$next];
            $layer = $next;
        }

        return $all;
    }
}
