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
 * preg_quote() as an operand of a concatenation is a quoted hole: a text
 * known at run time only, read as literal characters. The argument is a
 * static pattern around preg_quote() texts, a "quoted shell": it holds no
 * pattern to lint, and says so.
 *
 * PHP 8.4: preg_quote('a/b.c#d') is "a/b\.c\#d" and preg_quote('a/b.c#d',
 * '/') is "a\/b\.c\#d": the delimiter is escaped only when it is given, so
 * '/^' . preg_quote('a/b') . '$/' fails to compile ("Unknown modifier
 * 'b'") where the same with '/' as second argument matches. The state is
 * the same with or without it: no verdict is drawn from the delimiter.
 *
 * Every case runs through both strategies.
 */
final class PregQuoteHoleTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string}>
     */
    public static function provideQuotedShells(): iterable
    {
        yield 'with the delimiter of the pattern' => ['argument' => "'/^' . preg_quote(\$word, '/') . '$/'"];
        yield 'without a delimiter' => ['argument' => "'/^' . preg_quote(\$word) . '$/'"];
        yield 'with another delimiter than the pattern has' => ['argument' => "'/^' . preg_quote(\$word, '#') . '$/'"];
        yield 'with a delimiter that is no literal' => ['argument' => "'/^' . preg_quote(\$word, \$other) . '$/'"];
        yield 'with its arguments passed by name' => ['argument' => "'/^' . preg_quote(delimiter: '/', str: \$word) . '$/'"];
        yield 'fully qualified' => ['argument' => "'/^' . \\preg_quote(\$word, '/') . '$/'"];
        // PHP compares function names without case: PREG_QUOTE('a.b') is "a\.b".
        yield 'spelled in upper case' => ['argument' => "'/^' . PREG_QUOTE(\$word, '/') . '$/'"];
        yield 'two quoted texts' => ['argument' => "'/^' . preg_quote(\$word, '/') . '-' . preg_quote(\$other, '/') . '$/'"];
        yield 'two quoted texts side by side' => ['argument' => "'/^' . preg_quote(\$word, '/') . preg_quote(\$other, '/') . '$/'"];
        yield 'a quoted text ending the argument' => ['argument' => "'/^' . preg_quote(\$word, '/')"];
        yield 'of a constant' => ['argument' => "'/^' . preg_quote(self::WORD, '/') . '$/'"];
        yield 'of a literal' => ['argument' => "'/^' . preg_quote('a.b', '/') . '$/'"];
        yield 'of an expression' => ['argument' => "'/^' . preg_quote(trim(\$word) . 's', '/') . '$/'"];
        yield 'in parentheses' => ['argument' => "'/^' . (preg_quote(\$word, '/')) . '$/'"];
        yield 'in a concatenation in parentheses' => ['argument' => "'/^' . (preg_quote(\$word, '/') . '$') . '/'"];
        yield 'after two literals that make the opening' => ['argument' => "'/' . '^' . preg_quote(\$word, '/') . '$/'"];
        // Strings that follow each other are one string: the first part is "/^".
        yield 'after an empty literal and the opening' => ['argument' => "'' . '/^' . preg_quote(\$word, '/') . '$/'"];
        yield 'after a constant part' => ['argument' => "'/' . self::ANCHOR . preg_quote(\$word, '/') . '$/'"];
        yield 'beside a multi-byte literal' => ['argument' => "'/^é' . preg_quote(\$word, '/') . 'è$/u'"];
        yield 'between bracket delimiters' => ['argument' => "'{^' . preg_quote(\$word) . '$}i'"];
        yield 'in a group it opens' => ['argument' => "'/(' . preg_quote(\$word, '/') . ')+/'"];
        yield 'in a named group' => ['argument' => "'/(?<word>' . preg_quote(\$word, '/') . ')/'"];
        yield 'under the extended flag' => ['argument' => "'/^ ' . preg_quote(\$word, '/') . ' $/x'"];
    }

    #[Test]
    #[DataProvider('provideQuotedShells')]
    public function test_a_pattern_around_preg_quote_is_a_quoted_shell_and_holds_no_pattern(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'quoted_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A part that is no preg_quote() call and that the file does not prove
     * makes the shell a raw one, whatever is quoted beside it. A method or
     * a function of another namespace named preg_quote is not PHP's.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideRawShells(): iterable
    {
        yield 'a quoted text and a parameter' => ['argument' => "'/^' . preg_quote(\$word, '/') . \$other . '$/'"];
        yield 'a parameter and a quoted text' => ['argument' => "'/^' . \$other . preg_quote(\$word, '/') . '$/'"];
        yield 'a method named preg_quote' => ['argument' => "'/^' . \$this->preg_quote(\$word) . '$/'"];
        yield 'a static method named preg_quote' => ['argument' => "'/^' . Text::preg_quote(\$word) . '$/'"];
        yield 'a function of another namespace named preg_quote' => ['argument' => "'/^' . \\Vendor\\Text\\preg_quote(\$word) . '$/'"];
    }

    #[Test]
    #[DataProvider('provideRawShells')]
    public function test_a_part_that_is_not_quoted_makes_the_shell_raw(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The delimiter opens the first part: with nothing static there, or a
     * first part PHP finds no delimiter in, nothing is known of the
     * pattern.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideShellsWithoutADelimiter(): iterable
    {
        yield 'the quoted text first' => ['argument' => "preg_quote(\$word, '/') . '$/'"];
        yield 'the quoted text alone' => ['argument' => "preg_quote(\$word, '/')"];
        yield 'an empty literal first' => ['argument' => "'' . preg_quote(\$word, '/') . '/'"];
        yield 'a first part starting with a space' => ['argument' => "' /^' . preg_quote(\$word, '/') . '$/'"];
        yield 'a first part starting with a line break' => ['argument' => "\"\\n/^\" . preg_quote(\$word, '/') . '$/'"];
    }

    #[Test]
    #[DataProvider('provideShellsWithoutADelimiter')]
    public function test_a_quoted_text_before_any_delimiter_leaves_the_argument_opaque(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A local variable assigned once, a pattern built around preg_quote():
     * the hole does not end the whole value, the call reading the variable
     * reads a quoted shell. A constant read beside it is read as before.
     */
    #[Test]
    public function test_a_variable_assigned_a_pattern_around_preg_quote_is_a_quoted_shell(): void
    {
        $code = <<<'PHP'
            <?php

            final class Matcher
            {
                private const WORD = 'a.b';
                private const RE = '/^' . self::WORD . '$/';

                public function run(string $s, string $word): int
                {
                    $re = '/\b' . preg_quote($word, '/') . '\b/u';

                    return preg_match($re, $s) + preg_match(self::RE, $s);
                }
            }
            PHP;

        // self::RE holds no preg_quote(): it is read as before.
        $this->assertExtractedByBoth([
            ['pattern' => '/^a.b$/', 'at' => "'/^' . self::WORD . '$/'", 'location' => 'via self::RE'],
        ], $code);

        $this->assertArgumentsReadByBoth([
            ['state' => 'quoted_shell', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'constant', 'expression' => 'self::RE', 'at' => 'self::RE, $s)'],
        ], $code);
    }

    /**
     * A final class holding WORD and ANCHOR, whose method run() reads the
     * argument in preg_match().
     */
    private static function matcher(string $argument): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const WORD = 'a.b';\n    private const ANCHOR = '^';\n\n"
            ."    public function run(string \$s, string \$word, string \$other): int\n    {\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
