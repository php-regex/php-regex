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
 * with its square, and the values of several operands are not multiplied
 * past the most a composition may give.
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
     * A function assigning each of its variables as many values, one in
     * each branch, then reading the pattern all of them are joined into.
     */
    private static function productOf(int $operands, int $values): string
    {
        $code = "<?php\n\nfunction f(string \$s, int \$k): int\n{\n";
        $joined = "'/^'";
        for ($operand = 1; $operand <= $operands; $operand++) {
            $code .= \sprintf("    \$v%d = 'o%dv0';\n", $operand, $operand);
            for ($value = 1; $value < $values; $value++) {
                $code .= \sprintf("    if (%d === \$k) {\n        \$v%d = 'o%dv%d';\n    }\n", $value, $operand, $operand, $value);
            }
            $joined .= \sprintf(' . $v%d', $operand);
        }

        return $code."\n    return preg_match(".$joined." . '$/', \$s);\n}\n";
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
