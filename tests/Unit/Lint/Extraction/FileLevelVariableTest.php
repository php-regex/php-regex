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
 * A variable at file level is a global: any code the file runs may write
 * it. It is read under one rule, the whole of which stands in this class:
 *
 * - every write of it in the file is a plain assignment;
 * - the file holds no include, require, eval, extract(), compact(), no
 *   variable named by an expression, no "global" and no $GLOBALS;
 * - no call and no "new" stand between the assignment that reaches the
 *   read and the read: any call, one of a function of PHP included, and
 *   any call written in a loop that holds the read, which a later turn
 *   of the loop runs before the read. An assignment written in the loop,
 *   as the one its head makes of a foreach target, is made again at each
 *   turn: there the calls between it and the read, within the turn, are
 *   the ones that count. A call in the value assigned runs before the
 *   assignment;
 * - nothing else stands between that assignment and the read than what
 *   a closed list holds, since PHP runs code where no call is written:
 *   comments, plain assignments of literals and of variables proved the
 *   same way, an if over such values or over a variable compared in a
 *   way that runs no code, and the beginning of the statement that
 *   holds the read; anything off the list leaves the variable unread,
 *   whether it runs code or not (FileLevelImplicitCodeTest holds the
 *   list).
 *
 * Then it is read as a local variable is. A variable of a function body
 * is never the one of the file.
 *
 * PHP 8.4 (php -r), $re = '/x/' at file level, then, before $re is read:
 * a function that declares "global $re" and assigns it, called, leaves
 * "/(/" in it; so do one writing $GLOBALS['re'], a constructor run by
 * "new", a static method, a method, an included file, eval(), extract()
 * and "$$name" with $name = 're'; so does array_map('that_function',
 * [1]), a function of PHP running code of the file. Inside a function
 * body, isset($re) is false: the variable there is another one.
 *
 * Every case runs through both strategies.
 */
final class FileLevelVariableTest extends TestCase
{
    use ExtractsWithBothStrategies;

    /**
     * Each row gives the file, the argument, the state it ends in and the
     * patterns read, each with the text it is written at and the name it
     * is read through.
     *
     * @return iterable<string, array{code: string, argument: string, state: string, patterns: list<array{pattern: string, at: string, location: string|null}>}>
     */
    public static function provideFilesTheRuleReads(): iterable
    {
        yield 'assigned, then read' => [
            'code' => "<?php\n\n\$re = '/file-level/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/file-level/', 'via $re')],
        ];

        yield 'after a declare and a namespace' => [
            'code' => "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Script;\n\n\$re = '/in-a-namespace/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/in-a-namespace/', 'via $re')],
        ];

        yield 'in the braces of a namespace' => [
            'code' => "<?php\n\nnamespace App\\Script {\n    \$re = '/braced/';\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/braced/', 'via $re')],
        ];

        // '/file-' . 'concat/' is "/file-concat/".
        yield 'assigned a concatenation of literals' => [
            'code' => "<?php\n\n\$re = '/file-' . 'concat/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [['pattern' => '/file-concat/', 'at' => "'/file-' . 'concat/'", 'location' => 'via $re']],
        ];

        yield 'assigned a constant of the file' => [
            'code' => "<?php\n\nconst WORD = '/file-const/';\n\n\$re = WORD;\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/file-const/', 'via $re')],
        ];

        // $part = 'abc'; '/^' . $part . '$/' is "/^abc$/": composed at the
        // call, as with a local variable.
        yield 'an operand of a concatenation' => [
            'code' => "<?php\n\n\$part = 'abc';\npreg_match('/^' . \$part . '$/', \$subject);\n",
            'argument' => "'/^' . \$part . '$/'",
            'state' => 'constant',
            'patterns' => [['pattern' => '/^abc$/', 'at' => "'/^' . \$part . '$/'", 'location' => null]],
        ];

        yield 'read in a branch' => [
            'code' => "<?php\n\n\$re = '/in-a-branch/';\nif (\$argc > 1) {\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/in-a-branch/', 'via $re')],
        ];

        yield 'read in a branch whose condition the file proves' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/in-a-proved-branch/';\nif ('cli' === \$mode) {\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/in-a-proved-branch/', 'via $re')],
        ];

        // The call runs before the assignment: whatever it writes, the
        // assignment writes after it.
        yield 'a call before the assignment' => [
            'code' => "<?php\n\nprepare();\n\$kernel = new Kernel();\n\$re = '/after-a-call/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/after-a-call/', 'via $re')],
        ];

        yield 'a call after the read' => [
            'code' => "<?php\n\n\$re = '/before-a-call/';\npreg_match(\$re, \$subject);\nreport();\n\$kernel = new Kernel();\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/before-a-call/', 'via $re')],
        ];

        yield 'another variable assigned between' => [
            'code' => "<?php\n\n\$re = '/with-a-neighbour/';\n\$limit = 10;\n\$label = 'a ' . 'label';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/with-a-neighbour/', 'via $re')],
        ];

        // A plain assignment after the read does not reach it.
        yield 'a later assignment that does not reach the read' => [
            'code' => "<?php\n\n\$re = '/assigned-again-later/';\npreg_match(\$re, \$subject);\n\$re = \$argv[1];\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/assigned-again-later/', 'via $re')],
        ];

        // The words stand in a comment and in a string: they are no code.
        yield 'words that are no code' => [
            'code' => "<?php\n\n\$re = '/only-words/';\n// include, eval(), extract(), global and \$GLOBALS are only words here\n\$note = 'require \$GLOBALS compact() \$\$name';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/only-words/', 'via $re')],
        ];

        // The function writes a variable of its own body, which is not the
        // one of the file.
        yield 'a function of the file assigning a variable of the same name' => [
            'code' => "<?php\n\n\$re = '/beside-a-function/';\npreg_match(\$re, \$subject);\n\nfunction other(string \$given): string\n{\n    \$re = \$given;\n\n    return \$re;\n}\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/beside-a-function/', 'via $re')],
        ];

        yield 'assigned in two branches' => [
            'code' => "<?php\n\nif (\$argc > 1) {\n    \$re = '/first-branch/';\n} else {\n    \$re = '/second-branch/';\n}\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'finite_set',
            'patterns' => [self::at('/first-branch/', 'via $re'), self::at('/second-branch/', 'via $re')],
        ];

        yield 'assigned, then assigned again in a branch' => [
            'code' => "<?php\n\n\$re = '/default/';\nif (\$argc > 1) {\n    \$re = '/override/';\n}\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'finite_set',
            'patterns' => [self::at('/default/', 'via $re'), self::at('/override/', 'via $re')],
        ];

        yield 'assigned, then assigned again in a branch whose condition the file proves' => [
            'code' => "<?php\n\n\$mode = 'cli';\n\$re = '/default/';\nif ('cli' === \$mode) {\n    \$re = '/override/';\n}\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'finite_set',
            'patterns' => [self::at('/default/', 'via $re'), self::at('/override/', 'via $re')],
        ];

        yield 'assigned a ternary' => [
            'code' => "<?php\n\n\$re = \$argc > 1 ? '/then/' : '/else/';\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'finite_set',
            'patterns' => [self::at('/then/', 'via $re'), self::at('/else/', 'via $re')],
        ];

        yield 'assigned a match' => [
            'code' => "<?php\n\n\$re = match (\$argc) {\n    1 => '/one/',\n    default => '/other/',\n};\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'finite_set',
            'patterns' => [self::at('/one/', 'via $re'), self::at('/other/', 'via $re')],
        ];

        // sprintf('/^%s$/', 'abc') is "/^abc$/": the call runs before
        // the assignment, not between it and the read.
        yield 'a call in the value assigned' => [
            'code' => "<?php\n\n\$re = sprintf('/^%s$/', 'abc');\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [['pattern' => '/^abc$/', 'at' => "sprintf('/^%s$/', 'abc')", 'location' => 'via $re']],
        ];

        // implode('|', ['/a', 'b/']) is "/a|b/".
        yield 'implode() in the value assigned' => [
            'code' => "<?php\n\n\$re = implode('|', ['/a', 'b/']);\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [['pattern' => '/a|b/', 'at' => "implode('|', ['/a', 'b/'])", 'location' => 'via $re']],
        ];

        // array() is written as a call and is none: it is an array, as
        // [] is.
        yield 'array() between' => [
            'code' => "<?php\n\n\$re = '/past-array/';\n\$options = array('a', 'b');\npreg_match(\$re, \$subject);\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/past-array/', 'via $re')],
        ];

        // The assignment is made again at each turn of the loop, and no
        // call stands between it and the read within the turn.
        yield 'assigned in a loop, then read in the same turn' => [
            'code' => "<?php\n\nforeach (\$argv as \$line) {\n    \$re = '/in-the-turn/';\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/in-the-turn/', 'via $re')],
        ];

        yield 'assigned in a for loop, then read in the same turn' => [
            'code' => "<?php\n\nfor (\$i = 0; \$i < \$argc; \$i++) {\n    \$re = '/in-a-for-turn/';\n    preg_match(\$re, \$subject);\n}\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/in-a-for-turn/', 'via $re')],
        ];

        // The call runs after the read, and the next turn assigns again
        // before it reads.
        yield 'assigned in a loop, a call after the read' => [
            'code' => "<?php\n\nforeach (\$argv as \$line) {\n    \$re = '/before-the-call-of-the-turn/';\n    preg_match(\$re, \$subject);\n    report(\$line);\n}\n",
            'argument' => '$re',
            'state' => 'constant',
            'patterns' => [self::at('/before-the-call-of-the-turn/', 'via $re')],
        ];

        // The head of the loop assigns its target at each turn: a call
        // after the read is followed by that assignment.
        yield 'the target of a foreach, a call after the read' => [
            'code' => "<?php\n\nforeach (['/target-a/', '/target-b/'] as \$each) {\n    preg_match(\$each, \$subject);\n    report(\$each);\n}\n",
            'argument' => '$each',
            'state' => 'finite_set',
            'patterns' => [self::at('/target-a/', 'via $each'), self::at('/target-b/', 'via $each')],
        ];

        // One assignment gives the operand two values: "/^cat$/" or
        // "/^dog$/".
        yield 'an operand of two values' => [
            'code' => "<?php\n\n\$kind = \$argc > 1 ? 'cat' : 'dog';\npreg_match('/^' . \$kind . '$/', \$subject);\n",
            'argument' => "'/^' . \$kind . '$/'",
            'state' => 'finite_set',
            'patterns' => [
                ['pattern' => '/^cat$/', 'at' => "'/^' . \$kind . '$/'", 'location' => null],
                ['pattern' => '/^dog$/', 'at' => "'/^' . \$kind . '$/'", 'location' => null],
            ],
        ];
    }

    /**
     * @param list<array{pattern: string, at: string, location: string|null}> $patterns
     */
    #[Test]
    #[DataProvider('provideFilesTheRuleReads')]
    public function test_a_variable_at_file_level_is_read_when_nothing_may_write_it_out_of_sight(string $code, string $argument, string $state, array $patterns): void
    {
        $this->assertExtractedByBoth($patterns, $code);
        $this->assertArgumentsReadByBoth([
            ['state' => $state, 'expression' => $argument, 'at' => $argument.', $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * What stands between the assignment and the read is not on the
     * closed list: the variable is not read, whether code runs there or
     * not.
     *
     * PHP 8.4, $argv set to an object by another file before this one
     * runs: isset($argv[1]) runs its offsetExists() and list() = $argv
     * its offsetGet(), each of which leaves "/(/" in the global. Nothing
     * runs in the other rows: empty() of a variable and a declaration
     * run no code, and the variable is left unread because the list is
     * closed.
     *
     * @return iterable<string, array{code: string}>
     */
    public static function provideFilesHoldingSomethingOffTheListBetween(): iterable
    {
        // isset(), empty() and list() are written as calls and are none;
        // the list holds none of them.
        yield 'isset() between' => [
            'code' => "<?php\n\n\$re = '/past-isset/';\n\$known = isset(\$argv[1]);\npreg_match(\$re, \$subject);\n",
        ];

        yield 'empty() between' => [
            'code' => "<?php\n\n\$re = '/past-empty/';\nif (empty(\$argv)) {\n    \$subject = '';\n}\npreg_match(\$re, \$subject);\n",
        ];

        yield 'list() between' => [
            'code' => "<?php\n\n\$re = '/past-list/';\nlist(\$first, \$second) = \$argv;\npreg_match(\$re, \$subject);\n",
        ];

        // A declaration runs nothing where it stands: the calls of its
        // body run when it is called. The list holds no declaration.
        yield 'a function declared between' => [
            'code' => "<?php\n\n\$re = '/past-a-function/';\n\nfunction helper(string \$text): string\n{\n    return trim(\$text);\n}\n\npreg_match(\$re, \$subject);\n",
        ];

        yield 'a class declared between' => [
            'code' => "<?php\n\n\$re = '/past-a-class/';\n\nfinal class Helper\n{\n    public function run(string \$text): string\n    {\n        return trim(\$text);\n    }\n}\n\npreg_match(\$re, \$subject);\n",
        ];
    }

    #[Test]
    #[DataProvider('provideFilesHoldingSomethingOffTheListBetween')]
    public function test_a_variable_at_file_level_is_not_read_across_what_the_closed_list_does_not_hold(string $code): void
    {
        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A call or a "new" between the assignment and the read may run code
     * that writes the variable: a function of the file, a method, a
     * constructor, a function of PHP given a callback, or one that loads
     * a class. Whatever is called, the variable is not read.
     *
     * @return iterable<string, array{between: string}>
     */
    public static function provideCallsBetween(): iterable
    {
        yield 'a function call' => ['between' => 'prepare();'];
        yield 'a function call whose result is assigned' => ['between' => '$ready = prepare();'];
        yield 'a fully qualified function call' => ['between' => '\\App\\prepare();'];
        yield 'a method call' => ['between' => "\$logger->info('start');"];
        yield 'a nullsafe method call' => ['between' => "\$logger?->info('start');"];
        yield 'a static method call' => ['between' => 'Kernel::boot();'];
        yield 'new' => ['between' => '$kernel = new Kernel();'];
        yield 'new without parentheses' => ['between' => '$kernel = new Kernel;'];
        yield 'an anonymous class' => ['between' => '$listener = new class {};'];
        yield 'a closure of the file called' => ['between' => '$boot();'];
        // array_map('viaGlobal', [1]) runs the function of the file: "/(/".
        yield 'a function of PHP given a callback' => ['between' => "array_map('prepare', [\$subject]);"];
        yield 'a function of PHP' => ['between' => 'error_reporting(\\E_ALL);'];
        yield 'a call in a condition' => ['between' => "if (is_cli()) {\n    \$mode = 'cli';\n}"];
        yield 'a call in the value of another variable' => ['between' => '$subject = trim($subject);'];
        yield 'a call in an interpolated string' => ['between' => '$label = "run {$logger->name()}";'];
    }

    #[Test]
    #[DataProvider('provideCallsBetween')]
    public function test_a_call_or_a_new_between_the_assignment_and_the_read_leaves_the_variable_unread(string $between): void
    {
        $code = "<?php\n\n\$re = '/file-level/';\n".$between."\npreg_match(\$re, \$subject);\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * The read stands in a loop: its own call, and any other call of the
     * loop, has run when a later turn reads the variable again. The
     * assignment stands before the loop.
     *
     * PHP 8.4: $re = '/x/'; foreach ([1, 2] as $turn) { $seen[] = $re;
     * viaGlobal(); } sees "/x/" then "/(/".
     *
     * @return iterable<string, array{loop: string}>
     */
    public static function provideLoopsHoldingTheRead(): iterable
    {
        yield 'a foreach' => ['loop' => "foreach (\$argv as \$line) {\n    preg_match(\$re, \$line);\n}"];
        yield 'a foreach, alternative syntax' => ['loop' => "foreach (\$argv as \$line):\n    preg_match(\$re, \$line);\nendforeach;"];
        yield 'a while' => ['loop' => "while (\$line = array_shift(\$argv)) {\n    preg_match(\$re, \$line);\n}"];
        yield 'a for' => ['loop' => "for (\$i = 0; \$i < \$argc; \$i++) {\n    \$line = \$argv[\$i];\n    preg_match(\$re, \$line);\n}"];
        yield 'a do-while' => ['loop' => "do {\n    \$line = (string) next(\$argv);\n    preg_match(\$re, \$line);\n} while ('' !== \$line);"];
        yield 'a call after the read, in the loop' => ['loop' => "foreach (\$argv as \$line) {\n    preg_match(\$re, \$line);\n    report(\$line);\n}"];
        yield 'a loop in a loop' => ['loop' => "foreach (\$argv as \$first) {\n    foreach (\$argv as \$line) {\n        preg_match(\$re, \$line);\n    }\n}"];
        yield 'a branch in a loop' => ['loop' => "foreach (\$argv as \$line) {\n    if ('' !== \$line) {\n        preg_match(\$re, \$line);\n    }\n}"];
    }

    #[Test]
    #[DataProvider('provideLoopsHoldingTheRead')]
    public function test_a_read_in_a_loop_stands_after_the_calls_of_the_loop(string $loop): void
    {
        $code = "<?php\n\n\$re = '/file-level/';\n".$loop."\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $line)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * An assignment made in the loop, by its head for a foreach target or
     * by a statement of its body, is judged within the turn: a call
     * between it and the read may write the variable before it is read.
     *
     * PHP 8.4: foreach (['/a/', '/b/'] as $re) { viaGlobal(); $seen[] =
     * $re; }, viaGlobal() assigning the global $re, sees "/(/" twice.
     *
     * @return iterable<string, array{loop: string, argument: string}>
     */
    public static function provideTurnsWithACallBeforeTheRead(): iterable
    {
        yield 'a function call after the head of a foreach' => ['loop' => "foreach (['/target-a/', '/target-b/'] as \$each) {\n    log_it();\n    preg_match(\$each, \$subject);\n}", 'argument' => '$each'];
        yield 'a method call after the head of a foreach' => ['loop' => "foreach (['/target-a/', '/target-b/'] as \$each) {\n    \$logger->info('turn');\n    preg_match(\$each, \$subject);\n}", 'argument' => '$each'];
        yield 'new after the head of a foreach' => ['loop' => "foreach (['/target-a/', '/target-b/'] as \$each) {\n    \$turn = new Turn();\n    preg_match(\$each, \$subject);\n}", 'argument' => '$each'];
        yield 'a call in a condition after the head of a foreach' => ['loop' => "foreach (['/target-a/', '/target-b/'] as \$each) {\n    if (is_ready()) {\n        preg_match(\$each, \$subject);\n    }\n}", 'argument' => '$each'];
        yield 'a first read after the head of a foreach' => ['loop' => "foreach (['/target-a/', '/target-b/'] as \$each) {\n    preg_match('/first/', \$other);\n    preg_match(\$each, \$subject);\n}", 'argument' => '$each'];
        yield 'a call after the head, the key target read' => ['loop' => "foreach (['/target-a/' => 1, '/target-b/' => 2] as \$each => \$weight) {\n    log_it();\n    preg_match(\$each, \$subject);\n}", 'argument' => '$each'];
        yield 'a function call after an assignment of the turn' => ['loop' => "foreach (\$argv as \$line) {\n    \$re = '/in-the-turn/';\n    log_it();\n    preg_match(\$re, \$subject);\n}", 'argument' => '$re'];
        yield 'new after an assignment of the turn' => ['loop' => "foreach (\$argv as \$line) {\n    \$re = '/in-the-turn/';\n    \$turn = new Turn();\n    preg_match(\$re, \$subject);\n}", 'argument' => '$re'];
        yield 'a call after an assignment of a for turn' => ['loop' => "for (\$i = 0; \$i < \$argc; \$i++) {\n    \$re = '/in-the-turn/';\n    log_it();\n    preg_match(\$re, \$subject);\n}", 'argument' => '$re'];
    }

    #[Test]
    #[DataProvider('provideTurnsWithACallBeforeTheRead')]
    public function test_a_call_between_the_assignment_of_a_turn_and_the_read_leaves_the_variable_unread(string $loop, string $argument): void
    {
        $code = "<?php\n\n".$loop."\n";

        // The literal the first read holds is a pattern of its own.
        $this->assertExtractedByBoth(str_contains($loop, "'/first/'") ? [['pattern' => '/first/', 'at' => "'/first/'", 'location' => null]] : [], $code);

        $expected = [['state' => 'opaque', 'expression' => $argument, 'at' => $argument.', $subject)', 'source' => 'preg_match()']];
        if (str_contains($loop, "'/first/'")) {
            $expected[] = ['state' => 'constant', 'expression' => "'/first/'", 'source' => 'preg_match()'];
        }

        $this->assertArgumentsReadByBoth($expected, $code);
    }

    /**
     * Two assignments reach a read composed of the variable: nothing is
     * read, at file level as in a function body. Read alone, the variable
     * is one of the patterns it is assigned.
     */
    #[Test]
    public function test_two_assignments_reaching_a_composed_read_leave_it_opaque_at_file_level_too(): void
    {
        $code = "<?php\n\n\$kind = 'cat';\n\$re = '/whole-a/';\nif (\$argc > 1) {\n    \$kind = 'dog';\n    \$re = '/whole-b/';\n}\npreg_match('/^' . \$kind . '$/', \$subject);\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => "'/^' . \$kind . '$/'"],
        ], $code);

        $alone = "<?php\n\n\$kind = 'cat';\n\$re = '/whole-a/';\nif (\$argc > 1) {\n    \$kind = 'dog';\n    \$re = '/whole-b/';\n}\npreg_match(\$re, \$subject);\n";

        $this->assertExtractedByBoth([
            self::at('/whole-a/', 'via $re'),
            self::at('/whole-b/', 'via $re'),
        ], $alone);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$re', 'at' => '$re, $subject)'],
        ], $alone);
    }

    /**
     * The call that reads the variable is a call as any other for the
     * read that follows it: the first read is proved, the second is not.
     */
    #[Test]
    public function test_a_second_read_stands_after_the_call_of_the_first(): void
    {
        $code = "<?php\n\n\$re = '/read-twice/';\npreg_match(\$re, \$first);\npreg_match(\$re, \$second);\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/read-twice/', 'at' => "'/read-twice/'", 'source' => 'preg_match()', 'location' => 'via $re'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$re', 'at' => '$re, $first)'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $second)'],
        ], $code);
    }

    /**
     * What the file holds, anywhere, that may write a variable the file
     * does not show written: here in a function declared after the read,
     * which no call stands before, or at the top of the file.
     *
     * @return iterable<string, array{before: string, after: string}>
     */
    public static function provideFilesThatMayWriteOutOfSight(): iterable
    {
        yield 'an autoloader required at the top of the file' => ['before' => "require __DIR__ . '/vendor/autoload.php';\n", 'after' => ''];
        yield 'include in a function' => ['before' => '', 'after' => "function boot(): void\n{\n    include __DIR__ . '/boot.php';\n}\n"];
        yield 'include_once in a function' => ['before' => '', 'after' => "function boot(): void\n{\n    include_once __DIR__ . '/boot.php';\n}\n"];
        yield 'require in a function' => ['before' => '', 'after' => "function boot(): void\n{\n    require __DIR__ . '/boot.php';\n}\n"];
        yield 'require_once in a function' => ['before' => '', 'after' => "function boot(): void\n{\n    require_once __DIR__ . '/boot.php';\n}\n"];
        yield 'include after the read' => ['before' => '', 'after' => "include __DIR__ . '/footer.php';\n"];
        yield 'eval in a function' => ['before' => '', 'after' => "function run(string \$code): void\n{\n    eval(\$code);\n}\n"];
        yield 'extract() in a function' => ['before' => '', 'after' => "function import(array \$vars): int\n{\n    return extract(\$vars);\n}\n"];
        yield 'compact() in a function' => ['before' => '', 'after' => "function export(string \$name): array\n{\n    return compact('name');\n}\n"];
        yield 'a variable named by a variable' => ['before' => '', 'after' => "function set(string \$name): void\n{\n    \$\$name = '/(/';\n}\n"];
        yield 'a variable named by an expression' => ['before' => '', 'after' => "function set(string \$name): void\n{\n    \${'r' . \$name} = '/(/';\n}\n"];
        yield 'a function declaring the variable global' => ['before' => '', 'after' => "function configure(string \$given): void\n{\n    global \$re;\n    \$re = \$given;\n}\n"];
        yield 'a function declaring another variable global' => ['before' => '', 'after' => "function count_up(): void\n{\n    global \$counter;\n    \$counter++;\n}\n"];
        yield 'a method declaring the variable global' => ['before' => '', 'after' => "final class Config\n{\n    public function load(string \$given): void\n    {\n        global \$re;\n        \$re = \$given;\n    }\n}\n"];
        yield 'global at file level' => ['before' => "global \$re;\n", 'after' => ''];
        yield '$GLOBALS written in a function' => ['before' => '', 'after' => "function configure(string \$given): void\n{\n    \$GLOBALS['re'] = \$given;\n}\n"];
        yield '$GLOBALS read in a function' => ['before' => '', 'after' => "function dump(): array\n{\n    return \$GLOBALS;\n}\n"];
        yield '$GLOBALS at file level' => ['before' => '', 'after' => "\$GLOBALS['re'] = \$argv[1];\n"];
    }

    #[Test]
    #[DataProvider('provideFilesThatMayWriteOutOfSight')]
    public function test_a_file_that_may_write_its_variables_out_of_sight_reads_none_of_them(string $before, string $after): void
    {
        $code = "<?php\n\n".$before."\$re = '/file-level/';\npreg_match(\$re, \$subject);\n\n".$after;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * Every write of the variable in the file is a plain assignment, as
     * for a local variable: one that is not, before the read or after
     * it, and the variable is not read.
     *
     * @return iterable<string, array{before: string, after: string}>
     */
    public static function provideWritesThatAreNoPlainAssignment(): iterable
    {
        yield 'a concatenating assignment' => ['before' => "\$re .= 'i';", 'after' => ''];
        yield 'a concatenating assignment after the read' => ['before' => '', 'after' => "\$re .= 'i';"];
        yield 'a reference taken to it' => ['before' => '$alias = &$re;', 'after' => ''];
        yield 'a reference taken to it after the read' => ['before' => '', 'after' => '$alias = &$re;'];
        yield 'a destructuring assignment' => ['before' => '[$re, $flags] = $pair;', 'after' => ''];
        yield 'a foreach writing it' => ['before' => "foreach (\$argv as \$re) {\n}", 'after' => ''];
        yield 'a catch naming it' => ['before' => "try {\n} catch (\\Throwable \$re) {\n}", 'after' => ''];
        yield 'an assignment the file does not prove' => ['before' => "if (\$argc > 1) {\n    \$re = \$argv[1];\n}", 'after' => ''];
        yield 'a closure capturing it by reference' => ['before' => "\$writer = static function () use (&\$re): void {\n    \$re = '/(/';\n};", 'after' => ''];
    }

    #[Test]
    #[DataProvider('provideWritesThatAreNoPlainAssignment')]
    public function test_a_variable_at_file_level_written_otherwise_than_by_a_proved_plain_assignment_is_not_read(string $before, string $after): void
    {
        $code = "<?php\n\n\$re = '/file-level/';\n".$before."\npreg_match(\$re, \$subject);\n".$after."\n";

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $subject)', 'source' => 'preg_match()'],
        ], $code);
    }

    /**
     * A function body, a method, a closure and an arrow function have
     * variables of their own: the one of the file is not theirs (PHP 8.4:
     * isset($re) is false in a function and in a closure that does not
     * capture it). A variable a closure captures is not followed.
     */
    #[Test]
    public function test_a_variable_of_a_function_body_is_not_the_one_of_the_file(): void
    {
        $code = <<<'PHP'
            <?php

            $re = '/file-level/';

            function in_a_function(string $a): int
            {
                return preg_match($re, $a);
            }

            final class Matcher
            {
                public function run(string $b): int
                {
                    return preg_match($re, $b);
                }
            }

            $closure = static function (string $c): int {
                return preg_match($re, $c);
            };

            $capturing = static function (string $d) use ($re): int {
                return preg_match($re, $d);
            };

            $arrow = static fn (string $e): int => preg_match($re, $e);
            PHP;

        $this->assertExtractedByBoth([], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $a)'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $b)'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $c)'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $d)'],
            ['state' => 'opaque', 'expression' => '$re', 'at' => '$re, $e)'],
        ], $code);
    }

    /**
     * An array assigned at file level is read as one of a function body
     * is: one argument per item where a list of patterns is taken (PHP
     * 8.4: preg_replace(['/a/', '/b/'], '', 'xaxbx') is "xxx").
     */
    #[Test]
    public function test_an_array_at_file_level_is_one_argument_per_item(): void
    {
        $code = "<?php\n\n\$patterns = ['/file-item-a/', '/file-item-b/'];\n\$clean = preg_replace(\$patterns, '', \$subject);\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/file-item-a/', 'at' => "'/file-item-a/'", 'source' => 'preg_replace()', 'location' => 'via $patterns'],
            ['pattern' => '/file-item-b/', 'at' => "'/file-item-b/'", 'source' => 'preg_replace()', 'location' => 'via $patterns'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'constant', 'expression' => '$patterns', 'at' => "\$patterns, '', \$subject)"],
            ['state' => 'constant', 'expression' => '$patterns', 'at' => "\$patterns, '', \$subject)"],
        ], $code);
    }

    /**
     * The target of a foreach at file level is assigned at each turn of
     * the loop, and read before any call of that turn: it holds one of
     * the values of the array.
     */
    #[Test]
    public function test_the_target_of_a_foreach_at_file_level_holds_one_of_the_values(): void
    {
        $code = "<?php\n\nforeach (['/file-loop-a/', '/file-loop-b/'] as \$each) {\n    preg_match(\$each, \$subject);\n}\n";

        $this->assertExtractedByBoth([
            ['pattern' => '/file-loop-a/', 'at' => "'/file-loop-a/'", 'source' => 'preg_match()', 'location' => 'via $each'],
            ['pattern' => '/file-loop-b/', 'at' => "'/file-loop-b/'", 'source' => 'preg_match()', 'location' => 'via $each'],
        ], $code);
        $this->assertArgumentsReadByBoth([
            ['state' => 'finite_set', 'expression' => '$each', 'at' => '$each, $subject)'],
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
}
