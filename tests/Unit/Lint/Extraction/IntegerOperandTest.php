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

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An integer as an operand of a concatenation reads as the string PHP
 * makes of it: an integer literal, signed or not, or a constant whose
 * value is one. The pattern is composed at the call and reported there.
 * A float, a boolean and null are not read: each is a part built at run
 * time, and the argument a raw shell.
 *
 * Every expected pattern is what PHP 8.4 gives for the same expression
 * (php -r 'var_dump("/^a{" . 3 . "}\$/");' and so on, see each row).
 *
 * Every case runs through both strategies.
 */
final class IntegerOperandTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{operand: string, pattern: string}>
     */
    public static function provideIntegerLiterals(): iterable
    {
        // '/^a{' . 3 . '}$/' is "/^a{3}$/", and preg_match() compiles it.
        yield 'an integer' => ['operand' => '3', 'pattern' => '/^a{3}$/'];
        yield 'zero' => ['operand' => '0', 'pattern' => '/^a{0}$/'];
        // '/^a{' . -3 . '}$/' is "/^a{-3}$/".
        yield 'a negative integer' => ['operand' => '-3', 'pattern' => '/^a{-3}$/'];
        // '/^a{' . +3 . '}$/' is "/^a{3}$/".
        yield 'an integer with a plus sign' => ['operand' => '+3', 'pattern' => '/^a{3}$/'];
        // '/^a{' . -0 . '}$/' is "/^a{0}$/".
        yield 'minus zero' => ['operand' => '-0', 'pattern' => '/^a{0}$/'];
        yield 'a sign apart from its digits' => ['operand' => '- 3', 'pattern' => '/^a{-3}$/'];
        yield 'in parentheses' => ['operand' => '(3)', 'pattern' => '/^a{3}$/'];
        // PHP writes every integer in decimal: 0x10 is "16", 010 is "8",
        // 0o17 is "15", 0b11 is "3", 1_000 is "1000".
        yield 'hexadecimal' => ['operand' => '0x10', 'pattern' => '/^a{16}$/'];
        yield 'octal' => ['operand' => '010', 'pattern' => '/^a{8}$/'];
        yield 'octal with its prefix' => ['operand' => '0o17', 'pattern' => '/^a{15}$/'];
        yield 'binary' => ['operand' => '0b11', 'pattern' => '/^a{3}$/'];
        yield 'with a digit separator' => ['operand' => '1_000', 'pattern' => '/^a{1000}$/'];
        // PCRE refuses it ("number too big in {} quantifier"): an error of
        // the pattern, which is read all the same.
        yield 'a number too big for a quantifier' => ['operand' => '999999', 'pattern' => '/^a{999999}$/'];
        // 9223372036854775807 is the largest integer of a 64-bit PHP.
        yield 'the largest integer' => ['operand' => '9223372036854775807', 'pattern' => '/^a{9223372036854775807}$/'];
    }

    #[Test]
    #[DataProvider('provideIntegerLiterals')]
    public function test_an_integer_literal_reads_as_its_decimal_string(string $operand, string $pattern): void
    {
        $argument = "'/^a{' . ".$operand." . '}$/'";
        $code = self::matcher($argument);

        // Composed at the call: reported where the argument starts, with
        // no name to go through.
        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{operand: string, pattern: string}>
     */
    public static function provideIntegerConstants(): iterable
    {
        yield 'a class constant' => ['operand' => 'self::MAX', 'pattern' => '/^a{3}$/'];
        yield 'a negative class constant' => ['operand' => 'self::BELOW', 'pattern' => '/^a{-3}$/'];
        yield 'a class constant naming another' => ['operand' => 'self::ALIAS', 'pattern' => '/^a{3}$/'];
        yield 'a constant of the file' => ['operand' => 'LIMIT', 'pattern' => '/^a{12}$/'];
        yield 'a constant of the file, fully qualified' => ['operand' => '\\LIMIT', 'pattern' => '/^a{12}$/'];
        yield 'the value of an enum case' => ['operand' => 'Size::Small->value', 'pattern' => '/^a{2}$/'];
    }

    #[Test]
    #[DataProvider('provideIntegerConstants')]
    public function test_a_constant_holding_an_integer_reads_as_its_decimal_string(string $operand, string $pattern): void
    {
        $argument = "'/^a{' . ".$operand." . '}$/'";
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Two integers, an integer first, an integer last: each operand reads
     * on its own. '/a{' . 2 . ',' . 5 . '}/' is "/a{2,5}/"; '#^\d{' . 4
     * is "#^\d{4" and 4 . '/a/' is "4/a/" in PHP, whatever PCRE makes of
     * them.
     */
    #[Test]
    public function test_integers_read_wherever_they_stand_in_the_concatenation(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                return preg_match('/a{' . 2 . ',' . 5 . '}/', $s)
                    + preg_match('#^\d{' . 4, $s)
                    + preg_match(4 . '/a/', $s)
                    + preg_match('/^' . 4 . 2 . '$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/a{2,5}/', 'at' => "'/a{' . 2 . ',' . 5 . '}/'", 'location' => null],
            ['pattern' => '#^\d{4', 'at' => "'#^\\d{' . 4", 'location' => null],
            ['pattern' => '4/a/', 'at' => "4 . '/a/'", 'location' => null],
            ['pattern' => '/^42$/', 'at' => "'/^' . 4 . 2 . '$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/a{' . 2 . ',' . 5 . '}/'"],
            ['state' => 'constant', 'expression' => "'#^\\d{' . 4"],
            ['state' => 'constant', 'expression' => "4 . '/a/'"],
            ['state' => 'constant', 'expression' => "'/^' . 4 . 2 . '$/'"],
        ], $code);
    }

    /**
     * A constant or a variable built with an integer is read where its
     * concatenation is written, as one built with a string constant is.
     */
    #[Test]
    public function test_a_name_built_with_an_integer_is_read_at_its_concatenation(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const MAX = 3;
                private const RE = '/^c{' . self::MAX . '}$/';

                public function run(string $s): int
                {
                    $re = '/^v{1,' . self::MAX . '}$/';

                    return preg_match(self::RE, $s) + preg_match($re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^c{3}$/', 'at' => "'/^c{' . self::MAX . '}$/'", 'location' => 'via self::RE'],
            ['pattern' => '/^v{1,3}$/', 'at' => "'/^v{1,' . self::MAX . '}$/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $s)'],
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * PHP would write each of these too ('/^a{' . 1.5 . '}$/' is
     * "/^a{1.5}$/", 1.0 gives "1", true "1", false and null ""), but only
     * an integer is read: a float's string depends on the precision
     * setting, and a boolean or null in a pattern is no count anyone
     * wrote. 9223372036854775808 is a float in PHP
     * (9.2233720368547758E+18), as -9223372036854775808 is.
     *
     * @return iterable<string, array{operand: string}>
     */
    public static function provideOperandsThatAreNoInteger(): iterable
    {
        yield 'a float' => ['operand' => '1.5'];
        yield 'a float with no fraction' => ['operand' => '1.0'];
        yield 'a float with no integer part' => ['operand' => '.5'];
        yield 'a float with an exponent' => ['operand' => '1e3'];
        yield 'a negative float' => ['operand' => '-1.5'];
        yield 'one past the largest integer' => ['operand' => '9223372036854775808'];
        yield 'the smallest integer, a float as a literal' => ['operand' => '-9223372036854775808'];
        yield 'true' => ['operand' => 'true'];
        yield 'false' => ['operand' => 'false'];
        yield 'null' => ['operand' => 'null'];
        yield 'a constant holding a float' => ['operand' => 'self::RATIO'];
        yield 'a constant holding a boolean' => ['operand' => 'self::ENABLED'];
        yield 'a constant holding null' => ['operand' => 'self::NOTHING'];
        // No integer literal: an expression, which the file does not fold.
        yield 'a sum of integers' => ['operand' => '(1 + 2)'];
        yield 'a constant holding a sum' => ['operand' => 'self::SUM'];
        yield 'an integer cast to a string at run time' => ['operand' => '(string) $count'];
        yield 'a parameter typed int' => ['operand' => '$count'];
    }

    #[Test]
    #[DataProvider('provideOperandsThatAreNoInteger')]
    public function test_an_operand_that_is_no_integer_is_a_raw_hole(string $operand): void
    {
        $argument = "'/^a{' . ".$operand." . '}$/'";
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * What the library and its lint say of a pattern read through an
     * integer is what the engine says of it: PHP 8.4.27 with PCRE2 10.49 compiles /^a{3}$/
     * and /^a{65535}$/, and refuses /^a{65536}$/ and /^a{999999}$/ ("number
     * too big in {} quantifier").
     */
    #[Test]
    #[DataProvider('provideQuantifierBounds')]
    public function test_a_pattern_read_through_an_integer_is_judged_as_the_engine_judges_it(string $operand, bool $compiles): void
    {
        $read = $this->patternsReadByBoth(self::matcher("'/^a{' . ".$operand." . '}$/'"));

        $this->assertCount(1, $read);
        $this->assertSame($compiles, EngineProbe::compiles($read[0]));
        $this->assertSame($compiles, RegexParser::create()->validate($read[0])->isValid);

        // The lint reports an error for the pattern exactly where the engine refuses it.
        $issues = (new AnalysisService(RegexParser::create()))->lint([PatternOccurrence::found($read[0], 'bounds.php', 1)]);
        $errors = array_values(array_filter($issues, static fn (LintIssue $issue): bool => $issue->severity->failsRun()));
        $this->assertSame($compiles, [] === $errors, implode(' ', array_map(static fn (LintIssue $issue): string => $issue->message, $errors)));
    }

    /**
     * @return iterable<string, array{operand: string, compiles: bool}>
     */
    public static function provideQuantifierBounds(): iterable
    {
        yield 'a small bound' => ['operand' => '3', 'compiles' => true];
        yield 'the largest bound' => ['operand' => '65535', 'compiles' => true];
        yield 'one past the largest bound' => ['operand' => '65536', 'compiles' => false];
        yield 'a number too big for a quantifier' => ['operand' => '999999', 'compiles' => false];
    }

    /**
     * An enum, a constant of the file, and a final class holding integer
     * and other constants, whose method run() reads the argument in
     * preg_match().
     */
    private static function matcher(string $argument): string
    {
        return "<?php\n\nconst LIMIT = 12;\n\nenum Size: int\n{\n    case Small = 2;\n}\n\nfinal class Matcher\n{\n"
            ."    private const MAX = 3;\n    private const BELOW = -3;\n    private const ALIAS = self::MAX;\n"
            ."    private const RATIO = 1.5;\n    private const ENABLED = true;\n    private const NOTHING = null;\n    private const SUM = 1 + 2;\n\n"
            ."    public function run(string \$s, int \$count): int\n    {\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
