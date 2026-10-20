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
 * Whether a "(" opens an alphabetic name, "(*pla:", is told from the
 * characters after it alone: the rest of the pattern is not searched for a
 * ":" at each "(".
 *
 * A regex whose match must end in a given character, as "(*name:" ends in
 * ":", has PCRE2 search the subject for that character before it tries the
 * match. Up to PCRE2 10.44 the JIT searches to the end of the subject, and
 * a pattern with fewer ":" than groups was searched to its end at each "("
 * and each "(*" no ")" closes.
 */
final class GroupOpenerRunTest extends TestCase
{
    use LinearTimeAssertions;

    /**
     * Measured with PCRE2 10.44 and its JIT on arm64 when the rest of the
     * pattern was searched at each "(": 16 000 then 64 000 "(a)" took
     * 0.21 s then 2.4 s, eleven times as long for four times the text.
     */
    #[Test]
    #[DataProvider('provideRunsOfGroupOpeners')]
    public function test_lexer_reads_a_run_of_group_openers_in_linear_time(string $unit, int $size): void
    {
        $this->assertLinearTime(
            static function (int $size) use ($unit): void {
                try {
                    (new Lexer())->tokenize(str_repeat($unit, $size));
                } catch (LexerException) {
                    // A refusal ends the reading: only its time counts.
                }
            },
            $size,
            $unit,
        );
    }

    /**
     * @return iterable<string, array{unit: string, size: int}>
     */
    public static function provideRunsOfGroupOpeners(): iterable
    {
        yield 'groups' => ['unit' => '(a)', 'size' => 16_000];
        yield 'conditions left open' => ['unit' => '(?(', 'size' => 16_000];
        yield 'settings with no ")"' => ['unit' => '(*LIMIT_MATCH', 'size' => 4_000];
        yield 'unclosed verbs after a letter' => ['unit' => 'a(*', 'size' => 16_000];
    }
}
