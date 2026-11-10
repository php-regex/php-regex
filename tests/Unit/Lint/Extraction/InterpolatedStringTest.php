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
 * An interpolated string, and a heredoc that interpolates, read as the
 * concatenation they are: their literal pieces, decoded as PHP decodes
 * them, and their expressions. A variable, written $x or {$x}, reads as a
 * variable operand of a concatenation does; $this->p and {$this->p} read
 * by the rules of a property; $a[key] and {$a['key']} read as the item of
 * an array the body proves; any other expression is a part built at run
 * time.
 *
 * The pattern is composed at the call: it is reported where the string
 * starts.
 *
 * Every expected pattern is what PHP 8.4 builds from the same string with
 * $part = 'abc' (php -r '$part = "abc"; var_dump("/^{$part}$/");' is
 * "/^abc$/", and so on, see each row).
 *
 * Every case runs through both strategies.
 */
final class InterpolatedStringTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideInterpolatedVariables(): iterable
    {
        // "/^{$part}$/" is "/^abc$/": a "$" no name follows is a dollar.
        yield 'a variable in braces' => ['argument' => '"/^{$part}$/"', 'pattern' => '/^abc$/'];
        // "/^$part$/" is "/^abc$/".
        yield 'a bare variable' => ['argument' => '"/^$part$/"', 'pattern' => '/^abc$/'];
        // "/^$part+/" is "/^abc+/".
        yield 'a bare variable before a quantifier' => ['argument' => '"/^$part+/"', 'pattern' => '/^abc+/'];
        // $open = '/'; "{$open}abc/" is "/abc/".
        yield 'a variable first' => ['argument' => '"{$open}abc/"', 'pattern' => '/abc/'];
        // "$open$part$open" is "/abc/".
        yield 'variables only' => ['argument' => '"$open$part$open"', 'pattern' => '/abc/'];
        // $other = 'def'; "/$part|$other/" is "/abc|def/".
        yield 'two variables' => ['argument' => '"/$part|$other/"', 'pattern' => '/abc|def/'];
        // "/^{$part}$other/" is "/^abcdef/".
        yield 'two variables side by side' => ['argument' => '"/^{$part}$other/"', 'pattern' => '/^abcdef/'];
        // "/{$part}s/" is "/abcs/": the braces end the name.
        yield 'a variable in braces before a letter' => ['argument' => '"/{$part}s/"', 'pattern' => '/abcs/'];
        // "/{$part}{2}/" is "/abc{2}/": only "{$" opens an expression.
        yield 'a variable in braces before a quantifier in braces' => ['argument' => '"/{$part}{2}/"', 'pattern' => '/abc{2}/'];
        // "/^{ $part}/" is "/^{ abc}/": a brace before a space is a brace.
        yield 'a brace that opens no expression' => ['argument' => '"/^{ $part}/"', 'pattern' => '/^{ abc}/'];
        // "/^{self::WORD}$part/" is "/^{self::WORD}abc/": a constant is
        // not interpolated.
        yield 'a constant in braces, which PHP does not interpolate' => ['argument' => '"/^{self::WORD}$part/"', 'pattern' => '/^{self::WORD}abc/'];
        // "/a$ b{$part}/" is "/a$ babc/".
        yield 'a dollar no name follows' => ['argument' => '"/a$ b{$part}/"', 'pattern' => '/a$ babc/'];
        // "/^é{$part}è$/u" is "/^éabcè$/u", 11 bytes.
        yield 'beside multi-byte text' => ['argument' => '"/^é{$part}è$/u"', 'pattern' => '/^éabcè$/u'];
        // b"/^{$part}$/" is "/^abc$/".
        yield 'a binary string' => ['argument' => 'b"/^{$part}$/"', 'pattern' => '/^abc$/'];
        // "/^$part" . '$/' is "/^abc$/".
        yield 'an operand of a concatenation' => ['argument' => '"/^$part" . \'$/\'', 'pattern' => '/^abc$/'];
        // "{^$part$}i" is "{^abc$}i".
        yield 'between bracket delimiters' => ['argument' => '"{^$part$}i"', 'pattern' => '{^abc$}i'];
        // "/^${part}$/" is "/^abc$/": the older spelling of {$part},
        // deprecated since PHP 8.2, names the same variable.
        yield 'a variable named in dollar braces' => ['argument' => '"/^${part}$/"', 'pattern' => '/^abc$/'];
        // $count = 3; "/a{{$count}}/" is "/a{3}/": an integer reads as PHP
        // writes it, and the outer braces are braces.
        yield 'a variable holding an integer' => ['argument' => '"/a{{$count}}/"', 'pattern' => '/a{3}/'];
        // "/a{$count}/" is "/a3/": the braces are the expression's.
        yield 'a variable holding an integer, in its own braces only' => ['argument' => '"/a{$count}/"', 'pattern' => '/a3/'];
        // $alias = $part; "/^$alias$/" is "/^abc$/".
        yield 'a variable assigned another variable' => ['argument' => '"/^$alias$/"', 'pattern' => '/^abc$/'];
        // A variable of one value may stand twice: "/$part-$part/" is
        // "/abc-abc/".
        yield 'a variable of one value, twice' => ['argument' => '"/$part-$part/"', 'pattern' => '/abc-abc/'];
    }

    #[Test]
    #[DataProvider('provideInterpolatedVariables')]
    public function test_an_interpolated_variable_reads_as_the_value_assigned_to_it(string $argument, string $pattern): void
    {
        $code = self::matcher($argument);

        // Composed at the call: reported where the string starts, with no
        // name to go through.
        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The literal pieces are decoded as PHP decodes a double-quoted
     * string, whatever stands beside them.
     *
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideEscapes(): iterable
    {
        // "/^{$part}\$/" is "/^abc$/".
        yield 'an escaped dollar' => ['argument' => '"/^{$part}\\$/"', 'pattern' => '/^abc$/'];
        // "/{$part}\n/x" is "/abc", a line feed, "/x".
        yield 'a line feed' => ['argument' => '"/{$part}\\n/x"', 'pattern' => "/abc\n/x"];
        // "/\t{$part}/" is "/", a tab, "abc/".
        yield 'a tab' => ['argument' => '"/\\t{$part}/"', 'pattern' => "/\tabc/"];
        // "/a\v\f\r{$part}/" is 2f 61 0b 0c 0d 61 62 63 2f.
        yield 'a vertical tab, a form feed and a carriage return' => ['argument' => '"/a\\v\\f\\r{$part}/"', 'pattern' => "/a\x0B\x0C\x0Dabc/"];
        // "/a\e{$part}/" is 2f 61 1b 61 62 63 2f.
        yield 'an escape character' => ['argument' => '"/a\\e{$part}/"', 'pattern' => "/a\x1Babc/"];
        // "/\x41{$part}/" is "/Aabc/".
        yield 'a hexadecimal escape' => ['argument' => '"/\\x41{$part}/"', 'pattern' => '/Aabc/'];
        // "/\101{$part}/" is "/Aabc/".
        yield 'an octal escape' => ['argument' => '"/\\101{$part}/"', 'pattern' => '/Aabc/'];
        // "/\u{e9}{$part}/u" is "/éabc/u".
        yield 'a unicode escape' => ['argument' => '"/\\u{e9}{$part}/u"', 'pattern' => '/éabc/u'];
        // "/\u{1F600}{$part}/u" is 2f f0 9f 98 80 61 62 63 2f 75.
        yield 'a unicode escape of four bytes' => ['argument' => '"/\\u{1F600}{$part}/u"', 'pattern' => "/\u{1F600}abc/u"];
        // "/\"{$part}\"/" is /"abc"/.
        yield 'an escaped double quote' => ['argument' => '"/\\"{$part}\\"/"', 'pattern' => '/"abc"/'];
        // "/^\\d+{$part}/" is "/^\d+abc/": two backslashes are one.
        yield 'an escaped backslash' => ['argument' => '"/^\\\\d+{$part}/"', 'pattern' => '/^\\d+abc/'];
        // "/$part\\/" is "/abc\/".
        yield 'an escaped backslash after a variable' => ['argument' => '"/$part\\\\/"', 'pattern' => '/abc\\/'];
        // "/^\d+{$part}/" is "/^\d+abc/": \d is no escape of PHP.
        yield 'a backslash PHP keeps' => ['argument' => '"/^\\d+{$part}/"', 'pattern' => '/^\\d+abc/'];
        // "/\'{$part}/" is "/\'abc/": a single quote is not escaped.
        yield 'a backslash before a single quote' => ['argument' => '"/\\\'{$part}/"', 'pattern' => '/\\\'abc/'];
        // "/\xZ{$part}/" is "/\xZabc/": no hexadecimal digit follows.
        yield 'a hexadecimal escape with no digit' => ['argument' => '"/\\xZ{$part}/"', 'pattern' => '/\\xZabc/'];
        // "/\{$part}/" is "/\{abc}/": the backslash keeps the brace a
        // brace, and $part is read bare.
        yield 'an escaped brace before a variable' => ['argument' => '"/\\{$part}/"', 'pattern' => '/\\{abc}/'];
    }

    #[Test]
    #[DataProvider('provideEscapes')]
    public function test_the_literal_pieces_are_decoded_as_php_decodes_them(string $argument, string $pattern): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * $this->p reads by the rules of a property: a literal default,
     * private, in a final class, never written in the file. The class
     * holds private string $word = 'prop'.
     *
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideInterpolatedProperties(): iterable
    {
        // "/^{$this->word}$/" is "/^prop$/".
        yield 'a property in braces' => ['argument' => '"/^{$this->word}$/"', 'pattern' => '/^prop$/'];
        // "/^$this->word$/" is "/^prop$/".
        yield 'a bare property' => ['argument' => '"/^$this->word$/"', 'pattern' => '/^prop$/'];
        // "/^$this->word-$part$/" is "/^prop-abc$/".
        yield 'a property and a variable' => ['argument' => '"/^$this->word-$part$/"', 'pattern' => '/^prop-abc$/'];
    }

    #[Test]
    #[DataProvider('provideInterpolatedProperties')]
    public function test_an_interpolated_property_reads_by_the_rules_of_a_property(string $argument, string $pattern): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A property the rules do not read is a part built at run time,
     * interpolated or not.
     *
     * @return iterable<string, array{class: string, write: string}>
     */
    public static function providePropertiesTheRulesDoNotRead(): iterable
    {
        yield 'a property of an open class' => ['class' => 'class Matcher', 'write' => ''];
        yield 'a property written in the file' => ['class' => 'final class Matcher', 'write' => '$this->word = $s;'];
        yield 'a property passed to a call' => ['class' => 'final class Matcher', 'write' => 'normalize_part($this->word);'];
    }

    #[Test]
    #[DataProvider('providePropertiesTheRulesDoNotRead')]
    public function test_an_interpolated_property_the_rules_do_not_read_is_a_raw_hole(string $class, string $write): void
    {
        $code = "<?php\n\n".$class."\n{\n    private string \$word = 'prop';\n\n    public function run(string \$s): int\n    {\n        ".$write."\n\n"
            ."        return preg_match(\"/^{\$this->word}$/\", \$s) + preg_match(\"/^\$this->word+/\", \$s);\n    }\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => '"/^{$this->word}$/"'],
            ['state' => 'raw_shell', 'expression' => '"/^$this->word+/"'],
        ], $code);
    }

    /**
     * Any other expression is a part built at run time: the string is a
     * raw shell when its first piece gives the delimiter.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideExpressionsNothingReads(): iterable
    {
        yield 'a parameter in braces' => ['argument' => '"/^{$given}$/"'];
        yield 'a bare parameter' => ['argument' => '"/^$given+/"'];
        yield 'a parameter beside a variable the body proves' => ['argument' => '"/^$part-$given$/"'];
        yield 'a method call' => ['argument' => '"/^{$obj->name()}$/"'];
        yield 'a method call on $this' => ['argument' => '"/^{$this->name()}$/"'];
        yield 'a static method call' => ['argument' => '"/^{$obj::name()}$/"'];
        yield 'a property of another object, in braces' => ['argument' => '"/^{$obj->name}$/"'];
        yield 'a property of another object, bare' => ['argument' => '"/^$obj->name$/"'];
        yield 'a property read through a nullsafe fetch' => ['argument' => '"/^{$obj?->name}$/"'];
        yield 'a property of a property' => ['argument' => '"/^{$this->word->name}$/"'];
        yield 'a static property' => ['argument' => '"/^{$obj::$name}$/"'];
        // "${expr}" names a variable by a string computed at run time:
        // "/^${'pa' . 'rt'}$/" is "/^abc$/" in PHP, and is not followed.
        yield 'a variable named by an expression' => ['argument' => '"/^${\'pa\' . \'rt\'}$/"'];
        yield 'an item of a parameter, by a bare key' => ['argument' => '"/^$items[word]$/"'];
        yield 'an item of a parameter, by a number' => ['argument' => '"/^$items[0]$/"'];
        yield 'an item of a parameter, in braces' => ['argument' => '"/^{$items[\'word\']}$/"'];
        yield 'an item of an item' => ['argument' => '"/^{$items[\'word\'][0]}$/"'];
        // $map holds no 'missing' and $list no index 7: PHP gives null,
        // and warns "Undefined array key".
        yield 'an item a local array does not hold, by a bare key' => ['argument' => '"/^$map[missing]$/"'];
        yield 'an item a local array does not hold, in braces' => ['argument' => '"/^{$map[\'missing\']}$/"'];
        yield 'an item a local list does not hold' => ['argument' => '"/^$list[7]$/"'];
        yield 'a variable the body never assigns' => ['argument' => '"/^$missing$/"'];
    }

    #[Test]
    #[DataProvider('provideExpressionsNothingReads')]
    public function test_any_other_interpolated_expression_is_a_raw_hole(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An item of an array the body proves, named by a literal key, reads
     * as that item: $map is ['word' => 'abc'] and $list ['abc'] here.
     * PHP 8.4: "/^$map[word]$/", "/^{$map['word']}$/", "/^$list[0]$/"
     * and "/^{$list[0]}$/" are all "/^abc$/"; in a string, the bare key
     * of "$map[word]" is the string 'word'.
     *
     * @return iterable<string, array{argument: string, pattern: string}>
     */
    public static function provideInterpolatedArrayItems(): iterable
    {
        yield 'an item of a local array, by a bare key' => ['argument' => '"/^$map[word]$/"', 'pattern' => '/^abc$/'];
        yield 'an item of a local array, in braces' => ['argument' => '"/^{$map[\'word\']}$/"', 'pattern' => '/^abc$/'];
        yield 'an item of a local list, by a number' => ['argument' => '"/^$list[0]$/"', 'pattern' => '/^abc$/'];
        yield 'an item of a local list, in braces' => ['argument' => '"/^{$list[0]}$/"', 'pattern' => '/^abc$/'];
        // "/^$map[word]+$part$/" is "/^abc+abc$/".
        yield 'an item beside a variable' => ['argument' => '"/^$map[word]+$part$/"', 'pattern' => '/^abc+abc$/'];
    }

    #[Test]
    #[DataProvider('provideInterpolatedArrayItems')]
    public function test_an_interpolated_array_item_reads_as_the_item_the_body_proves(string $argument, string $pattern): void
    {
        $code = self::matcher($argument);

        // Composed at the call: reported where the string starts.
        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => $argument, 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The delimiter opens the first piece: an expression the file does not
     * prove there leaves nothing static.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideStringsWithoutADelimiter(): iterable
    {
        yield 'a bare parameter first' => ['argument' => '"$given/abc/"'];
        yield 'a parameter in braces first' => ['argument' => '"{$given}abc/"'];
        yield 'a parameter alone' => ['argument' => '"$given"'];
        yield 'a method call first' => ['argument' => '"{$obj->open()}abc/"'];
        yield 'a first piece starting with a space' => ['argument' => '" /^$given$/"'];
        yield 'a first piece starting with a line feed' => ['argument' => '"\\n/^$given$/"'];
    }

    #[Test]
    #[DataProvider('provideStringsWithoutADelimiter')]
    public function test_an_unproved_expression_before_any_delimiter_leaves_the_string_opaque(string $argument): void
    {
        $code = self::matcher($argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A variable one assignment gives two values, the arms of a ternary,
     * gives the string two values; one holding a preg_quote() text makes
     * it a quoted shell.
     *
     * $kind = true ? 'cat' : 'dog' gives "/^cats?$/", with false
     * "/^dogs?$/".
     */
    #[Test]
    public function test_an_interpolated_variable_brings_every_value_it_may_hold(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given, bool $flag): int
            {
                $kind = $flag ? 'cat' : 'dog';
                $quoted = preg_quote($given, '/');

                return preg_match("/^{$kind}s?$/", $s) + preg_match("/^{$quoted}$/", $s) + preg_match("/^$quoted$given/", $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^cats?$/', 'at' => '"/^{$kind}s?$/"', 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^dogs?$/', 'at' => '"/^{$kind}s?$/"', 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '"/^{$kind}s?$/"'],
            ['state' => 'quoted_shell', 'expression' => '"/^{$quoted}$/"'],
            ['state' => 'raw_shell', 'expression' => '"/^$quoted$given/"'],
        ], $code);
    }

    /**
     * Two interpolated variables of several values, or the same one
     * twice, would give strings the code never builds: nothing is known
     * of the string, and no pattern is read.
     *
     * PHP 8.4: $kind = $flag ? 'cat' : 'dog'; "/^$kind|$kind$/" is
     * "/^cat|cat$/" or "/^dog|dog$/", never "/^cat|dog$/".
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideStringsOfTwoExpressionsOfSeveralValues(): iterable
    {
        yield 'the same variable twice' => ['argument' => '"/^$kind|$kind$/"'];
        yield 'the same variable twice, in braces' => ['argument' => '"/^{$kind}s?|{$kind}$/"'];
        yield 'two variables' => ['argument' => '"/^$size-$kind$/"'];
        yield 'a variable in the string and one joined to it' => ['argument' => '"/^$size-" . $kind . \'$/\''];
    }

    #[Test]
    #[DataProvider('provideStringsOfTwoExpressionsOfSeveralValues')]
    public function test_two_interpolated_expressions_of_several_values_leave_the_string_opaque(string $argument): void
    {
        $code = "<?php\n\nfunction run(string \$s, bool \$flag): int\n{\n"
            ."    \$kind = \$flag ? 'cat' : 'dog';\n    \$size = \$flag ? 'big' : 'small';\n\n"
            .'    return preg_match('.$argument.", \$s) + preg_match(\"#^{\$kind}$#\", \$s);\n}\n";

        // One of them alone, once, is still read: "#^cat$#" and "#^dog$#".
        $this->assertExtractedByBoth([
            ['pattern' => '#^cat$#', 'at' => '"#^{$kind}$#"', 'location' => null],
            ['pattern' => '#^dog$#', 'at' => '"#^{$kind}$#"', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s) +'],
            ['state' => 'finite_set', 'expression' => '"#^{$kind}$#"'],
        ], $code);
    }

    /**
     * A variable assigned an interpolated string is read where the string
     * is written, through the name the call reads, as one assigned a
     * concatenation is.
     */
    #[Test]
    public function test_a_variable_assigned_an_interpolated_string_is_read_at_the_string(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given): int
            {
                $part = 'abc';
                $re = "/^{$part}$/";
                $shell = "/^{$given}$/";

                return preg_match($re, $s) + preg_match($shell, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => '"/^{$part}$/"', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'raw_shell', 'expression' => '$shell', 'at' => '$shell, $s)'],
        ], $code);
    }

    /**
     * A heredoc that interpolates is read as a double-quoted string is,
     * once PHP has taken the indentation of its closing line off every
     * line: the pattern is reported where the heredoc opens.
     *
     * PHP 8.4, $part = 'abc': the first three heredocs are "/^abc$/"; the
     * fourth is "/^abc$/i" with the flag joined to it.
     */
    #[Test]
    public function test_a_heredoc_with_an_interpolation_is_read_where_it_opens(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $given): int
            {
                $part = 'abc';

                return preg_match(<<<BRACES
                    /^{$part}$/
                    BRACES, $s)
                    + preg_match(<<<BARE
                    /^$part$/
                    BARE, $s)
                    + preg_match(<<<"QUOTED"
                        /^{$part}$/
                        QUOTED, $s)
                    + preg_match(<<<JOINED
                    /^{$part}$/
                    JOINED . 'i', $s)
                    + preg_match(<<<SHELL
                    /^{$given}$/
                    SHELL, $s)
                    + preg_match(<<<OPAQUE
                    {$given}/abc/
                    OPAQUE, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => '<<<BRACES', 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^abc$/', 'at' => '<<<BARE', 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^abc$/', 'at' => '<<<"QUOTED"', 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^abc$/i', 'at' => '<<<JOINED', 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "<<<BRACES\n        /^{\$part}$/\n        BRACES"],
            ['state' => 'constant', 'expression' => "<<<BARE\n        /^\$part$/\n        BARE"],
            ['state' => 'constant', 'expression' => "<<<\"QUOTED\"\n            /^{\$part}$/\n            QUOTED"],
            ['state' => 'constant', 'expression' => "<<<JOINED\n        /^{\$part}$/\n        JOINED . 'i'"],
            ['state' => 'raw_shell', 'expression' => "<<<SHELL\n        /^{\$given}$/\n        SHELL"],
            ['state' => 'opaque', 'expression' => "<<<OPAQUE\n        {\$given}/abc/\n        OPAQUE"],
        ], $code);
    }

    /**
     * The lines of a heredoc keep what stands past the indentation of its
     * closing line, its escapes are decoded, and a double quote needs no
     * backslash there.
     *
     * PHP 8.4, $part = 'abc': the heredoc below is
     * "/^abc  # the \"word\"\n  \\d+$   # digits\n\n/x" written as a
     * double-quoted string: \$ is a dollar, \n a line feed beside the one
     * that ends the line, \d is kept.
     */
    #[Test]
    public function test_a_heredoc_over_several_lines_is_decoded_as_php_decodes_it(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $part = 'abc';

                return preg_match(<<<RE
                    /^{$part}  # the "word"
                      \d+\$   # digits\n
                    /x
                    RE, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => "/^abc  # the \"word\"\n  \\d+$   # digits\n\n/x", 'at' => '<<<RE', 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertSame(['constant'], $this->statesReadByBoth($code));
    }

    /**
     * A nowdoc interpolates nothing: "{$part}" is five characters of the
     * pattern, as before (PHP 8.4: the nowdoc below is "/^{$part}$/"
     * written in single quotes).
     */
    #[Test]
    public function test_a_nowdoc_interpolates_nothing(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $part = 'abc';

                return preg_match(<<<'RE'
                    /^{$part}$/
                    RE, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^{$part}$/', 'at' => "<<<'RE'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertSame(['constant'], $this->statesReadByBoth($code));
    }

    /**
     * A final class holding WORD and the property $word, whose method
     * run() assigns $part, $other, $open, $count, $alias, $map and $list,
     * then reads the argument in preg_match().
     */
    private static function matcher(string $argument): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const WORD = 'const';\n\n    private string \$word = 'prop';\n\n"
            ."    public function run(string \$s, string \$given, object \$obj, array \$items): int\n    {\n"
            ."        \$part = 'abc';\n        \$other = 'def';\n        \$open = '/';\n        \$count = 3;\n        \$alias = \$part;\n        \$map = ['word' => 'abc'];\n        \$list = ['abc'];\n\n"
            .'        return preg_match('.$argument.", \$s);\n    }\n\n    private function name(): string\n    {\n        return 'name';\n    }\n}\n";
    }
}
