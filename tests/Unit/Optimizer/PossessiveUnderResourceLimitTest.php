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
 * A limit the pattern sets, "(*LIMIT_MATCH=n)", "(*LIMIT_DEPTH=n)", its old
 * name "(*LIMIT_RECURSION=n)", or "(*LIMIT_HEAP=n)", bounds what PCRE2's
 * interpreter may spend on a match, and a possessive group may spend more
 * of it than the greedy one: "c(?:ab)++e" makes a match call more than
 * "c(?:ab)+e" on "cababe", and "c(?:ab){2,}+e" holds a frame more than
 * "c(?:ab){2,}e". Under such a limit the possessive rewrite keeps every
 * repeated group greedy. A repeated character or class spends no more of
 * any of the three, JIT on or off, from PCRE2 10.34 to 10.49, and is still
 * made possessive there.
 */
final class PossessiveUnderResourceLimitTest extends TestCase
{
    use PcreGates;

    /**
     * The rows that hold from a PCRE2 newer than the floor: up to 10.40 the
     * interpreter keeps its first frames on the stack, where the heap limit
     * does not count them.
     */
    private const NEWER_RELEASES = ['10.42' => [self::HEAP_LIMITED]];

    /**
     * Fifty groups no match runs make each frame of the interpreter large
     * enough for one frame more to cross the heap limit.
     */
    private const HEAP_LIMITED = '/(*LIMIT_HEAP=3)c(?:ab){2,}e|(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)(z)/';

    #[Test]
    #[DataProvider('provideRepeatedGroupsUnderALimit')]
    public function test_a_repeated_group_stays_greedy_under_a_limit_the_pattern_sets(string $pattern, string $possessive, string $subject): void
    {
        $this->requiresPcre2For($pattern, self::NEWER_RELEASES);

        $this->assertSame([[1, \PREG_NO_ERROR]], self::matchedWithoutTheJit([$pattern], [$subject]), \sprintf('Oracle: %s gives up on "%s", JIT off.', $pattern, $subject));
        [[$answer, $error]] = self::matchedWithoutTheJit([$possessive], [$subject]);
        $this->assertFalse($answer, \sprintf('Oracle: %s keeps within its limit on "%s", JIT off.', $possessive, $subject));
        $this->assertNotSame(\PREG_NO_ERROR, $error);

        $rewriter = new Rewriter(possessive: true);
        $this->assertSame($pattern, Regex::create(['cache' => null])->parse($pattern)->accept($rewriter)->accept(new PatternPrinter()));
        $this->assertNotContains(Rewriter::POSSESSIVE, $rewriter->changes(), 'The rewrite offers no possessive to refuse.');

        $this->assertSame($pattern, (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true))->optimized);
    }

    /**
     * @return iterable<string, array{pattern: string, possessive: string, subject: string}>
     */
    public static function provideRepeatedGroupsUnderALimit(): iterable
    {
        yield 'a match limit' => ['pattern' => '/(*LIMIT_MATCH=4)c(?:ab)+e/', 'possessive' => '/(*LIMIT_MATCH=4)c(?:ab)++e/', 'subject' => 'cababe'];
        yield 'a match limit, a caseless group' => ['pattern' => '/(*LIMIT_MATCH=4)c(?i:ab)+e/', 'possessive' => '/(*LIMIT_MATCH=4)c(?i:ab)++e/', 'subject' => 'cababe'];
        yield 'a depth limit' => ['pattern' => '/(*LIMIT_DEPTH=3)c(?:ab){2,}e/', 'possessive' => '/(*LIMIT_DEPTH=3)c(?:ab){2,}+e/', 'subject' => 'cababe'];
        yield 'a depth limit, its old name' => ['pattern' => '/(*LIMIT_RECURSION=3)c(?:ab){2,}e/', 'possessive' => '/(*LIMIT_RECURSION=3)c(?:ab){2,}+e/', 'subject' => 'cababe'];
        yield 'a heap limit' => ['pattern' => self::HEAP_LIMITED, 'possessive' => str_replace('{2,}', '{2,}+', self::HEAP_LIMITED), 'subject' => 'cababe'];
        yield 'a match limit after another option' => ['pattern' => '/(*UTF)(*LIMIT_MATCH=4)c(?:ab)+e/', 'possessive' => '/(*UTF)(*LIMIT_MATCH=4)c(?:ab)++e/', 'subject' => 'cababe'];
    }

    /**
     * The repeated character keeps within every limit the greedy one keeps
     * within, from one to twenty, on each subject: the oracle runs both
     * under each.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideRepeatedCharactersUnderALimit')]
    public function test_a_repeated_character_is_made_possessive_under_a_limit_the_pattern_sets(string $pattern, string $optimized, array $subjects): void
    {
        $limits = range(1, 20);
        $limited = static fn (string $written): array => array_map(static fn (int $limit): string => (string) preg_replace('/^(.\(\*LIMIT_[A-Z]+=)\d+/', '${1}'.$limit, $written), $limits);
        $answers = self::matchedWithoutTheJit([...$limited($pattern), ...$limited($optimized)], $subjects);
        $possessive = \count($limits) * \count($subjects);
        foreach (\array_slice($answers, 0, $possessive) as $index => $greedy) {
            if (false !== $greedy[0]) {
                $this->assertSame($greedy, $answers[$possessive + $index], \sprintf('Oracle: %s spends more than %s on "%s" under a limit of %d, JIT off.', $optimized, $pattern, $subjects[$index % \count($subjects)], $limits[intdiv($index, \count($subjects))]));
            }
        }

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: true));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame([Rewriter::POSSESSIVE], $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, subjects: list<string>}>
     */
    public static function provideRepeatedCharactersUnderALimit(): iterable
    {
        yield 'a match limit' => ['pattern' => '/(*LIMIT_MATCH=10)^[a-z]+(?:-[a-z]+)*$/', 'optimized' => '/(*LIMIT_MATCH=10)^[a-z]++(?:-[a-z]++)*$/', 'subjects' => ['ab', 'ab-cd-ef', 'ab-cd-e1', 'a-b-c-d-e', 'a--b', 'ab-']];
        yield 'a depth limit' => ['pattern' => '/(*LIMIT_DEPTH=10)(a+b?)c/', 'optimized' => '/(*LIMIT_DEPTH=10)(a++b?)c/', 'subjects' => ['ac', 'aaaac', 'aaabc', 'aaaa', 'aaab', 'baaaacaac']];
        yield 'a depth limit, its old name' => ['pattern' => '/(*LIMIT_RECURSION=10)(a+b?)c/', 'optimized' => '/(*LIMIT_RECURSION=10)(a++b?)c/', 'subjects' => ['ac', 'aaaac', 'aaabc', 'aaaa', 'aaab', 'baaaacaac']];
        yield 'a heap limit, the group kept greedy' => ['pattern' => '/(*LIMIT_HEAP=10)x\d+(?:ab)+e/', 'optimized' => '/(*LIMIT_HEAP=10)x\d++(?:ab)+e/', 'subjects' => ['x1abe', 'x123ababe', 'x123abab', 'x1ab1abe']];
    }

    /**
     * What preg_match() and preg_last_error() answer for each pattern on
     * each subject in a PHP of its own, the JIT off: the suite keeps
     * patterns compiled with the JIT, which counts the limits otherwise, and
     * a pattern compiled once is not compiled again when pcre.jit changes.
     *
     * @param list<string> $patterns
     * @param list<string> $subjects
     *
     * @return list<array{int|false|null, int|null}>
     */
    private static function matchedWithoutTheJit(array $patterns, array $subjects): array
    {
        $code = '$answers = []; foreach (json_decode($argv[1], true) as $pattern) { foreach (json_decode($argv[2], true) as $subject) { $answers[] = [@preg_match($pattern, $subject), preg_last_error()]; } } echo json_encode($answers);';
        $process = proc_open([\PHP_BINARY, '-n', '-d', 'pcre.jit=0', '-r', $code, '--', json_encode($patterns, \JSON_THROW_ON_ERROR), json_encode($subjects, \JSON_THROW_ON_ERROR)], ProcessPipes::descriptors(), $pipes);
        self::assertIsResource($process);
        [$output] = ProcessPipes::drain($pipes);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $answers = [];
        foreach ((array) json_decode($output, true) as $answer) {
            [$matched, $error] = \is_array($answer) ? $answer + [null, null] : [null, null];
            $answers[] = [\is_int($matched) || false === $matched ? $matched : null, \is_int($error) ? $error : null];
        }

        return $answers;
    }
}
