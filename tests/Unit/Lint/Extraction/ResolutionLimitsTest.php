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

namespace PHPRegex\Tests\Unit\Lint\Extraction;

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ArgumentAwareInterface;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Benchmark\Support\PeakMemory;
use PHPRegex\Tests\Support\CpuClock;
use PHPRegex\Tests\Support\PcreGates;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the reading of names costs stays bounded: a constant folded to a
 * string past 64 KiB proves nothing, a variable assigned and read
 * thousands of times in one body is read in time that grows with it, not
 * with its square, the values of several operands are not multiplied
 * past the most a composition may give, an array read item by item
 * through a loop is read in time that grows with its items, and an item
 * read by its literal key is found without a walk over the array.
 */
final class ResolutionLimitsTest extends TestCase
{
    use PcreGates;
    use TemporaryProject;

    #[Test]
    public function test_a_constant_doubled_past_the_bound_proves_nothing(): void
    {
        $this->requiresPhp('8.2', 'The peak memory of a resolution', 'has no memory_reset_peak_usage()');

        $code = "<?php\n\nfinal class Blow\n{\n    const C0 = 'ab';\n";
        for ($i = 1; $i <= 24; $i++) {
            $code .= \sprintf("    const C%d = self::C%d . self::C%d;\n", $i, $i - 1, $i - 1);
        }
        $code .= "    const RE = '/' . self::C24 . '/';\n\n    public function m(string \$s): int\n    {\n        return preg_match(self::RE, \$s);\n    }\n}\n";
        $file = $this->makeProject(['blow.php' => $code]).'/blow.php';

        foreach ($this->strategies() as $name => $strategy) {
            PeakMemory::reset();
            $before = memory_get_peak_usage();
            $patterns = self::patterns($strategy, $file);
            $grown = memory_get_peak_usage() - $before;

            $this->assertSame([], $patterns, $name);
            $this->assertLessThan(32 * 1024 * 1024, $grown, $name.' grew the memory by '.$grown.' bytes.');
        }
    }

    #[Test]
    public function test_a_variable_reused_many_times_is_read_in_linear_time(): void
    {
        // Eight times the calls: linear work takes about 8 times as long,
        // quadratic about 64 times. The bound sits between the two, and the
        // best of three runs keeps a loaded machine from deciding.
        foreach ($this->strategies() as $name => $strategy) {
            $small = min(array_map(fn (): float => $this->timeReading($strategy, 500), range(1, 3)));
            $large = min(array_map(fn (): float => $this->timeReading($strategy, 4000), range(1, 3)));

            $this->assertLessThan(24 * $small, $large, \sprintf('%s: %.3fs for 500 calls, %.3fs for 4000.', $name, $small, $large));
        }
    }

    /**
     * Twelve operands of 32 values each would give 32 to the power of 12
     * strings: two operands of several values are not multiplied, and
     * the product is never built. Nothing is known of the argument, and
     * no pattern is read.
     */
    #[Test]
    public function test_a_product_of_sets_is_abandoned_past_the_cap_without_being_built(): void
    {
        $this->requiresPhp('8.2', 'The peak memory of a resolution', 'has no memory_reset_peak_usage()');

        $file = $this->makeProject(['product.php' => self::productOf(12, 32)]).'/product.php';

        foreach ($this->strategies() as $name => $strategy) {
            $this->assertInstanceOf(ArgumentAwareInterface::class, $strategy, $name);

            PeakMemory::reset();
            $before = memory_get_peak_usage();
            $read = $strategy->extractWithArguments([$file]);
            $grown = memory_get_peak_usage() - $before;

            $patterns = [];
            $states = [];
            foreach ($read as $occurrence) {
                if (null === $occurrence->state) {
                    $patterns[] = $occurrence->pattern;
                } else {
                    $states[] = $occurrence->state;
                }
            }

            $this->assertSame([], $patterns, $name);
            $this->assertSame(['opaque'], $states, $name);
            $this->assertLessThan(32 * 1024 * 1024, $grown, $name.' grew the memory by '.$grown.' bytes.');
        }
    }

    /**
     * Eight times the operands, each of 33 values, one past what a
     * composition may give: the composition is abandoned at the first of
     * them, and the reading takes about 8 times as long, not 8 times 8.
     */
    #[Test]
    public function test_a_product_of_sets_past_the_cap_is_abandoned_in_linear_time(): void
    {
        foreach ($this->strategies() as $name => $strategy) {
            $small = min(array_map(fn (): float => $this->timeProduct($strategy, 16), range(1, 3)));
            $large = min(array_map(fn (): float => $this->timeProduct($strategy, 128), range(1, 3)));

            $this->assertLessThan(24 * $small, $large, \sprintf('%s: %.3fs for 16 operands, %.3fs for 128.', $name, $small, $large));
        }
    }

    /**
     * Twelve constants of 32 values each, joined in one argument, would
     * give 32 to the power of 12 strings: the product is never built.
     * Nothing is known of the argument, and no pattern is read.
     */
    #[Test]
    public function test_a_product_of_constants_of_several_values_is_abandoned_without_being_built(): void
    {
        $this->requiresPhp('8.2', 'The peak memory of a resolution', 'has no memory_reset_peak_usage()');

        $code = "<?php\n\n";
        $joined = "'/^'";
        for ($constant = 1; $constant <= 12; $constant++) {
            $code .= \sprintf("define('SET_%d', match (\\PHP_INT_SIZE) {\n", $constant);
            for ($value = 1; $value < 32; $value++) {
                $code .= \sprintf("    %d => 'c%dv%d',\n", $value, $constant, $value);
            }
            $code .= \sprintf("    default => 'c%dv32',\n});\n", $constant);
            $joined .= \sprintf(' . SET_%d', $constant);
        }
        $code .= "\nfunction f(string \$s): int\n{\n    return preg_match(".$joined." . '$/', \$s);\n}\n";
        $file = $this->makeProject(['constants.php' => $code]).'/constants.php';

        foreach ($this->strategies() as $name => $strategy) {
            $this->assertInstanceOf(ArgumentAwareInterface::class, $strategy, $name);

            PeakMemory::reset();
            $before = memory_get_peak_usage();
            $read = $strategy->extractWithArguments([$file]);
            $grown = memory_get_peak_usage() - $before;

            $patterns = [];
            $states = [];
            foreach ($read as $occurrence) {
                if (null === $occurrence->state) {
                    $patterns[] = $occurrence->pattern;
                } else {
                    $states[] = $occurrence->state;
                }
            }

            $this->assertSame([], $patterns, $name);
            $this->assertSame(['opaque'], $states, $name);
            $this->assertLessThan(32 * 1024 * 1024, $grown, $name.' grew the memory by '.$grown.' bytes.');
        }
    }

    /**
     * An array of 2,000 patterns read through a foreach whose body calls
     * four functions: each item is read once, where it is written, and
     * eight times the items take about 8 times as long, not 8 times 8.
     */
    #[Test]
    public function test_an_array_read_through_a_foreach_at_several_calls_is_read_in_linear_time(): void
    {
        foreach ($this->strategies() as $name => $strategy) {
            $small = min(array_map(fn (): float => $this->timeLoop($strategy, 250), range(1, 3)));
            $large = min(array_map(fn (): float => $this->timeLoop($strategy, 2000), range(1, 3)));

            $this->assertLessThan(24 * $small, $large, \sprintf('%s: %.3fs for 250 items, %.3fs for 2000.', $name, $small, $large));
        }
    }

    /**
     * A variable assigned and joined to literals thousands of times in a
     * row: the first read has one assignment before it and is read; each
     * read after it has several, and is not. Telling so for eight times
     * the reads takes about 8 times as long, not 8 times 8.
     */
    #[Test]
    public function test_a_variable_reassigned_before_each_composed_read_is_read_in_linear_time(): void
    {
        foreach ($this->strategies() as $name => $strategy) {
            $small = min(array_map(fn (): float => $this->timeComposedReads($strategy, 500), range(1, 3)));
            $large = min(array_map(fn (): float => $this->timeComposedReads($strategy, 4000), range(1, 3)));

            $this->assertLessThan(24 * $small, $large, \sprintf('%s: %.3fs for 500 reads, %.3fs for 4000.', $name, $small, $large));
        }
    }

    /**
     * A constant holding 2,000 patterns, each read once by its literal
     * key: an item is found by its key, whatever the number of the items
     * beside it. The reading takes about what reading 2,000 constants
     * takes, each declared and read once; a walk over the array at each
     * read takes six to ten times that at this size, and the bound sits
     * between the two.
     */
    #[Test]
    public function test_the_items_of_a_class_constant_read_by_literal_keys_cost_what_as_many_constants_cost(): void
    {
        $this->assertItemsReadByKeyCostWhatConstantsCost(false);
    }

    /**
     * The same items in a local array, assigned once and read 2,000
     * times by a literal key.
     */
    #[Test]
    public function test_the_items_of_a_local_array_read_by_literal_keys_cost_what_as_many_constants_cost(): void
    {
        $this->assertItemsReadByKeyCostWhatConstantsCost(true);
    }

    private function assertItemsReadByKeyCostWhatConstantsCost(bool $local): void
    {
        $items = 2000;

        // The best of three runs keeps a loaded machine from deciding.
        foreach ($this->strategies() as $name => $strategy) {
            $constants = min(array_map(fn (): float => $this->timeItems($strategy, self::constantsReadOnce($items), $items), range(1, 3)));
            $byKey = min(array_map(fn (): float => $this->timeItems($strategy, self::itemsReadByKey($items, $local), $items), range(1, 3)));

            $this->assertLessThan(3 * $constants, $byKey, \sprintf('%s: %.3fs for %d constants read once each, %.3fs for %d items read by their keys.', $name, $constants, $items, $byKey, $items));
        }
    }

    /**
     * The CPU time of reading a file that holds as many patterns, each
     * read once.
     */
    private function timeItems(ExtractorInterface $strategy, string $code, int $items): float
    {
        $file = $this->makeProject(['items.php' => $code]).'/items.php';

        $patterns = CpuClock::timed(static fn () => self::patterns($strategy, $file), $elapsed);

        $this->assertCount($items, array_unique($patterns));

        return max($elapsed, 0.001);
    }

    /**
     * A class whose method reads each item of an array once, by its
     * literal key: the array is a constant of the class, or a variable
     * the method assigns once.
     */
    private static function itemsReadByKey(int $items, bool $local): string
    {
        $map = '';
        $reads = '';
        for ($i = 0; $i < $items; $i++) {
            $map .= \sprintf("        'k%d' => '/item-%d/',\n", $i, $i);
            $reads .= \sprintf("        \$found += preg_match(%s['k%d'], \$s);\n", $local ? '$map' : 'self::MAP', $i);
        }

        return "<?php\n\nfinal class Lookup\n{\n"
            .($local ? '' : "    private const MAP = [\n".$map."    ];\n\n")
            ."    public function f(string \$s): int\n    {\n"
            .($local ? "        \$map = [\n".$map."        ];\n" : '')
            ."        \$found = 0;\n".$reads."\n        return \$found;\n    }\n}\n";
    }

    /**
     * The same patterns, each the value of a constant of its own, read
     * once by its name.
     */
    private static function constantsReadOnce(int $items): string
    {
        $constants = '';
        $reads = '';
        for ($i = 0; $i < $items; $i++) {
            $constants .= \sprintf("    private const K%d = '/item-%d/';\n", $i, $i);
            $reads .= \sprintf("        \$found += preg_match(self::K%d, \$s);\n", $i);
        }

        return "<?php\n\nfinal class Lookup\n{\n".$constants."\n    public function f(string \$s): int\n    {\n        \$found = 0;\n".$reads."\n        return \$found;\n    }\n}\n";
    }

    private function timeLoop(ExtractorInterface $strategy, int $items): float
    {
        $code = "<?php\n\nfunction f(string \$s): int\n{\n    \$found = 0;\n    foreach ([\n";
        for ($i = 0; $i < $items; $i++) {
            $code .= \sprintf("        '/item-%d/',\n", $i);
        }
        $code .= "    ] as \$re) {\n        \$found += preg_match(\$re, \$s) + preg_match_all(\$re, \$s) + \\count(preg_split(\$re, \$s) ?: []) + \\count(preg_grep(\$re, [\$s]) ?: []);\n    }\n\n    return \$found;\n}\n";
        $file = $this->makeProject(['loop.php' => $code]).'/loop.php';

        $patterns = CpuClock::timed(static fn () => self::patterns($strategy, $file), $elapsed);

        $this->assertCount($items, $patterns);

        return max($elapsed, 0.001);
    }

    private function timeProduct(ExtractorInterface $strategy, int $operands): float
    {
        $file = $this->makeProject(['product.php' => self::productOf($operands, 33)]).'/product.php';

        $start = CpuClock::nanoseconds();
        $patterns = self::patterns($strategy, $file);
        $elapsed = (CpuClock::nanoseconds() - $start) / 1e9;

        $this->assertSame([], $patterns);

        return max($elapsed, 0.001);
    }

    /**
     * A function giving each of its variables as many values by one
     * assignment, the arms of a match, then reading the pattern all of
     * them are joined into: each operand is an exact set, and only their
     * product leaves the argument unread.
     */
    private static function productOf(int $operands, int $values): string
    {
        $code = "<?php\n\nfunction f(string \$s, int \$k): int\n{\n";
        $joined = "'/^'";
        for ($operand = 1; $operand <= $operands; $operand++) {
            $code .= \sprintf("    \$v%d = match (\$k) {\n", $operand);
            for ($value = 1; $value < $values; $value++) {
                $code .= \sprintf("        %d => 'o%dv%d',\n", $value, $operand, $value);
            }
            $code .= \sprintf("        default => 'o%dv0',\n    };\n", $operand);
            $joined .= \sprintf(' . $v%d', $operand);
        }

        return $code."\n    return preg_match(".$joined." . '$/', \$s);\n}\n";
    }

    private function timeComposedReads(ExtractorInterface $strategy, int $reads): float
    {
        $code = "<?php\n\nfunction f(\$s)\n{\n";
        for ($i = 0; $i < $reads; $i++) {
            $code .= \sprintf("    \$part = 'a%d'; preg_match('/' . \$part . '/', \$s);\n", $i);
        }
        $file = $this->makeProject(['composed.php' => $code."}\n"]).'/composed.php';

        $this->assertInstanceOf(ArgumentAwareInterface::class, $strategy);
        $start = CpuClock::nanoseconds();
        $read = $strategy->extractWithArguments([$file]);
        $elapsed = (CpuClock::nanoseconds() - $start) / 1e9;

        $patterns = [];
        $states = [];
        foreach ($read as $occurrence) {
            if (null === $occurrence->state) {
                $patterns[] = $occurrence->pattern;
            } else {
                $states[$occurrence->state] = ($states[$occurrence->state] ?? 0) + 1;
            }
        }

        // One assignment is written before the first read alone.
        ksort($states);
        $this->assertSame(['/a0/'], $patterns);
        $this->assertSame(['constant' => 1, 'opaque' => $reads - 1], $states);

        return max($elapsed, 0.001);
    }

    private function timeReading(ExtractorInterface $strategy, int $calls): float
    {
        $code = "<?php\n\nfunction f(\$s)\n{\n";
        for ($i = 0; $i < $calls; $i++) {
            $code .= \sprintf("    \$re = '/a%d/'; preg_match(\$re, \$s);\n", $i);
        }
        $file = $this->makeProject(['same.php' => $code."}\n"]).'/same.php';

        $patterns = CpuClock::timed(static fn () => self::patterns($strategy, $file), $elapsed);

        $this->assertCount($calls, $patterns);

        return max($elapsed, 0.001);
    }

    /**
     * @return iterable<string, ExtractorInterface>
     */
    private function strategies(): iterable
    {
        yield 'tokenizer' => new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native());
        if (class_exists(ParserFactory::class)) {
            yield 'php-parser' => new PhpParserExtractionStrategy([], PatternFunctionRegistry::native());
        }
    }

    /**
     * @return list<string>
     */
    private static function patterns(ExtractorInterface $strategy, string $file): array
    {
        return array_values(array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->pattern, $strategy->extract([$file])));
    }
}
