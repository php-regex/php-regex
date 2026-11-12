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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Tests\Support\ExtractsWithBothStrategies;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An argument that is one of several values, some of them arrays a
 * constant holds: each array gives its patterns, one argument per item,
 * whatever the other values are. A value beside them that the file does
 * not prove is one more argument, of which nothing is known: it does not
 * take the patterns of the arrays away, and they do not make it known.
 *
 * PHP 8.4 (php -r): $a = ['/a/']; $b = ['/b/']; three turns of { $tmp =
 * $a; $a = $b; $b = $tmp; } leave ['/b/'] in $a, and two turns ['/a/'].
 *
 * Every case runs through both strategies.
 */
final class ArrayAmongAlternativesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    private const LISTS = <<<'PHP'
            private const FIRST = ['/first-a/', '/first-b/'];
            private const SECOND = ['/second-a/', '/second-b/'];
        PHP;

    #[Test]
    public function test_a_ternary_of_two_lists_gives_the_patterns_of_both(): void
    {
        $code = self::cleaner('return preg_replace($flag ? self::FIRST : self::SECOND, \'\', $s);');

        $this->assertExtractedByBoth([
            ['pattern' => '/first-a/', 'at' => "'/first-a/'", 'location' => 'via self::FIRST'],
            ['pattern' => '/first-b/', 'at' => "'/first-b/'", 'location' => 'via self::FIRST'],
            ['pattern' => '/second-a/', 'at' => "'/second-a/'", 'location' => 'via self::SECOND'],
            ['pattern' => '/second-b/', 'at' => "'/second-b/'", 'location' => 'via self::SECOND'],
        ], $code);
        $this->assertArgumentsReadByBoth(array_fill(0, 4, ['state' => 'constant', 'expression' => '$flag ? self::FIRST : self::SECOND']), $code);
    }

    /**
     * Each row gives the body of the method, the argument, and the names
     * the two patterns of SECOND are read through.
     *
     * @return iterable<string, array{body: string, argument: string, via: string}>
     */
    public static function provideListsBesideAValueTheFileDoesNotProve(): iterable
    {
        // PHP joins nothing for $late where the call stands.
        yield 'a variable assigned only after the call' => [
            'body' => "\$cleaned = preg_replace(\$flag ? \$late : self::SECOND, '', \$s);\n        \$late = '/later/';\n\n        return \$cleaned;",
            'argument' => '$flag ? $late : self::SECOND',
            'via' => 'via self::SECOND',
        ];

        yield 'a variable assigned only after the call, through another variable' => [
            'body' => "\$re = \$flag ? \$late : self::SECOND;\n        \$cleaned = preg_replace(\$re, '', \$s);\n        \$late = '/later/';\n\n        return \$cleaned;",
            'argument' => '$re',
            'via' => 'via $re',
        ];

        // An array written in an arm is left out, as one assigned to a
        // variable is.
        yield 'an array written in the other arm' => [
            'body' => "\$re = \$flag ? ['/written-a/', '/written-b/'] : self::SECOND;\n\n        return preg_replace(\$re, '', \$s);",
            'argument' => '$re',
            'via' => 'via $re',
        ];
    }

    #[Test]
    #[DataProvider('provideListsBesideAValueTheFileDoesNotProve')]
    public function test_a_list_beside_a_value_the_file_does_not_prove_still_gives_its_patterns(string $body, string $argument, string $via): void
    {
        $code = self::cleaner($body);

        $this->assertExtractedByBoth([
            ['pattern' => '/second-a/', 'at' => "'/second-a/'", 'location' => $via],
            ['pattern' => '/second-b/', 'at' => "'/second-b/'", 'location' => $via],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'at' => $argument.", ''", 'source' => 'preg_replace()'],
            ['state' => 'constant', 'expression' => $argument, 'at' => $argument.", ''", 'source' => 'preg_replace()'],
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.", ''", 'source' => 'preg_replace()'],
        ], $code);
    }

    /**
     * The loop swaps the two variables at each turn: $a holds one of the
     * two lists, and, through $tmp, what it held itself, which the file
     * does not prove a second time.
     */
    #[Test]
    public function test_two_lists_swapped_in_a_loop_both_give_their_patterns(): void
    {
        $code = self::cleaner(<<<'PHP'
            $a = self::FIRST;
                    $b = self::SECOND;
                    while ($flag) {
                        $tmp = $a;
                        $a = $b;
                        $b = $tmp;
                        $flag = !$flag;
                    }

                    return preg_replace($a, '', $s);
            PHP);

        $this->assertExtractedByBoth([
            ['pattern' => '/first-a/', 'at' => "'/first-a/'", 'location' => 'via $a'],
            ['pattern' => '/first-b/', 'at' => "'/first-b/'", 'location' => 'via $a'],
            ['pattern' => '/second-a/', 'at' => "'/second-a/'", 'location' => 'via $a'],
            ['pattern' => '/second-b/', 'at' => "'/second-b/'", 'location' => 'via $a'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ...array_fill(0, 4, ['state' => 'constant', 'expression' => '$a', 'at' => "\$a, ''"]),
            ['state' => 'opaque', 'expression' => '$a', 'at' => "\$a, ''"],
        ], $code);
    }

    /**
     * At file level a call may have written $re since its assignment:
     * that arm is not read, and the list of the other arm is.
     */
    #[Test]
    public function test_a_list_beside_a_variable_of_the_file_level_a_call_may_have_written(): void
    {
        $code = <<<'PHP'
            <?php

            const PATTERNS = ['/listed-a/', '/listed-b/'];

            $re = '/assigned/';
            prepare();
            preg_replace($argc > 1 ? $re : PATTERNS, '', $subject);
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/listed-a/', 'at' => "'/listed-a/'", 'location' => 'via PATTERNS'],
            ['pattern' => '/listed-b/', 'at' => "'/listed-b/'", 'location' => 'via PATTERNS'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$argc > 1 ? $re : PATTERNS'],
            ['state' => 'constant', 'expression' => '$argc > 1 ? $re : PATTERNS'],
            ['state' => 'opaque', 'expression' => '$argc > 1 ? $re : PATTERNS'],
        ], $code);
    }

    /**
     * Options that hold no pattern give no argument; beside them, the
     * options that hold one are still read for it.
     */
    #[Test]
    public function test_options_holding_a_pattern_are_read_beside_options_holding_none(): void
    {
        $code = <<<'PHP'
            <?php

            use Symfony\Component\Validator\Constraints\Regex;

            final class Form
            {
                private const WITHOUT = ['message' => 'Nothing to match.'];
                private const WITH = ['pattern' => '/^[a-z]+$/', 'message' => 'Only letters.'];

                public function constraint(bool $strict): Regex
                {
                    return new Regex($strict ? self::WITHOUT : self::WITH);
                }
            }
            PHP;
        $registry = PatternFunctionRegistry::create(['symfony-validator']);

        $this->assertExtractedByBoth([
            ['pattern' => '/^[a-z]+$/', 'at' => "'/^[a-z]+$/'", 'source' => 'new Regex()', 'location' => 'via self::WITH'],
        ], $code, $registry);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$strict ? self::WITHOUT : self::WITH', 'source' => 'new Regex()'],
        ], $code, $registry);
    }

    /**
     * preg_replace_callback_array() reads its patterns from the keys,
     * whatever the first item is: an item with no key holds no pattern,
     * and no callback is one.
     */
    #[Test]
    public function test_a_map_of_callbacks_gives_its_keys_whatever_its_first_item_is(): void
    {
        $code = <<<'PHP'
            <?php

            final class Cleaner
            {
                private const HANDLERS = ['strtoupper', '/keyed/' => 'strtolower'];

                public function run(string $s): ?string
                {
                    return preg_replace_callback_array(self::HANDLERS, $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/keyed/', 'at' => "'/keyed/'", 'source' => 'preg_replace_callback_array()', 'location' => 'via self::HANDLERS'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => 'self::HANDLERS', 'at' => 'self::HANDLERS, $s)'],
        ], $code);
    }

    private static function cleaner(string $body): string
    {
        return "<?php\n\nfinal class Cleaner\n{\n".self::LISTS."\n\n    public function run(string \$s, bool \$flag): ?string\n    {\n        ".$body."\n    }\n}\n";
    }
}
