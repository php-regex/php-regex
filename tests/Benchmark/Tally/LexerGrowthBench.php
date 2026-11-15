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

namespace PHPRegex\Tests\Benchmark\Tally;

use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Lexer;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Tests\Benchmark\Tally\Support\Ladders;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Warmup;

/**
 * Runs the lexer once read in quadratic time, each opener looking for its
 * end over the rest of the pattern again. Every run must cost instructions
 * in proportion to its length. A refusal is fine: only its cost counts.
 *
 * The warm-up call loads the code and the lexer's process-wide tables, so
 * the counted call reads the run alone.
 */
#[Warmup(1)]
final class LexerGrowthBench
{
    use Ladders;

    /**
     * "(*" that no ")" closes: 30 000 then 60 000 took 0.83 s then 2.8 s.
     */
    #[DataProvider('from2000')]
    #[Linear('size')]
    public function benchUnclosedVerbs(int $size): void
    {
        self::tokenize(str_repeat('(*', $size));
    }

    /**
     * Alphabetic lookaheads each holding an unclosed "(*": 10 000 then
     * 20 000 took 0.30 s then 1.1 s.
     */
    #[DataProvider('from500')]
    #[Linear('size')]
    public function benchUnclosedVerbsInBodies(int $size): void
    {
        self::tokenize(str_repeat('(*pla:(*', $size));
    }

    /**
     * "(?C" that no ")" closes: 20 000 then 40 000 took 0.72 s then 2.4 s.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchUnclosedCallouts(int $size): void
    {
        self::tokenize(str_repeat('(?C', $size));
    }

    /**
     * The same callouts in a short lookahead: 1.3 s then 4.5 s.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchUnclosedCalloutsInABody(int $size): void
    {
        self::tokenize('(?*'.str_repeat('(?C', $size));
    }

    /**
     * Each quoted letter of a class searched the run for its "\E": 16 000
     * then 32 000 letters took 0.52 s then 2.0 s.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchQuotedRunInAClass(int $size): void
    {
        self::tokenize('[\\Q'.str_repeat('a', $size).'\\E]');
    }

    /**
     * The rest of the pattern searched at each "(": 16 000 then 64 000
     * groups took 0.21 s then 2.4 s.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchGroups(int $size): void
    {
        self::tokenize(str_repeat('(a)', $size));
    }

    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchConditionsLeftOpen(int $size): void
    {
        self::tokenize(str_repeat('(?(', $size));
    }

    #[DataProvider('from250')]
    #[Linear('size')]
    public function benchSettingsWithNoEnd(int $size): void
    {
        self::tokenize(str_repeat('(*LIMIT_MATCH', $size));
    }

    /**
     * "\p{" that no "}" closes, each one looking again for the "}" the one
     * before it did not find.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchUnclosedProperties(int $size): void
    {
        self::tokenize(str_repeat('\\p{', $size));
    }

    #[DataProvider('from500')]
    #[Linear('size')]
    public function benchUnclosedPropertiesInABody(int $size): void
    {
        self::tokenize('(*pla:'.str_repeat('\\p{', $size).')');
    }

    /**
     * Bodies turning x off around a "#", each followed by twenty escapes:
     * when the scan ignored the inline option, 500 units took 8.5 s.
     */
    #[DataProvider('from25')]
    #[Linear('size')]
    public function benchBodiesTurningXOff(int $size): void
    {
        self::tokenize(str_repeat('(?*(?-x)#)', $size)."\n".str_repeat('\\a', 20 * $size), 'x');
    }

    /**
     * A chain of bodies that never close: 1 000 then 2 000 "(?*" took
     * 0.48 s then 1.9 s.
     */
    #[DataProvider('from250')]
    #[Linear('size')]
    public function benchChainOfUnclosedBodies(int $size): void
    {
        self::tokenize(str_repeat('(?*', $size));
    }

    /**
     * A class of escapes read for PHP 7.3, whose bundled PCRE2 reads a
     * range end after an escape: each one replayed the class so far.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchLegacyClassEscapes(int $size): void
    {
        (new Lexer(PcreTarget::bundledWith(70300)))->tokenize('['.str_repeat('\\d', $size).']');
    }

    private static function tokenize(string $pattern, string $flags = ''): void
    {
        try {
            (new Lexer())->tokenize($pattern, $flags);
        } catch (LexerException) {
            // A refusal ends the reading: only its cost counts.
        }
    }
}
