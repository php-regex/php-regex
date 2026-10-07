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

namespace PHPRegex\Tests\Unit\Lexer;

use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPRegex\Tests\TestUtils\PcreMessageCodes;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A callout "(?C" that never closes is read once: the ones after it are
 * not each read again up to the end of the pattern.
 */
final class CalloutTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * Measured when each unclosed callout was read to the end again: 20 000
     * then 40 000 "(?C" took 0.72 s then 2.4 s, and 1.3 s then 4.5 s after
     * "(?*", four times as long for twice the text. A refusal is fine, as
     * long as it comes fast.
     */
    #[Test]
    #[DataProvider('provideRunsOfUnclosedCallouts')]
    public function test_lexer_reads_a_run_of_unclosed_callouts_in_linear_time(string $prefix, string $unit): void
    {
        $this->assertLinearTime(
            static function (int $size) use ($prefix, $unit): void {
                try {
                    (new Lexer())->tokenize($prefix.str_repeat($unit, $size));
                } catch (LexerException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            20_000,
            $prefix.$unit,
        );
    }

    /**
     * @return iterable<string, array{prefix: string, unit: string}>
     */
    public static function provideRunsOfUnclosedCallouts(): iterable
    {
        yield 'bare openers' => ['prefix' => '', 'unit' => '(?C'];
        yield 'openers with a number' => ['prefix' => '', 'unit' => '(?C1'];
        yield 'openers with a string that never closes' => ['prefix' => '', 'unit' => '(?C"a'];
        yield 'openers after a letter' => ['prefix' => '', 'unit' => 'a(?C'];
        yield 'openers in an alphabetic lookahead' => ['prefix' => '(*pla:', 'unit' => '(?C'];
        yield 'openers in a short lookahead' => ['prefix' => '(?*', 'unit' => '(?C'];
    }

    /**
     * An unclosed callout is refused where PCRE refuses it, with a code
     * PCRE's message allows (read from the running engine).
     */
    #[Test]
    #[DataProvider('provideUnclosedCallouts')]
    public function test_validate_refuses_an_unclosed_callout_where_pcre_does(string $pattern, int $offset): void
    {
        // The offset PCRE reports for a callout run it never closes moved
        // with the releases: the row below is verified against 10.45 and
        // later.
        if ('/(?C(?C/' === $pattern && !PcreTarget::runtime()->pcreAtLeast('10.45')) {
            $this->markTestSkipped(\sprintf('%s is verified against PCRE2 10.45 and later; PCRE2 %s reports it differently.', $pattern, \PCRE_VERSION));
        }

        $pcre = PcreMessageCodes::read(PcreMessageCodes::warningOf($pattern) ?? 'compiles');
        $this->assertSame($offset, $pcre['offset'], \sprintf('Oracle: %s (%s).', $pattern, $pcre['message']));

        $result = Regex::create(['cache' => null])->validate($pattern);

        $this->assertFalse($result->isValid, $pattern);
        $this->assertSame($offset, $result->offset, \sprintf('%s: %s', $pattern, (string) $result->error));
        $this->assertContains($result->errorCode?->value, PcreMessageCodes::CODES[$pcre['message']] ?? [], \sprintf('%s: PCRE says "%s", the library %s.', $pattern, $pcre['message'], $result->errorCode?->value));
    }

    /**
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideUnclosedCallouts(): iterable
    {
        // PCRE: "missing closing parenthesis" at the end of the pattern.
        yield 'short lookahead holding a bare opener' => ['pattern' => '/(?*(?C/', 'offset' => 6];
        // Refused where PCRE refuses them already: kept as guards.
        yield 'bare opener' => ['pattern' => '/(?C/', 'offset' => 3];
        yield 'number never closed' => ['pattern' => '/(?C1/', 'offset' => 4];
        yield 'string never closed' => ['pattern' => '/(?C"a/', 'offset' => 3];
        yield 'opener before another opener' => ['pattern' => '/(?C(?C/', 'offset' => 4];
        yield 'alphabetic lookahead holding a bare opener' => ['pattern' => '/(*pla:(?C/', 'offset' => 9];
        // No callout: a "C" after "(" and a letter is text in a group left open.
        yield 'group holding a letter, a C and a letter' => ['pattern' => '/(aCb/', 'offset' => 4];
        yield 'group holding a letter and a C' => ['pattern' => '/(aC/', 'offset' => 3];
        // PCRE: "missing ) after (?# comment": the comment holds the callout.
        yield 'comment left open holding a callout left open' => ['pattern' => '/(?#(?C1/', 'offset' => 7];
    }
}
