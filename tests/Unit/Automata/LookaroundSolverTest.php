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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Lookarounds keep a language regular: a lookahead is a promise about what
 * follows, a lookbehind a question about what came before. The automaton of
 * a pattern holding them must accept exactly the subjects preg_match()
 * matches; each pattern below is checked on every string over its letters
 * up to a length.
 */
final class LookaroundSolverTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_automaton_accepts_what_the_engine_matches(string $pattern, string $letters, int $length): void
    {
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));

        foreach (self::subjects($letters, $length) as $subject) {
            $this->assertSame(1 === preg_match($pattern, $subject), self::accepts($dfa, $subject), \sprintf('%s on %s.', $pattern, json_encode($subject)));
        }
    }

    #[Test]
    public function test_a_password_rule_is_contained_in_its_length_rule(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue($solver->subsetOf('/^(?=.*\d)(?=.*[a-z]).{8,}$/', '/^.{8,}$/')->isSubset);

        $result = $solver->subsetOf('/^.{8,}$/', '/^(?=.*\d)(?=.*[a-z]).{8,}$/');
        $this->assertFalse($result->isSubset);
        $this->assertNotNull($result->counterExample);
        $this->assertSame(1, preg_match('/^.{8,}$/', $result->counterExample));
        $this->assertSame(0, preg_match('/^(?=.*\d)(?=.*[a-z]).{8,}$/', $result->counterExample));
    }

    #[Test]
    public function test_two_spellings_of_one_rule_are_equivalent(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue($solver->equivalent('/^(?=.*\d)(?=.*[a-z]).{3,}$/', '/^(?=.*[a-z])(?=.*\d).{3,}$/')->isEquivalent);
        $this->assertTrue($solver->equivalent('/^a(?!b)./', '/^a[^b\n]/')->isEquivalent);
        $this->assertTrue($solver->equivalent('/a(?=b)c/', '/[^\s\S]/')->isEquivalent);
    }

    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_a_lookaround_the_solver_cannot_read_is_refused(string $pattern, string $reason): void
    {
        $this->expectException(ComplexityException::class);
        $this->expectExceptionMessage($reason);

        (new LanguageSolver())->compile($pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, letters: string, length: int}>
     */
    public static function providePatterns(): iterable
    {
        yield 'password rule' => ['pattern' => '/^(?=.*\d)(?=.*[a-z]).{4,}$/', 'letters' => "a1A\n", 'length' => 5];
        yield 'negative lookahead' => ['pattern' => '/^a(?!b)./', 'letters' => 'abc', 'length' => 4];
        yield 'lookahead at the end' => ['pattern' => '/a(?=b)/', 'letters' => 'ab', 'length' => 5];
        yield 'lookbehind' => ['pattern' => '/(?<=ab)c/', 'letters' => 'abc', 'length' => 5];
        yield 'negative lookbehind' => ['pattern' => '/(?<!ab)c/', 'letters' => 'abc', 'length' => 5];
        yield 'between two digits' => ['pattern' => '/(?<=\d)x(?=\d)/', 'letters' => '1x', 'length' => 5];
        yield 'nothing may contain a pair' => ['pattern' => '/^(?!.*ab).*$/', 'letters' => "ab\n", 'length' => 5];
        yield 'capture inside a lookahead' => ['pattern' => '/x(?=(a+))a/', 'letters' => 'xa', 'length' => 5];
        yield 'lookahead in a loop' => ['pattern' => '/^(?:(?=a)\w)+$/', 'letters' => 'ab', 'length' => 5];
        yield 'contradiction' => ['pattern' => '/a(?=b)c/', 'letters' => 'abc', 'length' => 4];
        yield 'lookbehind of two lengths' => ['pattern' => '/(?<=a|bb)c/', 'letters' => 'abc', 'length' => 5];
        yield 'caseless body' => ['pattern' => '/(?=A)a/i', 'letters' => 'aA', 'length' => 3];
    }

    /**
     * @return iterable<string, array{pattern: string, reason: string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'nested lookaround' => ['pattern' => '/(?=a(?=b))/', 'reason' => 'A lookaround inside a lookaround'];
        yield 'anchor inside a lookaround' => ['pattern' => '/(?=a$)/', 'reason' => 'An anchor inside a lookaround'];
        yield 'non-atomic lookahead' => ['pattern' => '/(*napla:a)a/', 'reason' => 'non-atomic'];
    }

    private static function accepts(Dfa $dfa, string $subject): bool
    {
        $state = $dfa->startState;
        foreach (str_split($subject) as $byte) {
            $next = $dfa->getState($state)->transitionFor(\ord($byte));
            if (null === $next) {
                return false;
            }
            $state = $next;
        }

        return $dfa->getState($state)->isAccepting;
    }

    /**
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
