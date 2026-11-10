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
 * An array of patterns the body or a constant proves is read three ways.
 *
 * A local variable assigned an array literal, passed to a function that
 * takes a list of patterns (preg_replace(), preg_replace_callback(),
 * preg_filter()), is one argument per item, as an array constant is.
 * Passed to a function that takes one pattern, nothing is known of it.
 *
 * The value target or the key target of a foreach over an array literal,
 * over such a variable or over an array constant holds one of the values,
 * or of the keys: the set of them. A foreach by reference, or a target
 * written elsewhere in the loop, is not read.
 *
 * An item named by a literal key, $map['word'], $list[0] or
 * self::MAP['word'], is that item; named by any other key, it is one of
 * the values of the array; a literal key the array does not hold names
 * nothing.
 *
 * A pattern that is one literal is reported where the literal is written;
 * one composed in the argument, where the argument starts.
 *
 * PHP 8.4 (php -r): preg_replace(['/a/', '/b/'], '', 'xaxbx') is "xxx",
 * and preg_match(['/a/'], 'x') a TypeError, "Argument #1 ($pattern) must
 * be of type string, array given"; foreach (['/k-a/' => 1, '/k-b/' => 2]
 * as $re => $w) gives $re "/k-a/" then "/k-b/"; ['word' => '/\w+/']
 * ['word'] is "/\w+/" and ['word' => '/\w+/']['missing'] is null, with
 * the warning 'Undefined array key "missing"'.
 *
 * Every case runs through both strategies.
 */
final class ArrayOfPatternsTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The file every snippet opens with: the global GLOBAL_LIST, and a
     * final class holding LIST, MAP and WORDS and the property $patterns.
     */
    private const HEAD = "<?php\n\nconst GLOBAL_LIST = ['/global-a/', '/global-b/'];\n\nfinal class Matcher\n{\n"
        ."    private const LIST = ['/const-a/', '/const-b/'];\n"
        ."    private const MAP = ['/const-key-a/' => 'x', '/const-key-b/' => 'y'];\n"
        ."    private const WORDS = ['word' => '/const-word/', 'digit' => '/const-digit/'];\n\n"
        ."    private array \$patterns = ['word' => '/property-word/'];\n\n";

    /**
     * @return iterable<string, array{call: string, source: string}>
     */
    public static function provideFunctionsTakingAList(): iterable
    {
        yield 'preg_replace()' => ['call' => "preg_replace(\$list, '', \$s)", 'source' => 'preg_replace()'];
        yield 'preg_replace_callback()' => ['call' => "preg_replace_callback(\$list, static fn (array \$m): string => '', \$s)", 'source' => 'preg_replace_callback()'];
        yield 'preg_filter()' => ['call' => "preg_filter(\$list, '', \$s)", 'source' => 'preg_filter()'];
    }

    /**
     * Each item is one argument, standing where the call names the
     * array; each pattern is reported where its literal is written,
     * through the name the call reads.
     */
    #[Test]
    #[DataProvider('provideFunctionsTakingAList')]
    public function test_a_local_array_passed_to_a_function_taking_a_list_is_one_argument_per_item(string $call, string $source): void
    {
        $code = "<?php\n\nfunction run(string \$s): ?string\n{\n    \$list = ['/item-a/', '/item-b/'];\n\n    return ".$call.";\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/item-a/', 'at' => "'/item-a/'", 'source' => $source, 'location' => 'via $list'],
            ['pattern' => '/item-b/', 'at' => "'/item-b/'", 'source' => $source, 'location' => 'via $list'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$list', 'at' => '$list, ', 'source' => $source],
            ['state' => 'constant', 'expression' => '$list', 'at' => '$list, ', 'source' => $source],
        ], $code);
    }

    /**
     * PHP 8.4: preg_match(), preg_match_all() and preg_split() given an
     * array are a TypeError; preg_grep() too. No list is taken there: the
     * items are not read as patterns.
     *
     * @return iterable<string, array{call: string}>
     */
    public static function provideFunctionsTakingOnePattern(): iterable
    {
        yield 'preg_match()' => ['call' => 'preg_match($list, $s)'];
        yield 'preg_match_all()' => ['call' => 'preg_match_all($list, $s)'];
        yield 'preg_split()' => ['call' => 'preg_split($list, $s)'];
        yield 'preg_grep()' => ['call' => 'preg_grep($list, [$s])'];
    }

    #[Test]
    #[DataProvider('provideFunctionsTakingOnePattern')]
    public function test_a_local_array_passed_where_one_pattern_is_taken_is_opaque(string $call): void
    {
        $code = "<?php\n\nfunction run(string \$s): mixed\n{\n    \$list = ['/item-a/', '/item-b/'];\n\n    return ".$call.";\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$list', 'at' => '$list, '],
        ], $code);
    }

    /**
     * The keys of the array are not patterns there (PHP 8.4:
     * preg_replace(['x' => '/a/', 'y' => '/b/'], '', 'xaxbx') is "xxx").
     * An item is read as a value is: a constant at its literal, a
     * concatenation where it is composed. An item the body does not prove
     * is an argument of its own state, as an item of an array constant
     * is, and the items beside it are still read; an item that is an
     * array is no pattern (PHP: "Array to string conversion").
     */
    #[Test]
    public function test_each_item_of_a_local_array_has_its_own_state(): void
    {
        $code = <<<'PHP'
            <?php

            final class Cleaner
            {
                private const RE = '/const-item/';

                public function run(string $s, string $given): string
                {
                    $list = ['first' => '/keyed-item/', self::RE, '/con' . 'cat/', $given, '/^' . $given . '$/', ['/nested/']];

                    return (string) preg_replace($list, '', $s);
                }
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/const-item/', 'at' => "'/const-item/'", 'source' => 'preg_replace()'],
            ['pattern' => '/keyed-item/', 'at' => "'/keyed-item/'", 'source' => 'preg_replace()', 'location' => 'via $list'],
            ['pattern' => '/concat/', 'at' => "'/con' . 'cat/'", 'source' => 'preg_replace()', 'location' => 'via $list'],
        ], $code);

        // One argument per item, all standing where the call names the
        // array: three patterns, the parameter, the pattern around it and
        // the nested array.
        $states = $this->statesReadByBoth($code);
        sort($states);
        $this->assertSame(['constant', 'constant', 'constant', 'opaque', 'opaque', 'raw_shell'], $states);
    }

    /**
     * An array with no item holds no argument, written at the call or
     * read through a variable.
     */
    #[Test]
    public function test_an_empty_local_array_holds_no_argument(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $word): string
            {
                $none = [];
                preg_match('/^' . $word . '/', $s);

                return (string) preg_replace($none, '', $s);
            }
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'raw_shell', 'expression' => "'/^' . \$word . '/'"],
        ], $code);
    }

    /**
     * An array is read by the rules of a local variable, and when one
     * assignment alone reaches the read: every write of it is a plain
     * assignment of the whole, and there is one of them. Any other write
     * may change its items; a second assignment gives another array.
     *
     * PHP 8.4, $list = ['/a/', '/b/']: after foreach ($list as &$item) {
     * $item = '/(/'; } it is ["/(/", "/(/"]; after $list[] = '/(/' it is
     * ["/a/", "/b/", "/(/"]; after $list[0] = '/(/' its first item is
     * "/(/"; after unset($list[0]) it is [1 => "/b/"].
     *
     * @return iterable<string, array{write: string}>
     */
    public static function provideWritesOfAnArray(): iterable
    {
        yield 'an item appended' => ['write' => '$list[] = $given;'];
        yield 'an item written by its key' => ['write' => "\$list['k'] = \$given;"];
        yield 'an item written by its index' => ['write' => '$list[0] = $given;'];
        yield 'an item unset' => ['write' => 'unset($list[0]);'];
        yield 'a foreach by reference over it' => ['write' => "foreach (\$list as &\$item) {\n        \$item = \$given;\n    }"];
        yield 'the array passed to a call' => ['write' => 'sort($list);'];
        yield 'a reference taken to it' => ['write' => '$alias = &$list;'];
        yield 'a compound assignment' => ['write' => '$list += [$given];'];
        yield 'a destructuring assignment' => ['write' => '[$list] = $unknown;'];
        yield 'a second assignment the body does not prove' => ['write' => "if ('' !== \$given) {\n        \$list = \$unknown;\n    }"];
        // Two arrays are not proved alternatives item by item: neither
        // is read, whatever the second holds.
        yield 'a second array assigned in a branch' => ['write' => "if ('' !== \$given) {\n        \$list = ['/other-a/', '/other-b/'];\n    }"];
        yield 'a second array assigned outright' => ['write' => "\$list = ['/other-a/', '/other-b/'];"];
    }

    /**
     * The array is read three ways after the write: as a list of
     * patterns, through a foreach and by a literal key. Nothing is known
     * of any of them, and no item is read.
     */
    #[Test]
    #[DataProvider('provideWritesOfAnArray')]
    public function test_a_local_array_written_otherwise_than_by_a_plain_assignment_is_not_read(string $write): void
    {
        $code = "<?php\n\nfunction run(string \$s, string \$given, array \$unknown): int\n{\n    \$list = ['/item-a/', '/item-b/'];\n    ".$write."\n"
            ."    \$found = \\strlen((string) preg_replace(\$list, '', \$s));\n"
            ."    foreach (\$list as \$re) {\n        \$found += preg_match(\$re, \$s);\n    }\n\n"
            ."    return \$found + preg_match(\$list[0], \$s);\n}\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$list', 'at' => "\$list, '', \$s)", 'source' => 'preg_replace()'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
            ['state' => 'opaque', 'expression' => '$list[0]', 'at' => '$list[0], $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A variable assigned an array variable holds that array: it is read
     * as the array is, through a list of patterns, a foreach and a key.
     *
     * PHP 8.4: $list = ['/alias-a/', '/alias-b/']; $copy = $list;
     * preg_replace($copy, '', 'x/alias-a/') reads both items, and
     * $copy[1] is "/alias-b/".
     */
    #[Test]
    public function test_a_variable_assigned_a_local_array_is_read_like_the_array(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $list = ['/alias-a/', '/alias-b/'];
                $copy = $list;
                $found = \strlen((string) preg_replace($copy, '', $s));
                foreach ($copy as $re) {
                    $found += preg_match($re, $s);
                }

                return $found + preg_match($copy[1], $s) + preg_match('/^(' . implode('|', $copy) . ')$/', $s);
            }
            PHP;

        // Each item is reported once, where it is written; the name it is
        // read through is only compared between the strategies. implode()
        // joins them at its call: "/^(/alias-a/|/alias-b/)$/".
        $this->assertExtractedByBoth([
            ['pattern' => '/alias-a/', 'at' => "'/alias-a/'"],
            ['pattern' => '/alias-b/', 'at' => "'/alias-b/'"],
            ['pattern' => '/^(/alias-a/|/alias-b/)$/', 'at' => "'/^(' . implode('|', \$copy) . ')$/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$copy', 'at' => "\$copy, '', \$s)", 'source' => 'preg_replace()'],
            ['state' => 'constant', 'expression' => '$copy', 'at' => "\$copy, '', \$s)", 'source' => 'preg_replace()'],
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'constant', 'expression' => '$copy[1]', 'at' => '$copy[1], $s)'],
            ['state' => 'constant', 'expression' => "'/^(' . implode('|', \$copy) . ')$/'"],
        ], $code);
    }

    /**
     * Each row gives what the loop iterates, its targets, and the
     * patterns the target "$re" may hold, each found in the code by its
     * literal.
     *
     * PHP 8.4: foreach (['/v-a/', '/v-b/'] as $re) gives "/v-a/" then
     * "/v-b/"; with "as $name => $re" the same; with "as $re => $weight"
     * over ['/k-a/' => 1, '/k-b/' => 2], "/k-a/" then "/k-b/"; the
     * multi-byte keys '/^ü/u' and '/日本語/u' come out as written.
     *
     * @return iterable<string, array{setup: string, iterated: string, target: string, patterns: list<string>}>
     */
    public static function provideLoops(): iterable
    {
        yield 'the value of an array literal' => [
            'setup' => '',
            'iterated' => "['/lit-a/', '/lit-b/']",
            'target' => '$re',
            'patterns' => ['/lit-a/', '/lit-b/'],
        ];

        yield 'the value of an array() literal' => [
            'setup' => '',
            'iterated' => "array('/old-a/', '/old-b/')",
            'target' => '$re',
            'patterns' => ['/old-a/', '/old-b/'],
        ];

        yield 'the value of a keyed array literal' => [
            'setup' => '',
            'iterated' => "['x' => '/keyed-a/', 'y' => '/keyed-b/']",
            'target' => '$re',
            'patterns' => ['/keyed-a/', '/keyed-b/'],
        ];

        yield 'the value, beside a key target' => [
            'setup' => '',
            'iterated' => "['x' => '/named-a/', 'y' => '/named-b/']",
            'target' => '$name => $re',
            'patterns' => ['/named-a/', '/named-b/'],
        ];

        yield 'the value of a local array' => [
            'setup' => "\$local = ['/local-a/', '/local-b/', '/local-c/'];",
            'iterated' => '$local',
            'target' => '$re',
            'patterns' => ['/local-a/', '/local-b/', '/local-c/'],
        ];

        yield 'the value of a class constant' => [
            'setup' => '',
            'iterated' => 'self::LIST',
            'target' => '$re',
            'patterns' => ['/const-a/', '/const-b/'],
        ];

        yield 'the value of a global constant' => [
            'setup' => '',
            'iterated' => 'GLOBAL_LIST',
            'target' => '$re',
            'patterns' => ['/global-a/', '/global-b/'],
        ];

        yield 'the value of multi-byte patterns' => [
            'setup' => '',
            'iterated' => "['/^é+$/u', '/日本/u']",
            'target' => '$re',
            'patterns' => ['/^é+$/u', '/日本/u'],
        ];

        yield 'the key of an array literal' => [
            'setup' => '',
            'iterated' => "['/key-a/' => 1, '/key-b/' => 2]",
            'target' => '$re => $weight',
            'patterns' => ['/key-a/', '/key-b/'],
        ];

        yield 'the key of a local array' => [
            'setup' => "\$local = ['/local-key-a/' => 'x', '/local-key-b/' => 'y'];",
            'iterated' => '$local',
            'target' => '$re => $replacement',
            'patterns' => ['/local-key-a/', '/local-key-b/'],
        ];

        yield 'the key of a class constant' => [
            'setup' => '',
            'iterated' => 'self::MAP',
            'target' => '$re => $replacement',
            'patterns' => ['/const-key-a/', '/const-key-b/'],
        ];

        yield 'multi-byte keys' => [
            'setup' => '',
            'iterated' => "['/^ü/u' => 1, '/日本語/u' => 2]",
            'target' => '$re => $weight',
            'patterns' => ['/^ü/u', '/日本語/u'],
        ];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideLoops')]
    public function test_the_target_of_a_foreach_holds_one_of_the_values_or_of_the_keys(string $setup, string $iterated, string $target, array $patterns): void
    {
        $code = self::looping($setup, 'foreach ('.$iterated.' as '.$target.") {\n            \$found += preg_match(\$re, \$s);\n        }");

        $expected = [];
        foreach ($patterns as $pattern) {
            $expected[] = ['pattern' => $pattern, 'at' => "'".$pattern."'", 'source' => 'preg_match()', 'location' => 'via $re'];
        }

        $this->assertExtractedByBoth($expected, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The alternative syntax is the same loop.
     */
    #[Test]
    public function test_a_foreach_in_its_alternative_syntax_is_read_the_same(): void
    {
        $code = self::looping('', "foreach (['/alt-a/', '/alt-b/'] as \$re):\n            \$found += preg_match(\$re, \$s);\n        endforeach;");

        $this->assertExtractedByBoth([
            ['pattern' => '/alt-a/', 'at' => "'/alt-a/'", 'location' => 'via $re'],
            ['pattern' => '/alt-b/', 'at' => "'/alt-b/'", 'location' => 'via $re'],
        ], $code);
        $this->assertSame(['finite_set'], $this->statesReadByBoth($code));
    }

    /**
     * One item is one pattern. No item is no value at all: the body of
     * the loop never runs, and nothing is known of the argument.
     *
     * @return iterable<string, array{setup: string, iterated: string, patterns: list<string>, state: string}>
     */
    public static function provideLoopsOverOneItemOrNone(): iterable
    {
        yield 'one item' => ['setup' => '', 'iterated' => "['/only/']", 'patterns' => ['/only/'], 'state' => 'constant'];
        yield 'one item of a local array' => ['setup' => "\$local = ['/only/'];", 'iterated' => '$local', 'patterns' => ['/only/'], 'state' => 'constant'];
        yield 'no item' => ['setup' => '', 'iterated' => '[]', 'patterns' => [], 'state' => 'opaque'];
        yield 'no item of a local array' => ['setup' => '$local = [];', 'iterated' => '$local', 'patterns' => [], 'state' => 'opaque'];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideLoopsOverOneItemOrNone')]
    public function test_a_foreach_over_one_item_is_a_constant_and_over_none_is_opaque(string $setup, string $iterated, array $patterns, string $state): void
    {
        $code = self::looping($setup, 'foreach ('.$iterated." as \$re) {\n            \$found += preg_match(\$re, \$s);\n        }");

        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);
        $this->assertSame($patterns, $this->patternsReadByBoth($code));
    }

    /**
     * Read by two calls of the loop, each item is reported once.
     */
    #[Test]
    public function test_the_items_read_by_two_calls_are_reported_once(): void
    {
        $code = self::looping('', "foreach (['/twice-a/', '/twice-b/'] as \$re) {\n            \$found += preg_match(\$re, \$s) + preg_match_all(\$re, \$s);\n        }");

        $this->assertExtractedByBoth([
            ['pattern' => '/twice-a/', 'at' => "'/twice-a/'", 'location' => 'via $re'],
            ['pattern' => '/twice-b/', 'at' => "'/twice-b/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s) +', 'source' => 'preg_match()'],
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s);', 'source' => 'preg_match_all()'],
        ], $code);
    }

    /**
     * A target joined to literals multiplies as a variable of several
     * values does: the patterns are composed at the call, and reported
     * where the argument starts. The key and the value of one loop, or
     * the same target twice, would give strings the code never builds.
     *
     * PHP 8.4: foreach (['cat', 'dog'] as $word) builds "/^cats?$/" then
     * "/^dogs?$/", and "/^cat$/" then "/^dog$/"; foreach (['d' =>
     * 'digit', 'w' => 'word'] as $letter => $name) builds "/d-digit/" and
     * "/w-word/", never "/d-word/".
     */
    #[Test]
    public function test_a_foreach_target_composed_in_the_argument_gives_one_pattern_per_value(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s): int
            {
                $found = 0;
                foreach (['cat', 'dog'] as $word) {
                    $found += preg_match('/^' . $word . 's?$/', $s)
                        + preg_match("/^{$word}$/", $s)
                        + preg_match('/' . $word . '|' . $word . '/', $s);
                }
                foreach (['d' => 'digit', 'w' => 'word'] as $letter => $name) {
                    $found += preg_match('/' . $letter . '-' . $name . '/', $s);
                }

                return $found;
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^cats?$/', 'at' => "'/^' . \$word . 's?$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^dogs?$/', 'at' => "'/^' . \$word . 's?$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^cat$/', 'at' => '"/^{$word}$/"', 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^dog$/', 'at' => '"/^{$word}$/"', 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/^' . \$word . 's?$/'"],
            ['state' => 'finite_set', 'expression' => '"/^{$word}$/"'],
            ['state' => 'opaque', 'expression' => "'/' . \$word . '|' . \$word . '/'"],
            ['state' => 'opaque', 'expression' => "'/' . \$letter . '-' . \$name . '/'"],
        ], $code);
    }

    /**
     * A composition gives 32 patterns at most: a target of 33 values
     * joined to a literal is not built. Read alone, the 33 patterns are
     * each a literal written in the file: a set, whatever their number.
     *
     * @return iterable<string, array{items: int, composed: string, alone: string}>
     */
    public static function provideLoopsAtTheCap(): iterable
    {
        yield '32 items' => ['items' => 32, 'composed' => 'finite_set', 'alone' => 'finite_set'];
        yield '33 items' => ['items' => 33, 'composed' => 'opaque', 'alone' => 'finite_set'];
    }

    #[Test]
    #[DataProvider('provideLoopsAtTheCap')]
    public function test_a_foreach_target_composed_past_the_cap_is_opaque(int $items, string $composed, string $alone): void
    {
        $words = [];
        $wholes = [];
        for ($i = 1; $i <= $items; $i++) {
            $words[] = \sprintf('w%02d', $i);
            $wholes[] = \sprintf('/whole-%02d/', $i);
        }

        $code = "<?php\n\nfunction run(string \$s): int\n{\n    \$found = 0;\n"
            ."    foreach (['".implode("', '", $words)."'] as \$word) {\n        \$found += preg_match('/^' . \$word . '$/', \$s);\n    }\n"
            ."    foreach (['".implode("', '", $wholes)."'] as \$re) {\n        \$found += preg_match(\$re, \$s);\n    }\n\n    return \$found;\n}\n";

        $this->assertArgumentsReadByBoth([
            ['state' => $composed, 'expression' => "'/^' . \$word . '$/'"],
            ['state' => $alone, 'expression' => '$re', 'at' => '$re, $s)'],
        ], $code);

        $expected = 'finite_set' === $composed ? array_map(static fn (string $word): string => '/^'.$word.'$/', $words) : [];
        $read = $this->patternsReadByBoth($code);
        sort($read, \SORT_STRING);
        $wanted = [...$expected, ...$wholes];
        sort($wanted, \SORT_STRING);

        $this->assertSame($wanted, $read);
    }

    /**
     * A loop the rule does not read: its target is written by reference
     * or elsewhere in the loop, its array is not proved whole, or the
     * target is not the value itself. Nothing is read through it.
     *
     * PHP 8.4: foreach (['/a/', '/b/'] as $re) { $re = '/rewritten/'; }
     * reads "/rewritten/" twice; foreach ([['/d-a/', 1], ['/d-b/', 2]] as
     * [$re, $n]) gives $re "/d-a/" then "/d-b/", the first item of each
     * value and not the value.
     *
     * @return iterable<string, array{setup: string, loop: string, state: string}>
     */
    public static function provideLoopsTheRuleDoesNotRead(): iterable
    {
        $body = " {\n            \$found += preg_match(\$re, \$s);\n        }";

        yield 'a foreach by reference over a local array' => [
            'setup' => "\$local = ['/ref-a/', '/ref-b/'];",
            'loop' => 'foreach ($local as &$re)'.$body,
            'state' => 'opaque',
        ];

        yield 'a foreach by reference over an array literal' => [
            'setup' => '',
            'loop' => "foreach (['/ref-a/', '/ref-b/'] as &\$re)".$body,
            'state' => 'opaque',
        ];

        yield 'a foreach by reference, beside a key target' => [
            'setup' => "\$local = ['/ref-a/', '/ref-b/'];",
            'loop' => 'foreach ($local as $index => &$re)'.$body,
            'state' => 'opaque',
        ];

        yield 'a target assigned before the read' => [
            'setup' => '',
            'loop' => "foreach (['/a/', '/b/'] as \$re) {\n            \$re = \$given;\n            \$found += preg_match(\$re, \$s);\n        }",
            'state' => 'opaque',
        ];

        yield 'a target assigned after the read' => [
            'setup' => '',
            'loop' => "foreach (['/a/', '/b/'] as \$re) {\n            \$found += preg_match(\$re, \$s);\n            \$re = \$given;\n        }",
            'state' => 'opaque',
        ];

        yield 'a target assigned in a branch of the loop' => [
            'setup' => '',
            'loop' => "foreach (['/a/', '/b/'] as \$re) {\n            if ('' !== \$given) {\n                \$re = '/other/';\n            }\n            \$found += preg_match(\$re, \$s);\n        }",
            'state' => 'opaque',
        ];

        yield 'a target written with a concatenating assignment' => [
            'setup' => '',
            'loop' => "foreach (['/a/', '/b/'] as \$re) {\n            \$re .= 'i';\n            \$found += preg_match(\$re, \$s);\n        }",
            'state' => 'opaque',
        ];

        yield 'a target passed to a call' => [
            'setup' => '',
            'loop' => "foreach (['/a/', '/b/'] as \$re) {\n            normalize_pattern(\$re);\n            \$found += preg_match(\$re, \$s);\n        }",
            'state' => 'opaque',
        ];

        yield 'an array the body does not know' => [
            'setup' => '',
            'loop' => 'foreach ($unknown as $re)'.$body,
            'state' => 'opaque',
        ];

        yield 'the keys of an array the body does not know' => [
            'setup' => '',
            'loop' => 'foreach ($unknown as $re => $weight)'.$body,
            'state' => 'opaque',
        ];

        yield 'an array returned by a call' => [
            'setup' => '',
            'loop' => 'foreach (array_keys(self::MAP) as $re)'.$body,
            'state' => 'opaque',
        ];

        yield 'a property holding an array' => [
            'setup' => '',
            'loop' => 'foreach ($this->patterns as $re)'.$body,
            'state' => 'opaque',
        ];

        yield 'an array one of whose items is a parameter' => [
            'setup' => '',
            'loop' => "foreach (['/a/', \$given] as \$re)".$body,
            'state' => 'opaque',
        ];

        // The argument is as known as the least known of its values.
        yield 'an array one of whose items is a pattern around a parameter' => [
            'setup' => '',
            'loop' => "foreach (['/a/', '/^' . \$given . '$/'] as \$re)".$body,
            'state' => 'raw_shell',
        ];

        yield 'an array holding a spread' => [
            'setup' => '',
            'loop' => "foreach (['/a/', ...\$unknown] as \$re)".$body,
            'state' => 'opaque',
        ];

        yield 'an array one of whose keys is a parameter' => [
            'setup' => '',
            'loop' => "foreach (['/a/' => 1, \$given => 2] as \$re => \$weight)".$body,
            'state' => 'opaque',
        ];

        yield 'a target that destructures the value' => [
            'setup' => '',
            'loop' => "foreach ([['/d-a/', 1], ['/d-b/', 2]] as [\$re, \$weight])".$body,
            'state' => 'opaque',
        ];

        yield 'a local array written before the loop' => [
            'setup' => "\$local = ['/a/', '/b/'];\n        \$local[] = \$given;",
            'loop' => 'foreach ($local as $re)'.$body,
            'state' => 'opaque',
        ];
    }

    #[Test]
    #[DataProvider('provideLoopsTheRuleDoesNotRead')]
    public function test_a_foreach_the_rule_does_not_read_gives_no_pattern(string $setup, string $loop, string $state): void
    {
        $code = self::looping($setup, $loop);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An item named by a literal key is that item, as PHP gives it: the
     * pattern is reported where its literal is written.
     *
     * PHP 8.4 normalises the keys: ['5' => '/five/'][5] is "/five/",
     * ['/zero/', '/one/']['1'] is "/one/", [3 => '/three/', '/four/'][4]
     * is "/four/", ['/zero/', 'k' => '/k/', '/one/'][1] is "/one/"; a key
     * written twice keeps its last value, ['k' => '/first/', 'k' =>
     * '/second/']['k'] is "/second/"; ['clé' => '/^é+$/u']['clé'] is
     * "/^é+$/u".
     *
     * @return iterable<string, array{setup: string, argument: string, pattern: string}>
     */
    public static function provideItemsNamedByALiteralKey(): iterable
    {
        $map = "\$map = ['word' => '/local-word/', 'digit' => '/local-digit/'];";
        $list = "\$list = ['/zero/', '/one/'];";

        yield 'a string key of a local array' => ['setup' => $map, 'argument' => "\$map['word']", 'pattern' => '/local-word/'];
        yield 'a string key in double quotes' => ['setup' => $map, 'argument' => '$map["digit"]', 'pattern' => '/local-digit/'];
        yield 'the first index of a local list' => ['setup' => $list, 'argument' => '$list[0]', 'pattern' => '/zero/'];
        yield 'another index of a local list' => ['setup' => $list, 'argument' => '$list[1]', 'pattern' => '/one/'];
        yield 'a string key of a class constant' => ['setup' => '', 'argument' => "self::WORDS['word']", 'pattern' => '/const-word/'];
        yield 'an index of a class constant' => ['setup' => '', 'argument' => 'self::LIST[1]', 'pattern' => '/const-b/'];
        yield 'an index of a global constant' => ['setup' => '', 'argument' => 'GLOBAL_LIST[0]', 'pattern' => '/global-a/'];
        yield 'a multi-byte key' => ['setup' => "\$accents = ['clé' => '/^é+$/u', 'autre' => '/^è+$/u'];", 'argument' => "\$accents['clé']", 'pattern' => '/^é+$/u'];
        yield 'an integer naming a key written as a string of digits' => ['setup' => "\$digits = ['5' => '/five/', '6' => '/six/'];", 'argument' => '$digits[5]', 'pattern' => '/five/'];
        yield 'a string of digits naming an index' => ['setup' => $list, 'argument' => "\$list['1']", 'pattern' => '/one/'];
        yield 'an index that follows a written one' => ['setup' => "\$sparse = [3 => '/three/', '/four/'];", 'argument' => '$sparse[4]', 'pattern' => '/four/'];
        yield 'an index counted past a string key' => ['setup' => "\$mixed = ['/zero/', 'k' => '/keyed/', '/one/'];", 'argument' => '$mixed[1]', 'pattern' => '/one/'];
        yield 'a key written twice' => ['setup' => "\$twice = ['k' => '/first/', 'k' => '/second/'];", 'argument' => "\$twice['k']", 'pattern' => '/second/'];
        yield 'an item beside one the body does not prove' => ['setup' => "\$partly = ['proved' => '/proved/', 'other' => \$given];", 'argument' => "\$partly['proved']", 'pattern' => '/proved/'];
    }

    #[Test]
    #[DataProvider('provideItemsNamedByALiteralKey')]
    public function test_an_item_named_by_a_literal_key_is_that_item(string $setup, string $argument, string $pattern): void
    {
        $code = self::matcher($setup, $argument);

        $this->assertExtractedByBoth([
            ['pattern' => $pattern, 'at' => "'".$pattern."'", 'source' => 'preg_match()'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Named by a key the file does not spell, the item is one of the
     * values of the array: each is read where it is written. An array of
     * one value gives one pattern; a value the body does not prove leaves
     * nothing known.
     *
     * @return iterable<string, array{setup: string, argument: string, patterns: list<string>, state: string}>
     */
    public static function provideItemsNamedByAnyOtherKey(): iterable
    {
        $map = "\$map = ['word' => '/local-word/', 'digit' => '/local-digit/'];";

        yield 'a parameter as the key of a local array' => ['setup' => $map, 'argument' => '$map[$k]', 'patterns' => ['/local-word/', '/local-digit/'], 'state' => 'finite_set'];
        yield 'a call as the key of a local array' => ['setup' => $map, 'argument' => '$map[strtolower($k)]', 'patterns' => ['/local-word/', '/local-digit/'], 'state' => 'finite_set'];
        yield 'a parameter as the key of a class constant' => ['setup' => '', 'argument' => 'self::WORDS[$k]', 'patterns' => ['/const-word/', '/const-digit/'], 'state' => 'finite_set'];
        yield 'a parameter as the index of a class constant' => ['setup' => '', 'argument' => 'self::LIST[$i]', 'patterns' => ['/const-a/', '/const-b/'], 'state' => 'finite_set'];
        yield 'a parameter as the key of an array of one value' => ['setup' => "\$single = ['only' => '/only/'];", 'argument' => '$single[$k]', 'patterns' => ['/only/'], 'state' => 'constant'];
        yield 'a parameter as the key of an array holding a parameter' => ['setup' => "\$partly = ['proved' => '/proved/', 'other' => \$given];", 'argument' => '$partly[$k]', 'patterns' => [], 'state' => 'opaque'];
    }

    /**
     * @param list<string> $patterns
     */
    #[Test]
    #[DataProvider('provideItemsNamedByAnyOtherKey')]
    public function test_an_item_named_by_any_other_key_is_one_of_the_values(string $setup, string $argument, array $patterns, string $state): void
    {
        $code = self::matcher($setup, $argument);

        $expected = [];
        foreach ($patterns as $pattern) {
            $expected[] = ['pattern' => $pattern, 'at' => "'".$pattern."'", 'source' => 'preg_match()'];
        }

        $this->assertExtractedByBoth($expected, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A literal key the array does not hold names nothing: PHP 8.4 gives
     * null and warns 'Undefined array key "missing"' ("Undefined array
     * key 7", "Undefined array key -1"). The keys are compared as PHP
     * compares them, with their case. An item of an item, and an item
     * that is itself an array, are not read ($nested['k'][0] is "/n-a/"
     * in PHP).
     *
     * @return iterable<string, array{setup: string, argument: string}>
     */
    public static function provideKeysThatNameNothing(): iterable
    {
        $map = "\$map = ['word' => '/local-word/', 'digit' => '/local-digit/'];";
        $list = "\$list = ['/zero/', '/one/'];";
        $nested = "\$nested = ['k' => ['/n-a/', '/n-b/'], '/flat/'];";

        yield 'a string key a local array does not hold' => ['setup' => $map, 'argument' => "\$map['missing']"];
        yield 'a key in another case' => ['setup' => $map, 'argument' => "\$map['WORD']"];
        yield 'an index past the end of a local list' => ['setup' => $list, 'argument' => '$list[7]'];
        yield 'a negative index' => ['setup' => $list, 'argument' => '$list[-1]'];
        yield 'a key of an empty array' => ['setup' => '$none = [];', 'argument' => "\$none['word']"];
        yield 'a string key a class constant does not hold' => ['setup' => '', 'argument' => "self::WORDS['missing']"];
        yield 'an index past the end of a class constant' => ['setup' => '', 'argument' => 'self::LIST[2]'];
        yield 'an item of an item' => ['setup' => $nested, 'argument' => "\$nested['k'][0]"];
        yield 'an item that is an array' => ['setup' => $nested, 'argument' => "\$nested['k']"];
        yield 'an item of a parameter' => ['setup' => '', 'argument' => "\$unknown['word']"];
        yield 'an item of a property' => ['setup' => '', 'argument' => "\$this->patterns['word']"];
        yield 'an item the body does not prove' => ['setup' => "\$partly = ['proved' => '/proved/', 'other' => \$given];", 'argument' => "\$partly['other']"];
    }

    #[Test]
    #[DataProvider('provideKeysThatNameNothing')]
    public function test_a_key_that_names_no_proved_item_leaves_the_argument_opaque(string $setup, string $argument): void
    {
        $code = self::matcher($setup, $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An item is an operand as a variable is: named by a literal key it
     * brings its value, named by any other key one of the values of the
     * array, once; an item the array does not hold is a part built at run
     * time.
     *
     * PHP 8.4, $parts = ['word' => 'abc', 'digit' => '123']: '/^' .
     * $parts['word'] . '$/' is "/^abc$/"; '/^(' . $parts[$k] . ')$/' is
     * "/^(abc)$/" or "/^(123)$/".
     */
    #[Test]
    public function test_an_item_joined_to_literals_is_composed_at_the_argument(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $k): int
            {
                $parts = ['word' => 'abc', 'digit' => '123'];

                return preg_match('/^' . $parts['word'] . '$/', $s)
                    + preg_match('/^(' . $parts[$k] . ')$/', $s)
                    + preg_match('/^' . $parts[$k] . '|' . $parts[$k] . '$/', $s)
                    + preg_match('/^' . $parts['missing'] . '$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/^abc$/', 'at' => "'/^' . \$parts['word'] . '$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^(123)$/', 'at' => "'/^(' . \$parts[\$k] . ')$/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/^(abc)$/', 'at' => "'/^(' . \$parts[\$k] . ')$/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "'/^' . \$parts['word'] . '$/'"],
            ['state' => 'finite_set', 'expression' => "'/^(' . \$parts[\$k] . ')$/'"],
            ['state' => 'opaque', 'expression' => "'/^' . \$parts[\$k] . '|' . \$parts[\$k] . '$/'"],
            ['state' => 'raw_shell', 'expression' => "'/^' . \$parts['missing'] . '$/'"],
        ], $code);
    }

    /**
     * The class of the snippets, whose method run() runs the statements,
     * then the loop that reads "$re" in preg_match().
     */
    private static function looping(string $setup, string $loop): string
    {
        return self::HEAD
            ."    public function run(string \$s, string \$given, array \$unknown): int\n    {\n        ".$setup."\n        \$found = 0;\n        ".$loop."\n\n        return \$found;\n    }\n}\n";
    }

    /**
     * The same class, whose method run() runs the statements, then reads
     * the argument in preg_match().
     */
    private static function matcher(string $setup, string $argument): string
    {
        return self::HEAD
            ."    public function run(string \$s, string \$given, string \$k, int \$i, array \$unknown): int\n    {\n        ".$setup."\n\n        return preg_match(".$argument.", \$s);\n    }\n}\n";
    }
}
