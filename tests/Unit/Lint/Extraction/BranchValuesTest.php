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
 * A ternary and a match are read as the set of the values of their arms,
 * the default arm of a match among them. The condition is not read: which
 * arm runs is decided at run time. A short ternary and a null coalescing
 * give what PHP gives: A ?: B keeps B only when a value of A is '' or
 * '0', A ?? B never keeps B when A is a string.
 *
 * Each arm that is one literal is reported where the literal is written,
 * once; an arm composed in the argument, where the argument starts. An
 * arm the file does not prove leaves nothing known of the argument, and
 * none of the other arms is read; a concatenating assignment is still not
 * followed.
 *
 * PHP 8.4: true ? '/a/' : '/b/' is "/a/"; '/a/' ?: '/b/' is "/a/", '' ?:
 * '/b/' and '0' ?: '/b/' are "/b/"; '/a/' ?? '/b/' is "/a/" and '' ??
 * '/b/' is ""; match (2) { 1 => '/one/', default => '/other/' } is
 * "/other/".
 *
 * Every case runs through both strategies.
 */
final class BranchValuesTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * The arms are literals written in the argument itself: each is
     * reported where it stands, with no name to go through.
     */
    #[Test]
    public function test_a_ternary_is_the_set_of_its_two_arms(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                return preg_match($flag ? '/then/' : '/else/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/then/', 'at' => "'/then/'", 'source' => 'preg_match()', 'location' => null],
            ['pattern' => '/else/', 'at' => "'/else/'", 'source' => 'preg_match()', 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "\$flag ? '/then/' : '/else/'", 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Each row gives the patterns with the text each is written at and
     * the name it is read through: a literal arm at the literal, with no
     * name; an arm naming a constant at the literal of the constant,
     * through the constant; an arm composed in the argument where the
     * argument starts.
     *
     * @return iterable<string, array{argument: string, patterns: list<array{pattern: string, at: string, location: string|null}>}>
     */
    public static function provideBranches(): iterable
    {
        yield 'a ternary in parentheses' => [
            'argument' => "(\$flag ? '/then/' : '/else/')",
            'patterns' => [self::at('/then/'), self::at('/else/')],
        ];

        yield 'a match with a default arm' => [
            'argument' => "match (\$kind) {\n            1 => '/one/',\n            2 => '/two/',\n            default => '/other/',\n        }",
            'patterns' => [self::at('/one/'), self::at('/two/'), self::at('/other/')],
        ];

        // Without a default arm PHP throws on any other subject: the
        // value, when there is one, is still one of the arms.
        yield 'a match without a default arm' => [
            'argument' => "match (\$kind) {\n            1 => '/one/',\n            2 => '/two/',\n        }",
            'patterns' => [self::at('/one/'), self::at('/two/')],
        ];

        // match (2) { 1, 2 => '/low/', default => '/other/' } is "/low/".
        yield 'a match arm under several conditions' => [
            'argument' => "match (\$kind) {\n            1, 2 => '/low/',\n            default => '/other/',\n        }",
            'patterns' => [self::at('/low/'), self::at('/other/')],
        ];

        yield 'a match on one line with a trailing comma' => [
            'argument' => "match (true) { \$flag => '/yes/', default => '/no/', }",
            'patterns' => [self::at('/yes/'), self::at('/no/')],
        ];

        // true ? '/a/' : (false ? '/b/' : '/c/') is "/a/"; PHP 8 refuses
        // a nested ternary without its parentheses.
        yield 'a ternary in an arm of a ternary' => [
            'argument' => "\$flag ? '/first/' : (\$kind > 1 ? '/second/' : '/third/')",
            'patterns' => [self::at('/first/'), self::at('/second/'), self::at('/third/')],
        ];

        yield 'a match in an arm of a ternary' => [
            'argument' => "\$flag ? '/first/' : match (\$kind) { 1 => '/second/', default => '/third/' }",
            'patterns' => [self::at('/first/'), self::at('/second/'), self::at('/third/')],
        ];

        // A is '/const-a/' and B '/const-b/'.
        yield 'arms naming constants' => [
            'argument' => '$flag ? self::A : self::B',
            'patterns' => [self::at('/const-a/', 'via self::A'), self::at('/const-b/', 'via self::B')],
        ];

        yield 'an arm naming a constant and a literal arm' => [
            'argument' => "\$flag ? self::A : '/else/'",
            'patterns' => [self::at('/const-a/', 'via self::A'), self::at('/else/')],
        ];

        yield 'match arms naming constants' => [
            'argument' => 'match ($kind) { 1 => self::A, default => self::B }',
            'patterns' => [self::at('/const-a/', 'via self::A'), self::at('/const-b/', 'via self::B')],
        ];

        // '/con' . 'cat/' is "/concat/": an arm composed in the argument
        // is reported where the argument starts.
        yield 'an arm that is a concatenation' => [
            'argument' => "\$flag ? '/con' . 'cat/' : '/else/'",
            'patterns' => [['pattern' => '/concat/', 'at' => "\$flag ? '/con' . 'cat/' : '/else/'", 'location' => null], self::at('/else/')],
        ];

        // The condition is no value: a literal there is no pattern.
        yield 'a ternary whose condition holds a literal' => [
            'argument' => "'/condition/' === \$s ? '/then/' : '/else/'",
            'patterns' => [self::at('/then/'), self::at('/else/')],
        ];

        // match ('/cond/') { '/cond/' => '/value/', default => '/other/' }
        // is "/value/": the subject and the conditions are no values.
        yield 'a match whose subject and conditions hold literals' => [
            'argument' => "match (\$s) { '/subject-a/', '/subject-b/' => '/value/', default => '/other/' }",
            'patterns' => [self::at('/value/'), self::at('/other/')],
        ];
    }

    /**
     * @param list<array{pattern: string, at: string, location: string|null}> $patterns
     */
    #[Test]
    #[DataProvider('provideBranches')]
    public function test_a_branching_expression_is_the_set_of_the_values_of_its_arms(string $argument, array $patterns): void
    {
        $code = self::matcher('', $argument);

        $this->assertExtractedByBoth($patterns, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => self::unwrapped($argument), 'at' => self::unwrapped($argument).(str_starts_with($argument, '(') ? '), $s)' : ', $s)'), 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A short ternary and a null coalescing give what PHP gives, not
     * both arms. A is '/const-a/', B '/const-b/', EMPTY '' and ZERO '0'.
     *
     * PHP 8.4: '/a/' ?: '/else/' is "/a/"; '' ?: '/else/' and '0' ?:
     * '/else/' are "/else/"; '/a/' ?? '/else/' is "/a/", '' ?? '/else/'
     * is "": a string is never null. $maybe = '/set/' or '': $maybe ?:
     * '/else/' is "/set/" or "/else/".
     *
     * @return iterable<string, array{setup: string, argument: string, patterns: list<array{pattern: string, at: string, location: string|null}>, state: string}>
     */
    public static function provideArmsPhpKeeps(): iterable
    {
        $setOrEmpty = "\$maybe = '/set/';\n        if (\$flag) {\n            \$maybe = '';\n        }";
        $setOrOther = "\$maybe = '/set/';\n        if (\$flag) {\n            \$maybe = '/other/';\n        }";
        $setOnOnePath = "if (\$flag) {\n            \$maybe = '/set/';\n        }";

        yield 'a short ternary on a constant that is not empty' => [
            'setup' => '',
            'argument' => "self::A ?: '/else/'",
            'patterns' => [self::at('/const-a/', 'via self::A')],
            'state' => 'constant',
        ];

        yield 'a short ternary on an empty constant' => [
            'setup' => '',
            'argument' => "self::EMPTY ?: '/else/'",
            'patterns' => [self::at('/else/')],
            'state' => 'constant',
        ];

        // '0' is the one string PHP takes for false beside the empty one.
        yield 'a short ternary on a constant holding "0"' => [
            'setup' => '',
            'argument' => "self::ZERO ?: '/else/'",
            'patterns' => [self::at('/else/')],
            'state' => 'constant',
        ];

        yield 'a short ternary of two constants' => [
            'setup' => '',
            'argument' => 'self::A ?: self::B',
            'patterns' => [self::at('/const-a/', 'via self::A')],
            'state' => 'constant',
        ];

        yield 'a short ternary on a variable that may be empty' => [
            'setup' => $setOrEmpty,
            'argument' => "\$maybe ?: '/else/'",
            'patterns' => [self::at('/set/', 'via $maybe'), self::at('/else/')],
            'state' => 'finite_set',
        ];

        // A variable is not proved to be assigned on every path: PHP takes
        // the other arm for one that is not, so that arm is kept beside its
        // values, here and under "??".
        yield 'a short ternary on a variable that is never empty' => [
            'setup' => $setOrOther,
            'argument' => "\$maybe ?: '/else/'",
            'patterns' => [self::at('/set/', 'via $maybe'), self::at('/other/', 'via $maybe'), self::at('/else/')],
            'state' => 'finite_set',
        ];

        // if ($flag) { $maybe = '/set/'; } $maybe ?: '/else/' is '/else/' when $flag is false (PHP 8.4.27).
        yield 'a short ternary on a variable one path leaves unassigned' => [
            'setup' => $setOnOnePath,
            'argument' => "\$maybe ?: '/else/'",
            'patterns' => [self::at('/set/', 'via $maybe'), self::at('/else/')],
            'state' => 'finite_set',
        ];

        yield 'a short ternary in an arm of a short ternary' => [
            'setup' => '',
            'argument' => "self::EMPTY ?: (self::ZERO ?: '/last/')",
            'patterns' => [self::at('/last/')],
            'state' => 'constant',
        ];

        yield 'a null coalescing on a constant' => [
            'setup' => '',
            'argument' => "self::A ?? '/else/'",
            'patterns' => [self::at('/const-a/', 'via self::A')],
            'state' => 'constant',
        ];

        yield 'a null coalescing of three' => [
            'setup' => '',
            'argument' => "self::A ?? self::B ?? '/else/'",
            'patterns' => [self::at('/const-a/', 'via self::A')],
            'state' => 'constant',
        ];

        // '' ?? '/else/' is "": an empty pattern, which is read as the
        // empty literal is, one value and no pattern.
        yield 'a null coalescing on an empty constant' => [
            'setup' => '',
            'argument' => "self::EMPTY ?? '/else/'",
            'patterns' => [],
            'state' => 'constant',
        ];

        yield 'a null coalescing on a variable of two patterns' => [
            'setup' => $setOrOther,
            'argument' => "\$maybe ?? '/else/'",
            'patterns' => [self::at('/set/', 'via $maybe'), self::at('/other/', 'via $maybe'), self::at('/else/')],
            'state' => 'finite_set',
        ];

        // if ($flag) { $maybe = '/set/'; } $maybe ?? '/else/' is '/else/' when $flag is false (PHP 8.4.27).
        yield 'a null coalescing on a variable one path leaves unassigned' => [
            'setup' => $setOnOnePath,
            'argument' => "\$maybe ?? '/else/'",
            'patterns' => [self::at('/set/', 'via $maybe'), self::at('/else/')],
            'state' => 'finite_set',
        ];
    }

    /**
     * @param list<array{pattern: string, at: string, location: string|null}> $patterns
     */
    #[Test]
    #[DataProvider('provideArmsPhpKeeps')]
    public function test_a_short_ternary_and_a_null_coalescing_keep_the_arms_php_keeps(string $setup, string $argument, array $patterns, string $state): void
    {
        $code = self::matcher($setup, $argument);

        $this->assertExtractedByBoth($patterns, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Both arms give the same pattern: one pattern, the literal arm
     * reported where it is written and the composed one where the
     * argument starts.
     */
    #[Test]
    public function test_arms_of_one_pattern_are_a_constant(): void
    {
        $code = self::matcher('', "\$flag ? '/same/' : '/sa' . 'me/'");

        $this->assertExtractedByBoth([
            ['pattern' => '/same/', 'at' => "'/same/'", 'location' => null],
            ['pattern' => '/same/', 'at' => "\$flag ? '/same/' : '/sa' . 'me/'", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => "\$flag ? '/same/' : '/sa' . 'me/'"],
        ], $code);
    }

    /**
     * An arm the file does not prove: nothing is known of the argument,
     * and the literal of the other arm is not read.
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideBranchesWithAnArmThatIsNotStatic(): iterable
    {
        yield 'a ternary whose else arm is a parameter' => ['argument' => "\$flag ? '/then/' : \$given"];
        yield 'a ternary whose then arm is a parameter' => ['argument' => "\$flag ? \$given : '/else/'"];
        yield 'a ternary whose arm is a call' => ['argument' => "\$flag ? '/then/' : load_pattern()"];
        yield 'a ternary whose arm is a method call' => ['argument' => "\$flag ? '/then/' : \$this->pattern()"];
        // $given ?: '/else/' is $given whenever it is not empty.
        yield 'a short ternary on a parameter' => ['argument' => "\$given ?: '/else/'"];
        yield 'a short ternary on a call' => ['argument' => "load_pattern() ?: '/else/'"];
        yield 'a null coalescing on a parameter' => ['argument' => "\$given ?? '/else/'"];
        yield 'a null coalescing on an item of a parameter' => ['argument' => "\$options['pattern'] ?? '/else/'"];
        yield 'a null coalescing on a property of another object' => ['argument' => "\$this->other->pattern ?? '/else/'"];
        yield 'a match whose default arm is a parameter' => ['argument' => "match (\$kind) { 1 => '/one/', default => \$given }"];
        yield 'a match one of whose arms is a call' => ['argument' => "match (\$kind) { 1 => '/one/', 2 => load_pattern(), default => '/other/' }"];
        yield 'a ternary in an arm, one of whose arms is a parameter' => ['argument' => "\$flag ? '/first/' : (\$kind > 1 ? '/second/' : \$given)"];
        // The delimiter is not known: the parameter comes first.
        yield 'an arm whose first part is a parameter' => ['argument' => "\$flag ? '/then/' : \$given . '/i'"];
    }

    #[Test]
    #[DataProvider('provideBranchesWithAnArmThatIsNotStatic')]
    public function test_an_arm_that_is_not_static_leaves_the_argument_opaque(string $argument): void
    {
        $code = self::matcher('', $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An arm that is a pattern around a part built at run time: the
     * argument is as known as the least known of its arms, and no arm is
     * read, as with an assignment in each branch.
     *
     * @return iterable<string, array{argument: string, state: string}>
     */
    public static function provideBranchesWithAnArmThatIsAShell(): iterable
    {
        yield 'an arm around a parameter' => ['argument' => "\$flag ? '/then/' : '/^' . \$given . '$/'", 'state' => 'raw_shell'];
        yield 'an arm around preg_quote()' => ['argument' => "\$flag ? '/then/' : '/^' . preg_quote(\$given, '/') . '$/'", 'state' => 'quoted_shell'];
        yield 'an arm around preg_quote() and an arm around a parameter' => ['argument' => "\$flag ? '/^' . preg_quote(\$given, '/') . '$/' : '/^' . \$given . '$/'", 'state' => 'raw_shell'];
        yield 'a match arm around a parameter' => ['argument' => "match (\$kind) { 1 => '/one/', default => '/^' . \$given . '$/' }", 'state' => 'raw_shell'];
    }

    #[Test]
    #[DataProvider('provideBranchesWithAnArmThatIsAShell')]
    public function test_an_arm_that_is_a_shell_gives_the_argument_its_state(string $argument, string $state): void
    {
        $code = self::matcher('', $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $s)'],
        ], $code);
    }

    /**
     * A variable assigned a ternary or a match holds the set of its arms:
     * each literal is reported where it is written, through the name the
     * call reads. Another assignment that may reach the read adds its
     * value to the set.
     */
    #[Test]
    public function test_a_variable_assigned_a_branching_expression_holds_its_arms(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag, int $kind): int
            {
                $ternary = $flag ? '/then/' : '/else/';
                $match = match ($kind) {
                    1 => '/one/',
                    default => '/other/',
                };
                $more = $flag ? '/more-a/' : '/more-b/';
                if (3 === $kind) {
                    $more = '/more-c/';
                }

                return preg_match($ternary, $s) + preg_match($match, $s) + preg_match($more, $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/then/', 'at' => "'/then/'", 'location' => 'via $ternary'],
            ['pattern' => '/else/', 'at' => "'/else/'", 'location' => 'via $ternary'],
            ['pattern' => '/one/', 'at' => "'/one/'", 'location' => 'via $match'],
            ['pattern' => '/other/', 'at' => "'/other/'", 'location' => 'via $match'],
            ['pattern' => '/more-a/', 'at' => "'/more-a/'", 'location' => 'via $more'],
            ['pattern' => '/more-b/', 'at' => "'/more-b/'", 'location' => 'via $more'],
            ['pattern' => '/more-c/', 'at' => "'/more-c/'", 'location' => 'via $more'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$ternary', 'at' => '$ternary, $s)'],
            ['state' => 'finite_set', 'expression' => '$match', 'at' => '$match, $s)'],
            ['state' => 'finite_set', 'expression' => '$more', 'at' => '$more, $s)'],
        ], $code);
    }

    /**
     * Read by two calls through one variable, each arm is reported once.
     */
    #[Test]
    public function test_the_arms_read_by_two_calls_are_reported_once(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, string $t, bool $flag): int
            {
                $re = $flag ? '/then/' : '/else/';

                return preg_match($re, $s) + preg_match($re, $t);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/then/', 'at' => "'/then/'", 'location' => 'via $re'],
            ['pattern' => '/else/', 'at' => "'/else/'", 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $s)'],
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $t)'],
        ], $code);
    }

    /**
     * A branching expression joined to a literal multiplies as a variable
     * of several values does: the patterns are composed at the call, and
     * reported where the argument starts.
     *
     * PHP 8.4: '/x/' . (true ? 'i' : 'u') is "/x/i", ('/a/' ?: '/b/') .
     * 'i' is "/a/i".
     */
    #[Test]
    public function test_a_branching_operand_multiplies_into_the_concatenation(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag, int $kind): int
            {
                return preg_match('/x/' . ($flag ? 'i' : 'u'), $s)
                    + preg_match('/^' . match ($kind) { 1 => 'one', default => 'other' } . '$/', $s);
            }
            PHP;

        $this->assertExtractedByBoth([
            ['pattern' => '/x/i', 'at' => "'/x/' . (\$flag ? 'i' : 'u')", 'location' => null],
            ['pattern' => '/x/u', 'at' => "'/x/' . (\$flag ? 'i' : 'u')", 'location' => null],
            ['pattern' => '/^one$/', 'at' => "'/^' . match", 'location' => null],
            ['pattern' => '/^other$/', 'at' => "'/^' . match", 'location' => null],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/x/' . (\$flag ? 'i' : 'u')"],
            ['state' => 'finite_set', 'expression' => "'/^' . match (\$kind) { 1 => 'one', default => 'other' } . '$/'"],
        ], $code);
    }

    /**
     * Two operands of several values are not multiplied, a branching one
     * as any other: the code builds only some of the combinations.
     *
     * PHP 8.4, one $flag deciding both: ($flag ? '/a' : '/b') . ($flag ?
     * '/' : '/i') is "/a/" or "/b/i", never "/a/i".
     *
     * @return iterable<string, array{argument: string}>
     */
    public static function provideCompositionsOfTwoBranchingOperands(): iterable
    {
        yield 'two ternaries' => ['argument' => "(\$flag ? '/a' : '/b') . (\$flag ? '/' : '/i')"];
        yield 'a ternary and a match' => ['argument' => "'/' . (\$flag ? 'a' : 'b') . match (\$kind) { 1 => '/', default => '/i' }"];
        yield 'a ternary and a variable of two values' => ['argument' => "'/' . \$two . (\$flag ? '/' : '/i')"];
    }

    #[Test]
    #[DataProvider('provideCompositionsOfTwoBranchingOperands')]
    public function test_two_branching_operands_leave_the_argument_opaque(string $argument): void
    {
        $code = self::matcher("\$two = 'a';\n        if (\$flag) {\n            \$two = 'b';\n        }", $argument);

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * "." binds tighter than "?:" in PHP 8: without parentheses the
     * concatenation is the condition, and the arms are the two flags
     * alone. PHP 8.4: '/x/' . $flag ? 'i' : 'u' is "i" for $flag true and
     * for $flag false, never "/x/i".
     */
    #[Test]
    public function test_a_ternary_after_a_concatenation_reads_its_arms_and_not_the_concatenation(): void
    {
        $code = <<<'PHP'
            <?php

            function run(string $s, bool $flag): int
            {
                return preg_match('/x/' . $flag ? 'i' : 'u', $s);
            }
            PHP;

        $this->assertSame(['i', 'u'], $this->patternsReadByBoth($code));
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => "'/x/' . \$flag ? 'i' : 'u'"],
        ], $code);
    }

    /**
     * A concatenating assignment is not followed, whatever the variable
     * was assigned first ($re = '/a'; $re .= '/'; leaves "/a/" in PHP):
     * nothing is known of the argument.
     *
     * @return iterable<string, array{setup: string}>
     */
    public static function provideConcatenatingAssignments(): iterable
    {
        yield 'after a literal' => ['setup' => "\$re = '/appended';\n        \$re .= '/';"];
        yield 'after a ternary' => ['setup' => "\$re = \$flag ? '/then' : '/else';\n        \$re .= '/';"];
        yield 'of a ternary' => ['setup' => "\$re = '/appended/';\n        \$re .= \$flag ? 'i' : 'u';"];
        yield 'in a branch' => ['setup' => "\$re = '/appended/';\n        if (\$flag) {\n            \$re .= 'i';\n        }"];
    }

    #[Test]
    #[DataProvider('provideConcatenatingAssignments')]
    public function test_a_concatenating_assignment_stays_unread(string $setup): void
    {
        $code = self::matcher($setup, '$re');

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $s)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A pattern written as one literal, found in the code by that
     * literal, and the name the call reads it through.
     *
     * @return array{pattern: string, at: string, location: string|null}
     */
    private static function at(string $pattern, ?string $location = null): array
    {
        return ['pattern' => $pattern, 'at' => "'".$pattern."'", 'location' => $location];
    }

    /**
     * The argument without the parentheses around the whole of it, where
     * its expression starts.
     */
    private static function unwrapped(string $argument): string
    {
        return str_starts_with($argument, '(') && str_ends_with($argument, ')') ? substr($argument, 1, -1) : $argument;
    }

    /**
     * A final class holding A, B, EMPTY and ZERO, whose method run() runs the
     * statements, then reads the argument in preg_match().
     */
    private static function matcher(string $setup, string $argument): string
    {
        return "<?php\n\nfinal class Matcher\n{\n    private const A = '/const-a/';\n    private const B = '/const-b/';\n    private const EMPTY = '';\n    private const ZERO = '0';\n\n"
            ."    public function run(string \$s, string \$given, bool \$flag, int \$kind, array \$options): int\n    {\n        ".$setup."\n\n"
            .'        return preg_match('.$argument.", \$s);\n    }\n}\n";
    }
}
