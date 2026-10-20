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
use PHPRegex\Optimizer\RedosRepair;
use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repair of a pattern open to catastrophic backtracking counts only with
 * its proofs: the automata prove it matches the same subjects, and the
 * backtracking model proves its attempts linear. A rewrite the automata
 * cannot judge is listed, and says so.
 */
final class RedosRepairerTest extends TestCase
{
    private const SUBJECTS = ['', 'a', 'aa', 'aaaa', 'aab', 'b', '1', '12', '12a', 'ab ab', 'ab', 'a a', 'aa!'];

    #[Test]
    #[DataProvider('provideRepairable')]
    public function test_a_nested_repeat_gets_a_proven_repair(string $pattern, string $repair, ?bool $sameMatches): void
    {
        $repairs = (new RedosRepairer())->repair($pattern);

        $this->assertNotSame([], $repairs);
        $best = $repairs[0];
        $this->assertSame($repair, $best->pattern);
        $this->assertTrue($best->sameSubjects);
        $this->assertSame($sameMatches, $best->sameMatches);
        $this->assertSame(RedosComplexity::Linear, (new RedosAnalyzer())->analyze($best->pattern)->complexity);
        $this->assertTrue($best->isCertified());

        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($pattern, $subject), preg_match($repair, $subject), \sprintf('%s and %s disagree on %s.', $pattern, $repair, json_encode($subject)));
        }
    }

    #[Test]
    public function test_a_rewrite_the_automata_cannot_judge_is_not_certified(): void
    {
        $repairs = (new RedosRepairer())->repair('/^(\w+\s?)+$/');

        $this->assertNotSame([], $repairs);
        foreach ($repairs as $repair) {
            $this->assertInstanceOf(RedosRepair::class, $repair);
            $this->assertSame(RedosComplexity::Linear, (new RedosAnalyzer())->analyze($repair->pattern)->complexity);
            if (null === $repair->sameSubjects) {
                $this->assertFalse($repair->isCertified());
            }
        }
    }

    /**
     * Every repair is proven linear, so the result carries no complexity
     * of its own: it would always say linear. Certified means the same
     * subjects.
     */
    #[Test]
    public function test_a_repair_carries_its_proofs_and_no_constant_complexity(): void
    {
        $this->assertFalse(property_exists(RedosRepair::class, 'complexity'));

        $repair = new RedosRepair('/^a+$/', true, null);
        $this->assertTrue($repair->isCertified());
        $this->assertFalse((new RedosRepair('/^a+$/', null, true))->isCertified());
        $this->assertFalse((new RedosRepair('/^a+$/', false, true))->isCertified());
    }

    /**
     * The optimizer's rewrite counts as a repair only once the automata
     * prove it writes the same $matches, as the lint's suggestions are:
     * here its class of one letter for an alternation of the same letter.
     */
    #[Test]
    public function test_the_optimizer_repair_is_the_verified_rewrite(): void
    {
        $repairs = (new RedosRepairer())->repair('/^(?:a|a)+$/');

        $this->assertNotSame([], $repairs);
        $this->assertSame('/^(?:[a])+$/', $repairs[0]->pattern);
        $this->assertTrue($repairs[0]->sameSubjects);
        $this->assertTrue($repairs[0]->sameMatches);
        $this->assertSame('/^(?:[a])+$/', (new Optimizer(RegexParser::create()))->optimize('/^(?:a|a)+$/', new OptimizerOptions(verifyWithAutomata: true))->optimized);
    }

    #[Test]
    #[DataProvider('provideSafePatterns')]
    public function test_a_pattern_without_risk_needs_no_repair(string $pattern): void
    {
        $this->assertSame([], (new RedosRepairer())->repair($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, repair: string, sameMatches: bool|null}>
     */
    public static function provideRepairable(): iterable
    {
        yield 'nested plus' => ['pattern' => '/^(?:a+)+$/', 'repair' => '/^a+$/', 'sameMatches' => true];
        yield 'nested plus in a group' => ['pattern' => '/^(a+)+$/', 'repair' => '/^(a+)$/', 'sameMatches' => true];
        yield 'star around plus, group' => ['pattern' => '/^(\d+)*$/', 'repair' => '/^(\d*)$/', 'sameMatches' => false];
        // The match solver does not read a loop over a body that can match empty.
        yield 'star around star' => ['pattern' => '/(?:x*)*y/', 'repair' => '/x*y/', 'sameMatches' => null];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSafePatterns(): iterable
    {
        yield 'linear' => ['pattern' => '/^\d{3}-\d{4}$/'];
        yield 'one loop' => ['pattern' => '/^a+b$/'];
        yield 'invalid' => ['pattern' => '/(a+/'];
    }
}
