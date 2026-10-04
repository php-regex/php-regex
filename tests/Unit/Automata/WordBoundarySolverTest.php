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
 * A word boundary is two lookarounds in disguise: \b holds between a word
 * character and something else, (?<=\w)(?!\w)|(?<!\w)(?=\w), and \B where
 * it does not. Word characters are the engine's: "é" is one under /u and two
 * bytes that are not without it.
 */
final class WordBoundarySolverTest extends TestCase
{
    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_automaton_accepts_what_the_engine_matches(string $pattern, array $subjects): void
    {
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));
        $unicode = str_ends_with($pattern, 'u');

        foreach ($subjects as $subject) {
            $this->assertSame(1 === preg_match($pattern, $subject), self::accepts($dfa, $subject, $unicode), \sprintf('%s on %s.', $pattern, json_encode($subject)));
        }
    }

    #[Test]
    public function test_a_word_boundary_is_its_lookarounds(): void
    {
        $solver = new LanguageSolver();

        $this->assertTrue($solver->equivalent('/\bfoo\b/', '/(?<!\w)foo(?!\w)/', new SolverOptions(matchMode: MatchMode::Partial))->isEquivalent);
        $this->assertTrue($solver->equivalent('/\Ba/', '/(?<=\w)a/', new SolverOptions(matchMode: MatchMode::Partial))->isEquivalent);
        $this->assertFalse($solver->equivalent('/\bé/u', '/\bé/', new SolverOptions(matchMode: MatchMode::Partial))->isEquivalent);
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: list<string>}>
     */
    public static function providePatterns(): iterable
    {
        $words = ['', 'foo', 'foobar', 'a foo b', 'xfoo', 'foo-', '-foo-', 'ab', 'a-', 'a', '_a', 'a_'];

        yield 'whole word' => ['pattern' => '/\bfoo\b/', 'subjects' => $words];
        yield 'inside a word' => ['pattern' => '/\Ba/', 'subjects' => $words];
        yield 'word end' => ['pattern' => '/a\b/', 'subjects' => $words];
        yield 'a boundary alone' => ['pattern' => '/\b/', 'subjects' => [...$words, ' ', '--']];
        yield 'not a boundary alone' => ['pattern' => '/\B/', 'subjects' => [...$words, ' ', '--']];
        yield 'every word' => ['pattern' => '/^(?:\b\w+\b\W*)+$/', 'subjects' => [...$words, 'a b', 'a  b ']];
        yield 'accented letter, bytes' => ['pattern' => '/\bé/', 'subjects' => ['é', 'xé', ' é', 'éé']];
        yield 'accented letter, utf mode' => ['pattern' => '/\bé/u', 'subjects' => ['é', 'xé', ' é', 'éé']];
    }

    private static function accepts(Dfa $dfa, string $subject, bool $unicode): bool
    {
        $state = $dfa->startState;
        foreach ($unicode ? mb_str_split($subject) : str_split($subject) as $character) {
            $next = $dfa->getState($state)->transitionFor($unicode ? mb_ord($character) : \ord($character));
            if (null === $next) {
                return false;
            }
            $state = $next;
        }

        return $dfa->getState($state)->isAccepting;
    }
}
