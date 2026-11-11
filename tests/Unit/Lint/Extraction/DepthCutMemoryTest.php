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
 * A value is read through 32 constants at most, counted from the call.
 * One constant farther, the constant is a part the file does not prove,
 * and what is written around it is kept. What a call reads of a constant
 * depends on how far from it the call stands, and on nothing else: not
 * on the calls read before it, whether they read the constant in full or
 * were cut short on the way to it.
 *
 * Every case runs through both strategies.
 */
final class DepthCutMemoryTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * D1 is 32 constants from its text, and NEAR one more: read after D1
     * was read in full, NEAR is cut all the same, and the argument is a
     * pattern around a part not read.
     */
    #[Test]
    public function test_a_constant_read_in_full_before_is_cut_one_constant_farther(): void
    {
        $code = self::matcher(
            self::chain('D', 32, "'deep'")."    private const NEAR = self::D1 . '+';\n",
            ["'/^' . self::D1 . '$/'", "'/^' . self::NEAR . '$/'"],
        );

        $this->assertExtractedByBoth([
            ['pattern' => '/^deep$/', 'at' => "'/^' . self::D1 . '$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . self::D1 . '$/'"],
            ['state' => 'raw_shell', 'expression' => "'/^' . self::NEAR . '$/'"],
        ], $code);
    }

    /**
     * BOTH joins D2, read in full by the first call, and E1, not read
     * yet; each is 31 constants from its text. Through BOTH they are 32
     * from the call, and through FARTHER, which names BOTH, 33: both are
     * cut there. The same holds of BOTH_REVERSED and FARTHER_REVERSED,
     * read after E1 was cut once already. BOTH and BOTH_REVERSED, read
     * by the last two calls after their cuts, are read in full.
     */
    #[Test]
    public function test_a_composition_cut_twice_from_afar_is_read_in_full_from_nearer(): void
    {
        $code = self::matcher(
            self::chain('D', 32, "'deep'").self::chain('E', 31, "'far'")
                ."    private const BOTH = self::D2 . self::E1;\n"
                ."    private const FARTHER = self::BOTH;\n"
                ."    private const BOTH_REVERSED = self::E1 . self::D2;\n"
                ."    private const FARTHER_REVERSED = self::BOTH_REVERSED;\n",
            [
                "'/a' . self::D1 . '/'",
                "'/b' . self::FARTHER . '/'",
                "'/c' . self::FARTHER_REVERSED . '/'",
                "'/d' . self::BOTH . '/'",
                "'/e' . self::BOTH_REVERSED . '/'",
            ],
        );

        $this->assertExtractedByBoth([
            ['pattern' => '/adeep/', 'at' => "'/a' . self::D1 . '/'", 'location' => null],
            ['pattern' => '/ddeepfar/', 'at' => "'/d' . self::BOTH . '/'", 'location' => null],
            ['pattern' => '/efardeep/', 'at' => "'/e' . self::BOTH_REVERSED . '/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/a' . self::D1 . '/'"],
            ['state' => 'raw_shell', 'expression' => "'/b' . self::FARTHER . '/'"],
            ['state' => 'raw_shell', 'expression' => "'/c' . self::FARTHER_REVERSED . '/'"],
            ['state' => 'constant', 'expression' => "'/d' . self::BOTH . '/'"],
            ['state' => 'constant', 'expression' => "'/e' . self::BOTH_REVERSED . '/'"],
        ], $code);
    }

    /**
     * EITHER is "known", or E1, 31 constants from its text. Through ALIAS
     * and through SUFFIXED, E1 is 33 constants from the call: EITHER is
     * "known" or a part not read, both times, and no pattern is read for
     * "known" alone. Read by the last call, EITHER is one of two texts.
     */
    #[Test]
    public function test_a_constant_of_two_values_cut_from_afar_keeps_both_at_the_same_distance(): void
    {
        $code = self::matcher(
            self::chain('E', 31, "'far'")
                ."    private const EITHER = \\PHP_INT_SIZE === 8 ? 'known' : self::E1;\n"
                ."    private const ALIAS = self::EITHER;\n"
                ."    private const SUFFIXED = self::EITHER . '+';\n",
            [
                "'/^' . self::ALIAS . '$/'",
                "'/^' . self::SUFFIXED . '$/'",
                "'/^' . self::EITHER . '$/'",
            ],
        );

        $this->assertExtractedByBoth([
            ['pattern' => '/^far$/', 'at' => "'/^' . self::EITHER . '$/'", 'location' => null],
            ['pattern' => '/^known$/', 'at' => "'/^' . self::EITHER . '$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . self::ALIAS . '$/'"],
            ['state' => 'raw_shell', 'expression' => "'/^' . self::SUFFIXED . '$/'"],
            ['state' => 'finite_set', 'expression' => "'/^' . self::EITHER . '$/'"],
        ], $code);
    }

    /**
     * The constants {$prefix}1 to {$prefix}{$length} of a class, each
     * naming the next one and the last holding $last.
     */
    private static function chain(string $prefix, int $length, string $last): string
    {
        $constants = '';
        for ($i = 1; $i < $length; $i++) {
            $constants .= \sprintf("    private const %s%d = self::%s%d;\n", $prefix, $i, $prefix, $i + 1);
        }

        return $constants.\sprintf("    private const %s%d = %s;\n", $prefix, $length, $last);
    }

    /**
     * @param list<string> $arguments the pattern of each call, in the order the calls are written
     */
    private static function matcher(string $constants, array $arguments): string
    {
        $calls = '';
        foreach ($arguments as $argument) {
            $calls .= '        $found += preg_match('.$argument.", \$s);\n";
        }

        return "<?php\n\nfinal class Matcher\n{\n".$constants."\n    public function run(string \$s): int\n    {\n        \$found = 0;\n".$calls."\n        return \$found;\n    }\n}\n";
    }
}
