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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Ordered\FirstMatchCell;
use PHPRegex\Automata\Ordered\FirstMatchPartition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Eff(i) = L(i) \ (L(1) ∪ ... ∪ L(i - 1)): what each candidate of a
 * first-match list keeps, the witness checked against the engine.
 */
final class FirstMatchPartitionTest extends TestCase
{
    #[Test]
    public function test_several_earlier_candidates_may_shadow_one_none_of_them_alone(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            [['/^\/n\/\d+$/']],
            [['/^\/n\/[a-z]+$/']],
            [['/^\/n\/(?:\d+|[a-z]+)$/']],
        ]);

        $this->assertTrue($cells[0]->reachable);
        $this->assertTrue($cells[1]->reachable);
        $this->assertFalse($cells[2]->reachable);
        $this->assertTrue($cells[2]->isShadowedCumulatively());
        $this->assertNull($cells[2]->shadowedBy);
        $this->assertSame([0 => ['/n/0'], 1 => ['/n/a']], $cells[2]->takenBy);
        $this->assertNull($cells[2]->witness);
    }

    #[Test]
    public function test_a_candidate_one_earlier_one_shadows_is_not_cumulative(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            [['/^\/n\/\d+$/']],
            [['/^\/n\/.+$/']],
            [['/^\/n\/\d{2}$/']],
        ]);

        $this->assertFalse($cells[2]->reachable);
        $this->assertSame(0, $cells[2]->shadowedBy, 'The first candidate takes every string alone.');
        $this->assertFalse($cells[2]->isShadowedCumulatively());
    }

    #[Test]
    public function test_the_witness_is_the_least_shortest_string_kept(): void
    {
        $patterns = ['/^\/a\/\d+$/', '/^\/a\/[a-z]+$/', '/^\/a\/[a-z0-9]+$/'];
        $cells = (new FirstMatchPartition())->partition(array_map(static fn (string $pattern): array => [[$pattern]], $patterns));

        $this->assertSame(['/a/0a'], $cells[2]->witness);
        foreach ([0, 1] as $earlier) {
            $this->assertSame(0, preg_match($patterns[$earlier], '/a/0a'), 'No earlier candidate takes the witness.');
        }

        $this->assertSame(1, preg_match($patterns[2], '/a/0a'));
    }

    #[Test]
    public function test_a_tuple_is_read_field_by_field(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            [['/^GET$/', '/^\/c\/[^\/]+$/']],
            [['/^POST$/', '/^\/c\/[^\/]+$/']],
            [['/^(?:GET|POST)$/', '/^\/c\/[^\/]+$/']],
            [['/^(?:GET|PUT)$/', '/^\/c\/[^\/]+$/']],
        ]);

        $this->assertTrue($cells[2]->isShadowedCumulatively());
        $this->assertTrue($cells[3]->reachable);
        $this->assertSame(['PUT', "/c/\0"], $cells[3]->witness, 'A method no earlier candidate takes.');
    }

    #[Test]
    public function test_a_candidate_takes_the_union_of_its_tuples(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            [['/^GET$/', '/^\/b$/'], ['/^GET$/', '/^\/b\/$/']],
            [['/^GET$/', '/^\/b\/?$/']],
        ]);

        $this->assertFalse($cells[1]->reachable);
        $this->assertSame(0, $cells[1]->shadowedBy);
    }

    #[Test]
    public function test_a_null_candidate_takes_nothing_and_keeps_nothing(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            null,
            [['/^a$/']],
            [],
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
        $candidates = [[['/^\/a$/']], [['/^\/a$/']]];

        $this->assertFalse((new FirstMatchPartition())->partition($candidates)[1]->reachable);
        $this->assertTrue((new FirstMatchPartition())->partition($candidates, [1 => []])[1]->reachable, 'A candidate left out of the rivals, a route with a condition, shadows nothing.');
    }

    #[Test]
    public function test_a_field_read_in_another_encoding_is_left_unread(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            [['/^\/é$/u']],
            [['/^\/\xC3\xA9$/']],
        ]);

        $this->assertSame([0], $cells[1]->unread);
        $this->assertTrue($cells[1]->reachable);
    }

    #[Test]
    public function test_a_witness_is_spelled_in_the_encoding_of_its_field(): void
    {
        $cells = (new FirstMatchPartition())->partition([
            [['/^GET$/u', '/^\/[é-ê]$/u']],
            [['/^GET$/u', '/^\/[é-ë]$/u']],
        ]);

        $this->assertSame(['GET', '/ë'], $cells[1]->witness);
    }

    #[Test]
    public function test_a_candidate_the_solver_gives_up_on_is_read_as_reachable(): void
    {
        $cells = (new FirstMatchPartition(new LanguageSolver(), new SolverOptions(maxNfaStates: 50)))->partition([
            [['/^a{100}$/']],
            [['/^b$/']],
        ]);

        $this->assertTrue($cells[0]->reachable);
        $this->assertNotNull($cells[0]->refused);
        $this->assertTrue($cells[1]->reachable);
        $this->assertSame([], $cells[1]->takenBy, 'A candidate the solver gave up on shadows nothing.');
    }

    #[Test]
    public function test_a_pattern_out_of_the_regular_subset_is_refused_not_thrown(): void
    {
        $cells = (new FirstMatchPartition())->partition([[['/^(a)\1$/']], [['/^aa$/']]]);

        $this->assertInstanceOf(FirstMatchCell::class, $cells[0]);
        $this->assertNotNull($cells[0]->refused);
        $this->assertTrue($cells[1]->reachable);
    }

    #[Test]
    public function test_tuples_of_other_sizes_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FirstMatchPartition())->partition([[['/^a$/', '/^b$/']], [['/^a$/']]]);
    }

    #[Test]
    public function test_a_long_first_match_list_stays_fast(): void
    {
        // Two hundred routes, each with a few earlier ones sharing a prefix,
        // and a last one the others shadow only together.
        $candidates = [];
        for ($i = 0; $i < 200; $i++) {
            $candidates[] = [['/^(?:GET|HEAD)$/', '/^\/r'.$i.'\/[a-z]+$/']];
        }

        $candidates[] = [['/^(?:GET|HEAD)$/', '/^\/r1\d?\/[a-z]+$/']];

        $start = hrtime(true);
        $cells = (new FirstMatchPartition())->partition($candidates);
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertTrue($cells[200]->isShadowedCumulatively());
        $this->assertCount(11, $cells[200]->takenBy);
        $this->assertLessThan(30.0, $elapsed);
    }
}
