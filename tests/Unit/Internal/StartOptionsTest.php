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

namespace PHPRegex\Tests\Unit\Internal;

use PHPRegex\Parser\Internal\StartOptions;
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
        // Oracle: PHP's PCRE compiles the option and matches past it.
        $this->assertSame(1, preg_match('/'.$options.'a/', 'a'));

        $this->assertSame($options, StartOptions::of($options.'a'));
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
        $this->assertSame(1, preg_match('/(*TURKISH_CASING)(*UTF)^.$/', 'é'));

        $this->assertTrue(StartOptions::turnUtfOn('(*TURKISH_CASING)(*UTF)a'));
    }
}
