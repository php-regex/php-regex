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

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Tests\Benchmark\Support\ColdStart;
use PHPRegex\Tests\Benchmark\Tally\Support\Ladders;
use PHPRegex\Toolkit\Regex;
use Tally\Attribute\DataProvider;
use Tally\Attribute\Linear;
use Tally\Attribute\Quadratic;
use Tally\Attribute\Warmup;
use Tally\Meter;

/**
 * Patterns the parser, the validator and the analyses once read again for
 * each level, body or comment. Each counted call starts from a parser with
 * no cache and the library's caches emptied, so it reads the whole pattern;
 * the warm-up call before it loads the code and the process-wide tables.
 */
#[Warmup(1)]
final class ParserGrowthBench
{
    use Ladders;

    public function __construct(private readonly Meter $meter) {}

    /**
     * Each level read its body again: 500 then 1 000 nested "(*pla:a"
     * parsed in 0.26 s then 0.96 s.
     */
    #[DataProvider('from100')]
    #[Linear('size')]
    public function benchNestedAlphabeticAssertions(int $size): void
    {
        self::parse('/'.str_repeat('(*pla:a', $size).str_repeat(')', $size).'/');
    }

    /**
     * Text after each nested body, "(*pla:a(*pla:a b) b)": 500 then 1 000
     * levels parsed in 0.71 s then 3.7 s.
     */
    #[DataProvider('from100')]
    #[Linear('size')]
    public function benchNestedAssertionsFollowedByText(int $size): void
    {
        self::parse('/'.str_repeat('(*pla:a', $size).str_repeat('b)', $size).'/');
    }

    /**
     * The options the pattern opens with searched again for a ")" for each
     * body: 1 000 then 4 000 bodies after a long first one parsed in 0.10 s
     * then 0.95 s.
     */
    #[DataProvider('from250')]
    #[Linear('size')]
    public function benchBodiesAfterALongFirstBody(int $size): void
    {
        self::longPatterns()->parse('/(*pla:#'.str_repeat('x', 50 * $size)."\n)".str_repeat('(*pla:b)', $size).'/x');
    }

    /**
     * Each class read alone before the error measured the whole pattern
     * again: 4 000 then 16 000 short lookaheads holding a POSIX class took
     * 0.75 s then 7.3 s.
     */
    #[DataProvider('from250')]
    #[Linear('size')]
    public function benchClassBodiesBeforeAnError(int $size): void
    {
        self::validate('/'.str_repeat('(?*[[:alpha:]])', $size).')/');
    }

    /**
     * Each class read alone copied the options the pattern opens with:
     * 4 000 then 16 000 "(*UCP)" before as many classes took 1.2 s then 12 s.
     */
    #[DataProvider('from250')]
    #[Linear('size')]
    public function benchOpeningOptionsBeforeClasses(int $size): void
    {
        self::longPatterns()->validate('/'.str_repeat('(*UCP)', $size).str_repeat('[a]', $size).')/');
    }

    /**
     * Version conditions no ")" closes: 500 then 2 000 took 0.09 s then
     * 1.2 s, and 9 600 took 24 s.
     */
    #[DataProvider('from100')]
    #[Linear('size')]
    public function benchUnclosedVersionConditions(int $size): void
    {
        self::validate('/'.str_repeat('(?(VERSION>=10.4', $size).'/');
    }

    /**
     * Each lookbehind measured again for each one around it: 120 then 240
     * nested took 0.047 s then 0.16 s.
     */
    #[DataProvider('from25')]
    #[Linear('size')]
    public function benchNestedLookbehinds(int $size): void
    {
        self::validate('/'.str_repeat('(?<=a', $size).'(?</');
    }

    /**
     * The same, each lookbehind holding a call: 0.027 s then 0.26 s.
     */
    #[DataProvider('from25')]
    #[Linear('size')]
    public function benchNestedLookbehindsHoldingACall(int $size): void
    {
        self::validate('/(a)'.str_repeat('(?<=(?1)', $size).'(?</');
    }

    /**
     * A quoted run in the class of an alphabetic lookahead: 16 000 then
     * 32 000 letters took 0.66 s then 2.3 s. From 1 000 to 2 000 letters
     * the count grows 2.2 times, then twice for each doubling: the ladder
     * starts at 2 000.
     */
    #[DataProvider('from2000')]
    #[Linear('size')]
    public function benchQuotedRunInAClassInABody(int $size): void
    {
        self::validate('/(*pla:[\\Q'.str_repeat('a', $size).'\\E])/');
    }

    /**
     * Comments before a quantifier, each one put at the front of the list
     * of those collected so far.
     */
    #[DataProvider('from500')]
    #[Linear('size')]
    public function benchCommentsBeforeAQuantifier(int $size): void
    {
        $body = 'a';
        for ($index = 0; $index < $size; $index++) {
            $body .= '(?#'.$index.')';
        }

        ColdStart::regex()->parse('/'.$body.'+/');
    }

    /**
     * A run of literals merged into one, each merge copying the run so far.
     */
    #[DataProvider('from1000')]
    #[Linear('size')]
    public function benchLiteralRun(int $size): void
    {
        (new HirTranslator())->translate(ColdStart::regex()->parse('/'.str_repeat("\u{e9}", $size).'z/u'));
    }

    /**
     * Each level a sequence whose first part is the level below: a second
     * reading of the parts at every level doubled the cost per level. The
     * analysis still grows as the square of the depth, which PCRE caps at
     * 250 levels: the class keeps it from growing any faster. The parse is
     * not counted.
     */
    #[DataProvider('from25')]
    #[Quadratic('size')]
    public function benchNestedAnchoredSequences(int $size): void
    {
        $tree = $this->meter->exclude(static fn (): RegexNode => ColdStart::regex()->parser()->parse('/'.str_repeat('(', $size).'^'.str_repeat('a$)', $size).'/'));
        (new PatternInfoAnalyzer())->analyze($tree);
    }

    /**
     * A chain of groups, each calling the one below: following the calls of
     * each level to the end of the chain grew with the square of the levels.
     */
    #[DataProvider('from25')]
    #[Linear('size')]
    public function benchChainOfCalls(int $size): void
    {
        $groups = '(?<g0>a)';
        for ($number = 1; $number <= $size; $number++) {
            $groups .= '(?<g'.$number.'>(?&g'.($number - 1).'))';
        }

        (new CaptureShapeAnalyzer())->analyze(ColdStart::regex()->parser()->parse('/(?(DEFINE)'.$groups.')(?&g'.$size.')/'));
    }

    private static function parse(string $pattern): void
    {
        try {
            ColdStart::regex()->parse($pattern);
        } catch (RegexException) {
            // A refusal ends the reading: only its cost counts.
        }
    }

    private static function validate(string $pattern): void
    {
        ColdStart::regex()->validate($pattern);
    }

    private static function longPatterns(): Regex
    {
        $regex = Regex::create(['cache' => null, 'max_pattern_length' => 1_000_000]);
        $regex->clearCaches();

        return $regex;
    }
}
