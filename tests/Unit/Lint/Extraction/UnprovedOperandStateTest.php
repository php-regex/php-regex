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

use PHPRegex\Tests\Support\ExtractsAcrossFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An operand of a pattern the file does not prove comes in two kinds,
 * and the state of the argument tells them apart.
 *
 * One the file names without proving its text is a part known at run
 * time only: what is written around it is kept, and the argument is a
 * pattern around such a part, a "raw shell". An array where a string is
 * joined, a constant PHP cannot hold the value written for, a name of a
 * class the file does not pin down, an item or a turn of something that
 * is no array the file proves.
 *
 * One of which nothing is known leaves nothing of the argument: a
 * variable two assignments reach, joined to other texts or read for an
 * item, a format or a separator, would give a pattern the code never
 * builds.
 *
 * Neither holds a pattern to lint.
 *
 * PHP 8.4 (php -r): '/a' . ['x'] . 'b/' is "/aArrayb/", with the warning
 * "Array to string conversion".
 *
 * Every case runs through both strategies.
 */
final class UnprovedOperandStateTest extends TestCase
{
    use ExtractsAcrossFiles;

    /**
     * @return iterable<string, array{code: string, argument: string}>
     */
    public static function providePartsKnownAtRunTimeOnly(): iterable
    {
        yield 'an array constant joined to strings' => [
            'code' => self::matcher("    private const WORDS = ['a', 'b'];", "'/^' . self::WORDS . '$/'"),
            'argument' => "'/^' . self::WORDS . '$/'",
        ];

        yield 'an array constant in a place of sprintf()' => [
            'code' => self::matcher("    private const WORDS = ['a', 'b'];", "sprintf('/^%s$/', self::WORDS)"),
            'argument' => "sprintf('/^%s$/', self::WORDS)",
        ];

        yield 'an item of an array a property holds' => [
            'code' => self::matcher("    private array \$words = ['k' => 'a'];", "'/^' . \$this->words['k'] . '$/'"),
            'argument' => "'/^' . \$this->words['k'] . '$/'",
        ];

        // PHP reads one byte of the string: which one is not followed.
        yield 'an offset of a string constant' => [
            'code' => self::matcher("    private const WORD = 'abc';", "'/^' . self::WORD[\$i] . '$/'"),
            'argument' => "'/^' . self::WORD[\$i] . '$/'",
        ];

        yield 'an item of a constant that is one of two arrays' => [
            'code' => self::matcher("    private const WORDS = \\PHP_INT_SIZE === 8 ? ['k' => 'a'] : ['k' => 'b'];", "'/^' . self::WORDS['k'] . '$/'"),
            'argument' => "'/^' . self::WORDS['k'] . '$/'",
        ];

        yield 'a constant the file declares twice' => [
            'code' => "<?php\n\nconst WORD = 'a';\nconst WORD = 'b';\n\nfunction run(string \$s): int\n{\n    return preg_match('/^' . WORD . '$/', \$s);\n}\n",
            'argument' => "'/^' . WORD . '$/'",
        ];

        // self:: names the class that uses the trait.
        yield 'a constant read through self:: in a trait' => [
            'code' => "<?php\n\ntrait Matching\n{\n    private const WORD = 'a';\n\n    public function run(string \$s): int\n    {\n        return preg_match('/^' . self::WORD . '$/', \$s);\n    }\n}\n",
            'argument' => "'/^' . self::WORD . '$/'",
        ];

        // The array_map() of the namespace may bind the closure to
        // another class.
        yield 'a constant read through self:: in a closure given to a function of the namespace' => [
            'code' => "<?php\n\nnamespace App;\n\nfunction array_map(callable \$callback, array \$items): array\n{\n    return [];\n}\n\nfinal class Matcher\n{\n    private const WORD = 'a';\n\n    public function run(array \$lines): array\n    {\n        return array_map(static fn (string \$s): int => preg_match('/^' . self::WORD . '$/', \$s), \$lines);\n    }\n}\n",
            'argument' => "'/^' . self::WORD . '$/'",
        ];

        yield 'the target of a foreach the loop appends to' => [
            'code' => "<?php\n\nfunction run(string \$s): void\n{\n    foreach (['a', 'b'] as \$word) {\n        \$word .= '+';\n        preg_match('/^' . \$word . '$/', \$s);\n    }\n}\n",
            'argument' => "'/^' . \$word . '$/'",
        ];

        yield 'the target of a foreach over an array a property holds' => [
            'code' => "<?php\n\nfinal class Matcher\n{\n    private array \$words = ['a', 'b'];\n\n    public function run(string \$s): void\n    {\n        foreach (\$this->words as \$word) {\n            preg_match('/^' . \$word . '$/', \$s);\n        }\n    }\n}\n",
            'argument' => "'/^' . \$word . '$/'",
        ];

        yield 'the target of a foreach over a string constant' => [
            'code' => "<?php\n\nfinal class Matcher\n{\n    private const WORD = 'abc';\n\n    public function run(string \$s): void\n    {\n        foreach (self::WORD as \$letter) {\n            preg_match('/^' . \$letter . '$/', \$s);\n        }\n    }\n}\n",
            'argument' => "'/^' . \$letter . '$/'",
        ];
    }

    #[Test]
    #[DataProvider('providePartsKnownAtRunTimeOnly')]
    public function test_a_part_known_at_run_time_only_keeps_what_is_written_around_it(string $code, string $argument): void
    {
        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * @return iterable<string, array{setup: string, argument: string}>
     */
    public static function provideOperandsNothingIsKnownOf(): iterable
    {
        yield 'an item of a variable two assignments reach' => [
            'setup' => "\$words = ['k' => 'a'];\n    if (\$flag) {\n        \$words = ['k' => 'b'];\n    }",
            'argument' => "'/^' . \$words['k'] . '$/'",
        ];

        yield 'a format two assignments reach' => [
            'setup' => "\$format = '(%s)';\n    if (\$flag) {\n        \$format = '[%s]';\n    }",
            'argument' => "'/^' . sprintf(\$format, 'a') . '$/'",
        ];

        yield 'a separator two assignments reach' => [
            'setup' => "\$separator = '|';\n    if (\$flag) {\n        \$separator = ',';\n    }",
            'argument' => "'/^' . implode(\$separator, ['a', 'b']) . '$/'",
        ];
    }

    #[Test]
    #[DataProvider('provideOperandsNothingIsKnownOf')]
    public function test_an_operand_nothing_is_known_of_leaves_the_argument_opaque(string $setup, string $argument): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag): int\n{\n    ".$setup."\n\n    return preg_match(".$argument.", \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The first assignment joins two constants of two values each: two
     * operands of several values are never multiplied, and nothing is
     * known of what it gives. The pattern the second assignment writes is
     * not read for the variable either: the argument is not that one
     * pattern.
     */
    #[Test]
    public function test_an_assignment_nothing_is_known_of_leaves_the_others_unread(): void
    {
        $code = <<<'PHP_WRAP'
            <?php

            final class Matcher
            {
                private const OPENING = \PHP_INT_SIZE === 8 ? '/^a' : '/^b';
                private const CLOSING = \PHP_OS_FAMILY === 'Windows' ? 'c$/' : 'd$/';

                public function run(string $s, bool $flag): int
                {
                    $re = self::OPENING . self::CLOSING;
                    if ($flag) {
                        $re = '/written/';
                    }

                    return preg_match($re, $s);
                }
            }
            PHP_WRAP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * A constant two files of the project declare is one of two texts,
     * which the reading does not choose between: a part known at run
     * time only. One a single file declares is read.
     */
    #[Test]
    public function test_a_global_constant_two_files_declare_is_a_part_known_at_run_time_only(): void
    {
        $files = [
            'src/matcher.php' => "<?php\n\nfunction run(string \$s): int\n{\n    return preg_match('/^' . DECLARED_TWICE . '$/', \$s) + preg_match('/^' . DECLARED_ONCE . '$/', \$s);\n}\n",
            'src/first.php' => "<?php\n\nconst DECLARED_TWICE = 'one';\nconst DECLARED_ONCE = 'only';\n",
            'src/second.php' => "<?php\n\nconst DECLARED_TWICE = 'two';\n",
        ];

        $this->assertSame(['/^only$/'], $this->patternsAcross($files));
        $this->assertSame([
            ['file' => 'src/matcher.php', 'expression' => "'/^' . DECLARED_TWICE . '$/'", 'state' => 'raw_shell'],
            ['file' => 'src/matcher.php', 'expression' => "'/^' . DECLARED_ONCE . '$/'", 'state' => 'constant'],
        ], $this->argumentsAcross($files));
    }

    private static function matcher(string $members, string $argument): string
    {
        return "<?php\n\nfinal class Matcher\n{\n".$members."\n\n    public function run(string \$s, int \$i): int\n    {\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
