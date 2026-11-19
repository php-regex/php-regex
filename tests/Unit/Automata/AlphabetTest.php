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
use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Alphabet;
use PHPRegex\Automata\Model\DfaState;
use PHPRegex\Automata\Options\SolverOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The alphabet of an automaton, cut into ranges grouped into classes, is
 * held once for every state: a state keeps one target per class.
 */
final class AlphabetTest extends TestCase
{
    #[Test]
    public function test_numbers_the_classes_by_their_first_range(): void
    {
        // Classes 1, 0, 1, 2: "a", "c-e" and "x" read alike, "b" and "z" not.
        $alphabet = Alphabet::fromRanges([[97, 97], [98, 98], [99, 101], [120, 120], [122, 122]], [1, 0, 1, 1, 2]);

        $this->assertSame(5, $alphabet->rangeCount());
        $this->assertSame(3, $alphabet->classCount);
        $this->assertSame([98, 97, 122], $alphabet->representative);
        $this->assertSame(1, $alphabet->classOf(100));
        $this->assertSame(0, $alphabet->classOf(98));
        $this->assertSame(2, $alphabet->classOf(122));
    }

    #[Test]
    public function test_a_character_in_a_hole_or_outside_has_no_class(): void
    {
        $alphabet = Alphabet::fromRanges([[10, 20], [30, 40]], [0, 1]);

        $this->assertNull($alphabet->rangeOf(9));
        $this->assertNull($alphabet->classOf(25));
        $this->assertNull($alphabet->classOf(41));
        $this->assertSame(0, $alphabet->rangeOf(10));
        $this->assertSame(0, $alphabet->rangeOf(20));
        $this->assertSame(1, $alphabet->rangeOf(30));
        $this->assertSame(1, $alphabet->rangeOf(40));
    }

    #[Test]
    public function test_a_state_by_classes_reads_as_its_ranges(): void
    {
        $alphabet = Alphabet::fromRanges([[97, 97], [98, 98], [99, 101]], [0, 1, 0]);
        $state = new DfaState(0, [], false, [], $alphabet, [4, 7]);

        $this->assertSame(3, $state->rangeCount());
        $this->assertSame([[97, 97, 4], [98, 98, 7], [99, 101, 4]], $state->ranges());
        $this->assertSame([97, 97, 4, 98, 98, 7, 99, 101, 4], $state->packedRanges());
        $this->assertSame(4, $state->transitionFor(100));
        $this->assertSame(7, $state->transitionFor(98));
        $this->assertNull($state->transitionFor(102));
        $this->assertSame($alphabet, $state->alphabet());
        $this->assertSame([4, 7], $state->classTargets());
    }

    /**
     * Every state of a determinized Unicode pattern shares one alphabet,
     * and a handful of class targets stand for over a thousand ranges.
     */
    #[Test]
    public function test_the_states_of_a_unicode_automaton_share_one_alphabet(): void
    {
        $pattern = '/^[\p{L}\p{N}]{3,64}$/u';
        foreach (['abc' => 1, 'été' => 1, 'ab1' => 1, 'ab' => 0, 'a_b' => 0, str_repeat('x', 64) => 1, str_repeat('x', 65) => 0] as $subject => $matched) {
            $this->assertSame($matched, preg_match($pattern, (string) $subject), (string) $subject);
        }

        $dfa = (new LanguageSolver())->compile($pattern);

        $this->assertInstanceOf(Alphabet::class, $dfa->alphabet);
        $this->assertGreaterThan(1_000, $dfa->alphabet->rangeCount());
        $this->assertLessThan(10, $dfa->alphabet->classCount);
        foreach ($dfa->states as $state) {
            $this->assertSame($dfa->alphabet, $state->alphabet());
            $this->assertCount($dfa->alphabet->classCount, $state->classTargets());
        }
    }

    /**
     * The witness found by classes is the one found range by range: the
     * product walks the classes in the order of their first range, and
     * takes each class's smallest character. PCRE reads it as the question
     * says: both patterns match an example of the intersection, the left
     * one alone a counterexample to the inclusion.
     */
    #[Test]
    #[DataProvider('providePairs')]
    public function test_the_witness_is_the_one_found_range_by_range(string $left, string $right, string $method): void
    {
        $witness = self::witness($left, $right, $method, DeterminizationAlgorithm::SubsetIndexed);
        $this->assertNotNull($witness);
        $this->assertSame('subsetOf' === $method ? [1, 0] : [1, 1], [preg_match($left, $witness), preg_match($right, $witness)], (string) json_encode($witness));

        $this->assertSame(self::witness($left, $right, $method, DeterminizationAlgorithm::Subset), self::witness($left, $right, $method, DeterminizationAlgorithm::SubsetIndexed));
        $this->assertNotNull(self::witness($left, $right, $method, DeterminizationAlgorithm::SubsetIndexed));
    }

    /**
     * @return iterable<string, array{left: string, right: string, method: string}>
     */
    public static function providePairs(): iterable
    {
        yield 'unicode letters beside words' => ['left' => '/^\p{L}{2}\d$/u', 'right' => '/^\w+$/u', 'method' => 'intersection'];
        yield 'letters not words' => ['left' => '/^[\p{L}\p{N}]{3}$/u', 'right' => '/^[a-z]{3}$/u', 'method' => 'subsetOf'];
        yield 'dates' => ['left' => '/^\d{4}-\d{2}$/', 'right' => '/^[0-9]{4}-(?:0[1-9]|1[0-2])$/', 'method' => 'subsetOf'];
        yield 'identifiers' => ['left' => '/^[A-Za-z_]\w*$/', 'right' => '/^[a-z_][a-z0-9_]*$/i', 'method' => 'intersection'];
    }

    private static function witness(string $left, string $right, string $method, DeterminizationAlgorithm $algorithm): ?string
    {
        $solver = new LanguageSolver();
        $options = new SolverOptions(determinizationAlgorithm: $algorithm);

        return 'subsetOf' === $method
            ? $solver->subsetOf($left, $right, $options)->counterExample
            : $solver->intersection($left, $right, $options)->example;
    }
}
