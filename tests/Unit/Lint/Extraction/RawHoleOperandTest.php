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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An operand of a concatenation the file does not prove no longer ends the
 * whole value: it is a raw hole, a part built at run time, and what is
 * written around it is kept. The argument is a static pattern around such
 * a part, a "raw shell", when its first part gives the delimiter; it holds
 * no pattern to lint.
 *
 * Every case runs through both strategies.
 */
final class RawHoleOperandTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string}>
     */
    public static function provideRawShells(): iterable
    {
        yield 'a parameter' => ['argument' => "'/^' . \$word . '$/'"];
        yield 'a parameter ending the argument' => ['argument' => "'/^' . \$word"];
        yield 'two parameters' => ['argument' => "'/^' . \$word . '-' . \$other . '$/'"];
        yield 'two parameters side by side' => ['argument' => "'/^' . \$word . \$other . '$/'"];
        yield 'a method call' => ['argument' => "'/^' . \$this->part() . '$/'"];
        yield 'a static method call' => ['argument' => "'/^' . Parts::word() . '$/'"];
        yield 'a function call' => ['argument' => "'/^' . build_part(\$word) . '$/'"];
        yield 'a property of another object' => ['argument' => "'/^' . \$config->part . '$/'"];
        yield 'an item of an array' => ['argument' => "'/^' . \$parts['word'] . '$/'"];
        yield 'a constant no file of the run declares' => ['argument' => "'/^' . UNDECLARED_PART . '$/'"];
        yield 'a constant of a class no file of the run declares' => ['argument' => "'/^' . \\Vendor\\Parts::WORD . '$/'"];
        yield 'a cast' => ['argument' => "'/^' . (string) \$word . '$/'"];
        yield 'in parentheses' => ['argument' => "'/^' . (\$word) . '$/'"];
        yield 'beside a constant part' => ['argument' => "'/' . self::ANCHOR . \$word . '$/'"];
        yield 'after two literals that make the opening' => ['argument' => "'/' . '^' . \$word . '$/'"];
        // Strings that follow each other are one string: the first part is "/^".
        yield 'after an empty literal and the opening' => ['argument' => "'' . '/^' . \$word . '$/'"];
        yield 'beside a multi-byte literal' => ['argument' => "'/^é' . \$word . 'è$/u'"];
        yield 'between bracket delimiters' => ['argument' => "'{^' . \$word . '$}i'"];
        yield 'opening a group' => ['argument' => "'/(' . \$word . ')+/'"];
        yield 'naming a group' => ['argument' => "'/(?<' . \$word . '>a)/'"];
        yield 'in a character class' => ['argument' => "'/[^' . \$word . ']+/'"];
        yield 'as the modifiers' => ['argument' => "'/abc/' . \$word"];
    }

    #[Test]
    #[DataProvider('provideRawShells')]
    public function test_an_operand_the_file_does_not_prove_is_a_raw_hole(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The delimiter opens the first part: with a hole there, an empty
     * first part, or one starting with the white space PHP skips before
     * the delimiter, nothing static is known.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideArgumentsWithoutADelimiter(): iterable
    {
        yield 'the parameter first' => ['argument' => "\$word . '/abc/'"];
        yield 'a parameter at both ends' => ['argument' => "\$word . 'abc' . \$word"];
        yield 'an empty literal first' => ['argument' => "'' . \$word . '/abc/'"];
        yield 'a first part starting with a space' => ['argument' => "' /^' . \$word . '$/'"];
        yield 'a first part starting with a tab' => ['argument' => "\"\\t/^\" . \$word . '$/'"];
        yield 'a call first' => ['argument' => "build_part(\$word) . '/i'"];
    }

    #[Test]
    #[DataProvider('provideArgumentsWithoutADelimiter')]
    public function test_a_hole_before_any_delimiter_leaves_the_argument_opaque(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The hole is kept through the names: a constant whose value holds a
     * part the file does not prove, a constant naming that constant, a
     * variable assigned a concatenation around a parameter — each is a
     * raw shell where a call reads it, and no pattern.
     */
    #[Test]
    public function test_a_raw_hole_is_kept_through_a_constant_and_a_variable(): void
    {
        $code = <<<'PHP'
            <?php

            namespace App;

            final class Matcher
            {
                private const SHELL = '/^' . \Vendor\Parts::WORD . '$/';
                private const ALIAS = self::SHELL;
                private const AROUND = self::SHELL . 'i';

                public function run(string $s, string $word): int
                {
                    $re = '/\b' . $word . '\b/';

                    return preg_match(self::SHELL, $s)
                        + preg_match(self::ALIAS, $s)
                        + preg_match(self::AROUND, $s)
                        + preg_match($re, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => 'self::SHELL', 'at' => 'self::SHELL, $s)'],
            ['state' => 'raw_shell', 'expression' => 'self::ALIAS', 'at' => 'self::ALIAS, $s)'],
            ['state' => 'raw_shell', 'expression' => 'self::AROUND', 'at' => 'self::AROUND, $s)'],
            ['state' => 'raw_shell', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * A raw shell beside whole patterns leaves them read: one argument
     * that is not whole takes nothing from the others.
     */
    #[Test]
    public function test_a_raw_shell_takes_nothing_from_the_arguments_beside_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $word): int
            {
                $re = '/variable-beside/';

                return preg_match('/literal-beside/', $s)
                    + preg_match('/^' . $word . '$/', $s)
                    + preg_match($re, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/variable-beside/', 'at' => "'/variable-beside/'", 'location' => 'via $re'],
            ['pattern' => '/literal-beside/', 'at' => "'/literal-beside/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/literal-beside/'"],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$word . '$/'"],
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
    }

    /**
     * A final class holding ANCHOR, whose method run() reads the argument
     * in preg_match().
     */
    private static function matcher(string $argument): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const ANCHOR = '^';\n\n"
            ."    public function run(string \$s, string \$word, string \$other, object \$config, array \$parts): int\n    {\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
