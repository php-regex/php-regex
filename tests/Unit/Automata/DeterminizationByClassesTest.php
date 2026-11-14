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

use PHPRegex\Automata\Determinization\DeterminizationAlgorithm;
use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Unicode class cuts the alphabet into some two thousand ranges, and the
 * characters of most ranges move every state alike: the determinization
 * reads the NFA's moves once for each class of such characters, not once
 * for each range, and writes the same automaton.
 */
final class DeterminizationByClassesTest extends TestCase
{
    /**
     * Four lookaheads of Unicode classes before a run of twelve or sixteen
     * characters: read range by range, they ran out of the default budget.
     */
    #[Test]
    #[DataProvider('provideLongUnicodePasswordRules')]
    public function test_a_unicode_password_rule_is_answered_within_the_default_budget(string $pattern): void
    {
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));

        foreach (self::passwords() as $subject) {
            $this->assertSame(1 === preg_match($pattern, $subject), self::accepts($dfa, $subject), \sprintf('%s on %s.', $pattern, json_encode($subject, \JSON_UNESCAPED_UNICODE)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLongUnicodePasswordRules(): iterable
    {
        yield 'twelve characters' => ['pattern' => '/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*\p{P}).{12,}$/u'];
        yield 'sixteen characters' => ['pattern' => '/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*\p{P}).{16,}$/u'];
    }

    /**
     * Read by classes, the states still count against maxDfaStates, the
     * first one past it refused while the determinization reads.
     */
    #[Test]
    public function test_a_state_past_the_limit_is_refused_while_the_classes_are_read(): void
    {
        $pattern = '/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*\p{P}).{8,}$/u';

        try {
            (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial, maxDfaStates: 40));
            $this->fail('Forty states held the automaton.');
        } catch (ComplexityException $e) {
            $this->assertSame('DFA state limit exceeded (40).', $e->getMessage());
            $this->assertSame(['phase' => 'determinization', 'limit' => 40], $e->getDiagnostic());
        }
    }

    /**
     * Read by classes or range by range, the automaton is the same: the
     * same states, reached the same way, accepting alike.
     */
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_indexed_construction_writes_the_automaton_the_plain_one_writes(string $pattern): void
    {
        $options = static fn (DeterminizationAlgorithm $algorithm): SolverOptions => new SolverOptions(matchMode: MatchMode::Partial, determinizationAlgorithm: $algorithm, maxTransitionsProcessed: 50_000_000);

        $plain = (new LanguageSolver())->compile($pattern, $options(DeterminizationAlgorithm::Subset));
        $indexed = (new LanguageSolver())->compile($pattern, $options(DeterminizationAlgorithm::SubsetIndexed));

        $this->assertSame($plain->alphabetRanges, $indexed->alphabetRanges);
        $this->assertSame(self::canonical($plain), self::canonical($indexed));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'a Unicode password rule' => ['pattern' => '/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*\p{P}).{8,}$/u'];
        yield 'a byte password rule' => ['pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{8,}$/'];
        yield 'overlapping classes' => ['pattern' => '/^(?:\p{L}+|\p{Lu}\d|[a-zé])*\p{N}$/u'];
        yield 'a class and its complement' => ['pattern' => '/^[^\p{Greek}]*\p{Greek}{2}/u'];
        yield 'bytes past ASCII' => ['pattern' => '/^[\x80-\xBF]+[\xC0-\xFF]?a$/'];
        yield 'a dot and a newline' => ['pattern' => '/^.*\n.?$/s'];
        yield 'nothing at all' => ['pattern' => '/[^\s\S]/'];
        yield 'literals' => ['pattern' => '/^(?:abc|abd|b)+$/'];
    }

    /**
     * The automaton with its states numbered in the order a walk from the
     * start meets them, each move read on the first character of each
     * range: two automata that differ only in their numbering are equal.
     *
     * @return list<array{bool, list<int>}>
     */
    private static function canonical(Dfa $dfa): array
    {
        $number = [$dfa->startState => 0];
        $queue = [$dfa->startState];
        $states = [];
        for ($at = 0; $at < \count($queue); $at++) {
            $state = $dfa->getState($queue[$at]);
            $moves = [];
            foreach ($dfa->alphabetRanges as [$first]) {
                $target = $state->transitionFor($first);
                if (null !== $target && !isset($number[$target])) {
                    $number[$target] = \count($queue);
                    $queue[] = $target;
                }
                $moves[] = null === $target ? -1 : $number[$target];
            }
            $states[] = [$state->isAccepting, $moves];
        }

        return $states;
    }

    private static function accepts(Dfa $dfa, string $subject): bool
    {
        $state = $dfa->startState;
        foreach (preg_split('//u', $subject, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $next = $dfa->getState($state)->transitionFor(mb_ord($character, 'UTF-8'));
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
    private static function passwords(): array
    {
        return [
            '', 'Abcdef1!', 'Abcdefgh1!', 'Abcdefghij1!', 'Abcdefghijklmn1!', 'abcdefghijkl1!', 'ABCDEFGHIJKL1!', 'Abcdefghijklm!', 'Abcdefghijkl1x',
            "Éabcdefghij\u{661}¡", 'ΑβγδεζηθικλΜ1!', "Abcdefghijk1!\n", 'Abc defghijk1!', 'Ab1!Ab1!Ab1!Ab1!', 'Ab1!Ab1!Ab1',
        ];
    }
}
