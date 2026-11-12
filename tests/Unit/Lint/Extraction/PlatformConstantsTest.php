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
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Tests\Support\EngineProbe;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Three constants of PHP depend on the system the code runs on, and each
 * has two values: DIRECTORY_SEPARATOR is "/" or "\", PHP_EOL a line feed
 * or a carriage return and a line feed, PATH_SEPARATOR ":" or ";". Joined
 * to literals, one of them gives two patterns, composed at the call and
 * reported where the argument starts; each pattern says which value of
 * the constant it stands for, and every other occurrence says none.
 *
 * The same constant standing twice in one composition has one value on
 * one system: two patterns, not four. Two of these constants, or one
 * beside another operand of several values, would give strings no system
 * builds: nothing is known of the argument. No other constant of PHP is
 * read.
 *
 * The engine, PHP 8.4 and PCRE2 10.49 (php -r): '~' . '/' . '~' matches
 * "/", '~' . '\\' . '~' is "No ending delimiter '~' found"; '#^[^' . '/'
 * . ']+#' compiles, '#^[^' . '\\' . ']+#' is "Compilation failed: missing
 * terminating ] for character class at offset 6"; '#^' . '\\' . 'x' .
 * '\\' . '#' is "No ending delimiter '#' found". "/^a\nb$/" matches
 * "a\nb", "/^a\r\nb$/" does not; '/^[^:]+:/' matches "a:b", '/^[^;]+;/'
 * does not.
 *
 * Every case runs through both strategies.
 */
final class PlatformConstantsTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Each row gives the argument and its two patterns, in the order of
     * their bytes, each with the value of the constant it stands for,
     * spelled as PHP writes the string between double quotes.
     *
     * @return iterable<string, array{argument: string, candidates: list<array{pattern: string, variant: string}>}>
     */
    public static function provideCompositionsOfOnePlatformConstant(): iterable
    {
        yield 'DIRECTORY_SEPARATOR' => [
            'argument' => "'~^' . DIRECTORY_SEPARATOR . '~'",
            'candidates' => [
                ['pattern' => '~^/~', 'variant' => 'DIRECTORY_SEPARATOR is "/"'],
                ['pattern' => '~^\~', 'variant' => 'DIRECTORY_SEPARATOR is "\"'],
            ],
        ];

        yield 'DIRECTORY_SEPARATOR, fully qualified' => [
            'argument' => "'#^[^' . \\DIRECTORY_SEPARATOR . ']+#'",
            'candidates' => [
                ['pattern' => '#^[^/]+#', 'variant' => 'DIRECTORY_SEPARATOR is "/"'],
                ['pattern' => '#^[^\]+#', 'variant' => 'DIRECTORY_SEPARATOR is "\"'],
            ],
        ];

        // '~^src' . '\\' . '.+\.php$~' is "~^src\.+\.php$~": it compiles, and
        // asks for dots where the other asks for a separator.
        yield 'DIRECTORY_SEPARATOR before a literal it changes the meaning of' => [
            'argument' => "'~^src' . DIRECTORY_SEPARATOR . '.+\\.php$~'",
            'candidates' => [
                ['pattern' => '~^src/.+\.php$~', 'variant' => 'DIRECTORY_SEPARATOR is "/"'],
                ['pattern' => '~^src\.+\.php$~', 'variant' => 'DIRECTORY_SEPARATOR is "\"'],
            ],
        ];

        yield 'DIRECTORY_SEPARATOR in sprintf()' => [
            'argument' => "sprintf('~^src%stests~', DIRECTORY_SEPARATOR)",
            'candidates' => [
                ['pattern' => '~^src/tests~', 'variant' => 'DIRECTORY_SEPARATOR is "/"'],
                ['pattern' => '~^src\tests~', 'variant' => 'DIRECTORY_SEPARATOR is "\"'],
            ],
        ];

        yield 'PATH_SEPARATOR' => [
            'argument' => "'/^[^' . PATH_SEPARATOR . ']+/'",
            'candidates' => [
                ['pattern' => '/^[^:]+/', 'variant' => 'PATH_SEPARATOR is ":"'],
                ['pattern' => '/^[^;]+/', 'variant' => 'PATH_SEPARATOR is ";"'],
            ],
        ];

        yield 'PATH_SEPARATOR, fully qualified' => [
            'argument' => "'#' . \\PATH_SEPARATOR . '#'",
            'candidates' => [
                ['pattern' => '#:#', 'variant' => 'PATH_SEPARATOR is ":"'],
                ['pattern' => '#;#', 'variant' => 'PATH_SEPARATOR is ";"'],
            ],
        ];

        // The value is spelled as PHP writes the string between double
        // quotes: a backslash and "n", not a line feed.
        yield 'PHP_EOL' => [
            'argument' => "'/^a' . PHP_EOL . 'b$/'",
            'candidates' => [
                ['pattern' => "/^a\nb$/", 'variant' => 'PHP_EOL is "\n"'],
                ['pattern' => "/^a\r\nb$/", 'variant' => 'PHP_EOL is "\r\n"'],
            ],
        ];

        yield 'PHP_EOL, fully qualified' => [
            'argument' => "'/' . \\PHP_EOL . '$/'",
            'candidates' => [
                ['pattern' => "/\n$/", 'variant' => 'PHP_EOL is "\n"'],
                ['pattern' => "/\r\n$/", 'variant' => 'PHP_EOL is "\r\n"'],
            ],
        ];

        // use const DIRECTORY_SEPARATOR as DS: the name is the constant's.
        yield 'DIRECTORY_SEPARATOR imported under another name' => [
            'argument' => "'~^' . DS . '~'",
            'candidates' => [
                ['pattern' => '~^/~', 'variant' => 'DIRECTORY_SEPARATOR is "/"'],
                ['pattern' => '~^\~', 'variant' => 'DIRECTORY_SEPARATOR is "\"'],
            ],
        ];
    }

    /**
     * @param list<array{pattern: string, variant: string}> $candidates
     */
    #[Test]
    #[DataProvider('provideCompositionsOfOnePlatformConstant')]
    public function test_one_platform_constant_in_a_composition_gives_two_patterns(string $argument, array $candidates): void
    {
        $code = self::matcher($argument);

        $expected = [];
        foreach ($candidates as $candidate) {
            $expected[] = ['pattern' => $candidate['pattern'], 'at' => $argument, 'source' => 'preg_match()', 'location' => null];
        }

        $this->assertExtractedByBoth($expected, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);

        $read = $this->candidatesReadByBoth($code);
        $this->assertSame(array_column($candidates, 'pattern'), array_column($read, 'pattern'));

        $variants = array_column($read, 'variant');
        $this->assertCount(2, $variants);
        $this->assertSame(array_column($candidates, 'variant'), $variants);
        $this->assertNotSame($variants[0], $variants[1], 'The two patterns stand for two values.');
    }

    /**
     * The patterns the rows above expect are what the engine is given on
     * each system: one compiles where the other does not.
     */
    #[Test]
    public function test_the_two_patterns_are_what_the_engine_is_given_on_each_system(): void
    {
        $this->assertTrue(EngineProbe::compiles('~^/~'));
        $this->assertFalse(EngineProbe::compiles('~^\\~'), "Oracle: PHP finds no ending delimiter in '~^\\~'.");
        $this->assertTrue(EngineProbe::compiles('#^[^/]+#'));
        $this->assertFalse(EngineProbe::compiles('#^[^\\]+#'), 'Oracle: the class of "#^[^\]+#" is not closed.');
        $this->assertTrue(EngineProbe::compiles('#^/x/#'));
        $this->assertFalse(EngineProbe::compiles('#^\\x\\#'), "Oracle: PHP finds no ending delimiter in '#^\\x\\#'.");
        $this->assertTrue(EngineProbe::compiles("/^a\nb\$/"));
        $this->assertTrue(EngineProbe::compiles("/^a\r\nb\$/"));

        // The values on this system are one of the two the rows give.
        $this->assertContains(\DIRECTORY_SEPARATOR, ['/', '\\']);
        $this->assertContains(\PHP_EOL, ["\n", "\r\n"]);
        $this->assertContains(\PATH_SEPARATOR, [':', ';']);
    }

    /**
     * The same constant twice has one value in each pattern: two
     * patterns, the separator the same at both places. The constant may
     * be the delimiter itself.
     *
     * PHP 8.4: '#^' . '/' . 'x' . '/' . '#' is "#^/x/#" and compiles;
     * '#^' . '\\' . 'x' . '\\' . '#' is "#^\x\#", "No ending delimiter '#'
     * found"; sprintf('#^%s[^%s]+#', '\\', '\\') is "#^\[^\]+#".
     */
    #[Test]
    public function test_the_same_platform_constant_twice_has_one_value_in_each_pattern(): void
    {
        $code = <<<'PHP_WRAP'
            <?php

            function run(string $s): int
            {
                return preg_match('#^' . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . '#', $s)
                    + preg_match(sprintf('#^%s[^%s]+#', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR), $s)
                    + preg_match(DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR, $s)
                    + preg_match('/a' . PHP_EOL . 'b' . PHP_EOL . '/', $s);
            }
            PHP_WRAP;

        $first = "'#^' . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . '#'";
        $second = "sprintf('#^%s[^%s]+#', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR)";
        $third = "DIRECTORY_SEPARATOR . 'vendor' . \\DIRECTORY_SEPARATOR";
        $fourth = "'/a' . PHP_EOL . 'b' . PHP_EOL . '/'";

        $this->assertExtractedByBoth([
            ['pattern' => '#^/x/#', 'at' => $first, 'location' => null],
            ['pattern' => '#^\x\#', 'at' => $first, 'location' => null],
            ['pattern' => '#^/[^/]+#', 'at' => $second, 'location' => null],
            ['pattern' => '#^\[^\]+#', 'at' => $second, 'location' => null],
            ['pattern' => '/vendor/', 'at' => $third, 'location' => null],
            ['pattern' => '\vendor\\', 'at' => $third, 'location' => null],
            ['pattern' => "/a\nb\n/", 'at' => $fourth, 'location' => null],
            ['pattern' => "/a\r\nb\r\n/", 'at' => $fourth, 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $first],
            ['state' => 'finite_set', 'expression' => $second],
            ['state' => 'finite_set', 'expression' => $third],
            ['state' => 'finite_set', 'expression' => $fourth],
        ], $code);

        $this->assertSame([
            ['#^/x/#', 'DIRECTORY_SEPARATOR is "/"'],
            ['#^\x\#', 'DIRECTORY_SEPARATOR is "\"'],
            ['#^/[^/]+#', 'DIRECTORY_SEPARATOR is "/"'],
            ['#^\[^\]+#', 'DIRECTORY_SEPARATOR is "\"'],
            ['/vendor/', 'DIRECTORY_SEPARATOR is "/"'],
            ['\vendor\\', 'DIRECTORY_SEPARATOR is "\"'],
            ["/a\nb\n/", 'PHP_EOL is "\n"'],
            ["/a\r\nb\r\n/", 'PHP_EOL is "\r\n"'],
        ], array_map(static fn (array $row): array => [$row['pattern'], $row['variant']], $this->candidatesReadByBoth($code)));
    }

    /**
     * The constant alone is the argument: its two values are the two
     * patterns (PHP 8.4: preg_match('/', 'x') is "No ending delimiter '/'
     * found", preg_match('\\', 'x') "Delimiter must not be alphanumeric,
     * backslash, or NUL byte").
     */
    #[Test]
    public function test_a_platform_constant_alone_is_one_of_its_two_values(): void
    {
        $code = self::matcher('DIRECTORY_SEPARATOR');

        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => 'DIRECTORY_SEPARATOR', 'at' => 'DIRECTORY_SEPARATOR, $s)'],
        ], $code);
        $this->assertSame([
            ['/', 'DIRECTORY_SEPARATOR is "/"'],
            ['\\', 'DIRECTORY_SEPARATOR is "\"'],
        ], array_map(static fn (array $row): array => [$row['pattern'], $row['variant']], $this->candidatesReadByBoth($code)));
    }

    /**
     * Held in a variable or in a constant of the file, the constant is
     * read through it: the patterns composed of that name still say
     * which value of DIRECTORY_SEPARATOR they stand for.
     *
     * A constant has one value: the constant of the file standing twice
     * is the same separator at both places, two patterns. A variable is
     * no constant: standing twice, it is two operands of several values,
     * and nothing is read, as for any variable of several values.
     */
    #[Test]
    public function test_a_platform_constant_read_through_a_name_keeps_the_value_it_stands_for(): void
    {
        $code = <<<'PHP'
            <?php

            final class Paths
            {
                private const DS = \DIRECTORY_SEPARATOR;

                public function run(string $s): int
                {
                    $separator = DIRECTORY_SEPARATOR;

                    return preg_match('~^src' . self::DS . '~', $s)
                        + preg_match('#^lib' . $separator . '#', $s)
                        + preg_match('{^' . self::DS . 'bin' . self::DS . '}', $s)
                        + preg_match('%^' . $separator . 'etc' . $separator . '%', $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '~^src/~', 'at' => "'~^src' . self::DS . '~'", 'location' => null],
            ['pattern' => '~^src\~', 'at' => "'~^src' . self::DS . '~'", 'location' => null],
            ['pattern' => '#^lib/#', 'at' => "'#^lib' . \$separator . '#'", 'location' => null],
            ['pattern' => '#^lib\#', 'at' => "'#^lib' . \$separator . '#'", 'location' => null],
            ['pattern' => '{^/bin/}', 'at' => "'{^' . self::DS . 'bin' . self::DS . '}'", 'location' => null],
            ['pattern' => '{^\bin\}', 'at' => "'{^' . self::DS . 'bin' . self::DS . '}'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'~^src' . self::DS . '~'"],
            ['state' => 'finite_set', 'expression' => "'#^lib' . \$separator . '#'"],
            ['state' => 'finite_set', 'expression' => "'{^' . self::DS . 'bin' . self::DS . '}'"],
            ['state' => 'opaque', 'expression' => "'%^' . \$separator . 'etc' . \$separator . '%'"],
        ], $code);
        $this->assertSame([
            ['~^src/~', 'DIRECTORY_SEPARATOR is "/"'],
            ['~^src\~', 'DIRECTORY_SEPARATOR is "\"'],
            ['#^lib/#', 'DIRECTORY_SEPARATOR is "/"'],
            ['#^lib\#', 'DIRECTORY_SEPARATOR is "\"'],
            ['{^/bin/}', 'DIRECTORY_SEPARATOR is "/"'],
            ['{^\bin\}', 'DIRECTORY_SEPARATOR is "\"'],
        ], array_map(static fn (array $row): array => [$row['pattern'], $row['variant']], $this->candidatesReadByBoth($code)));
    }

    /**
     * Two operands of several values are not multiplied: no system has
     * "/" beside ";", and a variable of two values beside the constant
     * would give four strings where the code builds some of them.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideCompositionsOfSeveralOperandsOfSeveralValues(): iterable
    {
        yield 'two platform constants' => ['argument' => "'#^' . DIRECTORY_SEPARATOR . 'x' . PATH_SEPARATOR . '#'"];
        yield 'DIRECTORY_SEPARATOR and PHP_EOL' => ['argument' => "'#^' . DIRECTORY_SEPARATOR . PHP_EOL . '#'"];
        yield 'the three of them' => ['argument' => "'#^' . DIRECTORY_SEPARATOR . PATH_SEPARATOR . PHP_EOL . '#'"];
        yield 'a platform constant and a variable of two values' => ['argument' => "'#^' . DIRECTORY_SEPARATOR . \$two . '#'"];
        yield 'a platform constant and a ternary' => ['argument' => "'#^' . DIRECTORY_SEPARATOR . (\$flag ? 'a' : 'b') . '#'"];
        yield 'a platform constant and a constant holding a ternary' => ['argument' => "'#^' . DIRECTORY_SEPARATOR . self::WORD . '#'"];
        yield 'two platform constants in sprintf()' => ['argument' => "sprintf('#^%s-%s#', DIRECTORY_SEPARATOR, PATH_SEPARATOR)"];
    }

    #[Test]
    #[DataProvider('provideCompositionsOfSeveralOperandsOfSeveralValues')]
    public function test_a_platform_constant_beside_another_operand_of_several_values_is_opaque(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * What is not one of the three constants: a name in another case
     * (PHP 8.4: 'Undefined constant "directory_separator"'), any other
     * constant of PHP, and the constant handed to preg_quote(), whose
     * text is known at run time only.
     *
     * @return iterable<string, array{argument: string, state: string}>
     */
    public static function provideOperandsThatAreNoPlatformConstant(): iterable
    {
        yield 'the name in lower case' => ['argument' => "'~^' . directory_separator . '~'", 'state' => 'raw_shell'];
        yield 'PHP_OS_FAMILY' => ['argument' => "'~^' . PHP_OS_FAMILY . '~'", 'state' => 'raw_shell'];
        yield 'PHP_VERSION' => ['argument' => "'~^' . PHP_VERSION . '~'", 'state' => 'raw_shell'];
        yield 'PHP_OS' => ['argument' => "'~^' . \\PHP_OS . '~'", 'state' => 'raw_shell'];
        yield 'PHP_INT_MAX' => ['argument' => "'/^.{0,' . PHP_INT_MAX . '}$/'", 'state' => 'raw_shell'];
        yield 'a class constant of that name' => ['argument' => "'~^' . Other::DIRECTORY_SEPARATOR . '~'", 'state' => 'raw_shell'];
        yield 'DIRECTORY_SEPARATOR in preg_quote()' => ['argument' => "'~^' . preg_quote(DIRECTORY_SEPARATOR, '~') . '~'", 'state' => 'quoted_shell'];
        yield 'PHP_EOL in preg_quote()' => ['argument' => "'/' . preg_quote(PHP_EOL, '/') . '$/'", 'state' => 'quoted_shell'];
    }

    #[Test]
    #[DataProvider('provideOperandsThatAreNoPlatformConstant')]
    public function test_no_other_constant_of_php_is_read(string $argument, string $state): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A constant the namespace of the call declares under that name is
     * that constant, with its one value (PHP 8.4: namespace App; const
     * DIRECTORY_SEPARATOR = '/x/'; gives "/x/" for the bare name and "/"
     * for \DIRECTORY_SEPARATOR): the bare name is no platform constant
     * there, the qualified one still is.
     */
    #[Test]
    public function test_a_constant_of_the_namespace_named_like_a_platform_constant_is_its_own(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            const DIRECTORY_SEPARATOR = '|';

            function run(string $s): int
            {
                return preg_match('~^own' . DIRECTORY_SEPARATOR . '~', $s)
                    + preg_match('~^php' . \DIRECTORY_SEPARATOR . '~', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '~^own|~', 'at' => "'~^own' . DIRECTORY_SEPARATOR . '~'", 'location' => null],
            ['pattern' => '~^php/~', 'at' => "'~^php' . \\DIRECTORY_SEPARATOR . '~'", 'location' => null],
            ['pattern' => '~^php\~', 'at' => "'~^php' . \\DIRECTORY_SEPARATOR . '~'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'~^own' . DIRECTORY_SEPARATOR . '~'"],
            ['state' => 'finite_set', 'expression' => "'~^php' . \\DIRECTORY_SEPARATOR . '~'"],
        ], $code);
        $this->assertSame([
            ['~^own|~', null],
            ['~^php/~', 'DIRECTORY_SEPARATOR is "/"'],
            ['~^php\~', 'DIRECTORY_SEPARATOR is "\"'],
        ], array_map(static fn (array $row): array => [$row['pattern'], $row['variant']], $this->candidatesReadByBoth($code)));
    }

    /**
     * A pattern that is not built of a platform constant stands for no
     * value of one: a literal, a pattern of a set of literals, a pattern
     * composed of a variable.
     */
    #[Test]
    public function test_no_other_pattern_stands_for_a_value_of_a_platform_constant(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                $kind = $flag ? 'dog' : 'cat';

                return preg_match('/literal/', $s)
                    + preg_match($flag ? '/then/' : '/else/', $s)
                    + preg_match('/^' . $kind . '$/', $s)
                    + preg_match('/^' . $s . '$/', $s);
            }
            PHP;

        $this->assertSame([
            ['/literal/', null],
            ['/then/', null],
            ['/else/', null],
            ['/^cat$/', null],
            ['/^dog$/', null],
        ], array_map(static fn (array $row): array => [$row['pattern'], $row['variant']], $this->candidatesReadByBoth($code)));
    }

    /**
     * The patterns both strategies read in the code, in the order of
     * their positions then of their bytes, each with the value of a
     * platform constant it stands for. An argument stands for none.
     *
     * @return list<array{pattern: string, offset: int|null, variant: string|null}>
     */
    private function candidatesReadByBoth(string $code): array
    {
        $file = $this->makeProject(['subject.php' => $code]).'/subject.php';

        $fromTokens = $this->candidatesOf((new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))->extractWithArguments([$file]));
        if (class_exists(ParserFactory::class)) {
            $fromAst = $this->candidatesOf((new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()))->extractWithArguments([$file]));
            $this->assertSame($fromAst, $fromTokens, 'The token strategy reads otherwise than the parser strategy.');
        }

        return $fromTokens;
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<array{pattern: string, offset: int|null, variant: string|null}>
     */
    private function candidatesOf(array $occurrences): array
    {
        $candidates = [];
        foreach ($occurrences as $occurrence) {
            $variant = $this->variantOf($occurrence);
            if (null !== $occurrence->state) {
                $this->assertNull($variant, 'An argument stands for no value of a constant: its patterns do.');

                continue;
            }

            $candidates[] = ['pattern' => $occurrence->pattern, 'offset' => $occurrence->fileOffset, 'variant' => $variant];
        }

        usort($candidates, static fn (array $a, array $b): int => [$a['offset'], $a['pattern']] <=> [$b['offset'], $b['pattern']]);

        return $candidates;
    }

    /**
     * The value of a platform constant an occurrence stands for, as its
     * public member "variant" holds it: "DIRECTORY_SEPARATOR is "\"" for
     * instance, null for an occurrence built of no such constant.
     */
    private function variantOf(PatternOccurrence $occurrence): ?string
    {
        $members = get_object_vars($occurrence);
        $this->assertArrayHasKey('variant', $members, 'An occurrence says which value of a platform constant it stands for, in a public member "variant".');

        $variant = $members['variant'];
        if (null !== $variant) {
            $this->assertIsString($variant);
        }

        return $variant;
    }

    /**
     * A final class holding WORD, a constant of two values, whose method
     * run() gives $two one of two values by one assignment, then reads
     * the argument in preg_match(). The file imports DIRECTORY_SEPARATOR
     * as DS.
     */
    private static function matcher(string $argument): string
    {
        return "<?php\n\nuse const DIRECTORY_SEPARATOR as DS;\n\nfinal class Matcher\n{\n    private const WORD = \\PHP_INT_SIZE === 8 ? 'cat' : 'dog';\n\n"
            ."    public function run(string \$s, bool \$flag): int\n    {\n        \$two = \$flag ? 'a' : 'b';\n\n"
            .'        return preg_match('.$argument.", \$s);\n    }\n}\n";
    }
}
