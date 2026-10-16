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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Automata\Model\Dfa;
use PHPRegex\Automata\Solver\DfaCacheInterface;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The automata a lint run builds are kept for the whole run: a pattern
 * repeated across files, or asked about by several rules, is determinized
 * once.
 */
final class AnalysisServiceDfaCacheTest extends TestCase
{
    #[Test]
    public function test_a_repeated_pattern_builds_each_automaton_once(): void
    {
        $cache = self::spy();
        $analysis = (new AnalysisService(RegexParser::create(), dfaCache: $cache))->withParser(RegexParser::create());

        $issues = $analysis->lint(self::twice(['/a(?=b)c/', '/\d++\d/', '/[a-z]\b[0-9]/', '/[-a]\b-/']));

        $ids = array_column($issues, 'issueId');
        $this->assertSame(2, array_count_values($ids)['regex.lint.lookaround.impossible'] ?? 0);
        $this->assertSame(2, array_count_values($ids)['regex.lint.quantifier.possessiveImpossible'] ?? 0);
        $this->assertSame(2, array_count_values($ids)['regex.lint.anchor.impossible.boundary'] ?? 0);
        $this->assertNotSame([], $cache->sets, 'The rules ask the automata through the cache of the analysis.');
        $this->assertSame(array_fill_keys(array_keys($cache->sets), 1), $cache->sets, 'Every automaton is built once.');
    }

    /**
     * The lookahead keeps the match check out, so the language check runs,
     * on the automata.
     */
    #[Test]
    public function test_the_optimizer_checks_share_the_cache_of_the_analysis(): void
    {
        $cache = self::spy();
        $analysis = new AnalysisService(RegexParser::create(), dfaCache: $cache);

        $suggestions = $analysis->suggestOptimizations(self::twice(['/(?=1)[0-9]+ x/x', '/(?=1)[0-9]+x/']), 1);

        $this->assertCount(4, $suggestions);
        $this->assertNotSame([], $cache->sets, 'The rewrite under x is checked with the automata of the analysis.');
        $this->assertSame(array_fill_keys(array_keys($cache->sets), 1), $cache->sets, 'Every automaton is built once.');
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<PatternOccurrence>
     */
    private static function twice(array $patterns): array
    {
        $occurrences = [];
        foreach ($patterns as $pattern) {
            $occurrences[] = new PatternOccurrence($pattern, 'first.php', 1, 'preg_match');
            $occurrences[] = new PatternOccurrence($pattern, 'second.php', 1, 'preg_match');
        }

        return $occurrences;
    }

    /**
     * @return DfaCacheInterface&object{sets: array<string, int>}
     */
    private static function spy(): DfaCacheInterface
    {
        return new class implements DfaCacheInterface {
            /**
             * @var array<string, Dfa>
             */
            public array $dfas = [];

            /**
             * @var array<string, int>
             */
            public array $sets = [];

            public function get(string $key): ?Dfa
            {
                return $this->dfas[$key] ?? null;
            }

            public function set(string $key, Dfa $dfa): void
            {
                $this->dfas[$key] = $dfa;
                $this->sets[$key] = ($this->sets[$key] ?? 0) + 1;
            }
        };
    }
}
