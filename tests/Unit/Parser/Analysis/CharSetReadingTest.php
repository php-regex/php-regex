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

namespace PHPRegex\Tests\Unit\Parser\Analysis;

use PHPRegex\Parser\Analysis\ByteCharSet;
use PHPRegex\Parser\Analysis\CharSetAnalyzer;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\LinearTimeAssertions;
use PHPRegex\Tests\Support\PcreGates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The characters a node starts and ends with, read above ASCII as the
 * ReDoS analysis reads them: what the start options change there, and the
 * items that may match empty.
 */
final class CharSetReadingTest extends TestCase
{
    use LinearTimeAssertions;
    use PcreGates;

    /**
     * The characters the atom after the options takes, each probe asked of
     * the engine first. A probe the atom takes is in its sets, by the byte
     * it starts with. A probe of one byte, any byte without UTF and an
     * ASCII one under it, is in the sets only where the atom takes it:
     * under UTF a byte above ASCII stands for every character it starts.
     *
     * (*CASELESS_RESTRICT) holds from the start of the pattern as the r
     * modifier does, until "(?-r)" or "(?^)" turns it off: under it "k" and
     * the Kelvin sign are no pair, and "[^\x{212A}]" takes "k".
     * (*TURKISH_CASING) pairs "i" with the capital dotted I and "I" with
     * the small dotless one, not "i" with "I", unless r holds, which pairs
     * them as ASCII does. "\R" takes the line breaks the last of
     * (*BSR_ANYCRLF) and (*BSR_UNICODE) says, where "\v" stays as it is.
     *
     * @param list<string> $probes
     */
    #[Test]
    #[DataProvider('provideAtomsUnderStartOptions')]
    public function test_the_sets_read_what_the_start_options_change(string $options, string $atom, string $flags, array $probes): void
    {
        $pattern = '/'.$options.$atom.'/'.$flags;
        $this->requiresCompile($pattern);
        [$analyzer, $node] = self::atom($pattern);
        $utf = str_contains($flags, 'u');

        foreach ($probes as $probe) {
            $takes = 1 === preg_match('/'.$options.'\A(?:'.$atom.')\z/'.$flags, $probe);
            foreach (['first' => $analyzer->firstChars($node), 'last' => $analyzer->lastChars($node)] as $end => $set) {
                $holds = $set->intersects(ByteCharSet::fromChar($probe));
                $said = \sprintf('%s, %s set, probe %s: the engine %s it.', $pattern, $end, bin2hex($probe), $takes ? 'takes' : 'refuses');
                if (1 === \strlen($probe) && (!$utf || \ord($probe) < 0x80)) {
                    $this->assertSame($takes, $holds, $said);
                } elseif ($takes) {
                    $this->assertTrue($holds, $said);
                }
            }
        }
    }

    /**
     * @return iterable<string, array{options: string, atom: string, flags: string, probes: list<string>}>
     */
    public static function provideAtomsUnderStartOptions(): iterable
    {
        $cases = ['k', 'K', "\u{212A}", 'i', 'I', "\u{130}", "\u{131}", 'h', 'j'];
        $breaks = ["\n", "\r", "\v", "\f", "\x85", ' '];
        $breaksUnderUtf = ["\n", "\r", "\v", "\f", "\u{85}", "\u{2028}", ' '];

        yield 'a negated Kelvin sign' => ['options' => '', 'atom' => '[^\x{212A}]', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated Kelvin sign under r' => ['options' => '', 'atom' => '[^\x{212A}]', 'flags' => 'iur', 'probes' => $cases];
        yield 'a negated Kelvin sign under the caseless restriction' => ['options' => '(*CASELESS_RESTRICT)', 'atom' => '[^\x{212A}]', 'flags' => 'iu', 'probes' => $cases];
        yield 'the Kelvin sign under the caseless restriction' => ['options' => '(*CASELESS_RESTRICT)', 'atom' => '\x{212A}', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated Kelvin sign in a group, under the caseless restriction' => ['options' => '(*CASELESS_RESTRICT)', 'atom' => '(?:[^\x{212A}])', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated Kelvin sign after the restriction turned off' => ['options' => '(*CASELESS_RESTRICT)(?-r)', 'atom' => '[^\x{212A}]', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated Kelvin sign after a reset' => ['options' => '(*CASELESS_RESTRICT)(?^i)', 'atom' => '[^\x{212A}]', 'flags' => 'u', 'probes' => $cases];
        yield 'a negated Kelvin sign in a group that turns the restriction off' => ['options' => '(*CASELESS_RESTRICT)', 'atom' => '(?-r:[^\x{212A}])', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated Kelvin sign after the restriction turned on again' => ['options' => '(*CASELESS_RESTRICT)(?-r)(?r)', 'atom' => '[^\x{212A}]', 'flags' => 'iu', 'probes' => $cases];

        yield 'a capital I' => ['options' => '', 'atom' => 'I', 'flags' => 'iu', 'probes' => $cases];
        yield 'a capital I under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => 'I', 'flags' => 'iu', 'probes' => $cases];
        yield 'a small i under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => 'i', 'flags' => 'iu', 'probes' => $cases];
        yield 'a capital I written as an escape, under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '\x49', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated capital I under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '[^I]', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated small i under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '[^i]', 'flags' => 'iu', 'probes' => $cases];
        yield 'a range around the capital I under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '[H-J]', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated range around the small i under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '[^h-j]', 'flags' => 'iu', 'probes' => $cases];
        yield 'the small dotless i under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '\x{131}', 'flags' => 'iu', 'probes' => $cases];
        yield 'the capital dotted I under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '\x{130}', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated small dotless i under Turkish casing' => ['options' => '(*TURKISH_CASING)', 'atom' => '[^\x{131}]', 'flags' => 'iu', 'probes' => $cases];
        yield 'a capital I under Turkish casing and r' => ['options' => '(*TURKISH_CASING)(?r)', 'atom' => 'I', 'flags' => 'iu', 'probes' => $cases];
        yield 'a negated capital I under Turkish casing and r' => ['options' => '(*TURKISH_CASING)(?r)', 'atom' => '[^I]', 'flags' => 'iu', 'probes' => $cases];
        yield 'a capital I under Turkish casing, read case-sensitively' => ['options' => '(*TURKISH_CASING)', 'atom' => 'I', 'flags' => 'u', 'probes' => $cases];

        yield 'a line break' => ['options' => '', 'atom' => '\R', 'flags' => '', 'probes' => $breaks];
        yield 'a line break under (*BSR_ANYCRLF)' => ['options' => '(*BSR_ANYCRLF)', 'atom' => '\R', 'flags' => '', 'probes' => $breaks];
        yield 'a line break under (*BSR_UNICODE)' => ['options' => '(*BSR_UNICODE)', 'atom' => '\R', 'flags' => '', 'probes' => $breaks];
        yield 'a line break under (*BSR_UNICODE) after (*BSR_ANYCRLF)' => ['options' => '(*BSR_ANYCRLF)(*BSR_UNICODE)', 'atom' => '\R', 'flags' => '', 'probes' => $breaks];
        yield 'a line break under (*BSR_ANYCRLF) after (*BSR_UNICODE)' => ['options' => '(*BSR_UNICODE)(*BSR_ANYCRLF)', 'atom' => '\R', 'flags' => '', 'probes' => $breaks];
        yield 'a line break under (*BSR_ANYCRLF) and UTF' => ['options' => '(*BSR_ANYCRLF)', 'atom' => '\R', 'flags' => 'u', 'probes' => $breaksUnderUtf];
        yield 'a vertical space under (*BSR_ANYCRLF)' => ['options' => '(*BSR_ANYCRLF)', 'atom' => '\v', 'flags' => '', 'probes' => $breaks];
        yield 'a line break under (*BSR_ANYCRLF), repeated' => ['options' => '(*BSR_ANYCRLF)', 'atom' => '\R+', 'flags' => '', 'probes' => $breaks];
    }

    /**
     * The analyzer of a pattern under other flags, as the linter asks for
     * the ones in effect at a node, keeps what the start options set: the
     * caseless restriction holds as r does, "\R" takes CR and LF alone, and
     * the dot stops at the newline they set.
     */
    #[Test]
    public function test_the_analyzer_under_other_flags_keeps_what_the_start_options_set(): void
    {
        $pattern = '/(*CASELESS_RESTRICT)(*BSR_ANYCRLF)(*CR)[^\x{212A}]\R./u';
        $this->requiresCompile($pattern);
        $this->assertSame(1, preg_match('/(*CASELESS_RESTRICT)(*BSR_ANYCRLF)(*CR)\A[^\x{212A}]\z/iu', 'k'), 'Oracle: the class takes "k" under the restriction.');
        $this->assertSame(0, preg_match('/(*CASELESS_RESTRICT)(*BSR_ANYCRLF)(*CR)\A\R\z/iu', "\v"), 'Oracle: "\R" refuses a vertical tab.');
        $this->assertSame(1, preg_match('/(*CASELESS_RESTRICT)(*BSR_ANYCRLF)(*CR)\A.\z/iu', "\n"), 'Oracle: the dot takes a line feed.');

        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $this->assertInstanceOf(SequenceNode::class, $regex->pattern);
        [$class, $break, $dot] = array_values(\array_slice($regex->pattern->children, -3));
        $analyzer = CharSetAnalyzer::forRegex($regex, true)->withFlags('iu');

        $this->assertTrue($analyzer->firstChars($class)->intersects(ByteCharSet::fromChar('k')));
        $this->assertFalse($analyzer->firstChars($break)->intersects(ByteCharSet::fromChar("\v")));
        $this->assertTrue($analyzer->firstChars($dot)->intersects(ByteCharSet::fromChar("\n")));
        $this->assertFalse($analyzer->firstChars($dot)->intersects(ByteCharSet::fromChar("\r")));
        $this->assertTrue($analyzer->withFlags('iur')->firstChars($class)->intersects(ByteCharSet::fromChar('k')));

        // Without the start option, the class leaves "k" out under i.
        $this->assertSame(0, preg_match('/\A[^\x{212A}]\z/iu', 'k'), 'Oracle: without the restriction the class refuses "k".');
        $unrestricted = RegexParser::create(['cache' => null])->parse('/[^\x{212A}]/u');
        $this->assertFalse(CharSetAnalyzer::forRegex($unrestricted, true)->withFlags('iu')->firstChars($unrestricted->pattern)->intersects(ByteCharSet::fromChar('k')));
    }

    /**
     * An item that may match empty lets the item after it lead, and the
     * one before it end, whatever its count: a lookaround repeated once,
     * "(?=a){1}a", reads nothing and "a" leads, and "(a?)+b" may start with
     * "b". An item that must match stops the scan, repeated or not: "b"
     * never leads "a{1}b". Each match is asked of the engine first.
     */
    #[Test]
    #[DataProvider('provideItemsThatMayMatchEmpty')]
    public function test_an_item_that_may_match_empty_lets_the_next_one_lead(string $pattern, string $match, string $never): void
    {
        $this->assertSame(1, preg_match('/\A(?:'.substr($pattern, 1, -1).')\z/', $match), 'Oracle: '.$pattern.' takes '.$match.' whole.');
        $this->assertSame(0, preg_match('/\A(?:'.substr($pattern, 1, -1).')/', $never.$match), 'Oracle: no match of '.$pattern.' starts with '.$never.'.');

        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $analyzer = CharSetAnalyzer::forRegex($regex, true);

        $this->assertTrue($analyzer->firstChars($regex)->intersects(ByteCharSet::fromChar($match[0])), $pattern.' starts with '.$match[0]);
        $this->assertTrue($analyzer->lastChars($regex)->intersects(ByteCharSet::fromChar($match[-1])), $pattern.' ends with '.$match[-1]);
        $this->assertFalse($analyzer->firstChars($regex)->intersects(ByteCharSet::fromChar($never)), $pattern.' never starts with '.$never);
    }

    /**
     * @return iterable<string, array{pattern: string, match: string, never: string}>
     */
    public static function provideItemsThatMayMatchEmpty(): iterable
    {
        yield 'a lookahead repeated once' => ['pattern' => '/(?=a){1}a/', 'match' => 'a', 'never' => 'b'];
        yield 'a lookahead repeated at least once' => ['pattern' => '/(?=a)+a/', 'match' => 'a', 'never' => 'b'];
        yield 'a negative lookahead repeated up to three times' => ['pattern' => '/(?!x){1,3}b/', 'match' => 'b', 'never' => 'x'];
        yield 'a lookbehind repeated once' => ['pattern' => '/a(?<=a){1}/', 'match' => 'a', 'never' => 'b'];
        yield 'a group that may match empty, repeated at least once' => ['pattern' => '/(a?)+b/', 'match' => 'b', 'never' => 'c'];
        yield 'a group that may match empty, repeated at least once, at the end' => ['pattern' => '/b(a?)+/', 'match' => 'b', 'never' => 'c'];
        yield 'an empty branch repeated at least once' => ['pattern' => '/(?:a|)+b/', 'match' => 'b', 'never' => 'c'];
        yield 'a letter repeated once' => ['pattern' => '/a{1}b/', 'match' => 'ab', 'never' => 'b'];
    }

    /**
     * The start options are read once for the pattern: an option setting
     * changes the flags the analyzer reads at each node after it, and the
     * analyzer it makes for them takes the newline convention and the rest
     * as read. Read again for each setting, a run of n options and n
     * settings cost n² (500 then 2,000 of each: 41 ms then 586 ms).
     */
    #[Test]
    public function test_the_start_options_are_read_once_for_the_settings_after_them(): void
    {
        $patterns = [];
        foreach ([500, 2000] as $size) {
            $patterns[$size] = RegexParser::create(['cache' => null])->parse('/'.str_repeat('(*LF)', $size).str_repeat('(?s)a(?-s)b', $size).'/');
        }

        $this->assertLinearTime(static function (int $size) use ($patterns): void {
            CharSetAnalyzer::forRegex($patterns[$size], true)->inForce($patterns[$size]);
        }, 500, 'The sets in force after a run of options');
    }

    /**
     * The analyzer in force at the last item of the pattern, and that item.
     *
     * @return array{CharSetAnalyzer, NodeInterface}
     */
    private static function atom(string $pattern): array
    {
        $regex = RegexParser::create(['cache' => null])->parse($pattern);
        $node = $regex->pattern;
        if ($node instanceof SequenceNode) {
            $node = $node->children[\count($node->children) - 1];
        }

        $inForce = CharSetAnalyzer::forRegex($regex, true)->inForce($regex);

        return [$inForce[$node], $node];
    }
}
