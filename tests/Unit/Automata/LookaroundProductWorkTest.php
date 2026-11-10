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
 * The product of a pattern's automaton with its lookarounds reads each class
 * of characters once, not each range of the alphabet, and counts every move
 * against the work budget: a pattern of many lookaheads is refused after a
 * bounded, counted amount of work, and the rules it answers keep their
 * answers.
 */
final class LookaroundProductWorkTest extends TestCase
{
    /**
     * Each pattern outgrows the NFA state limit. The budget given is one the
     * work counted before the refusal fits in: the states are what stops
     * the pattern, and nothing uncounted comes before them.
     */
    #[Test]
    #[DataProvider('provideManyLookaheads')]
    public function test_many_lookaheads_reach_the_state_limit_within_a_counted_amount_of_work(string $pattern, string $subject, int $budget): void
    {
        $this->assertSame(1, preg_match($pattern, $subject));

        $refusal = self::refusal($pattern, $budget);
        $this->assertSame('NFA state limit exceeded (5000).', $refusal->getMessage());
        $this->assertSame(['phase' => 'nfa construction', 'limit' => 5000], $refusal->getDiagnostic());
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, budget: int}>
     */
    public static function provideManyLookaheads(): iterable
    {
        yield 'twelve Unicode classes' => ['pattern' => self::twelveUnicodeClasses(), 'subject' => "Aa1!+ \u{3B1}\u{436}\u{4E2D}\u{639}\u{5D0}\u{E01}", 'budget' => 200_000];
        yield 'forty doubled characters' => ['pattern' => self::fortyDoubledCharacters(), 'subject' => implode('', array_map(static fn (string $character): string => $character.$character, self::fortyCharacters())), 'budget' => 60_000];
    }

    /**
     * Twelve one-letter lookaheads cost little to compile, 10,368 units, and
     * much to combine: 4,096 sets of pending promises. A budget their bodies
     * fit in stops the product one unit past it, before the state limit that
     * a larger budget lets it reach.
     */
    #[Test]
    public function test_a_small_budget_stops_the_product_one_unit_past_it(): void
    {
        $pattern = '/^(?=.*a)(?=.*b)(?=.*c)(?=.*d)(?=.*e)(?=.*f)(?=.*g)(?=.*h)(?=.*i)(?=.*j)(?=.*k)(?=.*l).*$/';
        $this->assertSame(1, preg_match($pattern, 'lkjihgfedcba'));
        $this->assertSame(0, preg_match($pattern, 'lkjihgfedcb'));

        $refusal = self::refusal($pattern, 12_000);
        $this->assertSame('Automata work budget exceeded during lookaround product (limit 12000, consumed 12001).', $refusal->getMessage());
        // One transition, ".*", read on thirteen classes: a to l, and the rest.
        $this->assertSame(['phase' => 'lookaround product', 'states' => 3902, 'transitions' => 1, 'alphabet' => 13, 'consumed' => 12_001, 'limit' => 12_000], $refusal->getDiagnostic());

        $this->assertSame(['phase' => 'nfa construction', 'limit' => 5000], self::refusal($pattern, 14_000)->getDiagnostic());
    }

    /**
     * /^(?=a)[ab]{40}$/u counts 128 units: 5 to split the alphabet, "a" in
     * two sets and "b" in one, then the two stretches kept; 43 steps that
     * read nothing; 40 transitions read on two classes each. A budget of 127
     * stops at the last move, one of 128 lets the determinization start.
     */
    #[Test]
    public function test_each_move_of_the_product_is_one_unit(): void
    {
        $pattern = '/^(?=a)[ab]{40}$/u';
        $this->assertSame(1, preg_match($pattern, 'a'.str_repeat('b', 39)));
        $this->assertSame(0, preg_match($pattern, str_repeat('b', 40)));

        $this->assertSame(['phase' => 'lookaround product', 'states' => 83, 'transitions' => 40, 'alphabet' => 2, 'consumed' => 128, 'limit' => 127], self::refusal($pattern, 127)->getDiagnostic());
        $this->assertSame('determinize', self::refusal($pattern, 128)->getDiagnostic()['phase'] ?? null);

        $this->assertCount(42, (new LanguageSolver())->compile($pattern, new SolverOptions(maxTransitionsProcessed: 2000))->states);
    }

    /**
     * The alphabet is split before the first move, and the split is counted:
     * 351 units for the stretches the twenty-six sets cover, "a" alone and
     * "a" to each letter after it, and 26 for the stretches kept.
     */
    #[Test]
    public function test_splitting_the_alphabet_is_counted_before_the_first_move(): void
    {
        $pattern = '/(?=a)'.implode('', array_map(static fn (string $last): string => '[a-'.$last.']', range('b', 'z'))).'/u';
        $this->assertSame(1, preg_match($pattern, 'abcdefghijklmnopqrstuvwxy'));
        $this->assertSame(0, preg_match($pattern, 'bbcdefghijklmnopqrstuvwxy'));

        // No state is built yet, and the classes are not known.
        $this->assertSame(['phase' => 'lookaround product', 'states' => 0, 'transitions' => 25, 'alphabet' => 0, 'consumed' => 377, 'limit' => 376], self::refusal($pattern, 376)->getDiagnostic());
        // The first move is the 378th unit: one state, twenty-six classes.
        $this->assertSame(['phase' => 'lookaround product', 'states' => 1, 'transitions' => 25, 'alphabet' => 26, 'consumed' => 378, 'limit' => 377], self::refusal($pattern, 377)->getDiagnostic());

        $this->assertCount(27, (new LanguageSolver())->compile($pattern)->states);
    }

    /**
     * The promises pending are a set: one made again while it is pending,
     * or two made in either order, lead to one state of the product, and a
     * negative one whose run is over is dropped from it. Each pattern builds
     * the states and counts the units below, and no more.
     *
     * @param array{phase: string, states: int, transitions: int, alphabet: int, consumed: int, limit: int} $lastMove
     */
    #[Test]
    #[DataProvider('providePendingPromises')]
    public function test_pending_promises_are_one_set(string $pattern, string $matched, string $refused, array $lastMove): void
    {
        $this->assertSame(1, preg_match($pattern, $matched));
        $this->assertSame(0, preg_match($pattern, $refused));

        $this->assertSame($lastMove, self::refusal($pattern, $lastMove['limit'])->getDiagnostic());
        $this->assertSame('determinize', self::refusal($pattern, $lastMove['consumed'])->getDiagnostic()['phase'] ?? null);

        $dfa = (new LanguageSolver())->compile($pattern);
        $this->assertTrue(self::accepts($dfa, $matched, true));
        $this->assertFalse(self::accepts($dfa, $refused, true));
    }

    /**
     * @return iterable<string, array{pattern: string, matched: string, refused: string, lastMove: array{phase: string, states: int, transitions: int, alphabet: int, consumed: int, limit: int}}>
     */
    public static function providePendingPromises(): iterable
    {
        yield 'made again at each turn of a loop' => [
            'pattern' => '/^(?:(?=.*b)[ab]){1,40}$/u',
            'matched' => 'abab',
            'refused' => 'abaa',
            'lastMove' => ['phase' => 'lookaround product', 'states' => 242, 'transitions' => 40, 'alphabet' => 3, 'consumed' => 373, 'limit' => 372],
        ];
        yield 'made in either order' => [
            'pattern' => '/^(?:(?=.*a)x|(?=.*b)y){1,12}[ab]{2}$/su',
            'matched' => 'yxab',
            'refused' => 'yxbb',
            'lastMove' => ['phase' => 'lookaround product', 'states' => 288, 'transitions' => 26, 'alphabet' => 5, 'consumed' => 378, 'limit' => 377],
        ];
        yield 'a negative one forgotten once it cannot be broken' => [
            'pattern' => '/^(?:(?!b)[ab])*[ab]{30}$/u',
            'matched' => 'a'.str_repeat('b', 30),
            'refused' => str_repeat('b', 31),
            'lastMove' => ['phase' => 'lookaround product', 'states' => 67, 'transitions' => 31, 'alphabet' => 2, 'consumed' => 105, 'limit' => 104],
        ];
    }

    /**
     * A lookahead that fails where it stands leads nowhere: a negative one
     * whose body matches nothing at all, a positive one whose body cannot
     * match. preg_match() finds no subject either.
     */
    #[Test]
    #[DataProvider('provideLookaheadsFailingAtOnce')]
    public function test_a_lookahead_that_fails_where_it_stands_matches_nothing(string $pattern): void
    {
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));

        foreach (['', 'a', 'b', 'ab', 'abb', 'aab', 'acb', 'ba'] as $subject) {
            $this->assertSame(0, preg_match($pattern, $subject));
            $this->assertFalse(self::accepts($dfa, $subject, false), \sprintf('%s on %s.', $pattern, json_encode($subject)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLookaheadsFailingAtOnce(): iterable
    {
        yield 'negative, of a body that matches the empty string' => ['pattern' => '/a(?!b*)b/'];
        yield 'positive, of a body that matches nothing' => ['pattern' => '/a(?=[^\s\S])/'];
    }

    /**
     * A password rule of four or six lookaheads is answered as before:
     * preg_match() agrees on each subject.
     */
    #[Test]
    #[DataProvider('providePasswordRules')]
    public function test_a_password_rule_keeps_its_answer(string $pattern, int $states): void
    {
        $dfa = (new LanguageSolver())->compile($pattern, new SolverOptions(matchMode: MatchMode::Partial));
        $unicode = str_ends_with($pattern, 'u');

        $this->assertCount($states, $dfa->states);
        foreach (self::passwords() as $subject) {
            $this->assertSame(1 === preg_match($pattern, $subject), self::accepts($dfa, $subject, $unicode), \sprintf('%s on %s.', $pattern, json_encode($subject, \JSON_UNESCAPED_UNICODE)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, states: int}>
     */
    public static function providePasswordRules(): iterable
    {
        yield 'four lookaheads' => ['pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{8,}$/', 'states' => 85];
        yield 'six lookaheads' => ['pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d])(?=.*[!@#$%])(?!.*\s).{8,}$/', 'states' => 81];
        yield 'four lookaheads over code points' => ['pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{8,}$/u', 'states' => 85];
        yield 'six lookaheads over code points' => ['pattern' => '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d])(?=.*[!@#$%])(?!.*\s).{8,}$/u', 'states' => 81];
        yield 'four lookaheads of Unicode classes' => ['pattern' => '/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*\p{P}).{8,}$/u', 'states' => 82];
    }

    /**
     * @return list<string>
     */
    private static function passwords(): array
    {
        return [
            '', 'a', 'abcdefgh', 'ABCDEFGH', '12345678', 'Abcdefg1', 'Abcdef1!', 'Abcde1!', 'Abcdef1!x', 'Abc def1!', "Abcdef1!\n", "Abcd\nef1!",
            'aB3$aB3$', 'aB3_aB3_', 'AB3$AB3$', 'ab3$ab3$', 'aB$$aB$$', 'aB3$', 'Passw0rd', 'Passw0rd!', 'P@ssw0rd', 'pa55w0rd#', '#########', 'aaaaaaA1#',
            'Éa1!aaaa', 'éA1!AAAA', "Aa\u{661}!aaaa", 'Aa1¡aaaa', 'Aa1 aaaa', "Éé\u{661}¡ΑΒΓΔ", 'αβγδεζηθ', 'ΑβγδεζηΘ1!',
        ];
    }

    private static function refusal(string $pattern, int $budget): ComplexityException
    {
        try {
            (new LanguageSolver())->compile($pattern, new SolverOptions(maxTransitionsProcessed: $budget));
        } catch (ComplexityException $e) {
            return $e;
        }

        self::fail(\sprintf('%s fit a budget of %d units.', $pattern, $budget));
    }

    private static function accepts(Dfa $dfa, string $subject, bool $unicode): bool
    {
        $characters = $unicode ? (preg_split('//u', $subject, -1, \PREG_SPLIT_NO_EMPTY) ?: []) : ('' === $subject ? [] : str_split($subject));
        $state = $dfa->startState;
        foreach ($characters as $character) {
            $next = $dfa->getState($state)->transitionFor($unicode ? mb_ord($character, 'UTF-8') : \ord($character));
            if (null === $next) {
                return false;
            }
            $state = $next;
        }

        return $dfa->getState($state)->isAccepting;
    }

    private static function twelveUnicodeClasses(): string
    {
        $lookaheads = array_map(static fn (string $class): string => '(?=.*\p{'.$class.'})', ['Lu', 'Ll', 'N', 'P', 'S', 'Zs', 'Greek', 'Cyrillic', 'Han', 'Arabic', 'Hebrew', 'Thai']);

        return '/^'.implode('', $lookaheads).'.{12,}$/u';
    }

    private static function fortyDoubledCharacters(): string
    {
        $lookaheads = array_map(static fn (string $character): string => '(?=.*'.$character.'{2})', self::fortyCharacters());

        return '/^'.implode('', $lookaheads).'.*$/';
    }

    /**
     * @return list<string>
     */
    private static function fortyCharacters(): array
    {
        return [...range('a', 'z'), ...array_map(strval(...), range(0, 9)), 'A', 'B', 'C', 'D'];
    }
}
