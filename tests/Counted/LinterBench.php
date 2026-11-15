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

namespace PHPRegex\Tests\Counted;

use PHPRegex\Tests\Benchmark\Support\BenchCases;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Counted\Support\Cases;
use PHPRegex\Toolkit\Regex;
use Tally\Attribute\DataProvider;
use Tally\Meter;

/**
 * What a lint run pays per pattern: patterns of real projects validated and
 * linted, and the rules and rewrites of the data cases. Each benchmark runs
 * its work once uncounted, so the code is loaded, then counts it again from
 * a facade with no cache and the library's caches emptied.
 */
final class LinterBench
{
    public function __construct(private readonly Meter $meter) {}

    /**
     * The optimizer cases that take under a third of a second natively; the
     * heaviest one stays with the timed benchmarks, too long under the
     * simulation.
     *
     * @return iterable<string, array{string}>
     */
    public static function optimizerCases(): iterable
    {
        return Cases::only('optimizer', 'common-prefix-words', 'email-like', 'quantified-groups', 'redundant-classes', 'single-char-alternation');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lintCases(): iterable
    {
        return Cases::patterns('lint');
    }

    /**
     * The first 250 patterns of the realistic corpus, in the order of their
     * keys, which a new corpus entry does not reorder.
     */
    public function benchValidateRealistic(): void
    {
        $patterns = \array_slice(array_values(BenchCases::realistic()), 0, 250);
        $regex = $this->cold(static function (Regex $regex) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $regex->validate($pattern);
            }
        });

        foreach ($patterns as $pattern) {
            $regex->validate($pattern);
        }
    }

    public function benchLintRealistic(): void
    {
        $patterns = \array_slice(array_values(BenchCases::realistic()), 0, 100);
        $regex = $this->cold(static function (Regex $regex) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $regex->lint($pattern);
            }
        });

        foreach ($patterns as $pattern) {
            $regex->lint($pattern);
        }
    }

    #[DataProvider('lintCases')]
    public function benchLint(string $pattern): void
    {
        $this->cold(static fn (Regex $regex): array => $regex->lint($pattern))->lint($pattern);
    }

    #[DataProvider('optimizerCases')]
    public function benchOptimize(string $pattern): void
    {
        $this->cold(static fn (Regex $regex): object => $regex->optimize($pattern))->optimize($pattern);
    }

    /**
     * Runs the work once, uncounted, then returns a facade that starts cold.
     *
     * @param \Closure(Regex): mixed $work
     */
    private function cold(\Closure $work): Regex
    {
        return $this->meter->exclude(static function () use ($work): Regex {
            $work(ColdStart::regex());

            return ColdStart::regex();
        });
    }
}
