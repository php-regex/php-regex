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

namespace PHPRegex\Tests\Unit\Parser\Syntax;

use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Syntax\StartOptions;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The run of start options is read as PCRE2 reads it: every option of its
 * list, one after the other. An option missing from the run ends it early,
 * and whatever follows, a newline convention or (*UTF), is lost.
 */
final class StartOptionsTest extends TestCase
{
    use PcreGates;

    /**
     * Every start option PCRE2 10.49 lists, each one that PHP's PCRE
     * compiles in front of "a". "(*TURKISH_CASING)" needs UTF mode, so it is
     * written after "(*UTF)".
     *
     * @return iterable<string, array{options: string}>
     */
    public static function provideStartOptions(): iterable
    {
        foreach ([
            'UTF', 'UTF8', 'UCP',
            'CR', 'LF', 'CRLF', 'ANYCRLF', 'ANY', 'NUL',
            'BSR_ANYCRLF', 'BSR_UNICODE',
            'NOTEMPTY', 'NOTEMPTY_ATSTART',
            'NO_AUTO_POSSESS', 'NO_DOTSTAR_ANCHOR', 'NO_JIT', 'NO_START_OPT',
            'LIMIT_MATCH=10', 'LIMIT_DEPTH=10', 'LIMIT_HEAP=10', 'LIMIT_RECURSION=10',
            'CASELESS_RESTRICT',
        ] as $option) {
            yield \sprintf('(*%s)', $option) => ['options' => \sprintf('(*%s)', $option)];
        }

        yield '(*TURKISH_CASING) after (*UTF)' => ['options' => '(*UTF)(*TURKISH_CASING)'];
    }

    #[Test]
    #[DataProvider('provideStartOptions')]
    public function test_of_reads_every_start_option_pcre_accepts(string $options): void
    {
        if (str_contains($options, 'CASELESS_RESTRICT') || str_contains($options, 'TURKISH_CASING')) {
            $this->requiresPcre2('10.45', $options);
        }

        // Oracle: PHP's PCRE compiles the option and matches past it.
        $this->assertSame(1, preg_match('/'.$options.'a/', 'a'));

        $this->assertSame($options, StartOptions::of($options.'a'));
    }

    /**
     * The validator knows the same options as the reading of the run: each
     * one is valid where the pattern opens, and out of place after "a",
     * where PCRE2 reads no option.
     */
    #[Test]
    #[DataProvider('provideStartOptions')]
    public function test_the_validator_takes_every_start_option_at_the_start_only(string $options): void
    {
        if (str_contains($options, 'CASELESS_RESTRICT') || str_contains($options, 'TURKISH_CASING')) {
            $this->requiresPcre2('10.45', $options);
        }

        $parser = RegexParser::create();

        $this->assertTrue($parser->validate('/'.$options.'a/u')->isValid, $options);

        $this->assertFalse(@preg_match('/a'.$options.'/u', 'a'));
        $this->assertSame(ErrorCode::VerbMisplaced, $parser->validate('/a'.$options.'/u')->errorCode, $options);
    }

    /**
     * The name of every start option, and where PCRE2 10.32 ends it: it
     * reads a verb name as a run of letters only.
     *
     * @return iterable<string, array{name: string, lettersEnd: int}>
     */
    public static function provideStartOptionNames(): iterable
    {
        foreach ([
            'UTF', 'UTF8', 'UCP',
            'CR', 'LF', 'CRLF', 'ANYCRLF', 'ANY', 'NUL',
            'BSR_ANYCRLF', 'BSR_UNICODE',
            'NOTEMPTY', 'NOTEMPTY_ATSTART',
            'NO_AUTO_POSSESS', 'NO_DOTSTAR_ANCHOR', 'NO_JIT', 'NO_START_OPT',
            'LIMIT_MATCH', 'LIMIT_DEPTH', 'LIMIT_HEAP', 'LIMIT_RECURSION',
            'CASELESS_RESTRICT', 'TURKISH_CASING',
        ] as $name) {
            yield $name => ['name' => $name, 'lettersEnd' => strspn($name, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')];
        }
    }

    /**
     * PCRE2 reads a start option only as it is written, "(*UTF)" or
     * "(*LIMIT_MATCH=10)": given an argument, "(*UTF:x)", it is a verb PCRE2
     * does not know, refused where its name ends, at the start of the
     * pattern or past it, before a ")" it leaves unmatched (pcre2test 10.32
     * to 10.49, each release).
     */
    #[Test]
    #[DataProvider('provideStartOptionNames')]
    public function test_a_start_option_given_an_argument_is_a_verb_pcre_does_not_know(string $name, int $lettersEnd): void
    {
        $nameLength = \strlen($name);
        $patterns = [
            '/(*'.$name.':x)a/' => 2,
            '/(*UTF)(*'.$name.':)a/' => 8,
            '/a(*'.$name.':x)/' => 3,
            '/(*'.$name.':x))/' => 2,
        ];

        foreach ($patterns as $pattern => $nameStart) {
            // Oracle: PHP's PCRE refuses it.
            $this->assertFalse(@preg_match($pattern, 'a'), $pattern);

            foreach (['10.32', '10.33', '10.40', '10.44', '10.45', '10.47', '10.49'] as $release) {
                $result = RegexParser::create(['php_version' => '8.0', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

                $this->assertFalse($result->isValid, $pattern.' on PCRE2 '.$release);
                $this->assertSame($nameStart + ('10.32' === $release ? $lettersEnd : $nameLength), $result->offset, $pattern.' on PCRE2 '.$release);
                $this->assertSame(ErrorCode::VerbInvalid, $result->errorCode, $pattern.' on PCRE2 '.$release);
            }
        }
    }

    /**
     * After a callout, what PCRE2 reads as nothing, a comment, a "\E" or an
     * empty quote, leaves the start option given an argument where the
     * assertion is due: refused on its "(" there (pcre2test 10.32 to 10.49,
     * PHP 8.4 with 10.49 for the line comment under "x").
     *
     * @return iterable<string, array{pattern: string, offset: int}>
     */
    public static function provideStartOptionsWithAnArgumentAfterACalloutAndNothing(): iterable
    {
        yield 'comment' => ['pattern' => '/(?(?C1)(?#c)(*UTF:x)a)/', 'offset' => 12];
        yield 'two comments' => ['pattern' => '/(?(?C1)(?#c)(?#d)(*UCP:x)|b)/', 'offset' => 17];
        yield 'quote end' => ['pattern' => '/(?(?C1)\E(*UTF:x)a)/', 'offset' => 9];
        yield 'empty quote' => ['pattern' => '/(?(?C1)\Q\E(*NO_JIT:)a|b)/', 'offset' => 11];
        yield 'string callout and a comment' => ['pattern' => '/(?(?C"x")(?#c)(*UTF:x)a)/', 'offset' => 14];
        yield 'line comment under x' => ['pattern' => "/(?(?C1) #c\n (*UTF:x)a)/x", 'offset' => 12];
    }

    #[Test]
    #[DataProvider('provideStartOptionsWithAnArgumentAfterACalloutAndNothing')]
    public function test_a_start_option_given_an_argument_after_a_callout_and_nothing_is_no_assertion(string $pattern, int $offset): void
    {
        $this->assertFalse(@preg_match($pattern, 'a'));

        foreach (['10.32', '10.33', '10.44', '10.45', '10.47', '10.49'] as $release) {
            $result = RegexParser::create(['php_version' => '8.0', 'pcre_version' => $release, 'runtime_pcre_validation' => false])->validate($pattern);

            $this->assertSame($offset, $result->offset, $pattern.' on '.$release);
            $this->assertSame(ErrorCode::ConditionalAssertionExpected, $result->errorCode, $pattern.' on '.$release);
        }
    }

    /**
     * As the condition of "(?(", a start option given an argument is no
     * assertion: PCRE2 refuses it on its "(", from 10.47 past it (pcre2test
     * 10.33 to 10.49).
     */
    #[Test]
    public function test_a_start_option_given_an_argument_as_a_condition_is_no_assertion(): void
    {
        $this->assertFalse(@preg_match('/(?(*UTF:x)a)/', 'a'));

        foreach (['10.33' => 2, '10.44' => 2, '10.46' => 2, '10.47' => 3, '10.49' => 3] as $release => $offset) {
            $result = RegexParser::create(['php_version' => '8.0', 'pcre_version' => (string) $release, 'runtime_pcre_validation' => false])->validate('/(?(*UTF:x)a)/');

            $this->assertSame($offset, $result->offset, (string) $release);
            $this->assertSame(ErrorCode::ConditionalAssertionExpected, $result->errorCode, (string) $release);
        }
    }

    /**
     * The newline convention set after a casing setting is the one the
     * dot reads: under (*CR) it takes "\n".
     *
     * @return iterable<string, array{source: string}>
     */
    public static function provideNewlinesAfterACasingSetting(): iterable
    {
        yield '(*CR) after (*CASELESS_RESTRICT)' => ['source' => '(*CASELESS_RESTRICT)(*CR)a'];
        yield '(*CR) after (*UTF)(*TURKISH_CASING)' => ['source' => '(*UTF)(*TURKISH_CASING)(*CR)a'];
    }

    #[Test]
    #[DataProvider('provideNewlinesAfterACasingSetting')]
    public function test_newline_reads_past_a_casing_setting(string $source): void
    {
        $this->requiresPcre2('10.45', $source);

        $dotOnNewline = substr($source, 0, -1).'^.$';
        $this->assertSame(1, preg_match('/'.$dotOnNewline.'/', "\n"));
        $this->assertSame(0, preg_match('/^.$/', "\n"));

        $this->assertSame('CR', StartOptions::newline($source));
    }

    /**
     * "(*UTF)" written after "(*TURKISH_CASING)" still turns UTF mode on:
     * the dot takes "é" whole.
     */
    #[Test]
    public function test_utf_after_turkish_casing_turns_utf_on(): void
    {
        $this->requiresPcre2('10.45', '(*TURKISH_CASING)(*UTF)');

        $this->assertSame(1, preg_match('/(*TURKISH_CASING)(*UTF)^.$/', 'é'));

        $this->assertTrue(StartOptions::turnUtfOn('(*TURKISH_CASING)(*UTF)a'));
    }
}
