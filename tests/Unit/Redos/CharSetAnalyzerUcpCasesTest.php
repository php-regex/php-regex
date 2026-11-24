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

namespace PHPRegex\Tests\Unit\Redos;

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Engine\CharacterTables;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Without UTF, (*UCP) gives a byte above ASCII the case of the character
 * of its value from PCRE2 10.35 alone: before it, the tables give the case
 * of every byte, as without (*UCP). The sets read caselessly follow the
 * release they are told, and read without one as 10.35 and later.
 */
final class CharSetAnalyzerUcpCasesTest extends TestCase
{
    /**
     * The locale of the tables a test gives.
     */
    private const GIVEN = 'xx_XX.GIVEN_UCP_CASES';

    /**
     * Without UTF, (*UCP) gives a byte above ASCII the other case of the
     * character of its value from PCRE2 10.35 alone, "\xE9" to "\xC9", as
     * the caseless item, a class or a range, an option setting or a group
     * sets i; before it, a byte has the case PCRE2's own tables give it,
     * none above ASCII, as without (*UCP), and a class leaving a byte out
     * takes its other case. A byte whose other cases are no bytes, the
     * micro sign or "\xFF", or that has none, a sign, matches itself alone
     * either side. Read without the release, the set holds what 10.35 and
     * later match. Engine: each set is what "/(*UCP)^(?:atom)$/" matches
     * of the 256 bytes, with the JIT and without it, on PCRE2 10.34
     * (php:7.4.10) for the rows of 10.34, and on the running engine, which
     * the test asks, where it reads (*UCP) as the row's release does.
     *
     * @param string|null                 $pcre   the release, null where the analyzer is not told it
     * @param list<array{0: int, 1: int}> $ranges the bytes the engine matches
     */
    #[Test]
    #[DataProvider('provideBytesUnderUcpByRelease')]
    public function test_under_ucp_a_byte_takes_the_case_of_its_character_from_pcre2_10_35(string $options, string $atom, string $flags, ?string $pcre, array $ranges): void
    {
        $pattern = '/'.$options.$atom.'/'.$flags;
        $label = addcslashes($pattern, "\200..\377").' on '.($pcre ?? 'a release not known');
        // Oracle: the bytes the running engine matches, on the JIT and on
        // the interpreter, where it reads the row's release alike.
        if (PcreTarget::runtime()->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf) === self::ucpFolds($pcre)) {
            $anchored = '/'.$options.'^(?:'.$atom.')$/'.$flags;
            foreach ([$anchored, PcreInterpreter::pattern($anchored)] as $engine) {
                $matched = ByteCharSet::empty();
                for ($byte = 0; $byte <= 0xFF; $byte++) {
                    $matched = 1 === preg_match($engine, \chr($byte)) ? $matched->union(ByteCharSet::fromBytes($byte, $byte)) : $matched;
                }

                $this->assertSame($ranges, $matched->ranges(), 'Oracle: '.addcslashes($engine, "\200..\377"));
            }
        }

        [$analyzer, $node] = self::atom($pattern, CharacterTables::BUILT_IN, self::ucpFolds($pcre));

        $this->assertSame($ranges, $analyzer->firstChars($node)->ranges(), $label);
        $this->assertSame($ranges, $analyzer->lastChars($node)->ranges(), $label);
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string, pcre: string|null, ranges: list<array{0: int, 1: int}>}>
     */
    public static function provideBytesUnderUcpByRelease(): iterable
    {
        $capital = [[0xC9, 0xC9]];
        $both = [[0xC9, 0xC9], [0xE9, 0xE9]];
        foreach ([
            'a Latin-1 capital' => ['(*UCP)', '\xC9', 'i', $capital, $both],
            'a Latin-1 capital written as it is' => ['(*UCP)', "\xC9", 'i', $capital, $both],
            'a Latin-1 capital written in octal' => ['(*UCP)', '\311', 'i', $capital, $both],
            'a class of a Latin-1 capital' => ['(*UCP)', '[\xC9]', 'i', $capital, $both],
            'a range of Latin-1 capitals' => ['(*UCP)', '[\xC0-\xDE]', 'i', [[0xC0, 0xDE]], [[0xC0, 0xDE], [0xE0, 0xF6], [0xF8, 0xFE]]],
            'a range between two signs' => ['(*UCP)', '[\xD7-\xF7]', 'i', [[0xD7, 0xF7]], [[0xC0, 0xFE]]],
            'a class leaving a Latin-1 letter out' => ['(*UCP)', '[^\xE9]', 'i', [[0x00, 0xE8], [0xEA, 0xFF]], [[0x00, 0xC8], [0xCA, 0xE8], [0xEA, 0xFF]]],
            'a Latin-1 capital after an option setting' => ['(*UCP)(?i)', '\xC9', '', $capital, $both],
            'a Latin-1 capital in a group that sets i' => ['(*UCP)', '(?i:\xC9)', '', $capital, $both],
            'a Latin-1 capital after a newline option' => ['(*CRLF)(*UCP)', '\xC9', 'i', $capital, $both],
            'a sign' => ['(*UCP)', '\xD7', 'i', [[0xD7, 0xD7]], [[0xD7, 0xD7]]],
            'the other sign' => ['(*UCP)', '\xF7', 'i', [[0xF7, 0xF7]], [[0xF7, 0xF7]]],
            'the micro sign' => ['(*UCP)', '\xB5', 'i', [[0xB5, 0xB5]], [[0xB5, 0xB5]]],
            'the small y with diaeresis' => ['(*UCP)', '\xFF', 'i', [[0xFF, 0xFF]], [[0xFF, 0xFF]]],
            'a range across the bound of ASCII' => ['(*UCP)', '[\x7F-\x80]', 'i', [[0x7F, 0x80]], [[0x7F, 0x80]]],
            'a Latin-1 capital, case-sensitive' => ['(*UCP)', '\xC9', '', $capital, $capital],
            'a Latin-1 capital without (*UCP)' => ['', '\xC9', 'i', $capital, $capital],
        ] as $name => [$options, $atom, $flags, $before, $from]) {
            yield $name.', on 10.34' => ['options' => $options, 'atom' => $atom, 'flags' => $flags, 'pcre' => '10.34', 'ranges' => $before];
            yield $name.', on 10.35' => ['options' => $options, 'atom' => $atom, 'flags' => $flags, 'pcre' => '10.35', 'ranges' => $from];
            yield $name.', on a release not known' => ['options' => $options, 'atom' => $atom, 'flags' => $flags, 'pcre' => null, 'ranges' => $from];
        }
    }

    /**
     * Before PCRE2 10.35, (*UCP) without UTF leaves the case of every byte
     * to the tables, as without it: the given tables pair 0xF2 with 0xD3,
     * 0xDD with "i" and 0x80 with "q" there, where from 10.35 0xF2 takes
     * Unicode's 0xD2 and 0xDD 0xFD, and a byte the tables or the
     * interpreter read otherwise is not known. Under u the case of a
     * character above ASCII is Unicode's on every release. Engine: on
     * PCRE2 10.34 (php:7.4.10), with the JIT and without it, glibc's
     * el_GR.ISO-8859-7 pairs 0xF2 with 0xD3 under (*UCP) as without it,
     * ru_RU.KOI8-R 0xD7 with 0xF7, and from 10.35 (*UCP) pairs 0xF2 with
     * 0xD2 under the first and leaves 0xD7 alone under the second.
     *
     * @param list<array{0: int, 1: int}>|null $ranges the bytes of the set, null where it is not known
     */
    #[Test]
    #[DataProvider('provideReadsUnderGivenTablesByRelease')]
    public function test_read_under_given_tables_ucp_leaves_the_case_of_a_byte_to_them_before_pcre2_10_35(string $pattern, string $pcre, ?array $ranges): void
    {
        self::giveTables();
        [$analyzer, $node] = self::atom($pattern, self::GIVEN, self::ucpFolds($pcre));
        $set = $analyzer->firstChars($node);

        if (null === $ranges) {
            $this->assertTrue($set->isUnknown(), $pattern.' on '.$pcre);

            return;
        }

        $this->assertSame($ranges, $set->ranges(), $pattern.' on '.$pcre);
        $this->assertSame($ranges, $analyzer->lastChars($node)->ranges(), $pattern.' on '.$pcre);
    }

    /**
     * @return iterable<string, array{pattern: string, pcre: string, ranges: list<array{0: int, 1: int}>|null}>
     */
    public static function provideReadsUnderGivenTablesByRelease(): iterable
    {
        foreach ([
            'a byte above ASCII under UCP' => ['/(*UCP)\xF2/i', [[0xD3, 0xD3], [0xF2, 0xF2]], [[0xD2, 0xD2], [0xF2, 0xF2]]],
            'a byte paired with an ASCII letter under UCP' => ['/(*UCP)\xDD/i', [[0x69, 0x69], [0xDD, 0xDD]], [[0xDD, 0xDD], [0xFD, 0xFD]]],
            'a range across the bound of ASCII under UCP' => ['/(*UCP)[\x7F-\x80]/i', [[0x71, 0x71], [0x78, 0x78], [0x7F, 0x80]], [[0x78, 0x78], [0x7F, 0x80]]],
            'a byte the interpreter reads otherwise under UCP' => ['/(*UCP)\xB5/i', null, [[0xB5, 0xB5]]],
            'a byte a release reads otherwise under UCP' => ['/(*UCP)\xFF/i', null, [[0xFF, 0xFF]]],
            'an ASCII letter under UCP' => ['/(*UCP)i/i', [[0x69, 0x69], [0xDD, 0xDD]], [[0x69, 0x69], [0xDD, 0xDD]]],
            'a byte above ASCII after an option setting under UCP' => ['/(*UCP)(?i)\xF2/', [[0xD3, 0xD3], [0xF2, 0xF2]], [[0xD2, 0xD2], [0xF2, 0xF2]]],
            'a byte above ASCII without UCP' => ['/\xF2/i', [[0xD3, 0xD3], [0xF2, 0xF2]], [[0xD3, 0xD3], [0xF2, 0xF2]]],
            'a letter above ASCII under UCP and u' => ['/(*UCP)\xF2/iu', [[0x80, 0xFF]], [[0x80, 0xFF]]],
        ] as $name => [$pattern, $before, $from]) {
            yield $name.', on 10.34' => ['pattern' => $pattern, 'pcre' => '10.34', 'ranges' => $before];
            yield $name.', on 10.35' => ['pattern' => $pattern, 'pcre' => '10.35', 'ranges' => $from];
        }
    }

    /**
     * Gives the tables of GIVEN: ASCII's cases but for "i", paired with
     * 0xDD, "I" with 0xFD, "x" with 0x7F and "q" with 0x80; 0xF2 with 0xD3
     * as Greek's final sigma, whose other case is 0xF3; 0xB5 read
     * otherwise by the interpreter, and 0xFF by the tables before 10.43.
     */
    private static function giveTables(): void
    {
        $others = [0x69 => 0xDD, 0xDD => 0x69, 0x49 => 0xFD, 0xFD => 0x49, 0x78 => 0x7F, 0x7F => 0x78, 0x71 => 0x80, 0x80 => 0x71, 0xF2 => 0xD3, 0xD3 => 0xF3, 0xF3 => 0xD3];
        $cases = '';
        for ($byte = 0; $byte < 256; $byte++) {
            $cases .= \chr($others[$byte] ?? (Ascii::isAlpha(\chr($byte)) ? $byte ^ 0x20 : $byte));
        }

        $before = $cases;
        $before[0xFF] = 'x';
        CharacterTables::useTables(self::GIVEN, $cases, [0xB5], $before);
    }

    /**
     * The analyzer of the pattern read above ASCII under the tables of
     * $locale, and its last atom: the one after the options it opens with.
     *
     * @param bool $ucpFoldsPastAscii whether the release reads the case of a byte under (*UCP) as 10.35 and later do
     *
     * @return array{CharSetAnalyzer, NodeInterface}
     */
    private static function atom(string $pattern, string $locale, bool $ucpFoldsPastAscii): array
    {
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $node = $regex->pattern;
        if ($node instanceof SequenceNode) {
            $node = $node->children[\count($node->children) - 1];
        }

        $inForce = CharSetAnalyzer::forRegex($regex, true, $locale, $ucpFoldsPastAscii)->inForce($regex);

        return [$inForce[$node], $node];
    }

    /**
     * Whether the release gives a byte under (*UCP) the case of the
     * character of its value; true where it is not known, as the analyzer
     * reads it then.
     */
    private static function ucpFolds(?string $pcre): bool
    {
        return null === $pcre || (new PcreTarget(\PHP_VERSION_ID, $pcre))->supports(PcreFeature::UcpFoldsPastAsciiWithoutUtf);
    }
}
