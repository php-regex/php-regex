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

use PHPRegex\Linter\Extraction\ProjectConstants;
use PHPRegex\Linter\Extraction\ValueExpr;
use PHPRegex\Tests\Support\ExtractsAcrossFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A constant, the value of an enum case or the default of a property
 * whose value is a ternary, a short ternary, a null coalescing or a match
 * holds one of the values of its arms, each proved as the value of a
 * constant is: the argument naming it is one of several patterns. The
 * condition is not read. A short ternary and a null coalescing keep the
 * arms PHP keeps. An arm the file does not prove leaves nothing known.
 *
 * Each arm that is one literal is reported where the literal is written,
 * once, in the file that writes it. Joined to literals at a call, such a
 * constant gives a pattern for each of its values. A constant has one
 * value: standing twice in one argument, it is the same value at both
 * places. Beside another operand of several values, the code builds only
 * some of the combinations and nothing is known of the argument.
 *
 * PHP 8.4 (php -r, 64 bits): const T = PHP_INT_SIZE === 8 ? '/wide/' :
 * '/narrow/' is "/wide/"; const S = self::T ?: '/else/' is "/wide/", ''
 * ?: '/else/' and '0' ?: '/else/' are "/else/", self::T ?? '/else/' is
 * "/wide/"; the same ternary is accepted as the default of a property,
 * as the value of an enum case, of a global const and of define(). A
 * match is no constant expression ("Fatal error: Constant expression
 * contains invalid operations" in a class constant, a global const and a
 * property default): only define() takes one.
 *
 * Every case runs through both strategies.
 */
final class BranchingConstantTest extends TestCase
{
    use ExtractsAcrossFiles;

    private const HEAD = <<<'PHP_WRAP'
        <?php

        const GLOBAL_RE = \PHP_OS_FAMILY === 'Windows' ? '/global-win/' : '/global-unix/';
        const WORD_OF_THE_FILE = \PHP_INT_SIZE === 8 ? 'bird' : 'fish';
        define('DEFINED_RE', \PHP_INT_SIZE === 8 ? '/defined-8/' : '/defined-4/');
        define('MATCHED_RE', match (\PHP_INT_SIZE) {
            8 => '/match-8/',
            4 => '/match-4/',
            default => '/match-other/',
        });

        enum Kind: string
        {
            case Re = \PHP_INT_SIZE === 8 ? '/enum-8/' : '/enum-4/';
        }

        final class Matcher
        {
            private const A = '/const-a/';
            private const B = '/const-b/';
            private const EMPTY = '';
            private const ZERO = '0';
            private const TERNARY = \PHP_INT_SIZE === 8 ? '/wide/' : '/narrow/';
            private const NESTED = \PHP_INT_SIZE === 8 ? '/n-first/' : (\PHP_INT_SIZE === 4 ? '/n-second/' : '/n-third/');
            private const NAMES = \PHP_INT_SIZE === 8 ? self::A : self::B;
            private const ALIAS = self::TERNARY;
            private const SHORT = self::A ?: '/short-else/';
            private const SHORT_EMPTY = self::EMPTY ?: '/short-kept/';
            private const SHORT_ZERO = self::ZERO ?: '/zero-kept/';
            private const COALESCE = self::A ?? '/coalesce-else/';
            private const NULLISH = null ?? '/nullish-else/';
            private const UNPROVED = \PHP_INT_SIZE === 8 ? '/proved-arm/' : self::MISSING;
            private const WORD = \PHP_INT_SIZE === 8 ? 'cat' : 'dog';
            private const SIZE = \PHP_INT_SIZE === 8 ? 'big' : 'small';

            private string $property = \PHP_INT_SIZE === 8 ? '/property-8/' : '/property-4/';


        PHP_WRAP;

    private const PATTERNS = <<<'PHP_WRAP'
        <?php

        namespace App;

        final class Patterns
        {
            public const RE = \PHP_INT_SIZE === 8 ? '/other-wide/' : '/other-narrow/';
            public const EMPTY = '';
            public const SHORT = self::EMPTY ?: '/other-kept/';
            public const WORD = \PHP_INT_SIZE === 8 ? 'cat' : 'dog';
            public const UNPROVED = \PHP_INT_SIZE === 8 ? '/other-proved/' : Elsewhere::MISSING;
        }

        enum Kind: string
        {
            case Re = \PHP_INT_SIZE === 8 ? '/other-enum-8/' : '/other-enum-4/';
        }
        PHP_WRAP;

    private const READER = <<<'PHP'
        <?php

        namespace App\Service;

        use App\Kind;
        use App\Patterns;

        final class Reader
        {
            public function run(string $s): int
            {
                return preg_match(Patterns::RE, $s)
                    + preg_match(Patterns::SHORT, $s)
                    + preg_match('/^' . Patterns::WORD . '$/', $s)
                    + preg_match(Kind::Re->value, $s)
                    + preg_match(Patterns::UNPROVED, $s);
            }
        }
        PHP;

    /**
     * Each row gives the argument, the patterns it may hold, each found
     * in the file by its literal, and, where the literal is an arm of the
     * very constant the call names, the name it is read through.
     *
     * @return iterable<string, array{argument: string, patterns: list<string>, state: string, location?: string}>
     */
    public static function provideBranchingConstants(): iterable
    {
        yield 'a class constant holding a ternary' => ['argument' => 'self::TERNARY', 'patterns' => ['/wide/', '/narrow/'], 'state' => 'finite_set', 'location' => 'via self::TERNARY'];
        // true ? '/a/' : (false ? '/b/' : '/c/') is "/a/": three arms.
        yield 'a class constant holding a ternary in a ternary' => ['argument' => 'self::NESTED', 'patterns' => ['/n-first/', '/n-second/', '/n-third/'], 'state' => 'finite_set', 'location' => 'via self::NESTED'];
        yield 'a class constant whose arms name constants' => ['argument' => 'self::NAMES', 'patterns' => ['/const-a/', '/const-b/'], 'state' => 'finite_set'];
        yield 'a class constant naming one that holds a ternary' => ['argument' => 'self::ALIAS', 'patterns' => ['/wide/', '/narrow/'], 'state' => 'finite_set'];
        yield 'a global constant holding a ternary' => ['argument' => 'GLOBAL_RE', 'patterns' => ['/global-win/', '/global-unix/'], 'state' => 'finite_set', 'location' => 'via GLOBAL_RE'];
        yield 'a define() holding a ternary' => ['argument' => 'DEFINED_RE', 'patterns' => ['/defined-8/', '/defined-4/'], 'state' => 'finite_set', 'location' => 'via DEFINED_RE'];
        yield 'a define() holding a match' => ['argument' => 'MATCHED_RE', 'patterns' => ['/match-8/', '/match-4/', '/match-other/'], 'state' => 'finite_set', 'location' => 'via MATCHED_RE'];
        yield 'an enum case holding a ternary' => ['argument' => 'Kind::Re->value', 'patterns' => ['/enum-8/', '/enum-4/'], 'state' => 'finite_set', 'location' => 'via Kind::Re->value'];
        yield 'a property default holding a ternary' => ['argument' => '$this->property', 'patterns' => ['/property-8/', '/property-4/'], 'state' => 'finite_set', 'location' => 'via $this->property'];
        // '/const-a/' ?: '/short-else/' is "/const-a/".
        yield 'a short ternary on a constant that is not empty' => ['argument' => 'self::SHORT', 'patterns' => ['/const-a/'], 'state' => 'constant'];
        // '' ?: '/short-kept/' is "/short-kept/".
        yield 'a short ternary on an empty constant' => ['argument' => 'self::SHORT_EMPTY', 'patterns' => ['/short-kept/'], 'state' => 'constant'];
        // '0' ?: '/zero-kept/' is "/zero-kept/".
        yield 'a short ternary on a constant holding "0"' => ['argument' => 'self::SHORT_ZERO', 'patterns' => ['/zero-kept/'], 'state' => 'constant'];
        // '/const-a/' ?? '/coalesce-else/' is "/const-a/".
        yield 'a null coalescing on a constant' => ['argument' => 'self::COALESCE', 'patterns' => ['/const-a/'], 'state' => 'constant'];
        // null ?? '/nullish-else/' is "/nullish-else/" in PHP: a left
        // side that is no proved string is not followed.
        yield 'a null coalescing on null' => ['argument' => 'self::NULLISH', 'patterns' => [], 'state' => 'opaque'];
        // One arm names a constant no file declares: the other is not read.
        yield 'a ternary one of whose arms the file does not prove' => ['argument' => 'self::UNPROVED', 'patterns' => [], 'state' => 'opaque'];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideBranchingConstants')]
    public function test_a_constant_holding_branches_is_the_set_of_the_values_of_its_arms(string $argument, array $patterns, string $state, ?string $location = null): void
    {
        $code = self::matcher('', $argument);

        $expected = [];
        foreach ($patterns as $pattern) {
            $row = ['pattern' => $pattern, 'at' => "'".$pattern."'", 'source' => 'preg_match()'];
            if (null !== $location) {
                $row['location'] = $location;
            }

            $expected[] = $row;
        }

        $this->assertExtractedByBoth($expected, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Read by two calls, each arm is reported once; each call is one of
     * several patterns.
     */
    #[Test]
    public function test_the_arms_of_a_constant_read_by_two_calls_are_reported_once(): void
    {
        $code = self::matcher('', 'self::TERNARY, $s) + preg_match_all(self::TERNARY');

        $this->assertExtractedByBoth([
            ['pattern' => '/wide/', 'at' => "'/wide/'", 'location' => 'via self::TERNARY'],
            ['pattern' => '/narrow/', 'at' => "'/narrow/'", 'location' => 'via self::TERNARY'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => 'self::TERNARY', 'at' => 'self::TERNARY, $s) +', 'source' => 'preg_match()'],
            ['state' => 'finite_set', 'expression' => 'self::TERNARY', 'at' => 'self::TERNARY, $s);', 'source' => 'preg_match_all()'],
        ], $code);
    }

    /**
     * Joined to literals, a constant of two values gives two patterns,
     * composed at the call and reported where the argument starts; it
     * fills a place of sprintf() the same way.
     *
     * PHP 8.4, 64 bits: WORD is "cat": '/^' . self::WORD . 's?$/' is
     * "/^cats?$/", and "/^dogs?$/" where PHP_INT_SIZE is not 8.
     */
    #[Test]
    public function test_a_constant_of_several_values_multiplies_into_a_composition(): void
    {
        $code = self::matcher('', "'/^' . self::WORD . 's?$/', \$s) + preg_match(sprintf('/^(%s)$/', self::WORD)");

        $this->assertExtractedByBoth([
            ['pattern' => '/^cats?$/', 'at' => "'/^' . self::WORD . 's?$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^dogs?$/', 'at' => "'/^' . self::WORD . 's?$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^(cat)$/', 'at' => "sprintf('/^(%s)$/', self::WORD)", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^(dog)$/', 'at' => "sprintf('/^(%s)$/', self::WORD)", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . self::WORD . 's?$/'"],
            ['state' => 'finite_set', 'expression' => "sprintf('/^(%s)$/', self::WORD)"],
        ], $code);
    }

    /**
     * A constant has one value wherever it stands: twice in one argument,
     * it is one operand of several values, and the argument a pattern for
     * each value, exactly. PHP 8.4, 64 bits: '/^' . self::WORD . '|' .
     * self::WORD . '$/' is "/^cat|cat$/", and "/^dog|dog$/" where
     * PHP_INT_SIZE is not 8, never "/^cat|dog$/".
     *
     * @return iterable<string, array{argument: string, patterns: list<string>}>
     */
    public static function provideCompositionsOfTheSameConstantTwice(): iterable
    {
        yield 'in a concatenation' => ['argument' => "'/^' . self::WORD . '|' . self::WORD . '$/'", 'patterns' => ['/^cat|cat$/', '/^dog|dog$/']];
        yield 'side by side' => ['argument' => "'/^' . self::WORD . self::WORD . '$/'", 'patterns' => ['/^catcat$/', '/^dogdog$/']];
        yield 'three times' => ['argument' => "'/' . self::WORD . '-' . self::WORD . '-' . self::WORD . '/'", 'patterns' => ['/cat-cat-cat/', '/dog-dog-dog/']];
        yield 'in sprintf()' => ['argument' => "sprintf('/^%s-%s$/', self::WORD, self::WORD)", 'patterns' => ['/^cat-cat$/', '/^dog-dog$/']];
        yield 'in implode()' => ['argument' => "'/^(' . implode('|', [self::WORD, 'bird', self::WORD]) . ')$/'", 'patterns' => ['/^(cat|bird|cat)$/', '/^(dog|bird|dog)$/']];
        yield 'in a concatenation and in sprintf() inside it' => ['argument' => "'/^' . self::WORD . sprintf('(%s)', self::WORD) . '$/'", 'patterns' => ['/^cat(cat)$/', '/^dog(dog)$/']];
        yield 'a global constant, twice' => ['argument' => "'/' . WORD_OF_THE_FILE . '|' . WORD_OF_THE_FILE . '/'", 'patterns' => ['/bird|bird/', '/fish|fish/']];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideCompositionsOfTheSameConstantTwice')]
    public function test_the_same_constant_twice_has_one_value_in_each_pattern(string $argument, array $patterns): void
    {
        $code = self::matcher('', $argument);

        $expected = [];
        foreach ($patterns as $pattern) {
            $expected[] = ['pattern' => $pattern, 'at' => $argument.', $s)', 'source' => 'preg_match()', 'location' => null];
        }

        $this->assertExtractedByBoth($expected, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A variable assigned such a constant is a variable: standing twice,
     * it is two operands of several values, as any variable of several
     * values is, and nothing is read. Standing once, it brings the two
     * values through.
     */
    #[Test]
    public function test_a_variable_assigned_a_constant_of_several_values_is_a_variable(): void
    {
        $code = self::matcher('$word = self::WORD;', "'/^' . \$word . 's?$/', \$s) + preg_match('/^' . \$word . '|' . \$word . '$/'");

        $this->assertExtractedByBoth([
            ['pattern' => '/^cats?$/', 'at' => "'/^' . \$word . 's?$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^dogs?$/', 'at' => "'/^' . \$word . 's?$/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . \$word . 's?$/'"],
            ['state' => 'opaque', 'expression' => "'/^' . \$word . '|' . \$word . '$/'"],
        ], $code);
    }

    /**
     * Two operands of several values are not multiplied, a constant as
     * any other: PHP_INT_SIZE decides WORD and SIZE at once, and the code
     * builds "/^cat-big$/" or "/^dog-small$/", never "/^cat-small$/".
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideCompositionsOfTwoOperandsOfSeveralValues(): iterable
    {
        yield 'two constants of two values' => ['argument' => "'/^' . self::WORD . '-' . self::SIZE . '$/'"];
        yield 'a constant and a variable of two values' => ['argument' => "'/^' . self::WORD . '-' . \$two . '$/'"];
        yield 'a constant and a ternary' => ['argument' => "'/^' . self::WORD . (\$flag ? '+' : '*') . '$/'"];
        yield 'a constant and a variable, in an interpolated string' => ['argument' => "'/^' . self::WORD . \"-\$two$/\""];
        yield 'two constants in sprintf()' => ['argument' => "sprintf('/^%s-%s$/', self::WORD, self::SIZE)"];
    }

    #[Test]
    #[DataProvider('provideCompositionsOfTwoOperandsOfSeveralValues')]
    public function test_a_constant_of_several_values_beside_another_operand_of_several_values_is_opaque(string $argument): void
    {
        // $two is given its two values by one assignment.
        $code = self::matcher("\$two = \$flag ? 'a' : 'b';", $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A composition gives 32 patterns at most: a constant of 33 values
     * joined to a literal is not built. Read alone, a constant of 33
     * patterns is a set, each a literal written in the file.
     *
     * @return iterable<string, array{arms: int, composed: string}>
     */
    public static function provideConstantsAtTheCap(): iterable
    {
        yield '32 values' => ['arms' => 32, 'composed' => 'finite_set'];
        yield '33 values' => ['arms' => 33, 'composed' => 'opaque'];
    }

    #[Test]
    #[DataProvider('provideConstantsAtTheCap')]
    public function test_a_constant_of_several_values_composed_past_the_cap_is_opaque(int $arms, string $composed): void
    {
        $words = '';
        $wholes = '';
        $expected = [];
        for ($i = 1; $i <= $arms; $i++) {
            $key = $i === $arms ? 'default' : (string) $i;
            $words .= \sprintf("    %s => 'w%02d',\n", $key, $i);
            $wholes .= \sprintf("    %s => '/whole-%02d/',\n", $key, $i);
            if ('finite_set' === $composed) {
                $expected[] = \sprintf('/^w%02d$/', $i);
            }
            $expected[] = \sprintf('/whole-%02d/', $i);
        }
        sort($expected, \SORT_STRING);

        $code = "<?php\n\ndefine('WORDS', match (\\PHP_INT_SIZE) {\n".$words."});\ndefine('WHOLES', match (\\PHP_INT_SIZE) {\n".$wholes."});\n\n"
            ."function run(string \$s): int\n{\n    return preg_match('/^' . WORDS . '$/', \$s) + preg_match(WHOLES, \$s);\n}\n";

        $this->assertArgumentsReadByBoth([
            ['state' => $composed, 'expression' => "'/^' . WORDS . '$/'"],
            ['state' => 'finite_set', 'expression' => 'WHOLES', 'at' => 'WHOLES, $s)'],
        ], $code);

        $read = $this->patternsReadByBoth($code);
        sort($read, \SORT_STRING);
        $this->assertSame($expected, $read);
    }

    /**
     * The constants of a file keep such a value: what another file reads
     * through them.
     */
    #[Test]
    public function test_the_constants_of_a_file_keep_a_value_holding_branches(): void
    {
        $constants = ProjectConstants::of([ProjectConstants::scan(self::PATTERNS, '/p/src/Patterns.php')]);

        $this->assertIsArray($constants->constant('app\patterns', 'RE'));
        $this->assertIsArray($constants->constant('app\patterns', 'SHORT'));
        $this->assertIsArray($constants->constant('app\patterns', 'WORD'));
        $this->assertIsArray($constants->constant('app\kind', ValueExpr::enumCaseName('Re')));
    }

    /**
     * Read from another file, each arm is reported in the file that
     * writes it, where its literal stands; a pattern composed at the call
     * is reported in the file of the call.
     */
    #[Test]
    public function test_a_constant_of_another_file_holding_branches_is_read_through_the_project(): void
    {
        $files = ['Patterns.php' => self::PATTERNS, 'Reader.php' => self::READER];

        $read = $this->readAcross($files);

        $this->assertSame([
            ['/other-wide/', 'Patterns.php', strpos(self::PATTERNS, "'/other-wide/'"), 'via Patterns::RE'],
            ['/other-narrow/', 'Patterns.php', strpos(self::PATTERNS, "'/other-narrow/'"), 'via Patterns::RE'],
            ['/other-kept/', 'Patterns.php', strpos(self::PATTERNS, "'/other-kept/'"), null],
            ['/other-enum-8/', 'Patterns.php', strpos(self::PATTERNS, "'/other-enum-8/'"), 'via Kind::Re->value'],
            ['/other-enum-4/', 'Patterns.php', strpos(self::PATTERNS, "'/other-enum-4/'"), 'via Kind::Re->value'],
            ['/^cat$/', 'Reader.php', strpos(self::READER, "'/^' . Patterns::WORD"), null],
            ['/^dog$/', 'Reader.php', strpos(self::READER, "'/^' . Patterns::WORD"), null],
        ], array_map(
            // The name a pattern kept by a short ternary is read through is
            // not compared: only where it stands.
            static fn (array $row): array => [$row['pattern'], $row['file'], $row['offset'], '/other-kept/' === $row['pattern'] ? null : $row['location']],
            $read,
        ));

        $this->assertSame([
            ['file' => 'Reader.php', 'expression' => 'Patterns::RE', 'state' => 'finite_set'],
            ['file' => 'Reader.php', 'expression' => 'Patterns::SHORT', 'state' => 'constant'],
            ['file' => 'Reader.php', 'expression' => "'/^' . Patterns::WORD . '$/'", 'state' => 'finite_set'],
            ['file' => 'Reader.php', 'expression' => 'Kind::Re->value', 'state' => 'finite_set'],
            ['file' => 'Reader.php', 'expression' => 'Patterns::UNPROVED', 'state' => 'opaque'],
        ], $this->argumentsAcross($files));
    }

    /**
     * The snippets' file, whose class Matcher has a method run() running
     * the statements, then reading the argument in preg_match().
     */
    private static function matcher(string $setup, string $argument): string
    {
        return self::HEAD
            ."    public function run(string \$s, bool \$flag): int\n    {\n        ".$setup."\n\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
