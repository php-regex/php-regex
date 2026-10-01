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

namespace PhpRegex\Tests\Unit\Engine;

use PhpRegex\Parser\Engine\PcreEngine;
use PhpRegex\Parser\Engine\PcreError;
use PhpRegex\Parser\Engine\PcreLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Where the verb goes when the body holds every spare delimiter: to a
 * bracket pair the body leaves balanced, and, with no such pair, nowhere;
 * the JIT is then turned off for the call. Expectations are read from the
 * running engine.
 */
final class PcreEngineDelimiterTest extends TestCase
{
    /**
     * Every spare delimiter, then a class holding a closing bracket before
     * its opening one: no bracket pair can hold this body.
     */
    private const NO_PAIR_HOLDS_IT = "(?R)|[])(}{><]\x01#~%!@;,";

    private string $jit = '';

    protected function setUp(): void
    {
        $this->jit = (string) \ini_get('pcre.jit');
    }

    protected function tearDown(): void
    {
        \ini_set('pcre.jit', $this->jit);
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideBracketFallbacks')]
    public function test_a_body_holding_every_spare_delimiter_moves_to_brackets(string $pattern, string $expected, array $subjects): void
    {
        $prepared = (new PcreEngine())->prepare($pattern);

        $this->assertSame($expected, $prepared);
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $before), preg_match($prepared, $subject, $after), $subject);
            $this->assertSame($before, $after, $subject);
        }
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function provideBracketFallbacks(): iterable
    {
        yield 'parentheses, balanced in the body' => [
            "_\x01#~%!@;,a(b)_i",
            "((*NO_JIT)\x01#~%!@;,a(b))i",
            ["\x01#~%!@;,AB", 'ab'],
        ];
        yield 'parentheses, the body escaping one' => [
            "_\x01#~%!@;,a\\(b_",
            "((*NO_JIT)\x01#~%!@;,a\\(b)",
            ["\x01#~%!@;,a(b", 'ab'],
        ];
        yield 'square brackets, the body leaving a parenthesis open in a class' => [
            "_\x01#~%!@;,[(]_",
            "[(*NO_JIT)\x01#~%!@;,[(]]",
            ["\x01#~%!@;,(", "\x01#~%!@;,)"],
        ];
        yield 'braces, the body closing a parenthesis and a square bracket first' => [
            "_\x01#~%!@;,][)]x{2}_",
            "{(*NO_JIT)\x01#~%!@;,][)]x{2}}",
            ["\x01#~%!@;,])xx", 'x'],
        ];
    }

    /**
     * With the JIT on, the pattern exhausts the JIT stack on the empty
     * subject, even served from PHP's cache as the JIT compiled it; the
     * engine gives the interpreter's answer and puts the JIT back.
     */
    #[Test]
    public function test_a_pattern_no_delimiter_holds_runs_with_the_jit_turned_off(): void
    {
        $pattern = '_'.self::NO_PAIR_HOLDS_IT.'_';
        \ini_set('pcre.jit', '1');
        $jit = $this->oracleError($pattern, '');
        $interpreter = $this->oracleError('/(*NO_JIT)'.self::NO_PAIR_HOLDS_IT.'/', '');
        $this->assertSame('JIT stack limit exhausted', $jit, 'The oracle runs no JIT.');
        $this->assertNotSame($jit, $interpreter);

        $engine = new PcreEngine();

        $this->assertSame($pattern, $engine->prepare($pattern));
        $match = $engine->match($pattern, '');
        $this->assertNull($match->matched);
        $this->assertSame($interpreter, $match->error);
        $this->assertSame('1', \ini_get('pcre.jit'));
    }

    /**
     * PHP ends the body at the first delimiter: "_a_i_" has the flags "i_",
     * and "_" is no modifier. A pattern read to its last delimiter would
     * compile.
     */
    #[Test]
    public function test_the_body_ends_where_php_ends_it(): void
    {
        $error = (new PcreEngine())->compile('_a_i_');

        $this->assertInstanceOf(PcreError::class, $error);
        $this->assertSame("Unknown modifier '_'", $error->message);
        $this->assertNull($error->offset);
    }

    #[Test]
    public function test_match_all_lists_the_whole_matches(): void
    {
        $engine = new PcreEngine();

        $this->assertSame(['ab', 'ab'], $engine->matchAll('_a(b)_', 'xabyab'));
        $this->assertSame([], $engine->matchAll('/z/', 'xab'));
        $this->assertNull($engine->matchAll('/[a/', 'a'));
        $this->assertNull($engine->matchAll('/(a+)+$/', str_repeat('a', 20).'!', new PcreLimits(10, 100000)));
    }

    private function oracleError(string $pattern, string $subject): string
    {
        set_error_handler(static fn (): bool => true);

        try {
            preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        return preg_last_error_msg();
    }
}
