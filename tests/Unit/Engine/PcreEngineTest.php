<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Engine\PcreEngine;
use RegexParser\Engine\PcreError;
use RegexParser\Engine\PcreLimits;
use RegexParser\Engine\PcreMatch;

/**
 * The one door a pattern the library was given goes through to the running
 * engine: without the JIT, without a warning, with the limits it was asked
 * for and the ini left as it was found.
 *
 * Every expectation below is read from the running engine itself (the
 * oracle helpers), never written from memory.
 */
final class PcreEngineTest extends TestCase
{
    private string $jit = '';

    private string $backtrackLimit = '';

    private string $recursionLimit = '';

    /**
     * @var list<string>
     */
    private array $leaked = [];

    protected function setUp(): void
    {
        $this->jit = (string) \ini_get('pcre.jit');
        $this->backtrackLimit = (string) \ini_get('pcre.backtrack_limit');
        $this->recursionLimit = (string) \ini_get('pcre.recursion_limit');
        $this->leaked = [];
    }

    protected function tearDown(): void
    {
        \ini_set('pcre.jit', $this->jit);
        \ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        \ini_set('pcre.recursion_limit', $this->recursionLimit);
    }

    #[Test]
    #[DataProvider('provideCompilablePatterns')]
    public function test_compile_returns_null_for_a_pattern_the_engine_compiles(string $pattern): void
    {
        $this->assertNull($this->oracleCompileError($pattern), 'The oracle refuses the case itself.');

        $this->assertNull($this->withoutWarnings(static fn (): ?PcreError => (new PcreEngine())->compile($pattern)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideCompilablePatterns(): iterable
    {
        yield 'slash delimiter with flags' => ['/a(b)c/i'];
        yield 'bracket delimiter' => ['(a(b))'];
        yield 'star delimiter, held by the verb' => ['*a\\*+b*'];
        yield 'underscore delimiter, held by the verb' => ['_a\\_b_'];
        yield 'closing parenthesis delimiter, held by the verb' => [')a\\)b)'];
        yield 'leading white space before the delimiter' => [" \n/a/"];
        yield 'a start verb of its own' => ['/(*UTF)é/'];
        yield 'already without the JIT' => ['/(*NO_JIT)a/'];
        yield 'extended mode with a comment' => ["/a # comment\n b/x"];
        yield 'empty body' => ['//'];
    }

    /**
     * The error is the one PHP reports for the pattern as the caller wrote
     * it: no "preg_match(): Compilation failed: " prefix, and an offset
     * counted in the caller's body, not in a body the engine lengthened with
     * "(*NO_JIT)" (nine bytes further on).
     */
    #[Test]
    #[DataProvider('provideRefusedPatterns')]
    public function test_compile_reports_the_error_php_reports_for_the_pattern_as_written(string $pattern): void
    {
        $expected = $this->oracleCompileError($pattern);
        $this->assertNotNull($expected, 'The oracle compiles the case itself.');

        $error = $this->withoutWarnings(static fn (): ?PcreError => (new PcreEngine())->compile($pattern));

        $this->assertInstanceOf(PcreError::class, $error);
        $this->assertSame($expected['offset'], $error->offset);
        $this->assertStringNotContainsString('preg_match()', $error->message);
        $this->assertStringNotContainsString('Compilation failed', $error->message);
        $this->assertSame($expected['message'], $error->message);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRefusedPatterns(): iterable
    {
        yield 'subroutine to a missing group, offset 3' => ['/(?1)a/'];
        yield 'same error under a delimiter the verb holds' => ['_(?1)a_'];
        yield 'same error under a star delimiter' => ['*(?1)a*'];
        yield 'unterminated class' => ['/[a/'];
        yield 'error past the ninth byte' => ['/aaaaaaaaaaaa(/'];
        yield 'error after a multibyte character in UTF mode' => ['/é(?2)/u'];
        yield 'unknown modifier, no offset' => ['/a/Q'];
        yield 'no ending delimiter, no offset' => ['/abc'];
        yield 'alphanumeric delimiter, no offset' => ['abc'];
        yield 'empty pattern, no offset' => [''];
        yield 'white space only, no offset' => ['   '];
    }

    #[Test]
    public function test_compile_and_match_leave_the_caller_error_handler_in_place(): void
    {
        $handler = static fn (): bool => true;
        set_error_handler($handler);

        try {
            $engine = new PcreEngine();
            $engine->compile('/(?1)a/');
            $engine->match('/[a/', 'a');
            $engine->match('/a/', 'a');

            $current = set_error_handler(static fn (): bool => true);
            restore_error_handler();

            $this->assertSame($handler, $current);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * The pattern run leads with "(*NO_JIT)" right after its delimiter; a
     * delimiter the verb holds moves to another, and the pattern still
     * matches what it matched.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('providePreparedPatterns')]
    public function test_prepare_puts_no_jit_after_the_delimiter(string $pattern, array $subjects): void
    {
        $prepared = (new PcreEngine())->prepare($pattern);

        $this->assertSame('(*NO_JIT)', substr($prepared, 1, 9), $prepared);
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $expected), preg_match($prepared, $subject, $actual), $subject);
            $this->assertSame($expected, $actual, $subject);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function providePreparedPatterns(): iterable
    {
        yield 'slash' => ['/a(b)/i', ['xAB', 'ab', 'b']];
        yield 'star' => ['*a\\*+(b)*', ['a**b', 'ab']];
        yield 'underscore' => ['_a\\_(b)_i', ['A_B', 'ab']];
        yield 'closing parenthesis' => [')a\\)b)i', ['A)B', 'ab']];
    }

    #[Test]
    public function test_prepare_keeps_the_flags_of_a_slash_pattern(): void
    {
        $this->assertSame('/(*NO_JIT)a/i', (new PcreEngine())->prepare('/a/i'));
    }

    /**
     * With the JIT on, "(?R)" on an empty subject exhausts the JIT stack;
     * without it the interpreter answers with its own error. The engine must
     * give the interpreter's answer, whatever the delimiter.
     */
    #[Test]
    #[DataProvider('provideRecursiveLoops')]
    public function test_match_never_runs_the_jit(string $pattern): void
    {
        \ini_set('pcre.jit', '1');
        $interpreter = $this->oracleMatch('/(*NO_JIT)(?R)/', '');

        $match = $this->withoutWarnings(static fn (): PcreMatch => (new PcreEngine())->match($pattern, ''));

        $this->assertNull($match->matched);
        $this->assertNotSame('JIT stack limit exhausted', $match->error);
        $this->assertSame($interpreter['error'], $match->error);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRecursiveLoops(): iterable
    {
        yield 'slash delimiter' => ['/(?R)/'];
        yield 'star delimiter' => ['*(?R)*'];
        yield 'underscore delimiter' => ['_(?R)_'];
    }

    /**
     * @param array<int|string, string> $groups
     */
    #[Test]
    #[DataProvider('provideMatches')]
    public function test_match_reports_what_preg_match_reports(string $pattern, string $subject, bool $matched, array $groups): void
    {
        $oracle = $this->oracleMatch($pattern, $subject);
        $this->assertSame($matched ? 1 : 0, $oracle['result'], 'The oracle disagrees with the case itself.');
        $this->assertSame($groups, $oracle['groups'], 'The oracle disagrees with the case itself.');

        $match = $this->withoutWarnings(static fn (): PcreMatch => (new PcreEngine())->match($pattern, $subject));

        $this->assertSame($matched, $match->matched);
        $this->assertNull($match->error);
        $this->assertSame($groups, $match->groups);
    }

    /**
     * @return iterable<string, array{string, string, bool, array<int|string, string>}>
     */
    public static function provideMatches(): iterable
    {
        yield 'match with a group' => ['/a(b)/', 'xab', true, ['ab', 'b']];
        yield 'no match' => ['/a(b)/', 'xyz', false, []];
        yield 'named group' => ['/(?<first>a)b/', 'ab', true, ['ab', 'first' => 'a', 1 => 'a']];
        yield 'trailing group left unset' => ['/a(b)?/', 'a', true, ['a']];
        yield 'star delimiter' => ['*a\\*+(b)*', 'a**b', true, ['a**b', 'b']];
        yield 'empty subject, empty pattern body' => ['//', '', true, ['']];
        yield 'UTF-8 subject in UTF mode' => ['/(é)/u', 'café', true, ['é', 'é']];
    }

    #[Test]
    public function test_match_reports_an_engine_error_as_a_null_verdict(): void
    {
        $subject = "a\xFF";
        $oracle = $this->oracleMatch('/a/u', $subject);
        $this->assertFalse($oracle['result']);

        $match = $this->withoutWarnings(static fn (): PcreMatch => (new PcreEngine())->match('/a/u', $subject));

        $this->assertNull($match->matched);
        $this->assertSame($oracle['error'], $match->error);
    }

    #[Test]
    public function test_match_on_a_refused_pattern_is_a_null_verdict_without_a_warning(): void
    {
        $match = $this->withoutWarnings(static fn (): PcreMatch => (new PcreEngine())->match('/[a/', 'a'));

        $this->assertNull($match->matched);
        $this->assertNotNull($match->error);
        $this->assertSame([], $match->groups);
    }

    #[Test]
    public function test_a_backtrack_limit_stops_a_catastrophic_pattern(): void
    {
        $subject = str_repeat('a', 20).'!';

        $match = $this->withoutWarnings(static fn (): PcreMatch => (new PcreEngine())->match(
            '/(a+)+$/',
            $subject,
            new PcreLimits(backtrackLimit: 10, recursionLimit: 100000),
        ));

        $this->assertNull($match->matched);
        $this->assertSame('Backtrack limit exhausted', $match->error);
        $this->assertSame($this->backtrackLimit, \ini_get('pcre.backtrack_limit'));
        $this->assertSame($this->recursionLimit, \ini_get('pcre.recursion_limit'));
    }

    /**
     * Oracle: without the JIT, "(a(?1)?)" over fifty "a" nests fifty calls;
     * a recursion limit of 2 stops it, the default limit does not.
     */
    #[Test]
    public function test_a_recursion_limit_stops_a_deep_recursion(): void
    {
        $subject = str_repeat('a', 50);
        $engine = new PcreEngine();

        $this->assertTrue($engine->match('/(a(?1)?)/', $subject)->matched);

        $match = $this->withoutWarnings(static fn (): PcreMatch => $engine->match(
            '/(a(?1)?)/',
            $subject,
            new PcreLimits(backtrackLimit: 1000000, recursionLimit: 2),
        ));

        $this->assertNull($match->matched);
        $this->assertSame('Recursion limit exhausted', $match->error);
        $this->assertSame($this->backtrackLimit, \ini_get('pcre.backtrack_limit'));
        $this->assertSame($this->recursionLimit, \ini_get('pcre.recursion_limit'));
    }

    #[Test]
    public function test_the_limits_are_restored_after_a_refused_pattern(): void
    {
        $this->withoutWarnings(static fn (): PcreMatch => (new PcreEngine())->match(
            '/[a/',
            'a',
            new PcreLimits(backtrackLimit: 10, recursionLimit: 2),
        ));

        $this->assertSame($this->backtrackLimit, \ini_get('pcre.backtrack_limit'));
        $this->assertSame($this->recursionLimit, \ini_get('pcre.recursion_limit'));
    }

    #[Test]
    public function test_a_match_without_limits_leaves_the_ini_alone(): void
    {
        \ini_set('pcre.backtrack_limit', '123456');

        $match = (new PcreEngine())->match('/a/', 'a');

        $this->assertTrue($match->matched);
        $this->assertSame('123456', \ini_get('pcre.backtrack_limit'));
    }

    /**
     * Runs the callback with an error handler that records anything the
     * engine lets through, and fails on it.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withoutWarnings(callable $callback): mixed
    {
        $this->leaked = [];
        set_error_handler(function (int $errno, string $message): bool {
            $this->leaked[] = $errno.': '.$message;

            return true;
        });

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $this->leaked, 'The engine let a warning through.');

        return $result;
    }

    /**
     * What PHP itself says of the pattern as written: null when it
     * compiles, else its message without the function prefix and the
     * "Compilation failed: " prefix, and the offset it names.
     *
     * @return array{message: string, offset: int|null}|null
     */
    private function oracleCompileError(string $pattern): ?array
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $result = preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if (false !== $result || null === $warning) {
            return null;
        }

        $message = (string) preg_replace('/^preg_match\(\):\s*(?:Compilation failed:\s*)?/', '', $warning);
        $offset = 1 === preg_match('/at offset (\d+)$/', $message, $digits) ? (int) $digits[1] : null;

        return ['message' => $message, 'offset' => $offset];
    }

    /**
     * @return array{result: int|false, error: string|null, groups: array<int|string, string>}
     */
    private function oracleMatch(string $pattern, string $subject): array
    {
        set_error_handler(static fn (): bool => true);

        try {
            $result = preg_match($pattern, $subject, $groups);
        } finally {
            restore_error_handler();
        }

        return [
            'result' => $result,
            'error' => false === $result ? preg_last_error_msg() : null,
            'groups' => $groups,
        ];
    }
}
