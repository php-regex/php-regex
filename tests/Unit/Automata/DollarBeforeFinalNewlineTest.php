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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without /D, "$" and "\Z" also match before a newline that ends the
 * subject: preg_match('/^ab$/', "ab\n") is 1, where "\z" says 0. A search
 * (partial match mode) sees that newline; a full match, which must cover the
 * whole subject, does not.
 */
final class DollarBeforeFinalNewlineTest extends TestCase
{
    /**
     * @param list<string> $members
     * @param list<string> $outsiders
     */
    #[Test]
    #[DataProvider('provideEndAnchors')]
    public function test_a_search_matches_before_a_final_newline_as_the_engine_does(string $pattern, array $members, array $outsiders): void
    {
        $solver = new LanguageSolver();
        $dfa = $solver->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));

        foreach ($members as $subject) {
            $this->assertSame(1, preg_match($pattern, $subject), \sprintf('%s must match %s.', $pattern, json_encode($subject)));
            $this->assertTrue(self::accepts($dfa, $subject), \sprintf('The solver must accept %s for %s.', json_encode($subject), $pattern));
        }

        foreach ($outsiders as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject), \sprintf('%s must not match %s.', $pattern, json_encode($subject)));
            $this->assertFalse(self::accepts($dfa, $subject), \sprintf('The solver must refuse %s for %s.', json_encode($subject), $pattern));
        }
    }

    #[Test]
    public function test_dollar_and_end_of_subject_are_not_equivalent_in_a_search(): void
    {
        $result = (new LanguageSolver())->equivalent('/^ab$/', '/^ab\z/', new SolverOptions(matchMode: MatchMode::Partial));

        $this->assertFalse($result->isEquivalent);
        $this->assertSame("ab\n", $result->leftOnlyExample);
        $this->assertSame(1, preg_match('/^ab$/', "ab\n"));
        $this->assertSame(0, preg_match('/^ab\z/', "ab\n"));
    }

    #[Test]
    public function test_dollar_is_the_end_of_subject_in_a_full_match(): void
    {
        $this->assertTrue((new LanguageSolver())->equivalent('/^ab$/', '/^ab\z/')->isEquivalent);
    }

    #[Test]
    public function test_alternatives_ending_on_different_anchors_are_read_in_a_search(): void
    {
        // Oracle: "/a$|b\z/" takes "a\n" and "xb", not "b\n".
        $this->assertSame(1, preg_match('/a$|b\z/', "a\n"));
        $this->assertSame(0, preg_match('/a$|b\z/', "b\n"));

        $options = new SolverOptions(matchMode: MatchMode::Partial);
        $this->assertTrue((new LanguageSolver())->equivalent('/a$|b\z/', '/(?:a\n?|b)\z/', $options)->isEquivalent);
    }

    /**
     * @return iterable<string, array{pattern: string, members: list<string>, outsiders: list<string>}>
     */
    public static function provideEndAnchors(): iterable
    {
        yield 'dollar' => ['pattern' => '/ab$/', 'members' => ['ab', "xab\n"], 'outsiders' => ["ab\n\n", "ab\nx"]];
        yield 'capital Z' => ['pattern' => '/ab\Z/', 'members' => ['ab', "xab\n"], 'outsiders' => ["ab\n\n"]];
        yield 'dollar under D' => ['pattern' => '/ab$/D', 'members' => ['ab', 'xab'], 'outsiders' => ["ab\n"]];
        yield 'lower z' => ['pattern' => '/ab\z/', 'members' => ['xab'], 'outsiders' => ["ab\n"]];
        yield 'every branch' => ['pattern' => '/a$|b$/', 'members' => ["a\n", 'xb'], 'outsiders' => ["a\n\n"]];
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
}
