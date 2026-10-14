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

namespace PHPRegex\Tests\Unit\Parser\Printer;

use PHPRegex\Parser\Cache\NullCache;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\FormattingException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharLiteralType;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\FormatRefusal;
use PHPRegex\Parser\Printer\FormatResult;
use PHPRegex\Parser\Printer\PatternFormatter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PatternEquivalence;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every expected layout below is checked against PCRE as well as compared
 * byte for byte: the output must compile, parse back to the input's tree,
 * keep the input's flags (give or take "x") and match every subject the way
 * the input does. Semantics verified on PHP 8.4.26 / PCRE2 10.49.
 */
final class PatternFormatterTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFittingPatterns')]
    public function test_format_returns_a_fitting_pattern_byte_for_byte(string $pattern, int $width): void
    {
        $result = (new PatternFormatter($width))->format($this->parse($pattern));

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertNull($result->refusal);
    }

    #[Test]
    #[DataProvider('provideWidthBoundaries')]
    public function test_format_breaks_past_the_width(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideNestingDepths')]
    public function test_format_breaks_past_two_nested_groups(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideGroupLayouts')]
    public function test_format_closes_a_broken_group_on_its_own_line_with_its_quantifier(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideSequences')]
    public function test_format_keeps_atom_runs_together_and_puts_each_group_on_its_line(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideAlternations')]
    public function test_format_hangs_the_bar_of_each_branch_in_the_indent(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideStartVerbs')]
    public function test_format_keeps_start_of_pattern_verbs_on_the_delimiter_line(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideDelimiters')]
    public function test_format_keeps_the_delimiter_and_the_flags_and_appends_x(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideRespellings')]
    public function test_format_respells_what_an_added_x_would_skip(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideSpellings')]
    public function test_format_keeps_the_author_spelling(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideSeparators')]
    public function test_format_separates_tokens_a_layout_would_fuse(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideComments')]
    public function test_format_keeps_every_comment(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideExtendedModeScopes')]
    public function test_format_keeps_a_region_without_x_on_one_line(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideGroupKinds')]
    public function test_format_lays_out_every_kind_of_group(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideRefusalNeighbours')]
    public function test_format_does_not_refuse_what_it_can_lay_out_safely(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideRefusals')]
    public function test_format_refuses_with_the_input_unchanged_and_a_reason(string $pattern, int $width, string $refusal): void
    {
        $result = (new PatternFormatter($width))->format($this->parse($pattern));

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::from($refusal), $result->refusal);
    }

    /**
     * Past ten indent levels the rest stays on one line: two hundred nested
     * groups laid out one per line would pass the length the parser reads
     * back (testinput2:1653).
     */
    #[Test]
    public function test_format_keeps_deep_nesting_on_one_line_past_ten_indent_levels(): void
    {
        $pattern = '/'.str_repeat('(', 200).'a'.str_repeat(')', 200).'/';
        $lines = ['/'];
        for ($level = 1; $level <= 9; $level++) {
            $lines[] = str_repeat('    ', $level).'(';
        }
        $lines[] = str_repeat('    ', 10).str_repeat('(', 191).'a'.str_repeat(')', 191);
        for ($level = 9; $level >= 1; $level--) {
            $lines[] = str_repeat('    ', $level).')';
        }
        $lines[] = '/x';

        $this->assertFormatsTo($pattern, 80, implode("\n", $lines));
    }

    #[Test]
    public function test_format_keeps_two_hundred_nested_groups_under_the_parser_length_limit(): void
    {
        $pattern = '/'.str_repeat('(?:', 200).'a'.str_repeat(')', 200).'/';

        $result = (new PatternFormatter(1))->format($this->parse($pattern));

        $this->assertLessThan(RegexParser::DEFAULT_MAX_PATTERN_LENGTH, \strlen($result->pattern));
        $this->assertSameMeaning($pattern, $result->pattern);
    }

    #[Test]
    #[DataProvider('provideEveryFormattedPattern')]
    public function test_format_is_idempotent(string $pattern, int $width): void
    {
        $formatter = new PatternFormatter($width);
        $once = $formatter->format($this->parse($pattern))->pattern;
        $twice = $formatter->format($this->parse($once));

        $this->assertSame($once, $twice->pattern);
        $this->assertFalse($twice->changed);
    }

    /**
     * Compacting the layout gives the pattern compacting the input gives,
     * tree for tree; the bytes may differ, as the layout respelled what "x"
     * would have skipped.
     */
    #[Test]
    #[DataProvider('provideEveryFormattedPattern')]
    public function test_compact_undoes_the_layout_up_to_spelling(string $pattern, int $width): void
    {
        $compactOfFormat = (new PatternFormatter())->compact($this->parse((new PatternFormatter($width))->format($this->parse($pattern))->pattern))->pattern;
        $compact = (new PatternFormatter())->compact($this->parse($pattern))->pattern;

        $this->assertSame(PatternEquivalence::structure($this->parse($compact)), PatternEquivalence::structure($this->parse($compactOfFormat)));
        $this->assertSame($this->parse($compact)->flags, $this->parse($compactOfFormat)->flags);
        $this->assertSameMeaning($pattern, $compactOfFormat);
    }

    /**
     * An editor that strips trailing spaces must not change the pattern: no
     * line may end in whitespace the pattern needs.
     */
    #[Test]
    #[DataProvider('provideWhitespaceAtLineEnds')]
    public function test_format_ends_no_line_in_significant_whitespace(string $pattern, int $width): void
    {
        $formatted = (new PatternFormatter($width))->format($this->parse($pattern))->pattern;
        $stripped = implode("\n", array_map(rtrim(...), explode("\n", $formatted)));

        $this->assertSameMeaning($pattern, $stripped);
    }

    /**
     * Every line break is layout, so indenting the continuation lines (as an
     * editor does inside a string literal) must not change the pattern.
     */
    #[Test]
    #[DataProvider('provideEveryFormattedPattern')]
    public function test_format_output_survives_a_reindentation(string $pattern, int $width): void
    {
        $formatted = (new PatternFormatter($width))->format($this->parse($pattern))->pattern;
        $reindented = str_replace("\n", "\n        ", $formatted);

        $this->assertSameMeaning($pattern, $reindented);
    }

    #[Test]
    #[DataProvider('provideLessCommonLayouts')]
    public function test_format_lays_out_the_less_common_shapes(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
    }

    #[Test]
    #[DataProvider('provideCompactions')]
    public function test_compact_writes_one_line_without_x_layout_or_comments(string $pattern, int $width, string $expected): void
    {
        $result = (new PatternFormatter($width))->compact($this->parse($pattern));

        $this->assertSame($expected, $result->pattern);
        $this->assertSame($expected !== $pattern, $result->changed);
        $this->assertNull($result->refusal);
        $this->assertStringNotContainsString("\n", $result->pattern);
        $this->assertSameMeaning($pattern, $result->pattern);
    }

    #[Test]
    #[DataProvider('provideEveryCompactedPattern')]
    public function test_compact_is_idempotent(string $pattern): void
    {
        $formatter = new PatternFormatter();
        $once = $formatter->compact($this->parse($pattern))->pattern;
        $twice = $formatter->compact($this->parse($once));

        $this->assertSame($once, $twice->pattern);
        $this->assertFalse($twice->changed);
    }

    /**
     * The formatter reads its own output back and compares it with its
     * input: a tree that does not say what its own text says makes the two
     * disagree, and the formatter throws rather than emit a wrong pattern.
     * It checks an unchanged result too.
     */
    #[Test]
    #[DataProvider('provideTreesThatDisagreeWithTheirText')]
    public function test_format_and_compact_throw_rather_than_emit_a_different_pattern(RegexNode $regex, string $operation): void
    {
        $formatter = new PatternFormatter();

        try {
            if ('format' === $operation) {
                $formatter->format($regex);
            } else {
                $formatter->compact($regex);
            }

            $this->fail(\sprintf('%s() emitted a pattern its own check does not read back.', $operation));
        } catch (FormattingException $exception) {
            $this->assertSame(ErrorCode::FormattingUnsafe, $exception->errorCode);
            $this->assertSame('regex.formatting.unsafe', $exception->errorCode->value);
            $this->assertInstanceOf(RegexException::class, $exception);
        }
    }

    #[Test]
    #[DataProvider('provideInvalidWidths')]
    public function test_construct_refuses_a_width_below_one(int $width): void
    {
        $this->expectException(InvalidRegexOptionException::class);

        new PatternFormatter($width);
    }

    #[Test]
    public function test_format_uses_a_width_of_80_by_default(): void
    {
        $pattern = '#^(?<scheme>https?)://(?:(?<user>[^:@/]+)(?::(?<pass>[^@/]*))?@)?(?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)(?::(?<port>\d+))?(?<path>/[^?\#]*)?(?:\?(?<query>[^\#]*))?(?:\#(?<fragment>.*))?$#i';

        $this->assertFormatsTo($pattern, null, self::lines(
            '#',
            '    ^',
            '    (?<scheme>https?)',
            '    ://',
            '    (?:',
            '        (?<user>[^:@/]+)',
            '        (?::(?<pass>[^@/]*))?',
            '        @',
            '    )?',
            '    (?<host>\[[0-9a-f:.]+\]|[^:/?\#]+)',
            '    (?::(?<port>\d+))?',
            '    (?<path>/[^?\#]*)?',
            '    (?:\?(?<query>[^\#]*))?',
            '    (?:\#(?<fragment>.*))?',
            '    $',
            '#ix',
        ));
        $this->assertSame('/'.str_repeat('a', 78).'/', (new PatternFormatter())->format($this->parse('/'.str_repeat('a', 78).'/'))->pattern);
    }

    #[Test]
    public function test_one_formatter_carries_no_state_from_one_pattern_to_the_next(): void
    {
        $formatter = new PatternFormatter(1);
        $refused = '/^alpha(?-x) b c(?:dd|ee)$/x';
        $laidOut = '/(?:ab)c/';

        $first = $formatter->format($this->parse($refused));
        $second = $formatter->format($this->parse($laidOut));
        $third = $formatter->format($this->parse($refused));

        $this->assertSame(FormatRefusal::ExtendedModeSwitchedOff, $first->refusal);
        $this->assertNull($second->refusal);
        $this->assertSame((new PatternFormatter(1))->format($this->parse($laidOut))->pattern, $second->pattern);
        $this->assertSame(self::lines('/', '    (?:', '        ab', '    )', '    c', '/x'), $second->pattern);
        $this->assertEquals($first, $third);
    }

    #[Test]
    public function test_format_result_holds_the_pattern_whether_it_changed_and_why_it_was_refused(): void
    {
        $result = new FormatResult('/a/', false);

        $this->assertSame('/a/', $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertNull($result->refusal);
        $this->assertTrue((new \ReflectionClass(FormatResult::class))->isReadOnly());
        $this->assertSame(FormatRefusal::SpacedQuantifier, (new FormatResult('/a{ 1}/', false, FormatRefusal::SpacedQuantifier))->refusal);
    }

    #[Test]
    public function test_format_refusal_values_are_stable(): void
    {
        $this->assertSame(
            ['newline_convention', 'spaced_quantifier', 'extended_mode_switched_off'],
            array_map(static fn (FormatRefusal $refusal): string => $refusal->value, FormatRefusal::cases()),
        );
    }

    /**
     * @return iterable<string, array{pattern: string, width: int}>
     */
    public static function provideFittingPatterns(): iterable
    {
        yield 'a short literal' => ['pattern' => '/abc/', 'width' => 80];
        yield 'another delimiter and a flag' => ['pattern' => '#^(?:a|b)+$#i', 'width' => 80];
        yield 'a one-line x pattern with a comment' => ['pattern' => '/a b # c/x', 'width' => 80];
        yield 'the empty pattern' => ['pattern' => '//', 'width' => 80];
        yield 'a lone comment' => ['pattern' => '/#/x', 'width' => 80];
        yield 'escapes spelled as written' => ['pattern' => '/\x41\101\o{102}(?P<n>a)/', 'width' => 80];
        yield 'two nested groups' => ['pattern' => '/(?:a(?:b))/', 'width' => 80];
        yield 'a spaced quantifier is not refused when nothing changes' => ['pattern' => '/^a{ 1,2 }$/', 'width' => 80];
        yield 'a top-level (?-x) is not refused when nothing changes' => ['pattern' => '/a(?-x) b/x', 'width' => 80];
        yield 'a comment under (*CR) is not refused when nothing changes' => ['pattern' => '/(*CR)a # c/x', 'width' => 80];
        yield 'exactly at a small width' => ['pattern' => '/(?:abc)(?:def)/', 'width' => 16];
    }

    /**
     * Width counts code points under u or a leading (*UTF), bytes otherwise:
     * "/(?:éé)(?:éé)/" is 14 code points and 18 bytes.
     *
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideWidthBoundaries(): iterable
    {
        yield 'exactly at the width stays' => ['pattern' => '/(?:abc)(?:def)/', 'width' => 16, 'expected' => '/(?:abc)(?:def)/'];
        yield 'one past the width breaks' => ['pattern' => '/(?:abc)(?:def)/', 'width' => 15, 'expected' => self::lines('/', '    (?:abc)', '    (?:def)', '/x')];
        yield 'a construct exactly at the width, indent included, stays' => ['pattern' => '/(?:abcdefgh)(?:ijklmnop)/', 'width' => 16, 'expected' => self::lines('/', '    (?:abcdefgh)', '    (?:ijklmnop)', '/x')];
        yield 'a construct one past the width, indent included, breaks' => ['pattern' => '/(?:abcdefgh)(?:ijklmnop)/', 'width' => 15, 'expected' => self::lines('/', '    (?:', '        abcdefgh', '    )', '    (?:', '        ijklmnop', '    )', '/x')];
        yield 'under u, code points exactly at the width stay' => ['pattern' => '/(?:éé)(?:éé)/u', 'width' => 15, 'expected' => '/(?:éé)(?:éé)/u'];
        yield 'under u, one code point past the width breaks' => ['pattern' => '/(?:éé)(?:éé)/u', 'width' => 14, 'expected' => self::lines('/', '    (?:éé)', '    (?:éé)', '/ux')];
        yield 'under (*UTF), code points exactly at the width stay' => ['pattern' => '/(*UTF)(?:éé)(?:éé)/', 'width' => 20, 'expected' => '/(*UTF)(?:éé)(?:éé)/'];
        yield 'under (*UTF), one code point past the width breaks' => ['pattern' => '/(*UTF)(?:éé)(?:éé)/', 'width' => 19, 'expected' => self::lines('/(*UTF)', '    (?:éé)', '    (?:éé)', '/x')];
        yield 'in byte mode, bytes exactly at the width stay' => ['pattern' => '/(?:éé)(?:éé)/', 'width' => 18, 'expected' => '/(?:éé)(?:éé)/'];
        yield 'in byte mode, one byte past the width breaks' => ['pattern' => '/(?:éé)(?:éé)/', 'width' => 17, 'expected' => self::lines('/', '    (?:éé)', '    (?:éé)', '/x')];
        yield 'in byte mode, fitting in code points is not fitting' => ['pattern' => '/(?:éé)(?:éé)/', 'width' => 14, 'expected' => self::lines('/', '    (?:éé)', '    (?:éé)', '/x')];
        yield 'a laid-out pattern that fits joins one line' => ['pattern' => "/\n    ^(?:a|b)\n    c\$\n/x", 'width' => 80, 'expected' => '/^(?:a|b)c$/x'];
        yield 'a lone atom wider than the width' => ['pattern' => '/a/', 'width' => 1, 'expected' => self::lines('/', '    a', '/x')];
        yield 'a run of atoms wider than the width stays together' => ['pattern' => '/abc/i', 'width' => 1, 'expected' => self::lines('/', '    abc', '/ix')];
        yield 'the empty pattern at width 1 has nothing to lay out' => ['pattern' => '//', 'width' => 1, 'expected' => '//'];
        yield 'start options alone at width 1 have nothing to lay out' => ['pattern' => '/(*UTF)(*CR)/', 'width' => 1, 'expected' => '/(*UTF)(*CR)/'];
        yield 'a lone comment at width 1' => ['pattern' => '/#/x', 'width' => 1, 'expected' => self::lines('/', '    #', '/x')];
    }

    /**
     * Lookarounds, conditionals, "(?|", atomic groups and DEFINE count as
     * groups; classes do not.
     *
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideNestingDepths(): iterable
    {
        yield 'three nested groups break' => ['pattern' => '/(?:a(?:b(?:c)))/', 'width' => 80, 'expected' => self::lines('/', '    (?:', '        a', '        (?:b(?:c))', '    )', '/x')];
        yield 'two nested groups stay' => ['pattern' => '/(?:a(?:b))/', 'width' => 80, 'expected' => '/(?:a(?:b))/'];
        yield 'three nested lookaheads break' => ['pattern' => '/(?=a(?=b(?=c)))/', 'width' => 80, 'expected' => self::lines('/', '    (?=', '        a', '        (?=b(?=c))', '    )', '/x')];
        yield 'a conditional counts as a group' => ['pattern' => '/(a)(?(1)(?:b(?:c)))/', 'width' => 80, 'expected' => self::lines('/', '    (a)', '    (?(1)', '        (?:b(?:c))', '    )', '/x')];
        yield 'atomic and branch-reset groups count' => ['pattern' => '/(?>a(?|b(?:c)))/', 'width' => 80, 'expected' => self::lines('/', '    (?>', '        a', '        (?|b(?:c))', '    )', '/x')];
        yield 'a class does not count' => ['pattern' => '/(?:a(?:[(b)]))/', 'width' => 80, 'expected' => '/(?:a(?:[(b)]))/'];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideGroupLayouts(): iterable
    {
        yield 'optional group' => ['pattern' => '/(?:(?:a(?:b))c)?/', 'width' => 80, 'expected' => self::lines('/', '    (?:', '        (?:a(?:b))', '        c', '    )?', '/x')];
        yield 'braced quantifier' => ['pattern' => '/(?:(?:a(?:b))c){2,}/', 'width' => 80, 'expected' => self::lines('/', '    (?:', '        (?:a(?:b))', '        c', '    ){2,}', '/x')];
        yield 'possessive star' => ['pattern' => '/(?:(?:a(?:b))c)*+/', 'width' => 80, 'expected' => self::lines('/', '    (?:', '        (?:a(?:b))', '        c', '    )*+', '/x')];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideSequences(): iterable
    {
        yield 'literals, a class, an escape and a quantified atom stay together' => ['pattern' => '/abc[de]\d+(?:xy)fg(z)h/', 'width' => 12, 'expected' => self::lines('/', '    abc[de]\d+', '    (?:xy)', '    fg', '    (z)', '    h', '/x')];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideAlternations(): iterable
    {
        yield 'top level' => ['pattern' => '/alpha|beta|gamma/', 'width' => 10, 'expected' => self::lines('/', '    alpha', '  | beta', '  | gamma', '/x')];
        yield 'nested' => ['pattern' => '/x(?:alpha|beta|gamma)y/', 'width' => 12, 'expected' => self::lines('/', '    x', '    (?:', '        alpha', '      | beta', '      | gamma', '    )', '    y', '/x')];
        yield 'an empty first branch gets no line' => ['pattern' => '/x(?:|alpha|beta)y/', 'width' => 12, 'expected' => self::lines('/', '    x', '    (?:', '      | alpha', '      | beta', '    )', '    y', '/x')];
        yield 'an empty last branch' => ['pattern' => '/x(?:alpha|beta|)y/', 'width' => 12, 'expected' => self::lines('/', '    x', '    (?:', '        alpha', '      | beta', '      |', '    )', '    y', '/x')];
        yield 'a branch that fits stays on one line' => ['pattern' => '/^(?:alpha|beta)$|^(?:gamma|delta)$/', 'width' => 24, 'expected' => self::lines('/', '    ^(?:alpha|beta)$', '  | ^(?:gamma|delta)$', '/x')];
    }

    /**
     * PCRE reads start-of-pattern verbs at offset 0 only: "/\n(*CR)a/x" does
     * not compile.
     *
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideStartVerbs(): iterable
    {
        yield '(*UTF)(*UCP)' => ['pattern' => '/(*UTF)(*UCP)^(?:é|ü)\w+$/', 'width' => 1, 'expected' => self::lines('/(*UTF)(*UCP)', '    ^', '    (?:', '        é', '      | ü', '    )', '    \w+$', '/x')];
        yield '(*CR)' => ['pattern' => '/(*CR)^(?:a|b)c$/', 'width' => 1, 'expected' => self::lines('/(*CR)', '    ^', '    (?:', '        a', '      | b', '    )', '    c$', '/x')];
        yield '(*LIMIT_MATCH=1000)' => ['pattern' => '/(*LIMIT_MATCH=1000)^(?:a|b)+$/', 'width' => 1, 'expected' => self::lines('/(*LIMIT_MATCH=1000)', '    ^', '    (?:', '        a', '      | b', '    )+', '    $', '/x')];
        yield 'a run of verbs' => ['pattern' => '/(*UTF)(*CR)(*LIMIT_MATCH=10)(?:ab)(?:cd)/', 'width' => 10, 'expected' => self::lines('/(*UTF)(*CR)(*LIMIT_MATCH=10)', '    (?:ab)', '    (?:cd)', '/x')];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideDelimiters(): iterable
    {
        yield 'slash, flags kept' => ['pattern' => '/(?:ab)(?:cd)/i', 'width' => 10, 'expected' => self::lines('/', '    (?:ab)', '    (?:cd)', '/ix')];
        yield 'hash, with an escaped hash' => ['pattern' => '#a\#b(?:cd)#i', 'width' => 6, 'expected' => self::lines('#', '    a\#b', '    (?:', '        cd', '    )', '#ix')];
        yield 'tilde' => ['pattern' => '~(?:ab)(?:cd)~ms', 'width' => 10, 'expected' => self::lines('~', '    (?:ab)', '    (?:cd)', '~msx')];
        yield 'bang' => ['pattern' => '!(?:ab)(?:cd)!', 'width' => 10, 'expected' => self::lines('!', '    (?:ab)', '    (?:cd)', '!x')];
        yield 'braces' => ['pattern' => '{(?:ab)(?:cd)}', 'width' => 10, 'expected' => self::lines('{', '    (?:ab)', '    (?:cd)', '}x')];
        yield 'parentheses' => ['pattern' => '((?:ab)(?:cd))', 'width' => 10, 'expected' => self::lines('(', '    (?:ab)', '    (?:cd)', ')x')];
        yield 'brackets' => ['pattern' => '[(?:ab)(?:cd)]', 'width' => 10, 'expected' => self::lines('[', '    (?:ab)', '    (?:cd)', ']x')];
        yield 'angle brackets' => ['pattern' => '<(?:ab)(?:cd)>', 'width' => 10, 'expected' => self::lines('<', '    (?:ab)', '    (?:cd)', '>x')];
        yield 'x already there is not added twice' => ['pattern' => '/(?:a b)(?:cd)/xi', 'width' => 10, 'expected' => self::lines('/', '    (?:ab)', '    (?:cd)', '/xi')];
        yield 'the n flag' => ['pattern' => '/(a)(?:b)/n', 'width' => 1, 'expected' => self::lines('/', '    (', '        a', '    )', '    (?:', '        b', '    )', '/nx')];
    }

    /**
     * "x" skips ASCII white space; under u also NEL, LRM, RLM, LS and PS
     * (not NBSP); in byte mode also byte 0x85. Inside a class it keeps
     * white space. "\c " is control-space (0x60), "\c#" is 0x63.
     *
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideRespellings(): iterable
    {
        yield 'space' => ['pattern' => '/a b(?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    a\ b', '    (?:', '        cd', '    )', '/x')];
        yield 'hash' => ['pattern' => '/a#b(?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    a\#b', '    (?:', '        cd', '    )', '/x')];
        yield 'tab' => ['pattern' => "/a\tb(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\tb', '    (?:', '        cd', '    )', '/x')];
        yield 'line feed' => ['pattern' => "/a\nb(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\nb', '    (?:', '        cd', '    )', '/x')];
        yield 'carriage return' => ['pattern' => "/a\rb(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\rb', '    (?:', '        cd', '    )', '/x')];
        yield 'vertical tab' => ['pattern' => "/a\x0Bb(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\x0Bb', '    (?:', '        cd', '    )', '/x')];
        yield 'form feed' => ['pattern' => "/a\x0Cb(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\fb', '    (?:', '        cd', '    )', '/x')];
        yield 'NEL under u' => ['pattern' => "/a\u{85}b(?:cd)/u", 'width' => 1, 'expected' => self::lines('/', '    a\x{85}b', '    (?:', '        cd', '    )', '/ux')];
        yield 'LRM under u' => ['pattern' => "/a\u{200E}b(?:cd)/u", 'width' => 1, 'expected' => self::lines('/', '    a\x{200E}b', '    (?:', '        cd', '    )', '/ux')];
        yield 'RLM under u' => ['pattern' => "/a\u{200F}b(?:cd)/u", 'width' => 1, 'expected' => self::lines('/', '    a\x{200F}b', '    (?:', '        cd', '    )', '/ux')];
        yield 'LS under u' => ['pattern' => "/a\u{2028}b(?:cd)/u", 'width' => 1, 'expected' => self::lines('/', '    a\x{2028}b', '    (?:', '        cd', '    )', '/ux')];
        yield 'PS under u' => ['pattern' => "/a\u{2029}b(?:cd)/u", 'width' => 1, 'expected' => self::lines('/', '    a\x{2029}b', '    (?:', '        cd', '    )', '/ux')];
        yield 'NBSP under u stays raw' => ['pattern' => "/a\u{A0}b(?:cd)/u", 'width' => 1, 'expected' => self::lines('/', "    a\u{A0}b", '    (?:', '        cd', '    )', '/ux')];
        yield 'byte 0x85 in byte mode' => ['pattern' => "/a\x85b(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\x85b', '    (?:', '        cd', '    )', '/x')];
        yield 'byte 0x85 inside a UTF-8 sequence in byte mode' => ['pattern' => '/Å(?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    \xC3\x85', '    (?:', '        cd', '    )', '/x')];
        yield 'the same character under u stays raw' => ['pattern' => '/Å(?:cd)/u', 'width' => 1, 'expected' => self::lines('/', '    Å', '    (?:', '        cd', '    )', '/ux')];
        yield 'control-space' => ['pattern' => '/a\c (?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    a\x60', '    (?:', '        cd', '    )', '/x')];
        yield 'control-hash' => ['pattern' => '/a\c#(?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    a\x63', '    (?:', '        cd', '    )', '/x')];
        yield 'a quoted run' => ['pattern' => '/\Q a # b \Ec(?:de)/', 'width' => 1, 'expected' => self::lines('/', '    \ a\ \#\ b\ c', '    (?:', '        de', '    )', '/x')];
        yield 'a raw tab and line feed in a class' => ['pattern' => "/[\t\n ](?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    [\t\n ]', '    (?:', '        cd', '    )', '/x')];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideSpellings(): iterable
    {
        yield 'group names and references' => ['pattern' => "/^(?:(?<n>a)\\k<n>|(?P<m>b)(?P=m)|(?'o'c)\\k{o})$/", 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?:', '        (?<n>', '            a', '        )', '        \k<n>', '      | (?P<m>', '            b', '        )', '        (?P=m)', "      | (?'o'", '            c', '        )', '        \k{o}', '    )', '    $', '/x')];
        yield 'escapes' => ['pattern' => '/^(?:\x{41}|\101|\o{102}|\N{U+43})$/u', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?:', '        \x{41}', '      | \101', '      | \o{102}', '      | \N{U+43}', '    )', '    $', '/ux')];
        yield 'quantifier text' => ['pattern' => '/^(?:a{2,3}|b{,4}|c{5,}|d{2}+|e*?)\d{2}$/', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?:', '        a{2,3}', '      | b{,4}', '      | c{5,}', '      | d{2}+', '      | e*?', '    )', '    \d{2}$', '/x')];
        yield 'a condition on a name in angle brackets' => ['pattern' => '/(?<n>a)?(?(<n>)bc|de)/', 'width' => 1, 'expected' => self::lines('/', '    (?<n>', '        a', '    )?', '    (?(<n>)', '        bc', '      | de', '    )', '/x')];
        yield 'a condition on a quoted name' => ['pattern' => "/(?<n>a)?(?('n')bc|de)/", 'width' => 1, 'expected' => self::lines('/', '    (?<n>', '        a', '    )?', "    (?('n')", '        bc', '      | de', '    )', '/x')];
        yield 'a subroutine call on a quoted name' => ['pattern' => "/(?<n>a)\\g'n'(?:de)/", 'width' => 1, 'expected' => self::lines('/', '    (?<n>', '        a', '    )', "    \\g'n'", '    (?:', '        de', '    )', '/x')];
    }

    /**
     * Under x a single space keeps "\1" from reading the digit after it;
     * without x an empty "(?:)" does. "\1 0" under x is the reference then
     * "0"; "/^a{,2}$/" no longer matches "a{,2}" from PCRE2 10.43 on, so
     * "a{(?#c),2}" keeps a separator in compact.
     *
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideSeparators(): iterable
    {
        yield 'a backreference before a digit under x' => ['pattern' => '/(a)\1 0/x', 'width' => 1, 'expected' => self::lines('/', '    (', '        a', '    )', '    \1 0', '/x')];
        yield 'ten groups, a backreference before a digit under x' => ['pattern' => '/^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)\1 0\10(?:x|y)$/x', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (', '        a', '    )', '    (', '        b', '    )', '    (', '        c', '    )', '    (', '        d', '    )', '    (', '        e', '    )', '    (', '        f', '    )', '    (', '        g', '    )', '    (', '        h', '    )', '    (', '        i', '    )', '    (', '        j', '    )', '    \1 0\10', '    (?:', '        x', '      | y', '    )', '    $', '/x')];
        yield 'a complete octal escape before a digit under x' => ['pattern' => '/^\000 0(?:x|y)$/x', 'width' => 1, 'expected' => self::lines('/', '    ^\000 0', '    (?:', '        x', '      | y', '    )', '    $', '/x')];
        yield 'a comment inside a brace stays where it is' => ['pattern' => '/a{(?#c),2}(?:bc)/', 'width' => 1, 'expected' => self::lines('/', '    a{(?#c),2}', '    (?:', '        bc', '    )', '/x')];
    }

    /**
     * A comment between an atom and its quantifier, or between a quantifier
     * and its lazy or possessive modifier, is kept after the quantified item,
     * on its line: "a # c\n +" is "a+" to PCRE.
     *
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideComments(): iterable
    {
        yield 'trailing comments align on one column' => ['pattern' => "/\n  ^(\\d{4}) # year\n  -(\\d{2}) # month of the year\n  -(\\d{2})   # day\n/x", 'width' => 80, 'expected' => self::lines('/', '    ^', '    (\d{4})  # year', '    -', '    (\d{2})  # month of the year', '    -', '    (\d{2})  # day', '/x')];
        yield 'an alignment run ends at a line without a comment' => ['pattern' => "/^(\\d{4}) # year\n-(\\d{2}) # month of the year\n-(\\d{2})   # day\n(?:T(\\d{2}):(\\d{2}))?\$/x", 'width' => 80, 'expected' => self::lines('/', '    ^', '    (\d{4})  # year', '    -', '    (\d{2})  # month of the year', '    -', '    (\d{2})  # day', '    (?:T(\d{2}):(\d{2}))?', '    $', '/x')];
        yield 'a comment after a nested bar trails its branch' => ['pattern' => "/^(?:alpha | # second\n beta | gamma)\$ # end\n/x", 'width' => 80, 'expected' => self::lines('/', '    ^', '    (?:', '        alpha  # second', '      | beta', '      | gamma', '    )', '    $  # end', '/x')];
        yield 'a comment after a top-level bar trails its branch' => ['pattern' => "/alpha | # second\n beta/x", 'width' => 80, 'expected' => self::lines('/', '    alpha  # second', '  | beta', '/x')];
        yield 'a comment on the opening line of a group' => ['pattern' => "/^(?: # the group\n a | b)c\$/x", 'width' => 80, 'expected' => self::lines('/', '    ^', '    (?:  # the group', '        a', '      | b', '    )', '    c$', '/x')];
        yield 'a comment never trails another comment' => ['pattern' => "/^a # one\n # two\n b\$/x", 'width' => 80, 'expected' => self::lines('/', '    ^a  # one', '    # two', '    b$', '/x')];
        yield 'one blank line is kept, never two' => ['pattern' => "/^a # one\n\n b # two\n\n\n c\$/x", 'width' => 80, 'expected' => self::lines('/', '    ^a  # one', '', '    b  # two', '', '    c$', '/x')];
        yield 'an inline comment group stays in its run' => ['pattern' => '/^(?:abc(?#first)|def)(?:g|h)$/', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?:', '        abc(?#first)', '      | def', '    )', '    (?:', '        g', '      | h', '    )', '    $', '/x')];
        yield 'a comment never forces its line to break' => ['pattern' => "/^abc # a short comment\n/x", 'width' => 80, 'expected' => self::lines('/', '    ^abc  # a short comment', '/x')];
        yield 'a lone comment with text at width 1' => ['pattern' => "/# note\n/x", 'width' => 1, 'expected' => self::lines('/', '    # note', '/x')];
        yield 'a comment between an atom and its quantifier' => ['pattern' => "/a # c\n +/x", 'width' => 80, 'expected' => self::lines('/', '    a+  # c', '/x')];
        yield 'a comment between a quantifier and its lazy modifier' => ['pattern' => "/a+ # c\n ?/x", 'width' => 80, 'expected' => self::lines('/', '    a+?  # c', '/x')];
        yield 'a comment between a group and its quantifier' => ['pattern' => "~^https?://[a-z0-9.-]+(?:/(?:[^?\\s]|%[0-9a-f]{2}) # The path and query (optional)\n        *)?\$~ix", 'width' => 80, 'expected' => self::lines('~', '    ^https?://[a-z0-9.-]+', '    (?:', '        /', '        (?:[^?\s]|%[0-9a-f]{2})*  # The path and query (optional)', '    )?', '    $', '~ix')];
    }

    /**
     * Without x a line break is a literal: "(?-x:...)" and "(?^...)" print on
     * one line, and an unscoped "(?-x)" glues the rest of its group, later
     * branches included.
     *
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideExtendedModeScopes(): iterable
    {
        yield '(?-x:...) stays on one line' => ['pattern' => '/^(?i:alpha(?-x: b c )(?:dd|ee))(?:x|y)$/', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?i:', '        alpha', '        (?-x: b c )', '        (?:', '            dd', '          | ee', '        )', '    )', '    (?:', '        x', '      | y', '    )', '    $', '/x')];
        yield '(?^:...) stays on one line' => ['pattern' => '/^(?:alpha(?^: b c )(?:dd|ee))(?:x|y)$/x', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?:', '        alpha', '        (?^: b c )', '        (?:', '            dd', '          | ee', '        )', '    )', '    (?:', '        x', '      | y', '    )', '    $', '/x')];
        yield 'an unscoped (?-x) glues the rest of its group' => ['pattern' => '/^(?:alpha(?-x) b c (?:dd|ee) f|gamma)$/', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?:', '        alpha(?-x) b c (?:dd|ee) f|gamma)', '    $', '/x')];
        yield 'an unscoped (?^) glues the rest of its group' => ['pattern' => '/^(?:alpha(?^) b c (?:dd|ee) f|gamma)(?:x|y)$/x', 'width' => 1, 'expected' => self::lines('/', '    ^', '    (?:', '        alpha(?^) b c (?:dd|ee) f|gamma)', '    (?:', '        x', '      | y', '    )', '    $', '/x')];
        yield 'inline xx keeps the class as written' => ['pattern' => '/(?xx)[a b](?:c)/', 'width' => 1, 'expected' => self::lines('/', '    (?xx)[a b]', '    (?:', '        c', '    )', '/x')];
        yield 'inline J' => ['pattern' => '/(?J)(?<n>a)|(?<n>b)/', 'width' => 1, 'expected' => self::lines('/', '    (?J)', '    (?<n>', '        a', '    )', '  | (?<n>', '        b', '    )', '/x')];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideGroupKinds(): iterable
    {
        yield 'capturing' => ['pattern' => '/(ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (', '        ab', '    )', '    c', '/x')];
        yield 'non-capturing' => ['pattern' => '/(?:ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?:', '        ab', '    )', '    c', '/x')];
        yield 'named' => ['pattern' => '/(?<n>ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?<n>', '        ab', '    )', '    c', '/x')];
        yield 'positive lookahead' => ['pattern' => '/(?=ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?=', '        ab', '    )', '    c', '/x')];
        yield 'negative lookahead' => ['pattern' => '/(?!ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?!', '        ab', '    )', '    c', '/x')];
        yield 'positive lookbehind' => ['pattern' => '/(?<=ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?<=', '        ab', '    )', '    c', '/x')];
        yield 'negative lookbehind' => ['pattern' => '/(?<!ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?<!', '        ab', '    )', '    c', '/x')];
        yield 'non-atomic lookahead' => ['pattern' => '/(?*ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?*', '        ab', '    )', '    c', '/x')];
        yield 'scoped inline flags' => ['pattern' => '/(?i:ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?i:', '        ab', '    )', '    c', '/x')];
        yield 'atomic' => ['pattern' => '/(?>ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (?>', '        ab', '    )', '    c', '/x')];
        yield 'branch reset' => ['pattern' => '/(?|(a)|(b))c/', 'width' => 1, 'expected' => self::lines('/', '    (?|', '        (', '            a', '        )', '      | (', '            b', '        )', '    )', '    c', '/x')];
        yield 'scan substring' => ['pattern' => '/(a)(*scs:(1)ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (', '        a', '    )', '    (*scs:(1)', '        ab', '    )', '    c', '/x')];
        yield 'condition on a group number' => ['pattern' => '/(a)?(?(1)bc|de)/', 'width' => 1, 'expected' => self::lines('/', '    (', '        a', '    )?', '    (?(1)', '        bc', '      | de', '    )', '/x')];
        yield 'condition on recursion' => ['pattern' => '/(?(R)bc|de)/', 'width' => 1, 'expected' => self::lines('/', '    (?(R)', '        bc', '      | de', '    )', '/x')];
        yield 'condition on an assertion' => ['pattern' => '/(?(?=a)ab|cd)/', 'width' => 1, 'expected' => self::lines('/', '    (?(?=a)', '        ab', '      | cd', '    )', '/x')];
        yield 'condition on the version' => ['pattern' => '/(?(VERSION>=10.0)ab|cd)/', 'width' => 1, 'expected' => self::lines('/', '    (?(VERSION>=10.0)', '        ab', '      | cd', '    )', '/x')];
        yield 'DEFINE' => ['pattern' => '/(?(DEFINE)(?<a>aa))(?&a)/', 'width' => 1, 'expected' => self::lines('/', '    (?(DEFINE)', '        (?<a>', '            aa', '        )', '    )', '    (?&a)', '/x')];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideRefusalNeighbours(): iterable
    {
        // A "#" comment ends at LF under (*LF), as it does by default.
        yield 'a comment under (*LF) is laid out' => ['pattern' => "/(*LF)^alpha # c\n(?:b|c)\$/x", 'width' => 1, 'expected' => self::lines('/(*LF)', '    ^alpha  # c', '    (?:', '        b', '      | c', '    )', '    $', '/x')];
        // Under x already, "a{ 1,2 }" reads the same before and after.
        yield 'a spaced quantifier under x is laid out' => ['pattern' => '/^a{ 1,2 }(?:b|c)$/x', 'width' => 1, 'expected' => self::lines('/', '    ^a{ 1,2 }', '    (?:', '        b', '      | c', '    )', '    $', '/x')];
    }

    /**
     * A "#" comment runs on past LF under (*CR), (*CRLF) and (*NUL); before
     * PCRE2 10.43 (PHP 8.2 ships 10.40/10.42) "a{ 1,2 }" is a literal that an
     * added x would change; a newline after a top-level "(?-x)" is a literal.
     *
     * @return iterable<string, array{pattern: string, width: int, refusal: string}>
     */
    public static function provideRefusals(): iterable
    {
        yield 'comments under (*CR)' => ['pattern' => "/(*CR)^alpha # c\n(?:b|c)\$/x", 'width' => 1, 'refusal' => 'newline_convention'];
        yield 'comments under (*CRLF)' => ['pattern' => "/(*CRLF)^alpha # c\n(?:b|c)\$/x", 'width' => 1, 'refusal' => 'newline_convention'];
        yield 'comments under (*NUL)' => ['pattern' => "/(*NUL)^alpha # c\n(?:b|c)\$/x", 'width' => 1, 'refusal' => 'newline_convention'];
        yield 'a spaced quantifier without x' => ['pattern' => '/^a{ 1,2 }(?:b|c)$/', 'width' => 1, 'refusal' => 'spaced_quantifier'];
        yield 'a top-level (?-x)' => ['pattern' => '/^alpha(?-x) b c(?:dd|ee)$/x', 'width' => 1, 'refusal' => 'extended_mode_switched_off'];
        yield 'a top-level (?^)' => ['pattern' => '/^alpha(?^) b c(?:dd|ee)$/', 'width' => 1, 'refusal' => 'extended_mode_switched_off'];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int}>
     */
    public static function provideEveryFormattedPattern(): iterable
    {
        foreach ([
            self::provideFittingPatterns(),
            self::provideWidthBoundaries(),
            self::provideNestingDepths(),
            self::provideGroupLayouts(),
            self::provideSequences(),
            self::provideAlternations(),
            self::provideStartVerbs(),
            self::provideDelimiters(),
            self::provideRespellings(),
            self::provideSpellings(),
            self::provideSeparators(),
            self::provideComments(),
            self::provideExtendedModeScopes(),
            self::provideGroupKinds(),
            self::provideRefusalNeighbours(),
            self::provideRefusals(),
        ] as $index => $rows) {
            foreach ($rows as $name => $row) {
                yield $index.': '.$name.' @'.$row['width'] => ['pattern' => $row['pattern'], 'width' => $row['width']];
            }
        }

        yield 'two hundred nested groups' => ['pattern' => '/'.str_repeat('(', 200).'a'.str_repeat(')', 200).'/', 'width' => 80];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int}>
     */
    public static function provideWhitespaceAtLineEnds(): iterable
    {
        yield from self::provideEveryFormattedPattern();
        yield 'an escaped space before a group' => ['pattern' => '/a\ (?:cd)/', 'width' => 1];
        yield 'an escaped space before a group under x' => ['pattern' => '/a\ (?:cd)/x', 'width' => 1];
        yield 'a quoted run ending in a space before a group' => ['pattern' => '/\Q a # b \E(?:cd)/', 'width' => 1];
        yield 'a space before a group' => ['pattern' => '/a (?:cd)/', 'width' => 1];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideLessCommonLayouts(): iterable
    {
        $long = str_repeat('b', 70);

        yield 'a DEFINE that fits stays on its line' => ['pattern' => "/(?(DEFINE)(?<a>x))(?&a)(?:{$long})/", 'width' => 80, 'expected' => self::lines('/', '    (?(DEFINE)(?<a>x))', '    (?&a)', "    (?:{$long})", '/x')];
        yield 'a conditional that fits stays on its line' => ['pattern' => "/(a)?(?(1)b|c)(?:{$long})/", 'width' => 80, 'expected' => self::lines('/', '    (a)?', '    (?(1)b|c)', "    (?:{$long})", '/x')];
        yield 'a blank line between branches is kept' => ['pattern' => "/\n    aa\n\n  | bb\n/x", 'width' => 4, 'expected' => self::lines('/', '    aa', '', '  | bb', '/x')];
        yield 'a comment after | trails the branch before, which then breaks' => ['pattern' => "/a | # c\n bb (?:cc)/x", 'width' => 6, 'expected' => self::lines('/', '    a  # c', '  | bb', '    (?:', '        cc', '    )', '/x')];
        yield 'a blank line before a comment is kept' => ['pattern' => "/(?:a)\n\n# c\n(?:b)/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        a', '    )', '', '    # c', '    (?:', '        b', '    )', '/x')];
        yield 'a comment on its own line closing a group stays on its own line' => ['pattern' => "/(?:aa\n# c\n)/x", 'width' => 80, 'expected' => self::lines('/', '    (?:', '        aa', '        # c', '    )', '/x')];
        yield 'two trailing comments in a group each keep their line' => ['pattern' => "/(?:a # x\nb # y\n)/x", 'width' => 80, 'expected' => self::lines('/', '    (?:', '        a  # x', '        b  # y', '    )', '/x')];
        yield 'a comment on the line after | opens its branch' => ['pattern' => "/a |\n# c\nb/x", 'width' => 1, 'expected' => self::lines('/', '    a', '  | # c', '    b', '/x')];
        yield 'comments alone' => ['pattern' => "/# a\n# b\n/x", 'width' => 1, 'expected' => self::lines('/', '    # a', '    # b', '/x')];
    }

    public static function provideCompactions(): iterable
    {
        yield 'drops the global x, its layout and its comments' => ['pattern' => "/^ a b # c\n (?: d | e ) \$/x", 'width' => 80, 'expected' => '/^ab(?:d|e)$/'];
        yield 'joins a laid-out pattern' => ['pattern' => "/\n    ^\n    (?:\n        a\n      | b\n    )\n    \$\n/ix", 'width' => 80, 'expected' => '/^(?:a|b)$/i'];
        yield 'keeps an inline (?x)' => ['pattern' => '/^(?x) a b (?:c | d) $/', 'width' => 80, 'expected' => '/^(?x)ab(?:c|d)$/'];
        yield 'keeps a scoped (?x:...)' => ['pattern' => '/^(?x: a b ) c$/', 'width' => 80, 'expected' => '/^(?x:ab) c$/'];
        yield 'drops a comment group' => ['pattern' => '/a(?#c)b/', 'width' => 80, 'expected' => '/ab/'];
        yield 'keeps the spelling of what x needed escaped' => ['pattern' => '/a\ b [ ] \#/x', 'width' => 80, 'expected' => '/a\ b[ ]\#/'];
        yield 'a backreference before a digit' => ['pattern' => '/(a)\1 0/x', 'width' => 80, 'expected' => '/(a)\1(?:)0/'];
        yield 'ten groups, a backreference before a digit' => ['pattern' => '/^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)\1 0\10(?:x|y)$/x', 'width' => 80, 'expected' => '/^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)\1(?:)0\10(?:x|y)$/'];
        yield 'a comment inside a brace keeps a separator' => ['pattern' => '/a{(?#c),2}/', 'width' => 80, 'expected' => '/a{(?:),2}/'];
        yield 'a comment between an atom and its quantifier' => ['pattern' => "/a # c\n +/x", 'width' => 80, 'expected' => '/a+/'];
        yield 'a closing \\c\\ keeps the \\E that ends its backslash' => ['pattern' => '/a\c\\\E/', 'width' => 80, 'expected' => '/a\c\\\E/'];
        yield 'a one-line pattern without x is unchanged' => ['pattern' => '/abc/i', 'width' => 80, 'expected' => '/abc/i'];
        yield 'the width does not matter' => ['pattern' => '/(?:abc)(?:def)/', 'width' => 1, 'expected' => '/(?:abc)(?:def)/'];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideEveryCompactedPattern(): iterable
    {
        foreach (self::provideCompactions() as $name => $row) {
            yield 'compaction: '.$name => ['pattern' => $row['pattern']];
        }

        foreach (self::provideEveryFormattedPattern() as $name => $row) {
            yield 'format row: '.$name => ['pattern' => $row['pattern']];
        }
    }

    /**
     * @return iterable<string, array{regex: RegexNode, operation: string}>
     */
    public static function provideTreesThatDisagreeWithTheirText(): iterable
    {
        // The tree of "/ab/" with "ac" recorded as its source: the fitting
        // pattern is given back from its source, which reads back as "ac".
        $parsed = Regex::create(['cache' => new NullCache()])->parse('/ab/');
        yield 'format, unchanged, a source that is not the tree' => [
            'regex' => new RegexNode($parsed->pattern, $parsed->flags, $parsed->delimiter, $parsed->startPosition, $parsed->endPosition, 'ac'),
            'operation' => 'format',
        ];

        // A character whose spelling "\x41" says A while its code point says B.
        $sequence = new SequenceNode([new CharLiteralNode('\x41', 0x42, CharLiteralType::Unicode, 0, 4), new LiteralNode('b', 4, 5)], 0, 5);
        yield 'format, a tree that disagrees with itself' => ['regex' => new RegexNode($sequence, '', '/', 0, 5), 'operation' => 'format'];
        yield 'compact, a tree that disagrees with itself' => ['regex' => new RegexNode($sequence, '', '/', 0, 5), 'operation' => 'compact'];

        // A spelling that is no escape at all: the output does not parse back.
        $broken = new SequenceNode([new CharLiteralNode('\x{', 0x41, CharLiteralType::Unicode, 0, 3), new LiteralNode('b', 3, 4)], 0, 4);
        yield 'format, an output that does not parse back' => ['regex' => new RegexNode($broken, '', '/', 0, 4), 'operation' => 'format'];
    }

    /**
     * @return iterable<string, array{width: int}>
     */
    public static function provideInvalidWidths(): iterable
    {
        yield 'zero' => ['width' => 0];
        yield 'negative' => ['width' => -1];
        yield 'the smallest integer' => ['width' => \PHP_INT_MIN];
    }

    private static function lines(string ...$lines): string
    {
        return implode("\n", $lines);
    }

    private function assertFormatsTo(string $pattern, ?int $width, string $expected): void
    {
        $formatter = null === $width ? new PatternFormatter() : new PatternFormatter($width);
        $result = $formatter->format($this->parse($pattern));

        $this->assertSame($expected, $result->pattern);
        $this->assertSame($expected !== $pattern, $result->changed);
        $this->assertNull($result->refusal);
        $this->assertSameMeaning($pattern, $result->pattern);
    }

    /**
     * The oracle: $output compiles, parses back to the tree of $input,
     * keeps its flags give or take x, and PCRE matches every subject with
     * both the same way.
     */
    private function assertSameMeaning(string $input, string $output): void
    {
        $this->assertNotFalse(@preg_match($output, ''), \sprintf('%s does not compile: %s', $output, preg_last_error_msg()));

        $inputTree = $this->parse($input);
        $outputTree = $this->parse($output);
        $this->assertSame(PatternEquivalence::structure($inputTree), PatternEquivalence::structure($outputTree), $output);
        $this->assertSame(str_replace('x', '', $inputTree->flags), str_replace('x', '', $outputTree->flags), $output);

        if (PatternEquivalence::crashesTheEngine($input)) {
            return;
        }

        foreach (PatternEquivalence::subjects($inputTree) as $subject) {
            $this->assertSame(
                PatternEquivalence::engineRun($input, $subject),
                PatternEquivalence::engineRun($output, $subject),
                \sprintf('%s and %s match %s differently.', $input, $output, json_encode($subject, \JSON_INVALID_UTF8_SUBSTITUTE)),
            );
        }
    }

    private function parse(string $pattern): RegexNode
    {
        return Regex::create(['cache' => new NullCache()])->parse($pattern);
    }
}
