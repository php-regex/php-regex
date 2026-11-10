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

use PHPRegex\Automata\LanguageSolver;
use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\RedosRepair;
use PHPRegex\Optimizer\RedosRepairer;
use PHPRegex\Parser\Cache\ArrayCache;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\Hotspot;
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
     * A rewrite the automata cannot judge is still put to the engine: one
     * it tells apart from the pattern on a subject is no repair. On PHP
     * 8.4.27 (PCRE2 10.49), /(x++x+)+y/ refuses "xxy", which /(x+x+)+y/
     * matches: the possessive keeps every x from the second run.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideRewritesTheEngineTellsApart')]
    public function test_a_rewrite_the_engine_tells_apart_is_no_repair(string $pattern, string $refuted, array $subjects): void
    {
        $repairs = (new RedosRepairer())->repair($pattern);

        $this->assertNotContains($refuted, array_map(static fn (RedosRepair $repair): string => $repair->pattern, $repairs));
        foreach ($repairs as $repair) {
            foreach ($subjects as $subject) {
                $this->assertSame(preg_match($pattern, $subject), preg_match($repair->pattern, $subject), \sprintf('%s is offered for %s, which it reads otherwise on %s.', $repair->pattern, $pattern, json_encode($subject)));
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, refuted: string, subjects: list<string>}>
     */
    public static function provideRewritesTheEngineTellsApart(): iterable
    {
        yield 'a possessive run before another' => ['pattern' => '/(x+x+)+y/', 'refuted' => '/(x++x+)+y/', 'subjects' => ['xxy', 'xxxy', 'xxxxy']];
        yield 'a possessive run before the same letter' => ['pattern' => '/^(a*a)+$/', 'refuted' => '/^(a*+a)+$/', 'subjects' => ['a', 'aa', 'aaa']];
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
     * here its class of one letter for an alternation of the same letter,
     * the group around it gone in the same pass.
     */
    #[Test]
    public function test_the_optimizer_repair_is_the_verified_rewrite(): void
    {
        $repairs = (new RedosRepairer())->repair('/^(?:a|a)+$/');

        $this->assertNotSame([], $repairs);
        $this->assertSame('/^[a]+$/', $repairs[0]->pattern);
        $this->assertTrue($repairs[0]->sameSubjects);
        $this->assertTrue($repairs[0]->sameMatches);
        $this->assertSame('/^[a]+$/', (new Optimizer(RegexParser::create()))->optimize('/^(?:a|a)+$/', new OptimizerOptions(verifyWithAutomata: true))->optimized);
    }

    /**
     * Every repair of a pattern, in the order it is offered, with what is
     * proven of it: the rewrites tried and what each costs may change, these
     * answers may not. /^\s*(?:\w+\s*)*$/ is repaired though the analysis
     * names no culprit and no hotspot in it.
     *
     * @param list<array{string, bool|null, bool|null}> $repairs
     */
    #[Test]
    #[DataProvider('provideRepairs')]
    public function test_the_repairs_of_a_pattern_are_kept(string $pattern, array $repairs): void
    {
        $this->assertSame($repairs, array_map(
            static fn (RedosRepair $repair): array => [$repair->pattern, $repair->sameSubjects, $repair->sameMatches],
            (new RedosRepairer())->repair($pattern),
        ));
    }

    /**
     * The rewrites of the quantifiers the analysis blames come first: those
     * a hotspot covers or lies in, the culprit being one of the hotspots.
     * Then the others in the order they had: the flattenings, the
     * optimizer's rewrite, the possessives, all of the possessives at once.
     * A quantifier that only touches a hotspot is not blamed.
     *
     * @param list<array{int, int}> $hotspots
     * @param list<string>          $candidates
     */
    #[Test]
    #[DataProvider('provideCandidatesInOrder')]
    public function test_the_rewrites_of_the_blamed_quantifiers_are_tried_first(string $pattern, array $hotspots, array $candidates): void
    {
        $parser = RegexParser::create();
        $analysis = (new RedosAnalyzer($parser))->analyze($pattern);
        $this->assertSame($hotspots, array_map(static fn (Hotspot $hotspot): array => [$hotspot->start, $hotspot->end], $analysis->hotspots));

        $tried = new \ReflectionMethod(RedosRepairer::class, 'candidates');

        $this->assertSame($candidates, $tried->invoke(new RedosRepairer($parser), $parser->parse($pattern), $pattern, $parser, $analysis));
    }

    /**
     * @return iterable<string, array{pattern: string, hotspots: list<array{int, int}>, candidates: list<string>}>
     */
    public static function provideCandidatesInOrder(): iterable
    {
        yield 'the loop around the hotspot, before the optimizer and the others' => [
            'pattern' => '/^c+(?:a+)+b+(?:[0-9]|\d)$/',
            'hotspots' => [[6, 8]],
            'candidates' => [
                '/^c+a+b+(?:[0-9]|\d)$/',
                '/^c+(?:a+)++b+(?:[0-9]|\d)$/',
                '/^c+(?:a++)+b+(?:[0-9]|\d)$/',
                '/^c+(?:a+)+b+(?:[0-90-9])$/',
                '/^c++(?:a+)+b+(?:[0-9]|\d)$/',
                '/^c+(?:a+)+b++(?:[0-9]|\d)$/',
                '/^c++(?:a++)++b++(?:[0-9]|\d)$/',
            ],
        ];
        yield 'neighbours that touch the hotspot on each side' => [
            'pattern' => '/^c+(?:a*)*b+$/',
            'hotspots' => [[3, 10], [6, 8]],
            'candidates' => [
                '/^c+a*b+$/',
                '/^c+(?:a*)*+b+$/',
                '/^c+(?:a*+)*b+$/',
                '/^c++(?:a*)*b+$/',
                '/^c+(?:a*)*b++$/',
                '/^c++(?:a*+)*+b++$/',
            ],
        ];
        yield 'no hotspot: the order they had' => [
            'pattern' => '/^\s*(?:\w+\s*)*$/',
            'hotspots' => [],
            'candidates' => [
                '/^\s*+(?:\w+\s*)*$/',
                '/^\s*(?:\w+\s*)*+$/',
                '/^\s*(?:\w++\s*)*$/',
                '/^\s*(?:\w+\s*+)*$/',
                '/^\s*+(?:\w++\s*+)*+$/',
            ],
        ];
    }

    /**
     * A rewrite tried is a pattern nobody wrote: it is proven linear or
     * dropped without the parser keeping a tree of it. The parser is asked
     * for the pattern, the optimizer's rewrite and the rewrites proven
     * linear, as often for twelve quantifiers no subject reaches as for
     * three.
     */
    #[Test]
    public function test_a_rewrite_dropped_asks_the_parser_nothing(): void
    {
        $asked = function (int $quantifiers): int {
            $cache = new ArrayCache();
            $repairs = (new RedosRepairer(RegexParser::create(['cache' => $cache])))->repair('/(?:'.str_repeat('x+', $quantifiers).'){0}^(?:a+)+$/');
            $this->assertCount(3, $repairs);

            return array_sum($cache->getStats());
        };

        $this->assertSame($asked(3), $asked(12));
    }

    /**
     * The rewrites the automata cannot judge are put to the engine on the
     * subjects of the pattern, spelled once for all of them: the automaton
     * of the pattern is asked for once more than the rewrites compared
     * with it, two of them here.
     */
    #[Test]
    public function test_the_subjects_of_the_pattern_are_spelled_once(): void
    {
        $pattern = '/^\s*(?:\w+\s*)*$/';
        $cache = new class implements DfaCacheInterface {
            /**
             * @var list<string>
             */
            public array $asked = [];

            public function get(string $key): ?Dfa
            {
                $this->asked[] = $key;

                return null;
            }

            public function set(string $key, Dfa $dfa): void {}
        };
        $parser = RegexParser::create();

        $repairs = (new RedosRepairer($parser, null, new LanguageSolver($parser, $cache)))->repair($pattern);

        $this->assertSame([null, null], array_map(static fn (RedosRepair $repair): ?bool => $repair->sameSubjects, $repairs));
        $own = LanguageSolver::dfaCacheKey($pattern, $parser->target()->cacheKey(), new SolverOptions(matchMode: MatchMode::Partial), \PCRE_VERSION);
        $times = array_count_values($cache->asked);
        $this->assertSame(\count($cache->asked) - $times[$own] + 1, $times[$own]);
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
     * @return iterable<string, array{pattern: string, repairs: list<array{string, bool|null, bool|null}>}>
     */
    public static function provideRepairs(): iterable
    {
        yield 'nested plus' => ['pattern' => '/^(?:a+)+$/', 'repairs' => [
            ['/^a+$/', true, true],
            ['/^(?:a++)+$/', null, null],
            ['/^(?:a++)++$/', null, null],
        ]];
        yield 'nested plus in a group' => ['pattern' => '/^(a+)+$/', 'repairs' => [
            ['/^(a+)$/', true, true],
            ['/^(a++)+$/', null, null],
            ['/^(a++)++$/', null, null],
        ]];
        yield 'star around plus, group' => ['pattern' => '/^(\\d+)*$/', 'repairs' => [
            ['/^(\\d*)$/', true, false],
            ['/^(\\d++)*$/', null, null],
            ['/^(\\d++)*+$/', null, null],
        ]];
        yield 'star around star' => ['pattern' => '/(?:x*)*y/', 'repairs' => [
            ['/x*y/', true, null],
            ['/(?:x*+)*y/', null, null],
            ['/(?:x*+)*+y/', null, null],
        ]];
        yield 'a repeat the automata cannot judge once possessive' => ['pattern' => '/^(\\w+\\s?)+$/', 'repairs' => [
            ['/^(\\w++\\s?)+$/', null, null],
            ['/^(\\w++\\s?)++$/', null, null],
        ]];
        yield 'a possessive run before another' => ['pattern' => '/(x+x+)+y/', 'repairs' => []];
        yield 'a possessive run before the same letter' => ['pattern' => '/^(a*a)+$/', 'repairs' => []];
        yield 'an alternation of the same letter' => ['pattern' => '/^(?:a|a)+$/', 'repairs' => [
            ['/^[a]+$/', true, true],
            ['/^(?:a|a)++$/', null, null],
        ]];
        yield 'no culprit and no hotspot named' => ['pattern' => '/^\\s*(?:\\w+\\s*)*$/', 'repairs' => [
            ['/^\\s*(?:\\w++\\s*)*$/', null, null],
            ['/^\\s*+(?:\\w++\\s*+)*+$/', null, null],
        ]];
        yield 'no culprit named, a literal after the repeat' => ['pattern' => '/^([a-zA-Z0-9]+[._-]?)*@x$/', 'repairs' => [
            ['/^([a-zA-Z0-9]++[._-]?)*@x$/', null, null],
            ['/^([a-zA-Z0-9]++[._-]?)*+@x$/', null, null],
        ]];
        yield 'a class repeated inside a repeat' => ['pattern' => '/href="([^"]+)*"/', 'repairs' => [
            ['/href="([^"]*)"/', true, false],
            ['/href="([^"]++)*"/', null, null],
            ['/href="([^"]++)*+"/', null, null],
        ]];
        yield 'quantifiers before the nested repeat' => ['pattern' => '/^\\d+\\s+\\d+\\s+\\d+\\s+(?:\\w+)+$/', 'repairs' => [
            ['/^\\d+\\s+\\d+\\s+\\d+\\s+\\w+$/', true, true],
            ['/^\\d+\\s+\\d+\\s+\\d+\\s+(?:\\w++)+$/', null, null],
            ['/^\\d++\\s++\\d++\\s++\\d++\\s++(?:\\w++)++$/', null, null],
        ]];
        yield 'quantifiers no subject reaches' => ['pattern' => '/(?:x+x+x+){0}^(?:a+)+$/', 'repairs' => [
            ['/(?:x+x+x+){0}^a+$/', true, true],
            ['/(?:x+x+x+){0}^(?:a++)+$/', null, null],
            ['/(?:x++x++x++){0}^(?:a++)++$/', null, null],
        ]];
        // The automata read neither pattern: there is no subject to put to
        // the engine, and each rewrite proven linear is listed uncertified.
        yield 'a possessive the automata do not read' => ['pattern' => '/^x++(?:a+)+$/', 'repairs' => [
            ['/^x++a+$/', null, true],
            ['/^x++(?:a++)+$/', null, null],
            ['/^x++(?:a++)++$/', null, null],
        ]];
        yield 'an atomic group the automata do not read' => ['pattern' => '/^(?>x+)(?:a+)+$/', 'repairs' => [
            ['/^(?>x+)a+$/', null, null],
            ['/^(?>x+)(?:a++)+$/', null, null],
            ['/^(?>x++)(?:a++)++$/', null, null],
        ]];
        yield 'linear' => ['pattern' => '/^\\d{3}-\\d{4}$/', 'repairs' => []];
        yield 'one loop' => ['pattern' => '/^a+b$/', 'repairs' => []];
        yield 'invalid' => ['pattern' => '/(a+/', 'repairs' => []];
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
