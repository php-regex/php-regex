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
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the reading of names costs stays bounded: a constant folded to a
 * string past 64 KiB proves nothing, and a variable assigned and read
 * thousands of times in one body is read in time that grows with it, not
 * with its square.
 */
final class ResolutionLimitsTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_a_constant_doubled_past_the_bound_proves_nothing(): void
    {
        $code = "<?php\n\nfinal class Blow\n{\n    const C0 = 'ab';\n";
        for ($i = 1; $i <= 24; $i++) {
            $code .= \sprintf("    const C%d = self::C%d . self::C%d;\n", $i, $i - 1, $i - 1);
        }
        $code .= "    const RE = '/' . self::C24 . '/';\n\n    public function m(string \$s): int\n    {\n        return preg_match(self::RE, \$s);\n    }\n}\n";
        $file = $this->makeProject(['blow.php' => $code]).'/blow.php';

        foreach ($this->strategies() as $name => $strategy) {
            memory_reset_peak_usage();
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
        foreach ($this->strategies() as $name => $strategy) {
            $small = $this->timeReading($strategy, 500);
            $large = $this->timeReading($strategy, 4000);

            $this->assertLessThan(12 * $small, $large, \sprintf('%s: %.3fs for 500 calls, %.3fs for 4000.', $name, $small, $large));
        }
    }

    private function timeReading(ExtractorInterface $strategy, int $calls): float
    {
        $code = "<?php\n\nfunction f(\$s)\n{\n";
        for ($i = 0; $i < $calls; $i++) {
            $code .= \sprintf("    \$re = '/a%d/'; preg_match(\$re, \$s);\n", $i);
        }
        $file = $this->makeProject(['same.php' => $code."}\n"]).'/same.php';

        $start = hrtime(true);
        $patterns = self::patterns($strategy, $file);
        $elapsed = (hrtime(true) - $start) / 1e9;

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
