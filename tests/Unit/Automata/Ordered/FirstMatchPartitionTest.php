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

namespace PHPRegex\Tests\Unit\Automata\Ordered;

use PHPRegex\Automata\Exception\IncompatibleArgumentException;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Ordered\FirstMatchCandidate;
use PHPRegex\Automata\Ordered\FirstMatchCell;
use PHPRegex\Automata\Ordered\FirstMatchPartition;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Eff(i) = L(i) \ (L(1) ∪ ... ∪ L(i - 1)): what each candidate of a
 * first-match list keeps, the example checked against the engine.
 */
final class FirstMatchPartitionTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_several_earlier_candidates_may_shadow_one_none_of_them_alone(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^\/n\/\d+$/']]),
            new FirstMatchCandidate([['/^\/n\/[a-z]+$/']]),
            new FirstMatchCandidate([['/^\/n\/(?:\d+|[a-z]+)$/']]),
        ]);

        $this->assertTrue($cells[0]->reachable);
        $this->assertTrue($cells[1]->reachable);
        $this->assertFalse($cells[2]->reachable);
        $this->assertTrue($cells[2]->isShadowedCumulatively());
        $this->assertNull($cells[2]->shadowedBy);
        $this->assertSame([0 => ['/n/0'], 1 => ['/n/a']], $cells[2]->takenBy);
        $this->assertNull($cells[2]->example);
    }

    #[Test]
    public function test_a_candidate_one_earlier_one_shadows_is_not_cumulative(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^\/n\/\d+$/']]),
            new FirstMatchCandidate([['/^\/n\/.+$/']]),
            new FirstMatchCandidate([['/^\/n\/\d{2}$/']]),
        ]);

        $this->assertFalse($cells[2]->reachable);
        $this->assertSame(0, $cells[2]->shadowedBy, 'The first candidate takes every string alone.');
        $this->assertFalse($cells[2]->isShadowedCumulatively());
    }

    #[Test]
    public function test_the_example_is_the_least_shortest_string_kept(): void
    {
        $patterns = ['/^\/a\/\d+$/', '/^\/a\/[a-z]+$/', '/^\/a\/[a-z0-9]+$/'];
        $cells = (new FirstMatchPartition())->partition(array_map(static fn (string $pattern): FirstMatchCandidate => new FirstMatchCandidate([[$pattern]]), $patterns));

        $this->assertSame(['/a/0a'], $cells[2]->example);
        foreach ([0, 1] as $earlier) {
            $this->assertSame(0, preg_match($patterns[$earlier], '/a/0a'), 'No earlier candidate takes the example.');
        }

        $this->assertSame(1, preg_match($patterns[2], '/a/0a'));
    }

    #[Test]
    public function test_a_tuple_is_read_field_by_field(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^GET$/', '/^\/c\/[^\/]+$/']]),
            new FirstMatchCandidate([['/^POST$/', '/^\/c\/[^\/]+$/']]),
            new FirstMatchCandidate([['/^(?:GET|POST)$/', '/^\/c\/[^\/]+$/']]),
            new FirstMatchCandidate([['/^(?:GET|PUT)$/', '/^\/c\/[^\/]+$/']]),
        ]);

        $this->assertTrue($cells[2]->isShadowedCumulatively());
        $this->assertTrue($cells[3]->reachable);
        $this->assertSame(['PUT', "/c/\0"], $cells[3]->example, 'A method no earlier candidate takes.');
    }

    #[Test]
    public function test_a_candidate_takes_the_union_of_its_tuples(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^GET$/', '/^\/b$/'], ['/^GET$/', '/^\/b\/$/']]),
            new FirstMatchCandidate([['/^GET$/', '/^\/b\/?$/']]),
        ]);

        $this->assertFalse($cells[1]->reachable);
        $this->assertSame(0, $cells[1]->shadowedBy);
    }

    /**
     * A candidate takes from the later ones what it takes, and keeps what
     * it keeps: a route takes "/b" and "/b/", and keeps the one it matches
     * as is.
     */
    #[Test]
    public function test_a_candidate_keeps_its_own_tuples_and_takes_the_others(): void
    {
        $this->assertSame(1, preg_match('/^\/b\/?$/', '/b'));

        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^\/b\/?$/']], keeps: [['/^\/b\/$/']]),
            new FirstMatchCandidate([['/^\/b$/']]),
        ]);

        $this->assertTrue($cells[0]->reachable);
        $this->assertSame(['/b/'], $cells[0]->example, 'The least shortest string it keeps, not one it only takes.');
        $this->assertFalse($cells[1]->reachable);
        $this->assertSame(0, $cells[1]->shadowedBy);
    }

    #[Test]
    public function test_a_null_candidate_takes_nothing_and_keeps_nothing(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            null,
            new FirstMatchCandidate([['/^a$/']]),
            new FirstMatchCandidate([]),
        ]);

        $this->assertArrayNotHasKey(0, $cells);
        $this->assertTrue($cells[1]->reachable);
        $this->assertSame([], $cells[1]->takenBy);
        $this->assertFalse($cells[2]->reachable, 'A candidate of no tuple keeps nothing.');
        $this->assertFalse($cells[2]->isShadowedCumulatively(), 'Nothing shadows it: it takes nothing.');
    }

    #[Test]
    public function test_rivals_restrict_the_earlier_candidates_read(): void
    {
        $partition = new FirstMatchPartition();

        $this->assertFalse($partition->partition([new FirstMatchCandidate([['/^\/a$/']]), new FirstMatchCandidate([['/^\/a$/']])])[1]->reachable);
        $this->assertTrue($partition->partition([new FirstMatchCandidate([['/^\/a$/']]), new FirstMatchCandidate([['/^\/a$/']], rivals: [])])[1]->reachable, 'A candidate left out of the rivals, a route with a condition, shadows nothing.');
    }

    #[Test]
    public function test_a_field_read_in_another_encoding_is_left_unread(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^\/é$/u']]),
            new FirstMatchCandidate([['/^\/\xC3\xA9$/']]),
        ]);

        $this->assertSame([0], $cells[1]->unread);
        $this->assertTrue($cells[1]->reachable);
    }

    #[Test]
    public function test_an_example_is_spelled_in_the_encoding_of_its_field(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^GET$/u', '/^\/[é-ê]$/u']]),
            new FirstMatchCandidate([['/^GET$/u', '/^\/[é-ë]$/u']]),
        ]);

        $this->assertSame(['GET', '/ë'], $cells[1]->example);
    }

    #[Test]
    public function test_a_candidate_the_solver_gives_up_on_has_no_verdict(): void
    {
        $cells = (new FirstMatchPartition(options: new SolverOptions(maxNfaStates: 50)))->partition([
            new FirstMatchCandidate([['/^a{100}$/']]),
            new FirstMatchCandidate([['/^b$/']]),
        ]);

        $this->assertNull($cells[0]->reachable);
        $this->assertNull($cells[0]->example);
        $this->assertNotNull($cells[0]->refused);
        $this->assertFalse($cells[0]->isShadowedCumulatively());
        $this->assertTrue($cells[1]->reachable);
        $this->assertSame([], $cells[1]->takenBy, 'A candidate the solver gave up on shadows nothing.');
        $this->assertSame([], $cells[1]->unread, 'Nor is it left unread: it is no rival.');
    }

    #[Test]
    public function test_a_field_reads_utf8_when_one_of_its_tuples_does(): void
    {
        $this->assertSame(1, preg_match('/^é$/u', 'é'));
        $this->assertSame(1, preg_match('/^a$/', 'a'));

        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^é$/u']]),
            new FirstMatchCandidate([['/^é$/u'], ['/^a$/']]),
        ]);

        $this->assertSame([], $cells[1]->unread);
        $this->assertSame([0 => ['é']], $cells[1]->takenBy);
        $this->assertSame(['a'], $cells[1]->example);
    }

    #[Test]
    public function test_a_rival_left_unread_leaves_the_next_ones_read(): void
    {
        $this->assertSame(1, preg_match('/^\/[a-z]$/', '/a'));
        $this->assertSame(1, preg_match('/^\/[a-z]$/', '/b'));

        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^\/é$/u']]),
            new FirstMatchCandidate([['/^\/a$/']]),
            new FirstMatchCandidate([['/^\/[a-z]$/']]),
        ]);

        $this->assertSame([0], $cells[2]->unread);
        $this->assertSame([1 => ['/a']], $cells[2]->takenBy);
        $this->assertSame(['/b'], $cells[2]->example);
    }

    #[Test]
    public function test_the_first_earlier_candidate_that_takes_all_alone_is_named(): void
    {
        $this->assertSame(1, preg_match('/^\/[a-c]$/', '/b'));

        $cells = (new FirstMatchPartition())->partition([
            new FirstMatchCandidate([['/^\/[a-z]$/']]),
            new FirstMatchCandidate([['/^\/[a-c]$/']]),
            new FirstMatchCandidate([['/^\/b$/']]),
        ]);

        $this->assertFalse($cells[2]->reachable);
        $this->assertSame(0, $cells[2]->shadowedBy, 'Both take "/b" alone: the first is named.');
        $this->assertFalse($cells[2]->isShadowedCumulatively());
    }

    #[Test]
    public function test_a_tuple_of_no_field_is_refused(): void
    {
        $this->expectException(IncompatibleArgumentException::class);
        $this->expectExceptionMessageIsOrContains('at least one: 0 expected, 0 given.');
        (new FirstMatchPartition())->partition([new FirstMatchCandidate([[]])]);
    }

    #[Test]
    public function test_a_pattern_out_of_the_regular_subset_is_refused_not_thrown(): void
    {
        $cells = (new FirstMatchPartition())->partition([new FirstMatchCandidate([['/^(a)\1$/']]), new FirstMatchCandidate([['/^aa$/']])]);

        $this->assertInstanceOf(FirstMatchCell::class, $cells[0]);
        $this->assertNull($cells[0]->reachable);
        $this->assertNotNull($cells[0]->refused);
        $this->assertTrue($cells[1]->reachable);
    }

    #[Test]
    public function test_tuples_of_other_sizes_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FirstMatchPartition())->partition([new FirstMatchCandidate([['/^a$/', '/^b$/']]), new FirstMatchCandidate([['/^a$/']])]);
    }

    #[Test]
    public function test_a_long_first_match_list_stays_fast(): void
    {
        // Two hundred routes, each with a few earlier ones sharing a prefix,
        // and a last one the others shadow only together.
        $candidates = [];
        for ($i = 0; $i < 200; $i++) {
            $candidates[] = new FirstMatchCandidate([['/^(?:GET|HEAD)$/', '/^\/r'.$i.'\/[a-z]+$/']]);
        }

        $candidates[] = new FirstMatchCandidate([['/^(?:GET|HEAD)$/', '/^\/r1\d?\/[a-z]+$/']]);

        $start = hrtime(true);
        $cells = (new FirstMatchPartition())->partition($candidates);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertTrue($cells[200]->isShadowedCumulatively());
        $this->assertCount(11, $cells[200]->takenBy);
        $this->assertLessThan(30.0, $elapsed);
    }
}
