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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\Parallel\ForkedWorkerPool;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lint run that suggests optimizations reads each pattern once: the
 * optimization step takes the tree and the validation the analysis step
 * built, from its workers too, instead of parsing and validating again.
 */
final class AnalysisServiceSharedParseTest extends TestCase
{
    #[Test]
    public function test_the_suggestions_parse_nothing_the_analysis_read(): void
    {
        // A cache holding one tree: whatever the optimization step finds in
        // it, it did not keep from the analysis step on its own.
        $cache = self::oneTreeCache();
        $analysis = new AnalysisService(RegexParser::create(['cache' => $cache]));
        $patterns = self::occurrences(['/abc/', '/xyz/', '/klm/']);

        [, $analyzed] = $analysis->lintForSuggestions($patterns);
        $misses = $cache->misses;
        $suggestions = $analysis->suggestOptimizations($patterns, 1, analyzed: $analyzed);

        $this->assertSame([], $suggestions);
        $this->assertGreaterThan(0, $misses);
        $this->assertSame($misses, $cache->misses, 'No pattern is parsed or validated twice.');
    }

    #[Test]
    public function test_the_analysis_keeps_what_it_read_beside_its_findings(): void
    {
        $analysis = new AnalysisService(RegexParser::create());
        $patterns = self::occurrences(['/(/', '/[0-9]+x/']);

        [$issues, $analyzed] = $analysis->lintForSuggestions($patterns);

        $this->assertEquals($analysis->lint($patterns), $issues);
        $this->assertSame(['/(/', '/[0-9]+x/'], array_keys($analyzed->byPattern));
        $this->assertFalse($analyzed->byPattern['/(/'][0]->isValid);
        $this->assertNull($analyzed->byPattern['/(/'][1], 'An invalid pattern has no tree.');
        $this->assertNotNull($analyzed->byPattern['/[0-9]+x/'][1]);
    }

    #[Test]
    public function test_a_lint_run_that_suggests_parses_no_more_than_one_that_does_not(): void
    {
        $patterns = self::occurrences(['/abc/', '/xyz/', '/klm/']);

        $without = self::oneTreeCache();
        (new LintService(new AnalysisService(RegexParser::create(['cache' => $without])), new PatternSourceCollection([])))
            ->analyze($patterns, new LintRequest(paths: [], excludePaths: [], minSavings: 1, checkOptimizations: false));

        $with = self::oneTreeCache();
        (new LintService(new AnalysisService(RegexParser::create(['cache' => $with])), new PatternSourceCollection([])))
            ->analyze($patterns, new LintRequest(paths: [], excludePaths: [], minSavings: 1, checkOptimizations: true));

        $this->assertGreaterThan(0, $without->misses);
        $this->assertSame($without->misses, $with->misses);
    }

    #[Test]
    public function test_the_suggestions_read_alone_without_the_analysis(): void
    {
        $analysis = new AnalysisService(RegexParser::create());

        $suggestions = $analysis->suggestOptimizations(self::occurrences(['/[0-9]+x/', '/(/']), 1);

        $this->assertCount(1, $suggestions);
        $this->assertSame('/\d+x/', $suggestions[0]['optimization']->optimized);
    }

    #[Test]
    public function test_the_workers_hand_over_what_they_read(): void
    {
        if (!ForkedWorkerPool::canFork()) {
            $this->assertFalse(ForkedWorkerPool::canFork(), 'Without fork, the run stays in one process.');

            return;
        }

        $patterns = self::occurrences(['/[0-9]+x/', '/(/', '/abc/', '/(?:a|b)c/', '/[a-z]+[0-9]/', '/x{1,1}/']);

        $alone = new AnalysisService(RegexParser::create());
        [$issues, $analyzed] = $alone->lintForSuggestions($patterns);
        $expected = array_map(static fn (array $entry): string => $entry['optimization']->optimized, $alone->suggestOptimizations($patterns, 1, analyzed: $analyzed));

        $parallel = new AnalysisService(RegexParser::create());
        [$parallelIssues, $parallelAnalyzed] = $parallel->lintForSuggestions($patterns, null, 3);
        $actual = array_map(static fn (array $entry): string => $entry['optimization']->optimized, $parallel->suggestOptimizations($patterns, 1, null, 3, analyzed: $parallelAnalyzed));

        $this->assertNotSame([], $expected);
        $this->assertSame($expected, $actual);
        $this->assertEqualsCanonicalizing($analyzed->byPattern, $parallelAnalyzed->byPattern, 'Every worker sends back what it read.');
        $this->assertCount(\count($issues), $parallelIssues, 'What the workers read is not a finding.');
    }

    /**
     * @param list<string> $patterns
     *
     * @return list<PatternOccurrence>
     */
    private static function occurrences(array $patterns): array
    {
        $occurrences = [];
        foreach ($patterns as $index => $pattern) {
            // One file per pattern: the workers share the patterns out by file.
            $occurrences[] = new PatternOccurrence($pattern, 'file'.$index.'.php', 1, 'preg_match()');
        }

        return $occurrences;
    }

    /**
     * @return CacheInterface&object{misses: int}
     */
    private static function oneTreeCache(): CacheInterface
    {
        return new class implements CacheInterface {
            public int $misses = 0;

            private ?string $key = null;

            private ?RegexNode $tree = null;

            public function generateKey(string $seed): string
            {
                return hash('sha256', $seed);
            }

            public function write(string $key, RegexNode $ast): void
            {
                $this->key = $key;
                $this->tree = $ast;
            }

            public function load(string $key): ?RegexNode
            {
                if ($key === $this->key) {
                    return $this->tree;
                }

                $this->misses++;

                return null;
            }
        };
    }
}
