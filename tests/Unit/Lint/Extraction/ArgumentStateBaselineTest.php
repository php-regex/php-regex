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

use PHPRegex\Parser\Resolution\ArgumentState;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The state every shape of pattern argument ends in, before the readings
 * that come next exist: no shape is dropped, each argument is held and
 * says what is known of it.
 *
 * Three tables. The first holds what the file proves today. The second
 * holds the shapes a reading still to come will read: each row names that
 * reading, and moves to the first table with it. The third holds the
 * shapes nothing reads.
 *
 * Every snippet holds one pattern argument; each runs through both
 * strategies, which must give it the same state at the same place.
 */
final class ArgumentStateBaselineTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * @return iterable<string, array{code: string, expression: string, state: ArgumentState, patterns: list<string>, at?: string}>
     */
    public static function provideShapesTheFileProves(): iterable
    {
        yield 'a literal' => [
            'code' => self::inFunction('string $s', "return preg_match('/literal/', \$s);"),
            'expression' => "'/literal/'",
            'state' => ArgumentState::Constant,
            'patterns' => ['/literal/'],
        ];

        yield 'a concatenation of literals' => [
            'code' => self::inFunction('string $s', "return preg_match('/con' . 'cat/', \$s);"),
            'expression' => "'/con' . 'cat/'",
            'state' => ArgumentState::Constant,
            'patterns' => ['/concat/'],
        ];

        yield 'a constant of the file' => [
            'code' => "<?php\n\nconst RE = '/constant/';\n\nfunction run(string \$s): int\n{\n    return preg_match(RE, \$s);\n}\n",
            'expression' => 'RE',
            'at' => 'RE, $s)',
            'state' => ArgumentState::Constant,
            'patterns' => ['/constant/'],
        ];

        yield 'a class constant' => [
            'code' => self::inFinalClass("private const RE = '/class-constant/';", 'return preg_match(self::RE, $s);'),
            'expression' => 'self::RE',
            'state' => ArgumentState::Constant,
            'patterns' => ['/class-constant/'],
        ];

        yield 'the value of an enum case' => [
            'code' => "<?php\n\nenum Kind: string\n{\n    case Re = '/enum-case/';\n}\n\nfunction run(string \$s): int\n{\n    return preg_match(Kind::Re->value, \$s);\n}\n",
            'expression' => 'Kind::Re->value',
            'state' => ArgumentState::Constant,
            'patterns' => ['/enum-case/'],
        ];

        yield 'a literal and a constant concatenated' => [
            'code' => self::inFinalClass("private const PART = 'abc';", "return preg_match('/' . self::PART . '+/', \$s);"),
            'expression' => "'/' . self::PART . '+/'",
            'state' => ArgumentState::Constant,
            'patterns' => ['/abc+/'],
        ];

        yield 'a local variable assigned once' => [
            'code' => self::inFunction('string $s', "\$re = '/assigned-once/';\n\n    return preg_match(\$re, \$s);"),
            'expression' => '$re',
            'at' => '$re, $s)',
            'state' => ArgumentState::Constant,
            'patterns' => ['/assigned-once/'],
        ];

        yield 'a local variable assigned a constant' => [
            'code' => self::inFinalClass("private const RE = '/through-a-variable/';", "\$re = self::RE;\n\n        return preg_match(\$re, \$s);"),
            'expression' => '$re',
            'at' => '$re, $s)',
            'state' => ArgumentState::Constant,
            'patterns' => ['/through-a-variable/'],
        ];

        yield 'a property of a final class' => [
            'code' => self::inFinalClass("private string \$re = '/property/';", 'return preg_match($this->re, $s);'),
            'expression' => '$this->re',
            'state' => ArgumentState::Constant,
            'patterns' => ['/property/'],
        ];

        yield 'a local variable assigned in two branches' => [
            'code' => self::inFunction('string $s, bool $flag', "\$re = '/first-branch/';\n    if (\$flag) {\n        \$re = '/second-branch/';\n    }\n\n    return preg_match(\$re, \$s);"),
            'expression' => '$re',
            'at' => '$re, $s)',
            'state' => ArgumentState::FiniteSet,
            'patterns' => ['/first-branch/', '/second-branch/'],
        ];

        yield 'a concatenation with a parameter' => [
            'code' => self::inFunction('string $s, string $x', "return preg_match('/^' . \$x . '$/', \$s);"),
            'expression' => "'/^' . \$x . '$/'",
            'state' => ArgumentState::RawShell,
            'patterns' => [],
        ];

        // $part = 'abc'; '/' . $part . '+/' is "/abc+/" in PHP.
        yield 'a concatenation with a local variable' => [
            'code' => self::inFunction('string $s', "\$part = 'abc';\n\n    return preg_match('/' . \$part . '+/', \$s);"),
            'expression' => "'/' . \$part . '+/'",
            'state' => ArgumentState::Constant,
            'patterns' => ['/abc+/'],
        ];

        yield 'a local variable assigned a concatenation with a local variable' => [
            'code' => self::inFunction('string $s', "\$part = 'abc';\n    \$re = '/' . \$part . '+/';\n\n    return preg_match(\$re, \$s);"),
            'expression' => '$re',
            'at' => '$re, $s)',
            'state' => ArgumentState::Constant,
            'patterns' => ['/abc+/'],
        ];

        // $part = 'abc'; "/^{$part}$/" is "/^abc$/" in PHP.
        yield 'an interpolated string' => [
            'code' => self::inFunction('string $s', "\$part = 'abc';\n\n    return preg_match(\"/^{\$part}$/\", \$s);"),
            'expression' => '"/^{$part}$/"',
            'state' => ArgumentState::Constant,
            'patterns' => ['/^abc$/'],
        ];

        yield 'an interpolated string holding a parameter' => [
            'code' => self::inFunction('string $s, string $x', 'return preg_match("/^$x+/", $s);'),
            'expression' => '"/^$x+/"',
            'state' => ArgumentState::RawShell,
            'patterns' => [],
        ];

        // PHP takes the indentation of the closing line off the body:
        // the heredoc is "/^abc$/".
        yield 'a heredoc with an interpolation' => [
            'code' => self::inFunction('string $s', "\$part = 'abc';\n\n    return preg_match(<<<RE\n        /^{\$part}$/\n        RE, \$s);"),
            'expression' => "<<<RE\n        /^{\$part}$/\n        RE",
            'state' => ArgumentState::Constant,
            'patterns' => ['/^abc$/'],
        ];

        yield 'a ternary' => [
            'code' => self::inFunction('string $s, bool $flag', "return preg_match(\$flag ? '/then/' : '/else/', \$s);"),
            'expression' => "\$flag ? '/then/' : '/else/'",
            'state' => ArgumentState::FiniteSet,
            'patterns' => ['/then/', '/else/'],
        ];

        // '/short/' ?: '/else/' is "/short/" in PHP: the other arm is
        // kept only for a value that is '' or '0'.
        yield 'a short ternary' => [
            'code' => self::inFinalClass("private const RE = '/short/';", "return preg_match(self::RE ?: '/else/', \$s);"),
            'expression' => "self::RE ?: '/else/'",
            'state' => ArgumentState::Constant,
            'patterns' => ['/short/'],
        ];

        // '/coalesced/' ?? '/else/' is "/coalesced/" in PHP: a string is
        // never null.
        yield 'a null coalescing' => [
            'code' => self::inFinalClass("private const RE = '/coalesced/';", "return preg_match(self::RE ?? '/else/', \$s);"),
            'expression' => "self::RE ?? '/else/'",
            'state' => ArgumentState::Constant,
            'patterns' => ['/coalesced/'],
        ];

        yield 'a match' => [
            'code' => self::inFunction('string $s, int $kind', "return preg_match(match (\$kind) {\n        1 => '/one/',\n        default => '/other/',\n    }, \$s);"),
            'expression' => "match (\$kind) {\n        1 => '/one/',\n        default => '/other/',\n    }",
            'state' => ArgumentState::FiniteSet,
            'patterns' => ['/one/', '/other/'],
        ];

        yield 'a local variable assigned a ternary' => [
            'code' => self::inFunction('string $s, bool $flag', "\$re = \$flag ? '/then/' : '/else/';\n\n    return preg_match(\$re, \$s);"),
            'expression' => '$re',
            'at' => '$re, $s)',
            'state' => ArgumentState::FiniteSet,
            'patterns' => ['/then/', '/else/'],
        ];

        // sprintf('/^%s$/', 'abc') is "/^abc$/" in PHP.
        yield 'sprintf() of literals' => [
            'code' => self::inFunction('string $s', "return preg_match(sprintf('/^%s$/', 'abc'), \$s);"),
            'expression' => "sprintf('/^%s$/', 'abc')",
            'state' => ArgumentState::Constant,
            'patterns' => ['/^abc$/'],
        ];

        // implode('|', ['/a', 'b/']) is "/a|b/" in PHP.
        yield 'implode() of literals' => [
            'code' => self::inFunction('string $s', "return preg_match(implode('|', ['/a', 'b/']), \$s);"),
            'expression' => "implode('|', ['/a', 'b/'])",
            'state' => ArgumentState::Constant,
            'patterns' => ['/a|b/'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideShapesTheFileProves')]
    public function test_a_shape_the_file_proves_ends_in_its_state(string $code, string $expression, ArgumentState $state, array $patterns, ?string $at = null): void
    {
        $this->assertSame($patterns, $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([self::argument($expression, $state, $at)], $code);
    }

    /**
     * Each row is read by a reading still to come, named on the row: the
     * state here is the one the argument has until then.
     *
     * @return iterable<string, array{code: string, expression: string, state: ArgumentState, at?: string}>
     */
    public static function provideShapesALaterReadingReads(): iterable
    {
        // Read with the arrays of patterns (row 3a): then one argument per
        // item, each constant.
        yield 'a local variable assigned an array of patterns' => [
            'code' => self::inFunction('string $s', "\$list = ['/item-a/', '/item-b/'];\n\n    return (string) preg_replace(\$list, '', \$s);", 'string'),
            'expression' => '$list',
            'at' => "\$list, '', \$s)",
            'state' => ArgumentState::Opaque,
        ];

        // Read with the arrays of patterns (row 3b): then a finite set.
        yield 'the value of a foreach over an array literal' => [
            'code' => self::inFunction('string $s', "\$found = 0;\n    foreach (['/loop-a/', '/loop-b/'] as \$re) {\n        \$found += preg_match(\$re, \$s);\n    }\n\n    return \$found;"),
            'expression' => '$re',
            'at' => '$re, $s)',
            'state' => ArgumentState::Opaque,
        ];

        // Read with the arrays of patterns (row 3b): then a finite set.
        yield 'the key of a foreach over an array literal' => [
            'code' => self::inFunction('string $s', "\$found = 0;\n    foreach (['/key-a/' => 1, '/key-b/' => 2] as \$re => \$weight) {\n        \$found += \$weight * preg_match(\$re, \$s);\n    }\n\n    return \$found;"),
            'expression' => '$re',
            'at' => '$re, $s)',
            'state' => ArgumentState::Opaque,
        ];

        // Read with the arrays of patterns (row 3c): then constant.
        yield 'an item of a local array, by its literal key' => [
            'code' => self::inFunction('string $s', "\$map = ['word' => '/\\\\w+/', 'digit' => '/\\\\d+/'];\n\n    return preg_match(\$map['word'], \$s);"),
            'expression' => "\$map['word']",
            'at' => "\$map['word'], \$s)",
            'state' => ArgumentState::Opaque,
        ];

        // Read with the arrays of patterns (row 3c): then constant.
        yield 'an item of a constant array, by its literal key' => [
            'code' => self::inFinalClass("private const MAP = ['word' => '/\\\\w+/'];", "return preg_match(self::MAP['word'], \$s);"),
            'expression' => "self::MAP['word']",
            'at' => "self::MAP['word'], \$s)",
            'state' => ArgumentState::Opaque,
        ];

        // Read with the variables at file level (row 4): then constant.
        yield 'a variable at file level' => [
            'code' => "<?php\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'expression' => '$re',
            'at' => '$re, $subject)',
            'state' => ArgumentState::Opaque,
        ];

        // Read with self:: in a closure passed to a PHP function (row 6a):
        // then constant.
        yield 'a class constant in a closure passed to array_filter()' => [
            'code' => self::inFinalClass("private const RE = '/in-a-closure/';", 'return \\count(array_filter([$s], static fn (string $item): bool => 1 === preg_match(self::RE, $item)));'),
            'expression' => 'self::RE',
            'at' => 'self::RE, $item)',
            'state' => ArgumentState::Opaque,
        ];

        // Read with the constants holding branches (row 6c): then a finite
        // set.
        yield 'a constant whose value is a ternary' => [
            'code' => self::inFinalClass("private const RE = \\PHP_INT_SIZE === 8 ? '/wide/' : '/narrow/';", 'return preg_match(self::RE, $s);'),
            'expression' => 'self::RE',
            'at' => 'self::RE, $s)',
            'state' => ArgumentState::Opaque,
        ];

        // Read with the platform constants (row 6e): then a finite set.
        yield 'a concatenation with DIRECTORY_SEPARATOR' => [
            'code' => self::inFunction('string $s', "return preg_match('~^' . \\DIRECTORY_SEPARATOR . '~', \$s);"),
            'expression' => "'~^' . \\DIRECTORY_SEPARATOR . '~'",
            'state' => ArgumentState::RawShell,
        ];
    }

    #[Test]
    #[DataProvider('provideShapesALaterReadingReads')]
    public function test_a_shape_a_later_reading_reads_is_held_until_then(string $code, string $expression, ArgumentState $state, ?string $at = null): void
    {
        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([self::argument($expression, $state, $at)], $code);
    }

    /**
     * Nothing static is known of these, and no reading of this phase
     * changes it.
     *
     * @return iterable<string, array{code: string, expression: string, at?: string}>
     */
    public static function provideShapesNothingReads(): iterable
    {
        yield 'a parameter' => [
            'code' => self::inFunction('string $s, string $re', 'return preg_match($re, $s);'),
            'expression' => '$re',
            'at' => '$re, $s)',
        ];

        yield 'a method call' => [
            'code' => self::inFinalClass('', 'return preg_match($this->pattern(), $s);'),
            'expression' => '$this->pattern()',
        ];

        yield 'a static method call' => [
            'code' => self::inFunction('string $s', 'return preg_match(Patterns::word(), $s);'),
            'expression' => 'Patterns::word()',
        ];

        yield 'a function call' => [
            'code' => self::inFunction('string $s', 'return preg_match(build_pattern(), $s);'),
            'expression' => 'build_pattern()',
        ];

        yield 'a property of another object' => [
            'code' => self::inFunction('string $s, object $config', 'return preg_match($config->pattern, $s);'),
            'expression' => '$config->pattern',
        ];

        yield 'a static property' => [
            'code' => self::inFunction('string $s', 'return preg_match(Patterns::$word, $s);'),
            'expression' => 'Patterns::$word',
        ];

        yield 'an item of a parameter' => [
            'code' => self::inFunction('string $s, array $patterns', "return preg_match(\$patterns['word'], \$s);"),
            'expression' => "\$patterns['word']",
        ];

        yield 'a variable a closure captures' => [
            'code' => self::inFunction('string $s', "\$re = '/captured/';\n    \$match = static function (string \$s) use (\$re): int {\n        return preg_match(\$re, \$s);\n    };\n\n    return \$match(\$s);"),
            'expression' => '$re',
            'at' => '$re, $s)',
        ];

        yield 'a variable written with a concatenating assignment' => [
            'code' => self::inFunction('string $s', "\$re = '/appended';\n    \$re .= '/';\n\n    return preg_match(\$re, \$s);"),
            'expression' => '$re',
            'at' => '$re, $s)',
        ];

        yield 'a late static constant of an open class' => [
            'code' => "<?php\n\nclass Matcher\n{\n    protected const RE = '/late-static/';\n\n    public function run(string \$s): int\n    {\n        return preg_match(static::RE, \$s);\n    }\n}\n",
            'expression' => 'static::RE',
        ];

        yield 'a property of an open class' => [
            'code' => "<?php\n\nclass Matcher\n{\n    private string \$re = '/open-class/';\n\n    public function run(string \$s): int\n    {\n        return preg_match(\$this->re, \$s);\n    }\n}\n",
            'expression' => '$this->re',
        ];

        yield 'a constant no file of the run declares' => [
            'code' => self::inFunction('string $s', 'return preg_match(UNDECLARED_PATTERN, $s);'),
            'expression' => 'UNDECLARED_PATTERN',
        ];

        yield 'a constant naming itself' => [
            'code' => self::inFinalClass('private const RE = self::RE;', 'return preg_match(self::RE, $s);'),
            'expression' => 'self::RE',
            'at' => 'self::RE, $s)',
        ];

        yield 'a cast' => [
            'code' => self::inFunction('string $s, mixed $re', 'return preg_match((string) $re, $s);'),
            'expression' => '(string) $re',
        ];

        // The delimiter is not known: neither end is static.
        yield 'a literal between two holes' => [
            'code' => self::inFunction('string $s, string $d', "return preg_match(\$d . 'abc' . \$d, \$s);"),
            'expression' => "\$d . 'abc' . \$d",
        ];

        yield 'a concatenation whose first part is a parameter' => [
            'code' => self::inFunction('string $s, string $x', "return preg_match(\$x . '/abc/', \$s);"),
            'expression' => "\$x . '/abc/'",
        ];

        yield 'a concatenation whose first part is preg_quote()' => [
            'code' => self::inFunction('string $s, string $x', "return preg_match(preg_quote(\$x, '/') . '/i', \$s);"),
            'expression' => "preg_quote(\$x, '/') . '/i'",
        ];

        yield 'preg_quote() alone' => [
            'code' => self::inFunction('string $s, string $x', 'return preg_match(preg_quote($x), $s);'),
            'expression' => 'preg_quote($x)',
        ];

        yield 'null' => [
            'code' => self::inFunction('string $s', 'return (int) @preg_match(null, $s);'),
            'expression' => 'null',
        ];
    }

    #[Test]
    #[DataProvider('provideShapesNothingReads')]
    public function test_a_shape_nothing_reads_is_opaque(string $code, string $expression, ?string $at = null): void
    {
        $this->assertSame([], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([self::argument($expression, ArgumentState::Opaque, $at)], $code);
    }

    /**
     * @return array{state: string, expression: string, at?: string}
     */
    private static function argument(string $expression, ArgumentState $state, ?string $at): array
    {
        $argument = ['state' => $state->value, 'expression' => $expression];
        if (null !== $at) {
            $argument['at'] = $at;
        }

        return $argument;
    }

    private static function inFunction(string $parameters, string $body, string $returns = 'int'): string
    {
        return "<?php\n\nfunction run(".$parameters.'): '.$returns."\n{\n    ".$body."\n}\n";
    }

    /**
     * A final class holding the members, and a method run(string $s)
     * holding the body.
     */
    private static function inFinalClass(string $members, string $body): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    ".$members."\n\n    public function run(string \$s): int\n    {\n        ".$body."\n    }\n}\n";
    }
}
