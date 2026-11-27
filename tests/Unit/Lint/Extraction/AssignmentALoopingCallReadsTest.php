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

use PhpParser\ParserFactory;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\InFileValueResolver;
use PHPRegex\Linter\Extraction\InteropPresets;
use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\ProjectConstants;
use PHPRegex\Linter\Extraction\ProjectConstantsAwareInterface;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A call that looks for every match, reading its pattern through a
 * variable, runs in its loop the literals of the assignment it reads
 * where it stands: the latest one before it in the block of braces that
 * holds both, or the head of the foreach around it. Where the code does
 * not settle which assignment the call reads, the call names no loop.
 * Both extraction strategies read the same.
 *
 * The oracle runs the functions named: "(?:a+\d)?" matches the empty
 * string at each position of a run of "a", so a function that goes on
 * after each match makes more matches on a longer run.
 */
final class AssignmentALoopingCallReadsTest extends TestCase
{
    use TemporaryProject;

    private const PROBE = '/(?:a+\d)?/';

    /**
     * @param list<array{string, string|null, list<string>}> $expected the pattern, the loop of its call and those of
     *                                                                 the other calls that read it
     */
    #[Test]
    #[DataProvider('provideReads')]
    public function test_extraction_names_the_loop_of_the_assignment_a_call_reads(string $code, array $expected): void
    {
        foreach ($expected as [, $everyMatch, $elsewhere]) {
            foreach (null === $everyMatch ? $elsewhere : [$everyMatch, ...$elsewhere] as $function) {
                $this->assertGreaterThan(self::matchesMade($function, str_repeat('a', 1000)), self::matchesMade($function, str_repeat('a', 2000)), 'Oracle: '.$function.' stops early.');
            }
        }

        $this->assertSame($expected, $this->readByBoth(['subject.php' => "<?php\n\$s = 'x';\n".$code."\n"], PatternFunctionRegistry::native()));
    }

    /**
     * @return iterable<string, array{code: string, expected: list<array{string, string|null, list<string>}>}>
     */
    public static function provideReads(): iterable
    {
        // A block of the alternative syntax ends with its keyword: the
        // blocks of braces after it are told apart again; those in it are
        // not, as the token reading counts them.
        yield 'an assignment in a block of braces after a block of the alternative syntax' => [
            'code' => "function f(\$s, \$c) { if (\$c): \$q = 1; endif; if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'an assignment in a block of braces inside a block of the alternative syntax' => [
            'code' => "function f(\$s, \$c, \$l) { if (\$c): foreach (\$l as \$x) { \$p = '/p/'; preg_match_all(\$p, \$s); } endif; }",
            'expected' => [['/p/', null, []]],
        ];
        yield 'an assignment in a block of braces after a nested block of the alternative syntax inside another' => [
            'code' => "function f(\$s, \$c, \$l) { if (\$c): if (\$c): \$q = 1; endif; foreach (\$l as \$x) { \$p = '/p/'; preg_match_all(\$p, \$s); } endif; }",
            'expected' => [['/p/', null, []]],
        ];
        // A comment between the head and the body is no part of either.
        yield 'a comment holding a colon between the head and the brace' => [
            'code' => "function f(\$s, \$l) { foreach (\$l as \$x) /*/ : */ { \$p = '/p/'; preg_match_all(\$p, \$s); } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'an empty comment between the head and the brace' => [
            'code' => "function f(\$s, \$l) { foreach (\$l as \$x) /**/ { \$p = '/p/'; preg_match_all(\$p, \$s); } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a comment right before the colon of the alternative syntax' => [
            'code' => "function f(\$s, \$c, \$l) { if (\$c) /* x */: foreach (\$l as \$x) { \$p = '/p/'; preg_match_all(\$p, \$s); } endif; }",
            'expected' => [['/p/', null, []]],
        ];
        // A pattern choosing between a literal and a variable whose value
        // a branch may change runs no literal more than another.
        yield 'a choice between a variable a branch may change and a literal' => [
            'code' => "function f(\$s, \$c) { \$p = '/p/'; if (\$c) { \$p = '/q/'; } preg_match_all(\$c ? \$p : '/r/', \$s); }",
            'expected' => [['/p/', null, []], ['/q/', null, []], ['/r/', null, []]],
        ];
        // The head of a foreach gives its values only when it walks no
        // variable.
        yield 'the head of a foreach walking a variable and a literal' => [
            'code' => "function f(\$s) { \$q = '/q/'; foreach ([\$q, '/r/'] as \$p) { preg_match_all(\$p, \$s); } }",
            'expected' => [['/q/', null, []], ['/r/', null, []]],
        ];
        yield 'the head of a foreach walking literals under literal keys' => [
            'code' => "function f(\$s) { foreach (['a' => '/p/', 'b' => '/q/'] as \$p) { preg_match_all(\$p, \$s); } }",
            'expected' => [['/p/', 'preg_match_all', []], ['/q/', 'preg_match_all', []]],
        ];
        // At the next turn of a loop, the read runs after what the loop
        // wrote later: the assignment the read stands in, or the step of
        // a for.
        yield 'the assignment the read stands in, run again by the loop around it' => [
            'code' => "function f(\$s, \$l) { \$p = '/p/'; foreach (\$l as \$x) { \$p = preg_match_all(\$p, \$s) ? '/q/' : '/r/'; } }",
            'expected' => [['/p/', null, []], ['/q/', null, []], ['/r/', null, []]],
        ];
        yield 'the step of a for that writes the variable after the read' => [
            'code' => "function f(\$s) { \$p = '/p/'; for (\$i = 0; \$i < 3; \$p = '/q/') { preg_match_all(\$p, \$s); } }",
            'expected' => [['/p/', null, []], ['/q/', null, []]],
        ];
        // The loops of the other calls come in the order of the calls,
        // the first call's left out.
        yield 'a literal two calls of one loop read before a third one' => [
            'code' => "function f(\$s) { \$p = '/p/'; preg_match_all(\$p, \$s); preg_match_all(\$p, \$s, \$m); preg_split(\$p, \$s); }",
            'expected' => [['/p/', 'preg_match_all', ['preg_split']]],
        ];
        // What PHP reads as no statement before the brace that closes a
        // block leaves the block where it ends: an empty statement, a
        // closing tag, the opening tag after it and the text between them.
        yield 'text out of PHP before the brace that closes the block' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); ?>html<?php } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a brace out of PHP before the brace that closes the block' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); ?>}<?php } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a closing tag that ends the last statement of the block' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s) ?><?php } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a closing tag after the last statement of the block' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); ?><?php } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a closing tag, its newline and an opening tag in capitals' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); ?>\n<?PHP\n} }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a comment a closing tag ends before the brace' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); // c ?><?php } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'an echo tag before the brace that closes the block' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); ?><?= \$s ?><?php } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'an empty statement before the brace that closes the block' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s);; } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'an empty statement after an opening tag before the brace' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); ?><?php ; } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'text out of PHP before the brace of an else, of a loop and of a try' => [
            'code' => "function f(\$s, \$c) { if (\$c) { } else { \$p = '/p/'; preg_match_all(\$p, \$s); ?>a<?php }\n"
                ."while (\$c) { \$q = '/q/'; preg_split(\$q, \$s); ?>b<?php }\n"
                ."do { \$r = '/r/'; preg_match_all(\$r, \$s); ?>c<?php } while (\$c);\n"
                ."try { \$t = '/t/'; preg_split(\$t, \$s); ?>d<?php } finally { } }",
            'expected' => [['/p/', 'preg_match_all', []], ['/q/', 'preg_split', []], ['/r/', 'preg_match_all', []], ['/t/', 'preg_split', []]],
        ];
        // The call after the block is not in it: text out of PHP does not
        // move the brace.
        yield 'a call after a block that text out of PHP ends' => [
            'code' => "function f(\$s, \$c) { \$p = '/q/'; if (\$c) { \$p = '/p/'; ?>html<?php } preg_match_all(\$p, \$s); }",
            'expected' => [['/q/', null, []], ['/p/', null, []]],
        ];
        yield 'a block of the alternative syntax that text out of PHP ends' => [
            'code' => "function f(\$s, \$c) { if (\$c): \$p = '/p/'; preg_match_all(\$p, \$s); ?>html<?php endif; }",
            'expected' => [['/p/', null, []]],
        ];
        // A branch without braces is no block: the statements after it,
        // empty or not, are not its own.
        yield 'an empty statement after a branch without braces' => [
            'code' => "function f(\$s, \$c) { if (\$c) \$p = '/p/';; preg_match_all(\$p, \$s); }",
            'expected' => [['/p/', null, []]],
        ];
        yield 'a branch without braces the file ends in' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$p = '/p/'; preg_match_all(\$p, \$s); } }\nif (\$s) \$s = 'y';",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        // A carriage return alone ends a comment, as a newline does.
        yield 'a comment a newline ends between the head and the brace' => [
            'code' => "function f(\$s, \$l) { foreach (\$l as \$x) // : c\n{ \$p = '/p/'; preg_match_all(\$p, \$s); } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a comment a carriage return ends between the head and the brace' => [
            'code' => "function f(\$s, \$l) { foreach (\$l as \$x) // : c\r{ \$p = '/p/'; preg_match_all(\$p, \$s); } }",
            'expected' => [['/p/', 'preg_match_all', []]],
        ];
        yield 'a comment a newline ends before the colon of the alternative syntax' => [
            'code' => "function f(\$s, \$c, \$l) { if (\$c) // c\n: foreach (\$l as \$x) { \$p = '/p/'; preg_match_all(\$p, \$s); } endif; }",
            'expected' => [['/p/', null, []]],
        ];
        yield 'a comment a carriage return ends before the colon of the alternative syntax' => [
            'code' => "function f(\$s, \$c, \$l) { if (\$c) # c\r: foreach (\$l as \$x) { \$p = '/p/'; preg_match_all(\$p, \$s); } endif; }",
            'expected' => [['/p/', null, []]],
        ];
    }

    /**
     * A list of patterns a variable holds is read by the calls PHP gives
     * a list of patterns to, each item one pattern: such a call runs in
     * its loop the items of the list it reads where it stands, as it runs
     * those of a list written at the call. A list whose items read a
     * variable, one written otherwise than by its assignment and one the
     * code does not settle name no loop.
     *
     * The oracle runs each function named on a list holding the probe,
     * and reads the items of a list as PHP does: each value a pattern,
     * whatever its key, the last one of a key written twice.
     *
     * @param list<array{string, string|null, list<string>}> $expected the pattern, the loop of its call and those of
     *                                                                 the other calls that read it
     */
    #[Test]
    #[DataProvider('provideListsOfPatterns')]
    public function test_extraction_names_the_loop_of_a_list_of_patterns_a_variable_holds(string $code, array $expected): void
    {
        foreach ($expected as [, $everyMatch, $elsewhere]) {
            foreach (null === $everyMatch ? $elsewhere : [$everyMatch, ...$elsewhere] as $function) {
                $this->assertGreaterThan(self::matchesMade($function, str_repeat('a', 1000)), self::matchesMade($function, str_repeat('a', 2000)), 'Oracle: '.$function.' stops early.');
            }
        }

        $list = ['/p/', '/q/'];
        $this->assertSame('', preg_replace($list, '', 'pq'), 'Oracle: preg_replace() reads each item of a list.');
        $this->assertSame('', preg_replace(['k' => '/p/', 7 => '/q/'], '', 'pq'), 'Oracle: preg_replace() reads the items whatever their keys.');
        $this->assertSame(['/b/'], eval("return [0 => '/a/', 0 => '/b/'];"), 'Oracle: a key written twice holds its last value.');

        $this->assertSame($expected, $this->readByBoth(['subject.php' => "<?php\n\$s = 'x';\n".$code."\n"], PatternFunctionRegistry::native()));
    }

    /**
     * @return iterable<string, array{code: string, expected: list<array{string, string|null, list<string>}>}>
     */
    public static function provideListsOfPatterns(): iterable
    {
        yield 'a list a statement of the body assigns' => [
            'code' => "function f(\$s) { \$l = ['/p/']; preg_replace(\$l, '', \$s); }",
            'expected' => [['/p/', 'preg_replace', []]],
        ];
        yield 'a list of two patterns' => [
            'code' => "function f(\$s) { \$l = ['/p/', '/q/']; preg_replace(\$l, '', \$s); }",
            'expected' => [['/p/', 'preg_replace', []], ['/q/', 'preg_replace', []]],
        ];
        yield 'a list written the long way, passed by name' => [
            'code' => "function f(\$s) { \$l = array('/p/'); preg_replace(pattern: \$l, replacement: '', subject: \$s); }",
            'expected' => [['/p/', 'preg_replace', []]],
        ];
        yield 'a list given to preg_filter' => [
            'code' => "function f(\$s) { \$l = ['/p/']; preg_filter(\$l, '', \$s); }",
            'expected' => [['/p/', 'preg_filter', []]],
        ];
        yield 'a list given to preg_replace_callback' => [
            'code' => "function f(\$s) { \$l = ['/p/']; preg_replace_callback(\$l, static fn (\$m) => '', \$s); }",
            'expected' => [['/p/', 'preg_replace_callback', []]],
        ];
        yield 'a list of the file level' => [
            'code' => "\$l = ['/p/']; preg_replace(\$l, '', \$s);",
            'expected' => [['/p/', 'preg_replace', []]],
        ];
        yield 'a list a block of braces assigns before the call' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$l = ['/p/']; preg_replace(\$l, '', \$s); } }",
            'expected' => [['/p/', 'preg_replace', []]],
        ];
        yield 'a list read in a loop that does not write it' => [
            'code' => "function f(\$s, \$c) { \$l = ['/p/']; while (\$c) { preg_replace(\$l, '', \$s); } }",
            'expected' => [['/p/', 'preg_replace', []]],
        ];
        yield 'a list read through a copy' => [
            'code' => "function f(\$s) { \$l = ['/p/']; \$m = \$l; preg_replace(\$m, '', \$s); }",
            'expected' => [['/p/', 'preg_replace', []]],
        ];
        yield 'a list under keys' => [
            'code' => "function f(\$s) { \$l = ['k' => '/p/', 7 => '/q/']; preg_replace(\$l, '', \$s); }",
            'expected' => [['/p/', 'preg_replace', []], ['/q/', 'preg_replace', []]],
        ];
        yield 'a list with a key written twice' => [
            'code' => "function f(\$s) { \$l = [0 => '/a/', 0 => '/b/']; preg_replace(\$l, '', \$s); }",
            'expected' => [['/b/', 'preg_replace', []]],
        ];
        yield 'a list with an item that chooses between two literals' => [
            'code' => "function f(\$s, \$c) { \$l = [\$c ? '/a/' : '/b/', '/c/']; preg_replace(\$l, '', \$s); }",
            'expected' => [['/a/', 'preg_replace', []], ['/b/', 'preg_replace', []], ['/c/', 'preg_replace', []]],
        ];
        yield 'a list two calls read' => [
            'code' => "function f(\$s) { \$l = ['/p/']; preg_replace(\$l, '', \$s); preg_filter(\$l, '', \$s); }",
            'expected' => [['/p/', 'preg_replace', ['preg_filter']]],
        ];
        yield 'a list an item of which a call that stops at the first match reads first' => [
            'code' => "function f(\$s) { \$l = ['/p/']; preg_match(\$l[0], \$s); preg_replace(\$l, '', \$s); }",
            'expected' => [['/p/', null, ['preg_replace']]],
        ];
        // A limit bounds the loop of the call, whatever it reads.
        yield 'a list given with a limit' => [
            'code' => "function f(\$s) { \$l = ['/p/']; preg_replace(\$l, '', \$s, 1); }",
            'expected' => [['/p/', null, []]],
        ];
        // The list is built where it is assigned: what a variable held
        // there is not told.
        yield 'a list with an item that reads a variable' => [
            'code' => "function f(\$s) { \$a = '/a/'; \$l = [\$a, '/b/']; preg_replace(\$l, '', \$s); }",
            'expected' => [['/a/', null, []], ['/b/', null, []]],
        ];
        yield 'a list assigned in a branch before the call' => [
            'code' => "function f(\$s, \$c) { if (\$c) { \$l = ['/p/']; } preg_replace(\$l, '', \$s); }",
            'expected' => [['/p/', null, []]],
        ];
        // A list assigned again is never read as the list it held before.
        yield 'a list assigned again after the call' => [
            'code' => "function f(\$s) { \$l = ['/p/']; preg_replace(\$l, '', \$s); \$l = ['/q/']; }",
            'expected' => [['/p/', 'preg_replace', []]],
        ];
        yield 'a list assigned again before the call' => [
            'code' => "function f(\$s) { \$l = ['/p/']; \$l = ['/q/']; preg_replace(\$l, '', \$s); }",
            'expected' => [],
        ];
        yield 'a list a branch may assign again before the call' => [
            'code' => "function f(\$s, \$c) { \$l = ['/p/']; if (\$c) { \$l = ['/q/']; } preg_replace(\$l, '', \$s); }",
            'expected' => [],
        ];
        yield 'a list the loop of the call assigns again after it' => [
            'code' => "function f(\$s, \$x) { \$l = ['/p/']; foreach (\$x as \$y) { preg_replace(\$l, '', \$s); \$l = ['/q/']; } }",
            'expected' => [],
        ];
        yield 'a list an item is appended to' => [
            'code' => "function f(\$s) { \$l = ['/p/']; \$l[] = '/q/'; preg_replace(\$l, '', \$s); }",
            'expected' => [],
        ];
        yield 'a list passed to a call before' => [
            'code' => "function f(\$s) { \$l = ['/p/']; foo(\$l); preg_replace(\$l, '', \$s); }",
            'expected' => [],
        ];
    }

    /**
     * A call reads the value of a variable through others, each
     * assignment and each alternative of a choice a step from the call:
     * past InFileValueResolver::MAX_DEPTH steps, the call names no loop,
     * though the pattern is reported.
     */
    #[Test]
    public function test_extraction_follows_the_assignments_a_looping_call_reads_up_to_a_depth(): void
    {
        $this->assertGreaterThan(self::matchesMade('preg_match_all', str_repeat('a', 1000)), self::matchesMade('preg_match_all', str_repeat('a', 2000)), 'Oracle: preg_match_all stops early.');

        $copies = static function (int $count): string {
            $code = "\$p0 = '/p/';";
            for ($at = 1; $at <= $count; $at++) {
                $code .= ' $p'.$at.' = $p'.($at - 1).';';
            }

            return "<?php\nfunction f(\$s) { ".$code.' preg_match_all($p'.$count.", \$s); }\n";
        };
        $choices = static function (int $count): string {
            $code = "\$p0 = '/p/';";
            for ($at = 1; $at <= $count; $at++) {
                $code .= ' $p'.$at.' = $c ? $p'.($at - 1)." : '/x".$at."/';";
            }

            return "<?php\nfunction f(\$s, \$c) { ".$code.' preg_match_all($p'.$count.", \$s); }\n";
        };

        // The occurrence of the literal the chain starts from.
        $first = function (string $code): array {
            $read = array_values(array_filter($this->readByBoth(['subject.php' => $code], PatternFunctionRegistry::native()), static fn (array $row): bool => '/p/' === $row[0]));
            $this->assertCount(1, $read);

            return $read[0];
        };

        $this->assertSame(['/p/', 'preg_match_all', []], $first($copies(InFileValueResolver::MAX_DEPTH)), 'A chain of copies within the depth.');
        $this->assertSame(['/p/', null, []], $first($copies(InFileValueResolver::MAX_DEPTH + 1)), 'A chain of copies past the depth.');
        $this->assertSame(['/p/', 'preg_match_all', []], $first($choices(intdiv(InFileValueResolver::MAX_DEPTH, 2))), 'A chain of choices within the depth.');
        $this->assertSame(['/p/', null, []], $first($choices(intdiv(InFileValueResolver::MAX_DEPTH, 2) + 1)), 'A chain of choices past the depth.');
    }

    /**
     * The literal a call reads through a variable is told by its file and
     * by what the call appends to it: a constant of another file is
     * reported there with the loop of the call, and so is a pattern Nette
     * reads with "u" appended under utf8: true.
     */
    #[Test]
    public function test_extraction_names_the_loop_of_a_literal_of_another_file_or_with_a_suffix(): void
    {
        $this->assertGreaterThan(self::matchesMade('preg_match_all', str_repeat('a', 1000)), self::matchesMade('preg_match_all', str_repeat('a', 2000)), 'Oracle: preg_match_all stops early.');

        $files = [
            'Patterns.php' => "<?php\nnamespace App;\nfinal class Patterns { public const RE = '/p/'; }\n",
            'Reader.php' => "<?php\nfunction f(\$s) { \$p = \\App\\Patterns::RE; preg_match_all(\$p, \$s); }\n",
            'Nette.php' => "<?php\nfunction g(\$s) { \$q = '/q/'; \\Nette\\Utils\\Strings::matchAll(\$s, \$q, utf8: true); }\n",
        ];

        $this->assertSame(
            [['Patterns.php', '/p/', 'preg_match_all', []], ['Nette.php', '/q/u', 'preg_match_all', []]],
            $this->readByBoth($files, PatternFunctionRegistry::create([InteropPresets::NETTE_UTILS]), true),
        );
    }

    /**
     * A read the code gives no assignment, before the foreach that
     * assigns the variable or with none at all, and a switch the file
     * ends in after its condition, are read without a warning.
     *
     * @param list<array{string, string|null, list<string>}> $expected
     */
    #[Test]
    #[DataProvider('provideReadsWithoutAnAssignment')]
    public function test_extraction_reads_without_a_warning(string $code, array $expected, bool $parsed): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $files = ['subject.php' => "<?php\n".$code];
            $this->assertSame($expected, $this->loopsReadWith(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()), $files), 'The token strategy.');
            if ($parsed && class_exists(ParserFactory::class)) {
                $this->assertSame($expected, $this->loopsReadWith(new PhpParserExtractionStrategy([], PatternFunctionRegistry::native()), $files), 'The parser strategy.');
            }
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return iterable<string, array{code: string, expected: list<array{string, string|null, list<string>}>, parsed: bool}>
     */
    public static function provideReadsWithoutAnAssignment(): iterable
    {
        yield 'a variable read before the foreach that assigns it' => [
            'code' => "function f(\$s) { preg_match_all(\$p, \$s); foreach (['/p/'] as \$p) { } }\n",
            'expected' => [],
            'parsed' => true,
        ];
        yield 'a variable no assignment writes, in a choice' => [
            'code' => "function f(\$s, \$c) { preg_match_all(\$c ? \$p : '/r/', \$s); }\n",
            'expected' => [],
            'parsed' => true,
        ];
        yield 'a switch the file ends in after its condition' => [
            'code' => "\$re = '/x/';\npreg_match(\$re, \$s);\nswitch (\$c)",
            'expected' => [['/x/', null, []]],
            'parsed' => false,
        ];
    }

    /**
     * What both strategies read: each occurrence's pattern, the loop of
     * its call and those of the other calls that read it, preceded by the
     * name of its file when asked.
     *
     * @param array<string, string> $files relative path => contents
     *
     * @return list<list<string|list<string>|null>>
     */
    private function readByBoth(array $files, PatternFunctionRegistry $registry, bool $withFile = false): array
    {
        $fromTokens = $this->loopsReadWith(new TokenBasedExtractionStrategy([], $registry), $files, $withFile);
        if (class_exists(ParserFactory::class)) {
            $this->assertSame($fromTokens, $this->loopsReadWith(new PhpParserExtractionStrategy([], $registry), $files, $withFile), 'The token strategy reads otherwise than the parser strategy.');
        }

        return $fromTokens;
    }

    /**
     * @param array<string, string> $files relative path => contents
     *
     * @return list<list<string|list<string>|null>>
     */
    private function loopsReadWith(ExtractorInterface $strategy, array $files, bool $withFile = false): array
    {
        $directory = $this->makeProject($files);
        $paths = array_map(static fn (string $relative): string => $directory.'/'.$relative, array_keys($files));
        if (\count($paths) > 1) {
            $this->assertInstanceOf(ProjectConstantsAwareInterface::class, $strategy);
            $strategy = $strategy->withProjectConstants(ProjectConstants::of(array_map(ProjectConstants::scanFile(...), $paths)));
        }

        $read = [];
        foreach ($strategy->extract($paths) as $occurrence) {
            $this->assertNull($occurrence->parserFallback, 'The parser refused the file: '.($occurrence->parserFallback ?? ''));
            $row = [$occurrence->pattern, $occurrence->everyMatch, $occurrence->everyMatchElsewhere];
            $read[] = $withFile ? [basename($occurrence->file), ...$row] : $row;
        }

        return $read;
    }

    /**
     * How many matches the function makes with the probe on the subject:
     * the oracle of its loop. A function PHP gives a list of patterns to
     * is given the probe in a list, and counts its replacements.
     */
    private static function matchesMade(string $function, string $subject): int
    {
        $count = 0;
        $made = match ($function) {
            'preg_match_all' => preg_match_all(self::PROBE, $subject),
            // The pieces of preg_split() are one more than its matches.
            'preg_split' => \count(preg_split(self::PROBE, $subject) ?: []) - 1,
            'preg_replace' => null === preg_replace([self::PROBE], '', $subject, -1, $count) ? false : $count,
            'preg_filter' => null === preg_filter([self::PROBE], '', $subject, -1, $count) ? false : $count,
            'preg_replace_callback' => null === preg_replace_callback([self::PROBE], static fn (array $match): string => '', $subject, -1, $count) ? false : $count,
            default => false,
        };
        self::assertIsInt($made, 'Oracle: '.$function.' failed.');

        return $made;
    }
}
