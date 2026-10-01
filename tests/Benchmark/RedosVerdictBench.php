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

namespace PHPRegex\Tests\Benchmark;

use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\ParamProviders;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;

/**
 * The static ReDoS verdict on every pattern of the repository corpus, one
 * variant per pattern: the time of one theoretical analysis, the path
 * PHPStan and the linter take. The release check reads the 99th percentile
 * of the variants' mean times (5 ms at most) and the share of patterns over
 * the analysis budget.
 *
 * Run with: tools/phpbench/vendor/bin/phpbench run tests/Benchmark/RedosVerdictBench.php --group=redos-verdict
 */
#[Groups(['redos-verdict'])]
#[Iterations(3)]
#[Revs(5)]
#[Warmup(1)]
#[OutputTimeUnit('milliseconds')]
#[BeforeMethods('setUp')]
final class RedosVerdictBench
{
    private const CORPUS = __DIR__.'/../Fixtures/Corpus/lint-expectations.json';

    private RedosAnalyzer $analyzer;

    public function setUp(): void
    {
        $this->analyzer = new RedosAnalyzer(RegexParser::create());
    }

    /**
     * @param array{pattern: string} $params
     */
    #[ParamProviders('provideCorpusPatterns')]
    public function benchTheoreticalVerdict(array $params): void
    {
        $this->analyzer->analyze($params['pattern'], null, RedosMode::Theoretical);
    }

    /**
     * @return \Generator<string, array{pattern: string}>
     */
    public function provideCorpusPatterns(): \Generator
    {
        $corpus = json_decode((string) file_get_contents(self::CORPUS), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($corpus)) {
            return;
        }

        foreach ($corpus as $index => $entry) {
            if (\is_array($entry) && \is_string($entry['pattern'] ?? null)) {
                yield \sprintf('%04d', $index) => ['pattern' => $entry['pattern']];
            }
        }
    }
}
