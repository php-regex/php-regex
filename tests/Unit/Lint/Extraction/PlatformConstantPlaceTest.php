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

use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP_EOL, DIRECTORY_SEPARATOR and PATH_SEPARATOR passed as the pattern
 * itself are a text no file writes: each of the two values stands where
 * the argument starts, on its line, and each argument that reads it
 * reports it, one read through a variable as one that names the
 * constant.
 *
 * Every case runs through both strategies.
 */
final class PlatformConstantPlaceTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * preg_split(PHP_EOL, $text) is the classic of the kind: a pattern
     * with no delimiter, on every system.
     */
    #[Test]
    public function test_every_argument_reading_a_platform_constant_through_one_variable_reports_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $first, string $second): array
            {
                $eol = PHP_EOL;

                return [preg_split($eol, $first), preg_split($eol, $second)];
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => "\n", 'at' => '$eol, $first)', 'location' => null],
            ['pattern' => "\r\n", 'at' => '$eol, $first)', 'location' => null],
            ['pattern' => "\n", 'at' => '$eol, $second)', 'location' => null],
            ['pattern' => "\r\n", 'at' => '$eol, $second)', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$eol', 'at' => '$eol, $first)', 'source' => 'preg_split()'],
            ['state' => 'finite_set', 'expression' => '$eol', 'at' => '$eol, $second)', 'source' => 'preg_split()'],
        ], $code);
    }

    /**
     * The file starts with an empty line, which PHP sends to the output:
     * the argument stands on the sixth line.
     */
    #[Test]
    public function test_a_platform_constant_stands_on_the_line_of_its_argument_in_a_file_starting_with_a_newline(): void
    {
        $code = "\n<?php\n\nfunction run(string \$s): array\n{\n    return preg_split(PHP_EOL, \$s);\n}\n";

        $this->assertSame(6, substr_count($code, "\n", 0, (int) strpos($code, 'PHP_EOL')) + 1);
        $this->assertExtractedByBoth([
            ['pattern' => "\n", 'at' => 'PHP_EOL, $s)', 'location' => null],
            ['pattern' => "\r\n", 'at' => 'PHP_EOL, $s)', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => 'PHP_EOL', 'source' => 'preg_split()'],
        ], $code);
    }
}
