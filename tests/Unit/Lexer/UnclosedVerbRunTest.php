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
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A "(*" that no ")" closes is read once: the ones after it are not each
 * read again up to the end of the pattern.
 */
final class UnclosedVerbRunTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * Measured when each unclosed "(*" was read to the end again: 30 000
     * then 60 000 "(*" took 0.83 s then 2.8 s, and 10 000 then 20 000
     * "(*pla:(*" 0.30 s then 1.1 s, nearly four times as long for twice
     * the text. A refusal is fine, as long as it comes fast.
     */
    #[Test]
    #[DataProvider('provideRunsOfUnclosedVerbs')]
    public function test_lexer_reads_a_run_of_unclosed_verbs_in_linear_time(string $prefix, string $unit, int $size): void
    {
        $this->assertLinearTime(
            static function (int $size) use ($prefix, $unit): void {
                try {
                    (new Lexer())->tokenize($prefix.str_repeat($unit, $size));
                } catch (LexerException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            $size,
            $prefix.$unit,
        );
    }

    /**
     * @return iterable<string, array{prefix: string, unit: string, size: int}>
     */
    public static function provideRunsOfUnclosedVerbs(): iterable
    {
        yield 'bare openers' => ['prefix' => '', 'unit' => '(*', 'size' => 30_000];
        yield 'openers with a colon' => ['prefix' => '', 'unit' => '(*:', 'size' => 20_000];
        yield 'openers after a letter' => ['prefix' => '', 'unit' => 'a(*', 'size' => 20_000];
        yield 'openers in an alphabetic lookahead' => ['prefix' => '(*pla:', 'unit' => '(*', 'size' => 30_000];
        yield 'openers in a short lookahead' => ['prefix' => '(?*', 'unit' => '(*', 'size' => 20_000];
        yield 'alphabetic lookaheads each holding an opener' => ['prefix' => '', 'unit' => '(*pla:(*', 'size' => 10_000];
    }
}
