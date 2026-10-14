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
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\FormatRefusal;
use PHPRegex\Parser\Printer\FormatResult;
use PHPRegex\Parser\Printer\PatternFormatter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PatternEquivalence;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    /**
     * A tree built by hand has no source: whether the output changed is
     * read against the tree's own spelling. A sequence nested in a sequence,
     * as a visitor may build one, reads back as the flat sequence it prints.
     */
    #[Test]
    #[DataProvider('provideTreesWithoutSource')]
    public function test_format_and_compact_read_a_tree_without_source_against_its_own_spelling(RegexNode $regex, string $expected): void
    {
        $this->assertSame(1, preg_match($expected, 'abc'), 'The oracle on the output.');

        foreach (['format' => (new PatternFormatter())->format($regex), 'compact' => (new PatternFormatter())->compact($regex)] as $operation => $result) {
            $this->assertSame($expected, $result->pattern, $operation);
            $this->assertFalse($result->changed, $operation);
            $this->assertNull($result->refusal, $operation);
        }
    }

    /**
     * @return iterable<string, array{regex: RegexNode, expected: string}>
     */
    public static function provideTreesWithoutSource(): iterable
    {
        yield 'a sequence' => [
            'regex' => new RegexNode(new SequenceNode([new LiteralNode('a', 0, 1), new LiteralNode('b', 1, 2)], 0, 2), '', '/', 0, 2),
            'expected' => '/ab/',
        ];
        yield 'a sequence nested in a sequence' => [
            'regex' => new RegexNode(new SequenceNode([new LiteralNode('a', 0, 1), new SequenceNode([new LiteralNode('b', 1, 2), new LiteralNode('c', 2, 3)], 1, 3)], 0, 3), '', '/', 0, 3),
            'expected' => '/abc/',
        ];
    }

    /**
     * Where the source does not spell a node at its offsets, as with a tree
     * a cache gave back for another text, the node is written from the
     * tree: a subroutine call over "xyz" is "\g<1>", not "xyz".
     */
    #[Test]
    public function test_format_and_compact_write_a_subroutine_call_from_the_tree_where_the_source_spells_something_else(): void
    {
        $group = $this->parse('/(a)/')->pattern;
        $regex = new RegexNode(new SequenceNode([$group, new SubroutineNode('1', 'g', 3, 6)], 0, 6), '', '/', 0, 6, '(a)xyz');
        $this->assertSame(1, preg_match('/^(a)\g<1>$/', 'aa'), 'The oracle: the tree matches "aa".');

        $this->assertSame('/(a)\g<1>/', (new PatternFormatter())->compact($regex)->pattern);
        $this->assertSame(self::lines('/', '    (', '        a', '    )', '    \g<1>', '/x'), (new PatternFormatter(1))->format($regex)->pattern);
    }

    /**
     * "\12" is an octal escape only while fewer than twelve groups open
     * before it. A tree that places it after twelve is written "\o{12}",
     * laid out or not, so that it does not read back as a backreference.
     */
    #[Test]
    public function test_format_and_compact_write_a_legacy_octal_escape_after_as_many_groups_in_braces(): void
    {
        $twelve = '(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)(l)';
        $groups = $this->parse('/'.$twelve.'/')->pattern;
        $this->assertInstanceOf(SequenceNode::class, $groups);
        $regex = new RegexNode(new SequenceNode([...$groups->children, new CharLiteralNode('\12', 0o12, CharLiteralType::OctalLegacy, 36, 39)], 0, 39), '', '/', 0, 39);
        // The oracle: after twelve groups "\12" is the backreference.
        $this->assertSame([1, 0], [preg_match('/^'.$twelve.'\o{12}$/', "abcdefghijkl\n"), preg_match('/^'.$twelve.'\12$/', "abcdefghijkl\n")]);

        $laidOut = (new PatternFormatter(1))->format($regex)->pattern;

        $this->assertStringEndsWith("\n    )\n    \\o{12}\n/x", $laidOut);
        $this->assertSame(1, preg_match($laidOut, "abcdefghijkl\n"));
        $this->assertSame('/'.$twelve.'\o{12}/', (new PatternFormatter())->compact($regex)->pattern);
    }

    /**
     * PHP 8.4 reads "r" among the inline letters: a top-level "(?r-x)"
     * turns x off as "(?-x)" does, and the formatter refuses to break the
     * lines after it.
     */
    #[Test]
    public function test_format_refuses_a_top_level_setting_that_turns_x_off_next_to_r(): void
    {
        $pattern = '/^alpha(?r-x) b c(?:dd|ee)$/x';
        if (\PHP_VERSION_ID >= 80400 && PcreTarget::runtime()->pcreAtLeast('10.43')) {
            $this->assertSame([1, 0], [preg_match($pattern, 'alpha b cdd'), preg_match($pattern, 'alphabcdd')], 'The oracle: x is off after (?r-x).');
        }
        $tree = Regex::create(['cache' => new NullCache(), 'php_version' => '8.4', 'pcre_version' => '10.44'])->parse($pattern);

        $result = (new PatternFormatter(1, new PcreTarget(80400, '10.44')))->format($tree);

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::ExtendedModeSwitchedOff, $result->refusal);
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
            ['newline_convention', 'spaced_quantifier', 'extended_mode_switched_off', 'line_break_in_name'],
            array_map(static fn (FormatRefusal $refusal): string => $refusal->value, FormatRefusal::cases()),
        );
    }

    #[Test]
    public function test_construct_takes_the_target_after_the_width(): void
    {
        $parameters = (new \ReflectionMethod(PatternFormatter::class, '__construct'))->getParameters();

        $this->assertCount(2, $parameters);
        $this->assertSame('width', $parameters[0]->getName());
        $this->assertSame(80, $parameters[0]->getDefaultValue());
        $this->assertSame('target', $parameters[1]->getName());
        $this->assertSame('?'.PcreTarget::class, (string) $parameters[1]->getType());
        $this->assertNull($parameters[1]->getDefaultValue());
    }

    /**
     * The output is read back for the caller's target, not the running
     * engine's: "{,2}" is a quantifier from PCRE2 10.43 on and literal text
     * before it, so a tree read for 10.40 must not be checked against the
     * output read for the running engine.
     */
    #[Test]
    #[DataProvider('provideOutputsReadForAnOlderTarget')]
    public function test_format_and_compact_read_their_output_back_for_the_target_they_are_given(string $pattern, string $operation, int $width): void
    {
        // The oracle: "/^b{,2}$/" matches "b{,2}" only before 10.43.
        $this->assertSame(PcreTarget::runtime()->pcreAtLeast('10.43') ? 0 : 1, preg_match('/^b{,2}$/', 'b{,2}'));
        $older = Regex::create(['cache' => new NullCache(), 'pcre_version' => '10.40']);
        $newer = Regex::create(['cache' => new NullCache(), 'pcre_version' => '10.44']);
        $this->assertNotSame(PatternEquivalence::structure($newer->parse($pattern)), PatternEquivalence::structure($older->parse($pattern)), 'The two targets read the input differently.');

        $formatter = new PatternFormatter($width, new PcreTarget(\PHP_VERSION_ID, '10.40'));
        $tree = $older->parse($pattern);
        $result = 'format' === $operation ? $formatter->format($tree) : $formatter->compact($tree);

        $this->assertNull($result->refusal);
        $this->assertSame(PatternEquivalence::structure($tree), PatternEquivalence::structure($older->parse($result->pattern)), $result->pattern);
        $this->assertNotFalse(@preg_match($result->pattern, ''), $result->pattern);
    }

    /**
     * A raw line feed or carriage return the syntax lets an escape spell is
     * spelled with it, laid out or not: every line break of the output is
     * layout, so re-indenting it cannot change the pattern.
     */
    #[Test]
    #[DataProvider('provideLineBreaksThatAreNotLayout')]
    public function test_format_spells_a_line_break_that_is_not_layout_as_an_escape(string $pattern, int $width, string $expected, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), 'The oracle: the input matches its line break.');

        $this->assertFormatsTo($pattern, $width, $expected);
        $this->assertSame(1, preg_match($expected, $subject));
    }

    /**
     * A raw line break in a verb name or a callout string has no escape to
     * spell it: "(*MARK:a\nb)" names "a\nb", and a layout would put it on two
     * lines that an editor re-indents. Both operations give the input back,
     * with the reason.
     */
    #[Test]
    #[DataProvider('provideLineBreaksNoEscapeCanSpell')]
    public function test_format_refuses_a_line_break_no_escape_can_spell(string $pattern, int $width, ?string $mark): void
    {
        $this->assertSame(1, preg_match($pattern, 'xyc', $match));
        $this->assertSame($mark, $match['MARK'] ?? null, 'The oracle: the line break belongs to the name.');

        $result = (new PatternFormatter($width))->format($this->parse($pattern));

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::from('line_break_in_name'), $result->refusal);
    }

    #[Test]
    #[DataProvider('provideLineBreaksNoEscapeCanSpell')]
    public function test_compact_refuses_a_line_break_no_escape_can_spell(string $pattern, int $width, ?string $mark): void
    {
        $this->assertSame(1, preg_match($pattern, 'xyc', $match));
        $this->assertSame($mark, $match['MARK'] ?? null, 'The oracle: the line break belongs to the name.');

        $result = (new PatternFormatter($width))->compact($this->parse($pattern));

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::from('line_break_in_name'), $result->refusal);
    }

    /**
     * "(?-x)" inside a group switches x off up to that group's ")", later
     * branches included, and no further; an empty scoped "(?-x:)" switches
     * it off for nothing. Each subject's result is the oracle's, PCRE2 10.49.
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideExtendedModeScopeLeaks')]
    public function test_format_switches_x_off_only_where_the_pattern_does(string $pattern, int $width, array $subjects): void
    {
        $result = (new PatternFormatter($width))->format($this->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertTrue($result->changed);
        foreach ($subjects as $subject => $expected) {
            $this->assertSame($expected, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
            $this->assertSame($expected, preg_match($result->pattern, (string) $subject), $result->pattern.' on '.json_encode((string) $subject));
        }
        $this->assertSameMeaning($pattern, $result->pattern);
    }

    /**
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideExtendedModeScopeLeaks')]
    public function test_compact_switches_x_off_only_where_the_pattern_does(string $pattern, int $width, array $subjects): void
    {
        $result = (new PatternFormatter($width))->compact($this->parse($pattern));

        $this->assertNull($result->refusal);
        foreach ($subjects as $subject => $expected) {
            $this->assertSame($expected, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
            $this->assertSame($expected, preg_match($result->pattern, (string) $subject), $result->pattern.' on '.json_encode((string) $subject));
        }
        $this->assertSameMeaning($pattern, $result->pattern);
    }

    /**
     * Where the plain separator "(?:)" or a dropped comment would collide
     * with the delimiter, compact still writes one line that means what the
     * input meant: the delimiter is not written into the body unescaped, and
     * "\E" separates where "(?:)" holds the delimiter.
     *
     * @param array<string, int> $subjects
     */
    #[Test]
    #[DataProvider('provideCompactionsAgainstTheDelimiter')]
    public function test_compact_keeps_the_meaning_where_the_delimiter_is_in_the_way(string $pattern, array $subjects, ?string $expected): void
    {
        $formatter = new PatternFormatter();
        $result = $formatter->compact($this->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertStringNotContainsString("\n", $result->pattern);
        if (null !== $expected) {
            $this->assertSame($expected, $result->pattern);
        }
        foreach ($subjects as $subject => $matches) {
            $this->assertSame($matches, preg_match($pattern, (string) $subject), 'The oracle on the input, '.json_encode((string) $subject));
            $this->assertSame($matches, preg_match($result->pattern, (string) $subject), $result->pattern.' on '.json_encode((string) $subject));
        }
        $this->assertSameMeaning($pattern, $result->pattern);
        $this->assertSame($result->pattern, $formatter->compact($this->parse($result->pattern))->pattern, 'Compacting the output again changes nothing.');
    }

    /**
     * Laying a pattern out never writes a line of indentation alone: an
     * editor strips it, and a second pass would read it as a blank line.
     */
    #[Test]
    #[DataProvider('provideEveryFormattedPattern')]
    public function test_format_writes_no_line_of_indentation_only(string $pattern, int $width): void
    {
        $formatted = (new PatternFormatter($width))->format($this->parse($pattern))->pattern;

        foreach (explode("\n", $formatted) as $number => $line) {
            $this->assertDoesNotMatchRegularExpression('/^[ \t]+$/', $line, \sprintf('Line %d of %s', $number + 1, json_encode($formatted)));
        }
    }

    /**
     * A literal or a comment holding bytes a formatter might use to mark a
     * trailing comment is text like any other: it formats and keeps that
     * text as written.
     */
    #[Test]
    #[DataProvider('provideMarkerLookalikes')]
    public function test_format_keeps_text_that_looks_like_a_comment_marker(string $pattern, int $width, string $kept): void
    {
        $result = (new PatternFormatter($width))->format($this->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertStringContainsString($kept, $result->pattern);
        $this->assertSameMeaning($pattern, $result->pattern);
    }

    /**
     * An added x must not leave a raw no-break space where a locale's tables
     * would skip it; a pattern already under x keeps its own.
     */
    #[Test]
    #[DataProvider('provideRawNoBreakSpaces')]
    public function test_format_escapes_a_raw_no_break_space_when_it_adds_x(string $pattern, int $width, bool $keptRaw, string $subject): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), 'The oracle: the input matches its no-break space.');

        $result = (new PatternFormatter($width))->format($this->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertTrue($result->changed);
        $this->assertSame($keptRaw, str_contains($result->pattern, "\xA0"), json_encode($result->pattern, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));
        $this->assertSame(1, preg_match($result->pattern, $subject));
        $this->assertSameMeaning($pattern, $result->pattern);
    }

    /**
     * Once LC_CTYPE names a locale, PHP builds PCRE's tables from it, and on
     * macOS those call 0xA0 white space: x then skips a raw no-break space.
     * The formatted pattern must match under such a locale what the input
     * matches. Run apart: PHP keeps building the tables from LC_CTYPE for the
     * rest of the process, even once the previous locale is set back.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function test_format_output_keeps_its_no_break_space_under_a_locale_that_calls_it_white_space(): void
    {
        $rows = [];
        foreach (self::provideRawNoBreakSpaces() as $row) {
            if (!$row['keptRaw']) {
                $rows[] = $row + ['formatted' => (new PatternFormatter($row['width']))->format($this->parse($row['pattern']))->pattern];
            }
        }

        $previous = (string) setlocale(\LC_CTYPE, '0');
        $set = false;

        try {
            foreach (['en_US.UTF-8', 'C.UTF-8', 'fr_FR.UTF-8'] as $locale) {
                if (false !== setlocale(\LC_CTYPE, $locale)) {
                    $set = true;

                    break;
                }
            }
            $skipped = 1 === preg_match("/^a\xA0b\$/x", 'ab');
            foreach ($rows as $row) {
                $this->assertSame(1, preg_match($row['pattern'], $row['subject']), 'The input carries no x: the locale does not change it.');
                $this->assertSame(1, preg_match($row['formatted'], $row['subject']), json_encode($row['formatted'], \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR));
            }
        } finally {
            setlocale(\LC_CTYPE, $previous);
        }

        // The C library decides: macOS calls 0xA0 white space, others may not.
        if ('Darwin' === \PHP_OS_FAMILY) {
            $this->assertTrue($set);
            $this->assertTrue($skipped);
        }
    }

    /**
     * Time grows with the length of the pattern, not with its square: four
     * times the escapes may take about four times as long, not sixteen.
     * A wall-clock ratio is noisy on a loaded machine, so it runs with the
     * corpus: tools/phpunit/vendor/bin/phpunit --group formatter-corpus
     */
    #[Test]
    #[Group('formatter-corpus')]
    #[DataProvider('provideOperations')]
    public function test_format_and_compact_take_time_linear_in_the_length_of_the_pattern(string $operation): void
    {
        $short = $this->parse('/'.str_repeat('\.', 5000).'/');
        $long = $this->parse('/'.str_repeat('\.', 20000).'/');
        $formatter = new PatternFormatter();
        $formatter->{$operation}($this->parse('/\.\./'));

        $start = hrtime(true);
        $formatter->{$operation}($short);
        $shortTime = hrtime(true) - $start;

        $start = hrtime(true);
        $formatter->{$operation}($long);
        $longTime = hrtime(true) - $start;

        $this->assertLessThan(6, $longTime / max(1, $shortTime), \sprintf('%s: %.0f ms for 5,000 escapes, %.0f ms for 20,000.', $operation, $shortTime / 1e6, $longTime / 1e6));
    }

    /**
     * @return iterable<string, array{operation: string}>
     */
    public static function provideOperations(): iterable
    {
        yield 'format' => ['operation' => 'format'];
        yield 'compact' => ['operation' => 'compact'];
    }

    /**
     * Before PCRE2 10.43 a quantifier written with spaces is a literal, and
     * x drops those spaces: "/b{ 1,2 }/x" reads "b{1,2}" there, "/b{ 1,2 }/"
     * reads "b{ 1,2 }". Dropping x would change the pattern on PHP 8.2's
     * PCRE2, so compact refuses, as format refuses to add x to such a
     * pattern. The trees the library reads for 10.40 show the difference.
     */
    #[Test]
    #[DataProvider('provideSpacedQuantifiersUnderX')]
    public function test_compact_refuses_to_drop_x_from_a_spaced_quantifier(string $pattern): void
    {
        $older = Regex::create(['cache' => new NullCache(), 'pcre_version' => '10.40']);
        $this->assertNotSame(PatternEquivalence::structure($older->parse($pattern)), PatternEquivalence::structure($older->parse(rtrim($pattern, 'x'))), 'For PCRE2 10.40, x changes what the quantifier reads.');
        $this->assertNotFalse(preg_match($pattern, ''));

        $result = (new PatternFormatter())->compact($this->parse($pattern));

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::SpacedQuantifier, $result->refusal);
    }

    #[Test]
    public function test_compact_does_not_refuse_a_spaced_quantifier_it_keeps_without_x(): void
    {
        $result = (new PatternFormatter())->compact($this->parse('/(?:(?:(?:a)))b{ 1,2 }/'));

        $this->assertNull($result->refusal);
        $this->assertSame('/(?:(?:(?:a)))b{ 1,2 }/', $result->pattern);
    }

    /**
     * From PCRE2 10.43 a brace quantifier may hold spaces and tabs, before
     * it a literal: whether "a{ 1,2 }" repeats depends on the engine that
     * runs the pattern, not on the one the tree was read for. So format
     * refuses to add x to such a pattern whatever the target, and the
     * output never pins one engine's reading. Subject is a run the
     * quantifier accepts, followed by "b".
     */
    #[Test]
    #[DataProvider('provideSpacedQuantifiersWithoutX')]
    public function test_format_refuses_a_spaced_quantifier_without_x_whatever_the_target(string $pattern, string $subject, ?string $pcreVersion): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'The input compiles.');
        if (PcreTarget::runtime()->pcreAtLeast('10.43')) {
            $this->assertSame(1, preg_match($pattern, $subject), 'The oracle: from 10.43 on, the braces repeat.');
        }
        $this->assertNotSame(
            PatternEquivalence::structure(Regex::create(['cache' => new NullCache(), 'pcre_version' => '10.40'])->parse($pattern)),
            PatternEquivalence::structure(Regex::create(['cache' => new NullCache(), 'pcre_version' => '10.44'])->parse($pattern)),
            'The two engines read the braces differently.',
        );

        $result = self::formatterFor($pcreVersion, 1)->format(self::regexFor($pcreVersion)->parse($pattern));

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::SpacedQuantifier, $result->refusal);
    }

    /**
     * Braces no engine reads as a quantifier, "{ }" or "{ ,}", are text on
     * both sides of 10.43: format respells their spaces and lays them out.
     */
    #[Test]
    #[DataProvider('provideBracesThatNeverRepeat')]
    public function test_format_does_not_refuse_braces_no_engine_reads_as_a_quantifier(string $pattern, string $subject, ?string $pcreVersion): void
    {
        $this->assertSame(1, preg_match($pattern, $subject), 'The oracle: the braces are text.');

        $result = self::formatterFor($pcreVersion, 1)->format(self::regexFor($pcreVersion)->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertTrue($result->changed);
        $this->assertSame(1, preg_match($result->pattern, $subject), $result->pattern);
        $this->assertSameMeaning($pattern, $result->pattern);
        $this->assertSameTreeFor($pcreVersion, $pattern, $result->pattern);
    }

    /**
     * Dropping x from "a{ 1,2 }" changes it on one side of 10.43 or the
     * other, whatever the target the tree was read for.
     */
    #[Test]
    #[DataProvider('provideSpacedQuantifiersUnderXForEachTarget')]
    public function test_compact_refuses_a_spaced_quantifier_under_x_whatever_the_target(string $pattern, ?string $pcreVersion): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), 'The input compiles.');

        $result = self::formatterFor($pcreVersion)->compact(self::regexFor($pcreVersion)->parse($pattern));

        $this->assertSame($pattern, $result->pattern);
        $this->assertFalse($result->changed);
        $this->assertSame(FormatRefusal::SpacedQuantifier, $result->refusal);
    }

    /**
     * Inside "(?-x:...)" or "(?^:...)" x is off already: dropping the global
     * x leaves the braces as they are read on every engine, so compact has
     * no reason to refuse. Each subject's match is compared, groups and
     * MARK included, input against output.
     *
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideSpacedQuantifiersWhereXIsOff')]
    public function test_compact_does_not_refuse_a_spaced_quantifier_where_x_is_off(string $pattern, string $expected, array $subjects, ?string $pcreVersion): void
    {
        $result = self::formatterFor($pcreVersion)->compact(self::regexFor($pcreVersion)->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertSame($expected, $result->pattern);
        $this->assertTrue($result->changed);
        foreach ($subjects as $subject) {
            $this->assertSame(preg_match($pattern, $subject, $before), preg_match($result->pattern, $subject, $after), $result->pattern.' on '.json_encode($subject));
            $this->assertSame($before, $after, $result->pattern.' on '.json_encode($subject));
        }
        $this->assertSameMeaning($pattern, $result->pattern);
        $this->assertSameTreeFor($pcreVersion, $pattern, $result->pattern);
    }

    /**
     * Where x is on already, globally or in "(?x:...)", adding x changes
     * nothing: the braces are laid out as written, for every target, so the
     * output reads on each engine as the input does there. Checked against
     * the running PCRE2 (10.49 when written) and against the library's tree
     * for the target.
     */
    #[Test]
    #[DataProvider('provideSpacedQuantifiersWhereXIsOn')]
    public function test_format_lays_out_a_spaced_quantifier_where_x_is_on_whatever_the_target(string $pattern, int $width, string $expected, ?string $pcreVersion): void
    {
        $result = self::formatterFor($pcreVersion, $width)->format(self::regexFor($pcreVersion)->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertSame($expected, $result->pattern);
        $this->assertTrue($result->changed);
        $this->assertSameMeaning($pattern, $result->pattern);
        $this->assertSameTreeFor($pcreVersion, $pattern, $result->pattern);
    }

    /**
     * Each of these once came back as an error, or as a layout that a second
     * pass moved again. Whatever the result's spelling, it reads back as the
     * input for the target, matches as the input does on the running engine,
     * and does not move when laid out again.
     */
    #[Test]
    #[DataProvider('provideShapesThatOnceBrokeTheSelfCheck')]
    public function test_format_and_compact_keep_a_shape_that_once_broke_the_self_check(string $pattern, string $mode, int $width, ?string $pcreVersion): void
    {
        $formatter = self::formatterFor($pcreVersion, $width);
        $regex = self::regexFor($pcreVersion);
        $result = 'compact' === $mode ? $formatter->compact($regex->parse($pattern)) : $formatter->format($regex->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertSameTreeFor($pcreVersion, $pattern, $result->pattern);
        if (null === $pcreVersion) {
            $this->assertSameMeaning($pattern, $result->pattern);
        }
        $again = 'compact' === $mode ? $formatter->compact($regex->parse($result->pattern)) : $formatter->format($regex->parse($result->pattern));
        $this->assertSame($result->pattern, $again->pattern);
        $this->assertFalse($again->changed);
    }

    /**
     * The x that format adds does not reach into "(?-x:...)": braces written
     * with spaces there keep their meaning, whatever the target.
     */
    #[Test]
    #[DataProvider('provideSpacedQuantifiersInAScopeWithoutX')]
    public function test_format_lays_out_a_spaced_quantifier_in_a_scope_x_cannot_reach(string $pattern, int $width, string $expected, ?string $pcreVersion): void
    {
        $result = self::formatterFor($pcreVersion, $width)->format(self::regexFor($pcreVersion)->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertSame($expected, $result->pattern);
        $this->assertTrue($result->changed);
        $this->assertSameMeaning($pattern, $result->pattern);
        $this->assertSameTreeFor($pcreVersion, $pattern, $result->pattern);
    }

    /**
     * PHP pairs bracket delimiters by nesting, and a verb name or a callout
     * string has no escape: "(*MARK:\{)" names "\{". Where the bracket a
     * dropped comment held is the one that balances such a name, compact
     * keeps that comment and drops the rest. Each pattern matches $subject;
     * mark is the name PCRE reports for it.
     */
    #[Test]
    #[DataProvider('provideBracketsTheDelimiterBalanceNeeds')]
    public function test_compact_keeps_a_comment_whose_bracket_balances_an_unescapable_one(string $pattern, string $subject, ?string $mark, string $expected): void
    {
        $this->assertSame(1, preg_match($pattern, $subject, $before), 'The oracle on the input.');
        $this->assertSame($mark, $before['MARK'] ?? null, 'The oracle: the name holds the bracket.');

        $result = (new PatternFormatter())->compact($this->parse($pattern));

        $this->assertNull($result->refusal);
        $this->assertSame($expected, $result->pattern);
        $this->assertStringNotContainsString("\n", $result->pattern);
        $this->assertSame(1, preg_match($result->pattern, $subject, $after), $result->pattern);
        $this->assertSame($mark, $after['MARK'] ?? null, $result->pattern);
        $this->assertSame($before, $after, $result->pattern);
        $this->assertSameMeaning($pattern, $result->pattern);
        $this->assertSame($result->pattern, (new PatternFormatter())->compact($this->parse($result->pattern))->pattern, 'Compacting the output again changes nothing.');
    }

    /**
     * Under x the line breaks inside a "(?#...)" comment are layout like
     * any other: each continuation line starts at the indent of the line
     * the comment opens on, its own leading white space dropped. A comment
     * matches nothing, so PCRE reads the output as the input.
     */
    #[Test]
    #[DataProvider('provideCommentGroupsHoldingALineFeed')]
    public function test_format_reindents_the_lines_of_a_comment_group_to_its_indent(string $pattern, int $width, string $expected): void
    {
        $this->assertFormatsTo($pattern, $width, $expected);
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
        // Nothing but the verbs, past the width: no line would hold anything,
        // so the pattern comes back as written, layout included.
        yield '(*UTF) alone, then a space under x' => ['pattern' => '/(*UTF) /x', 'width' => 1, 'expected' => '/(*UTF) /x'];
        yield '(*UTF) alone, then a line break under x' => ['pattern' => "/(*UTF)\n/x", 'width' => 1, 'expected' => "/(*UTF)\n/x"];
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
     * "x" skips ASCII white space; under u also NEL, LRM, RLM, LS and PS;
     * in byte mode also byte 0x85. A no-break space (U+00A0, byte 0xA0) is
     * not skipped with PCRE's default tables, but it is with the tables PHP
     * builds once LC_CTYPE names a locale such as en_US.UTF-8 (measured on
     * macOS), so it is escaped as well. Inside a class x keeps white space.
     * "\c " is control-space (0x60), "\c#" is 0x63.
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
        yield 'NBSP under u' => ['pattern' => "/a\u{A0}b(?:cd)/u", 'width' => 1, 'expected' => self::lines('/', '    a\x{A0}b', '    (?:', '        cd', '    )', '/ux')];
        yield 'byte 0xA0 in byte mode' => ['pattern' => "/a\xA0b(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\xA0b', '    (?:', '        cd', '    )', '/x')];
        yield 'byte 0xA0 inside a UTF-8 sequence in byte mode' => ['pattern' => "/a\u{A0}b(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\xC2\xA0b', '    (?:', '        cd', '    )', '/x')];
        yield 'byte 0x85 in byte mode' => ['pattern' => "/a\x85b(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a\x85b', '    (?:', '        cd', '    )', '/x')];
        yield 'byte 0x85 inside a UTF-8 sequence in byte mode' => ['pattern' => '/Å(?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    \xC3\x85', '    (?:', '        cd', '    )', '/x')];
        yield 'the same character under u stays raw' => ['pattern' => '/Å(?:cd)/u', 'width' => 1, 'expected' => self::lines('/', '    Å', '    (?:', '        cd', '    )', '/ux')];
        yield 'control-space' => ['pattern' => '/a\c (?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    a\x60', '    (?:', '        cd', '    )', '/x')];
        yield 'control-hash' => ['pattern' => '/a\c#(?:cd)/', 'width' => 1, 'expected' => self::lines('/', '    a\x63', '    (?:', '        cd', '    )', '/x')];
        // "\c\" is control-backslash (0x1C): the escaped space after it ends
        // its line, so it is spelled "\x20", not "\ " an editor would strip.
        yield 'control-backslash, then an escaped space at a line end' => ['pattern' => '/(?:x)a\c\ (?:y)(?:zzzzzzzz)/', 'width' => 1, 'expected' => self::lines('/', '    (?:', '        x', '    )', '    a\c\\\\x20', '    (?:', '        y', '    )', '    (?:', '        zzzzzzzz', '    )', '/x')];
        // An escape such as "\d" is two characters long, "\\" too: what
        // follows it is read on its own.
        yield 'an escape, then an escaped space at a line end' => ['pattern' => "/\\d\\ \n(?:b)/x", 'width' => 1, 'expected' => self::lines('/', '    \d\x20', '    (?:', '        b', '    )', '/x')];
        yield 'an escaped backslash and c, then an escaped space at a line end' => ['pattern' => "/\\\\c\\ \n(?:b)/x", 'width' => 1, 'expected' => self::lines('/', '    \\\\c\x20', '    (?:', '        b', '    )', '/x')];
        // Inside a class x skips nothing: "[\c ]" is control-space as written.
        yield 'control-space in a class' => ['pattern' => '/(?:[\c ]b)(?:c)/x', 'width' => 1, 'expected' => self::lines('/', '    (?:', '        [\c ]b', '    )', '    (?:', '        c', '    )', '/x')];
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
        // A mark name is read up to ")" under x too: "(*:a#b)" names "a#b".
        yield 'a short mark name holding a hash' => ['pattern' => '/(*:a#b)(?:x)/', 'width' => 1, 'expected' => self::lines('/', '    (*:a#b)', '    (?:', '        x', '    )', '/x')];
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
        // A branch that fits with the comment closing it trailing its line:
        // the "\E" written between "\1" and "0" stays between them.
        yield 'a written \\E before a digit in a branch with a trailing comment' => ['pattern' => "/(a)(?:b|\\1\\E0 # trail\n)/x", 'width' => 20, 'expected' => self::lines('/', '    (a)', '    (?:', '        b', '      | \1\E0  # trail', '    )', '/x')];
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
        yield 'a comment after a bar trails once, its branch breaking before the comment that closes it' => ['pattern' => "/(?:ab| # lead\ncd(?:efghijklmnopqrstuvwxyz) # trail\n)g/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        ab  # lead', '      | cd', '        (?:', '            efghijklmnopqrstuvwxyz', '        )  # trail', '    )', '    g', '/x')];
        yield 'a comment after a bar trails once, its branch fitting with the comment that closes it' => ['pattern' => "/(?:ab| # lead\ncd(?:efghijklmnopqrstuvwxyz) # trail\n)g/x", 'width' => 80, 'expected' => self::lines('/', '    (?:', '        ab                            # lead', '      | cd(?:efghijklmnopqrstuvwxyz)  # trail', '    )', '    g', '/x')];
        yield 'a comment closing a branch trails the group that breaks before it' => ['pattern' => "/(?:(?:ab) # one\n|cd)g/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        (?:', '            ab', '        )  # one', '      | cd', '    )', '    g', '/x')];
        yield 'a comment after a bar never trails a comment on its own line' => ['pattern' => "/(?:ab\n# own\n| # lead\ncd)e/x", 'width' => 12, 'expected' => self::lines('/', '    (?:', '        ab', '        # own', '      | # lead', '        cd', '    )', '    e', '/x')];
        yield 'a comment after a bar trails its branch below an earlier trailing comment' => ['pattern' => "/(?:ab # one\n|cd| # two\nef)g/x", 'width' => 12, 'expected' => self::lines('/', '    (?:', '        ab  # one', '      | cd  # two', '      | ef', '    )', '    g', '/x')];
        yield 'an inline comment group closing a branch is no trailing comment' => ['pattern' => '/(?:x|abcdefgh(?#comment))y/x', 'width' => 20, 'expected' => self::lines('/', '    (?:', '        x', '      | abcdefgh(?#comment)', '    )', '    y', '/x')];
        yield 'a branch nesting three groups breaks before the comment that closes it' => ['pattern' => "/(?:x|(?:(?:(?:a)))b # trail\n)c/x", 'width' => 30, 'expected' => self::lines('/', '    (?:', '        x', '      | (?:', '            (?:(?:a))', '        )', '        b  # trail', '    )', '    c', '/x')];
        yield 'a branch nesting two groups keeps the comment that closes it on its line' => ['pattern' => "/(?:x|(?:(?:a))b # trail\n)c/x", 'width' => 20, 'expected' => self::lines('/', '    (?:', '        x', '      | (?:(?:a))b  # trail', '    )', '    c', '/x')];
        yield 'a comment right after the opening of a group trails the opening' => ['pattern' => "/(?:# lead\nab)c/x", 'width' => 1, 'expected' => self::lines('/', '    (?:  # lead', '        ab', '    )', '    c', '/x')];
        yield 'the line after an alignment run still ends in \\x20, not in an escaped space' => ['pattern' => "/a # c\nb\\ \n(?:c)/x", 'width' => 1, 'expected' => self::lines('/', '    a  # c', '    b\x20', '    (?:', '        c', '    )', '/x')];
        yield 'a trailing comment keeps its text, a closing escaped space included' => ['pattern' => "/a # c\\ \nb/x", 'width' => 1, 'expected' => self::lines('/', '    a  # c\ ', '    b', '/x')];
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
        yield 'an unscoped (?-x) glues every later branch of its group' => ['pattern' => '/(?:(?:abc)(?-x)|de|fg)h/x', 'width' => 1, 'expected' => self::lines('/', '    (?:', '        (?:', '            abc', '        )', '        (?-x)|de|fg)', '    h', '/x')];
        yield 'a (?x) later in the group does not unglue the rest of it' => ['pattern' => '/(?:a(?-x)b(?x)c(?:d)e)f/x', 'width' => 1, 'expected' => self::lines('/', '    (?:', '        a(?-x)b(?x)c(?:d)e', '    )', '    f', '/x')];
        yield 'a glued run keeps a written \\E before a digit' => ['pattern' => '/(?:(a)(?-x)\1\E0 b)c/x', 'width' => 12, 'expected' => self::lines('/', '    (?:', '        (a)', '        (?-x)\1\E0 b)', '    c', '/x')];
        // DEFINE is a group: x is back on after it.
        yield 'an unscoped (?-x) in DEFINE ends with it' => ['pattern' => '/(?(DEFINE)(?-x)(?<n>a))b c d/x', 'width' => 1, 'expected' => self::lines('/', '    (?(DEFINE)', '        (?-x)(?<n>a))', '    bcd', '/x')];
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
        // "(*scs:" compiles from PCRE2 10.45 on: before, the output cannot be
        // checked against the engine.
        if (PcreTarget::runtime()->pcreAtLeast('10.45')) {
            yield 'scan substring' => ['pattern' => '/(a)(*scs:(1)ab)c/', 'width' => 1, 'expected' => self::lines('/', '    (', '        a', '    )', '    (*scs:(1)', '        ab', '    )', '    c', '/x')];
        }
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
        // The start options are those of the first top-level branch, and a
        // (*LIMIT_MATCH=n) before (*CR) does not hide it.
        yield 'comments under (*CR) opening the first top-level branch' => ['pattern' => "/(*CR)ab|cd # c\n/x", 'width' => 1, 'refusal' => 'newline_convention'];
        yield 'comments under (*CR) after (*LIMIT_MATCH)' => ['pattern' => "/(*LIMIT_MATCH=5)(*CR)a # c\nb/x", 'width' => 1, 'refusal' => 'newline_convention'];
    }

    /**
     * @return iterable<string, array{pattern: string, operation: string, width: int}>
     */
    public static function provideOutputsReadForAnOlderTarget(): iterable
    {
        yield 'format, a pattern that fits' => ['pattern' => '/b{,2}/', 'operation' => 'format', 'width' => 80];
        yield 'format, laid out' => ['pattern' => '/(?:ab)b{,2}(?:cd)/', 'operation' => 'format', 'width' => 1];
        yield 'compact, a one-line pattern' => ['pattern' => '/b{,2}/', 'operation' => 'compact', 'width' => 80];
        yield 'compact, a laid-out pattern under x' => ['pattern' => "/\n    (?:ab)\n    b{,2}\n/x", 'operation' => 'compact', 'width' => 80];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string, subject: string}>
     */
    public static function provideLineBreaksThatAreNotLayout(): iterable
    {
        yield 'a raw line feed in a pattern that fits' => ['pattern' => "/a\nb/", 'width' => 80, 'expected' => '/a\nb/', 'subject' => "a\nb"];
        yield 'a raw carriage return in a pattern that fits' => ['pattern' => "/a\rb/", 'width' => 80, 'expected' => '/a\rb/', 'subject' => "a\rb"];
        yield 'a raw line feed in a class in a pattern that fits' => ['pattern' => "/[a\nb]/", 'width' => 80, 'expected' => '/[a\nb]/', 'subject' => "\n"];
        yield 'a raw line feed in a class in an x pattern that fits' => ['pattern' => "/[a\nb]c/x", 'width' => 80, 'expected' => '/[a\nb]c/x', 'subject' => "\nc"];
        yield 'a raw line feed inside (?-x:...) in an x pattern that fits' => ['pattern' => "/(?-x:a\nb)c/x", 'width' => 80, 'expected' => '/(?-x:a\nb)c/x', 'subject' => "a\nbc"];
        yield 'a raw line feed inside (?-x:...)' => ['pattern' => "/(?:x)(?-x:a\nb)(?:y)/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        x', '    )', '    (?-x:a\nb)', '    (?:', '        y', '    )', '/x'), 'subject' => "xa\nby"];
        yield 'a raw carriage return inside (?-x:...)' => ['pattern' => "/(?:x)(?-x:a\rb)(?:y)/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        x', '    )', '    (?-x:a\rb)', '    (?:', '        y', '    )', '/x'), 'subject' => "xa\rby"];
        yield 'a raw line feed inside (?^:...)' => ['pattern' => "/(?:x)(?^:a\nb)(?:y)/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        x', '    )', '    (?^:a\nb)', '    (?:', '        y', '    )', '/x'), 'subject' => "xa\nby"];
        yield 'a raw line feed after an unscoped (?-x) in its group' => ['pattern' => "/(?:x)(?:(?-x)a\nb)(?:y)/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        x', '    )', '    (?:', '        (?-x)a\nb)', '    (?:', '        y', '    )', '/x'), 'subject' => "xa\nby"];
    }

    /**
     * Each pattern matches "xyc"; mark is the name PCRE reports for it.
     *
     * @return iterable<string, array{pattern: string, width: int, mark: ?string}>
     */
    public static function provideLineBreaksNoEscapeCanSpell(): iterable
    {
        yield 'a mark name, laid out' => ['pattern' => "/(?:x)(*MARK:a\nb)(?:y)/", 'width' => 1, 'mark' => "a\nb"];
        yield 'a mark name in a pattern that fits' => ['pattern' => "/(*MARK:a\nb)c/", 'width' => 80, 'mark' => "a\nb"];
        yield 'a short mark name' => ['pattern' => "/(?:x)(*:a\nb)(?:y)/", 'width' => 1, 'mark' => "a\nb"];
        yield 'a mark name under x' => ['pattern' => "/(?:x)(*MARK:a\nb)(?:y)/x", 'width' => 1, 'mark' => "a\nb"];
        yield 'a mark name holding a carriage return' => ['pattern' => "/(?:x)(*MARK:a\rb)(?:y)/", 'width' => 1, 'mark' => "a\rb"];
        yield 'the name of (*PRUNE)' => ['pattern' => "/(?:x)(*PRUNE:a\nb)(?:y)/", 'width' => 1, 'mark' => "a\nb"];
        yield 'a callout string' => ['pattern' => "/(?:x)(?C'a\nb')(?:y)/", 'width' => 1, 'mark' => null];
        yield 'a callout string holding a carriage return' => ['pattern' => "/(?:x)(?C'a\rb')(?:y)/", 'width' => 1, 'mark' => null];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, subjects: array<string, int>}>
     */
    public static function provideExtendedModeScopeLeaks(): iterable
    {
        yield '(?-x) in the first branch of a conditional ends with it' => ['pattern' => '/(a)?(?(1)(?-x)a|b)c d|e f/x', 'width' => 1, 'subjects' => ['aacd' => 1, 'bcd' => 1, 'bc d' => 0, 'ef' => 1, 'e f' => 0, 'aac d' => 0]];
        yield '(?-x) in a DEFINE ends with it' => ['pattern' => '/(?(DEFINE)(?-x)(?<n>a b))(?&n)c d|x/x', 'width' => 1, 'subjects' => ['a bcd' => 1, 'a bc d' => 0, 'abcd' => 0, 'x' => 1]];
        yield 'an empty (?-x:) in a group switches nothing off' => ['pattern' => '/(?:x(?-x:)a b|q)(c)(d)/', 'width' => 1, 'subjects' => ['xa bcd' => 1, 'xabcd' => 0, 'qcd' => 1]];
        yield 'an empty (?-x:) at the top level is not refused' => ['pattern' => '/x(?-x:)a b(c)(d)/', 'width' => 1, 'subjects' => ['xa bcd' => 1, 'xabcd' => 0]];
    }

    /**
     * @return iterable<string, array{pattern: string, subjects: array<string, int>, expected: ?string}>
     */
    public static function provideCompactionsAgainstTheDelimiter(): iterable
    {
        // PHP pairs bracket delimiters by nesting: the "{" in the comment is
        // counted, so dropping it unbalances the body.
        yield 'a dropped comment held an opening brace delimiter' => ['pattern' => '{a(?#{)b}c}', 'subjects' => ['ab}c' => 1, 'abc' => 0], 'expected' => null];
        yield 'a dropped comment held an opening bracket delimiter' => ['pattern' => '[a(?#[)b]c]', 'subjects' => ['ab]c' => 1, 'abc' => 0], 'expected' => null];
        yield 'a dropped comment held an opening angle delimiter' => ['pattern' => '<a(?#<)b>c>', 'subjects' => ['ab>c' => 1, 'abc' => 0], 'expected' => null];
        yield 'the delimiter is the colon of (?:)' => ['pattern' => ':(a)\1 0:x', 'subjects' => ['aa0' => 1, 'a a0' => 0], 'expected' => ':(a)\1\E0:'];
        yield 'the delimiter is the question mark of (?:)' => ['pattern' => '?(a)\1 0?x', 'subjects' => ['aa0' => 1, 'a a0' => 0], 'expected' => '?(a)\1\E0?'];
        yield 'an empty group then a comment group' => ['pattern' => '/(?:)(?#c)/', 'subjects' => ['' => 1], 'expected' => null];
        yield 'an empty group then a comment under x' => ['pattern' => '/(?:)# c/x', 'subjects' => ['' => 1], 'expected' => null];
    }

    /**
     * Rows that once grew or moved on a second pass.
     *
     * @return iterable<string, array{pattern: string, width: int}>
     */
    public static function provideLayoutsThatMustNotGrow(): iterable
    {
        yield '(*CR) before an empty first branch' => ['pattern' => '/(*CR)|a/x', 'width' => 1];
        yield '(*UTF) before an empty first branch' => ['pattern' => '/(*UTF)|a/x', 'width' => 1];
        yield '(*LIMIT_MATCH=5) before an empty first branch' => ['pattern' => '/(*LIMIT_MATCH=5)|a/x', 'width' => 1];
        yield 'a comment after (*ANY)' => ['pattern' => '/(*ANY)#(/x', 'width' => 1];
        // "\Q\Q]" quotes "\Q]"; its output starts "\\Q", which quotes nothing.
        yield 'a quoted \\Q, laid out' => ['pattern' => '/\Q\Q]/', 'width' => 1];
        yield 'a quoted \\Q that fits' => ['pattern' => '/\Q\Q]/', 'width' => 80];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, kept: string}>
     */
    public static function provideMarkerLookalikes(): iterable
    {
        yield 'in a literal' => ['pattern' => "/(?:x)a\x01TRAILING-COMMENT\x01b(?:y)/", 'width' => 1, 'kept' => 'TRAILING-COMMENT'];
        yield 'in a comment group' => ['pattern' => "/(?:x)a(?#\x01TRAILING-COMMENT\x01)b(?:y)/", 'width' => 1, 'kept' => "(?#\x01TRAILING-COMMENT\x01)"];
        yield 'in a # comment' => ['pattern' => "/(?:x)a # \x01TRAILING-COMMENT\x01\nb(?:y)/x", 'width' => 1, 'kept' => "# \x01TRAILING-COMMENT\x01"];
        yield 'the trailing-comment marker itself' => ['pattern' => "/(?:x)a\x01TRAILING-COMMENT\x02b # c\n(?:y)/x", 'width' => 1, 'kept' => "a\x01TRAILING-COMMENT\x02b  # c"];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, keptRaw: bool, subject: string}>
     */
    public static function provideRawNoBreakSpaces(): iterable
    {
        yield 'under u, x added' => ['pattern' => "/price:\u{A0}(\\d+)\u{A0}EUR/u", 'width' => 10, 'keptRaw' => false, 'subject' => "price:\u{A0}12\u{A0}EUR"];
        yield 'a byte in byte mode, x added' => ['pattern' => "/price:\xA0(\\d+)\xA0EUR/", 'width' => 10, 'keptRaw' => false, 'subject' => "price:\xA012\xA0EUR"];
        yield 'UTF-8 in byte mode, x added' => ['pattern' => "/price:\u{A0}(\\d+)\u{A0}EUR/", 'width' => 10, 'keptRaw' => false, 'subject' => "price:\u{A0}12\u{A0}EUR"];
        yield 'under u, x already there' => ['pattern' => "/price:\u{A0}(\\d+)\u{A0}EUR/ux", 'width' => 10, 'keptRaw' => true, 'subject' => "price:\u{A0}12\u{A0}EUR"];
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideSpacedQuantifiersUnderX(): iterable
    {
        yield 'both bounds spaced' => ['pattern' => '/(?:(?:(?:a)))b{ 1,2 }/x'];
        yield 'an upper bound alone, spaced' => ['pattern' => '/a{ ,3}b/x'];
    }

    /**
     * Every spelling below repeats on PCRE2 10.49 (the oracle in the test);
     * a line feed inside the braces does not, nor do "{ }" and "{ ,}".
     *
     * @return iterable<string, array{pattern: string, subject: string, pcreVersion: ?string}>
     */
    public static function provideSpacedQuantifiersWithoutX(): iterable
    {
        $spellings = [
            'both bounds spaced' => ['a{ 1,2 }', 'aa'],
            'a space after the comma' => ['a{1, 2}', 'aa'],
            'a space before the comma' => ['a{1 ,2}', 'aa'],
            'a space before the closing brace' => ['a{1,2 }', 'aa'],
            'one bound, spaced on both sides' => ['a{ 2 }', 'aa'],
            'one bound, a space before it' => ['a{ 1}', 'a'],
            'an open upper bound, spaced' => ['a{2, }', 'aaa'],
            'a missing lower bound, spaced' => ['a{ ,2}', 'aa'],
            'a missing lower bound, spaced everywhere' => ['a{ , 2 }', 'aa'],
            'a tab' => ["a{\t2}", 'aa'],
        ];

        foreach (self::targets() as $target => $pcreVersion) {
            foreach ($spellings as $name => [$quantifier, $run]) {
                yield $name.', '.$target => ['pattern' => '/^'.$quantifier.'(?:b|c)$/', 'subject' => $run.'b', 'pcreVersion' => $pcreVersion];
            }
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, pcreVersion: ?string}>
     */
    public static function provideBracesThatNeverRepeat(): iterable
    {
        foreach (self::targets() as $target => $pcreVersion) {
            yield 'a space alone, '.$target => ['pattern' => '/^a{ }(?:b)$/', 'subject' => 'a{ }b', 'pcreVersion' => $pcreVersion];
            yield 'a space and a comma, '.$target => ['pattern' => '/^a{ ,}(?:b)$/', 'subject' => 'a{ ,}b', 'pcreVersion' => $pcreVersion];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, pcreVersion: ?string}>
     */
    public static function provideSpacedQuantifiersUnderXForEachTarget(): iterable
    {
        foreach (self::targets() as $target => $pcreVersion) {
            foreach (self::provideSpacedQuantifiersUnderX() as $name => $row) {
                yield $name.', '.$target => ['pattern' => $row['pattern'], 'pcreVersion' => $pcreVersion];
            }
            yield 'a space after the comma, '.$target => ['pattern' => '/a{1, 2}b/x', 'pcreVersion' => $pcreVersion];
            yield 'a tab, '.$target => ['pattern' => "/a{\t2}b/x", 'pcreVersion' => $pcreVersion];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, expected: string, subjects: list<string>, pcreVersion: ?string}>
     */
    public static function provideSpacedQuantifiersWhereXIsOff(): iterable
    {
        foreach (self::targets() as $target => $pcreVersion) {
            yield '(?-x:...), '.$target => ['pattern' => '/(?-x:a{ 1,2 })/x', 'expected' => '/(?-x:a{ 1,2 })/', 'subjects' => ['a', 'a{ 1,2 }', 'aa'], 'pcreVersion' => $pcreVersion];
            yield '(?-x:...), anchored, '.$target => ['pattern' => '/^(?-x:a{ 1,2 })$/x', 'expected' => '/^(?-x:a{ 1,2 })$/', 'subjects' => ['a', 'a{ 1,2 }', 'aa'], 'pcreVersion' => $pcreVersion];
            yield '(?^:...), '.$target => ['pattern' => '/^(?^:a{ 1,2 })b$/x', 'expected' => '/^(?^:a{ 1,2 })b$/', 'subjects' => ['ab', 'a{ 1,2 }b', 'aab'], 'pcreVersion' => $pcreVersion];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string, pcreVersion: ?string}>
     */
    public static function provideSpacedQuantifiersWhereXIsOn(): iterable
    {
        foreach (self::targets() as $target => $pcreVersion) {
            yield 'under a global x, '.$target => ['pattern' => '/^a{ 1,2 }(?:b|c)$/x', 'width' => 1, 'expected' => self::lines('/', '    ^a{ 1,2 }', '    (?:', '        b', '      | c', '    )', '    $', '/x'), 'pcreVersion' => $pcreVersion];
            yield 'in a (?x:...) of a pattern without x, '.$target => ['pattern' => '/(?x:a{ 1,2 })(?:b)/', 'width' => 1, 'expected' => self::lines('/', '    (?x:', '        a{ 1,2 }', '    )', '    (?:', '        b', '    )', '/x'), 'pcreVersion' => $pcreVersion];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, mode: string, width: int, pcreVersion: ?string}>
     */
    public static function provideShapesThatOnceBrokeTheSelfCheck(): iterable
    {
        foreach (self::targets() as $target => $pcreVersion) {
            // "\c" takes the next character whatever it is: the "{" or the
            // "\" after it opens nothing.
            yield 'spaces after a control brace, compacted, '.$target => ['pattern' => '/\c{ 1 }/x', 'mode' => 'compact', 'width' => 80, 'pcreVersion' => $pcreVersion];
            yield 'a reference after a control backslash, compacted, '.$target => ['pattern' => '/(a)\c\\1 0/x', 'mode' => 'compact', 'width' => 80, 'pcreVersion' => $pcreVersion];
            yield 'spaced braces after a control backslash, laid out, '.$target => ['pattern' => '/(foo)(bar)\c\{ 1,2 }/x', 'mode' => 'format', 'width' => 6, 'pcreVersion' => $pcreVersion];
            // A comment group whose last line is blank.
            yield 'a comment group ending on a blank line, laid out, '.$target => ['pattern' => "/(?#\n  z\n  )(a)(b)/", 'mode' => 'format', 'width' => 6, 'pcreVersion' => $pcreVersion];
            yield 'a comment group ending in a line feed, laid out, '.$target => ['pattern' => "/(?#z\n)(a)(b)/", 'mode' => 'format', 'width' => 6, 'pcreVersion' => $pcreVersion];
            // A group that holds nothing but a comment.
            yield 'a group holding only a comment, compacted, '.$target => ['pattern' => "/a(?:#c\n)b/x", 'mode' => 'compact', 'width' => 80, 'pcreVersion' => $pcreVersion];
            yield 'a group holding only a comment, alone, compacted, '.$target => ['pattern' => "/(?:#c\n)/x", 'mode' => 'compact', 'width' => 80, 'pcreVersion' => $pcreVersion];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string, pcreVersion: ?string}>
     */
    public static function provideSpacedQuantifiersInAScopeWithoutX(): iterable
    {
        foreach (self::targets() as $target => $pcreVersion) {
            yield 'in a (?-x:...) of a pattern without x, '.$target => ['pattern' => '/(?-x:a{ 1,2 })(?:b)/', 'width' => 1, 'expected' => self::lines('/', '    (?-x:a{ 1,2 })', '    (?:', '        b', '    )', '/x'), 'pcreVersion' => $pcreVersion];
            yield 'in a (?^:...) of a pattern without x, '.$target => ['pattern' => '/(?^:a{ 1,2 })(?:b)/', 'width' => 1, 'expected' => self::lines('/', '    (?^:a{ 1,2 })', '    (?:', '        b', '    )', '/x'), 'pcreVersion' => $pcreVersion];
        }
    }

    /**
     * @return iterable<string, array{pattern: string, subject: string, mark: ?string, expected: string}>
     */
    public static function provideBracketsTheDelimiterBalanceNeeds(): iterable
    {
        yield 'a mark name holding an opening brace' => ['pattern' => '{(*MARK:{)(?#})a}', 'subject' => 'a', 'mark' => '{', 'expected' => '{(*MARK:{)(?#})a}'];
        yield 'a short mark name holding an opening brace' => ['pattern' => '{(*:{)(?#})a}', 'subject' => 'a', 'mark' => '{', 'expected' => '{(*:{)(?#})a}'];
        // PHP skips the character after a backslash: "\{" is not counted.
        yield 'an escaped brace before the mark name' => ['pattern' => '{\{(*MARK:{)(?#})a}', 'subject' => '{a', 'mark' => '{', 'expected' => '{\{(*MARK:{)(?#})a}'];
        // The comment's brace pairs with a literal one, not with the name's:
        // every comment holding a bracket is kept.
        yield 'a mark name brace paired past a literal brace' => ['pattern' => '{(*MARK:{){(?#})}a}', 'subject' => '{}a', 'mark' => '{', 'expected' => '{(*MARK:{){(?#})}a}'];
        yield 'a mark name holding an opening square bracket' => ['pattern' => '[(*MARK:[)(?#])a]', 'subject' => 'a', 'mark' => '[', 'expected' => '[(*MARK:[)(?#])a]'];
        yield 'a mark name holding an opening angle bracket' => ['pattern' => '<(*MARK:<)(?#>)a>', 'subject' => 'a', 'mark' => '<', 'expected' => '<(*MARK:<)(?#>)a>'];
        yield 'a mark name holding a closing brace' => ['pattern' => '{(?#{)(*MARK:})a}', 'subject' => 'a', 'mark' => '}', 'expected' => '{(?#{)(*MARK:})a}'];
        yield 'a callout string in double quotes holding an opening brace' => ['pattern' => '{(?C"{")(?#})a}', 'subject' => 'a', 'mark' => null, 'expected' => '{(?C"{")(?#})a}'];
        yield 'a callout string in braces holding an opening brace' => ['pattern' => '{(?C{{})(?#})a}', 'subject' => 'a', 'mark' => null, 'expected' => '{(?C{{})(?#})a}'];
        // Only the comment the balance needs stays; x and the rest go.
        yield 'under x, the other comment dropped' => ['pattern' => '{(*MARK:{)(?#}) a (?#c) b}x', 'subject' => 'ab', 'mark' => '{', 'expected' => '{(*MARK:{)(?#})ab}'];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideCommentGroupsHoldingALineFeed(): iterable
    {
        yield 'in a group' => ['pattern' => "/(?:ab(?#one\n   two)c)d/", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        ab(?#one', '        two)c', '    )', '    d', '/x')];
        yield 'at the top level' => ['pattern' => "/a(?#one\n   two)b(?:cd)/", 'width' => 1, 'expected' => self::lines('/', '    a(?#one', '    two)b', '    (?:', '        cd', '    )', '/x')];
        yield 'three lines' => ['pattern' => "/(?:ab(?#one\n two\n      three)c)d/", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        ab(?#one', '        two', '        three)c', '    )', '    d', '/x')];
        yield 'laid out already at another indent' => ['pattern' => "/\n  (?:\n    ab(?#one\n  two)c\n  )\n  d\n/x", 'width' => 1, 'expected' => self::lines('/', '    (?:', '        ab(?#one', '        two)c', '    )', '    d', '/x')];
    }

    /**
     * Shapes of a comment group holding a line feed for the invariants that
     * run on every formatted pattern: no line of indentation alone, the
     * same pattern once formatted twice or re-indented.
     *
     * @return iterable<string, array{pattern: string, width: int}>
     */
    public static function provideCommentGroupLineFeedShapes(): iterable
    {
        yield 'a blank line in the comment' => ['pattern' => "/(?:ab(?#one\n\n   two)c)d/", 'width' => 1];
        yield 'a line of white space in the comment' => ['pattern' => "/(?:ab(?#one\n   \n   two)c)d/", 'width' => 1];
        yield 'a comment closing on its own line' => ['pattern' => "/(?:ab(?#one\n   )c)d/", 'width' => 1];
        yield 'a comment that is a line feed' => ['pattern' => "/(?:ab(?#\n)c)d/", 'width' => 1];
        yield 'deep in an x pattern with a # comment' => ['pattern' => "/(?:(?:ab(?#one\n         two)c) # trail\n)d/x", 'width' => 80];
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
            self::provideLineBreaksThatAreNotLayout(),
            self::provideExtendedModeScopeLeaks(),
            self::provideLayoutsThatMustNotGrow(),
            self::provideMarkerLookalikes(),
            self::provideRawNoBreakSpaces(),
            self::provideCommentGroupsHoldingALineFeed(),
            self::provideCommentGroupLineFeedShapes(),
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
        yield 'a string callout keeps its quote and its space' => ['pattern' => "/a(?C'b c')(?:dd)/", 'width' => 1, 'expected' => self::lines('/', "    a(?C'b c')", '    (?:', '        dd', '    )', '/x')];
        $e = str_repeat('e', 80);
        yield 'a conditional in a branch after (?-x) stays glued' => ['pattern' => "/(a)(?:b(?-x)|(?(1)c|d){$e})/x", 'width' => 80, 'expected' => self::lines('/', '    (a)', '    (?:', "        b(?-x)|(?(1)c|d){$e})", '/x')];
        yield 'a DEFINE that breaks breaks its body too, which would fit' => ['pattern' => '/(?(DEFINE)(?<a>x)(?<b>y))z/', 'width' => 24, 'expected' => self::lines('/', '    (?(DEFINE)', '        (?<a>x)', '        (?<b>y)', '    )', '    z', '/x')];
        yield 'a branch after a DEFINE that broke tries its own line' => ['pattern' => '/(?(DEFINE)(?<a>xxxxx)(?<b>yyyy))|abc(?:d)/', 'width' => 20, 'expected' => self::lines('/', '    (?(DEFINE)', '        (?<a>xxxxx)', '        (?<b>yyyy)', '    )', '  | abc(?:d)', '/x')];
        yield 'a conditional that breaks lays both branches out' => ['pattern' => '/(a)(?(1)b c(?:d)|d e(?:f))f/x', 'width' => 12, 'expected' => self::lines('/', '    (a)', '    (?(1)', '        bc', '        (?:', '            d', '        )', '      | de', '        (?:', '            f', '        )', '    )', '    f', '/x')];
    }

    /**
     * @return iterable<string, array{pattern: string, width: int, expected: string}>
     */
    public static function provideCompactions(): iterable
    {
        yield 'drops the global x, its layout and its comments' => ['pattern' => "/^ a b # c\n (?: d | e ) \$/x", 'width' => 80, 'expected' => '/^ab(?:d|e)$/'];
        yield 'joins a laid-out pattern' => ['pattern' => "/\n    ^\n    (?:\n        a\n      | b\n    )\n    \$\n/ix", 'width' => 80, 'expected' => '/^(?:a|b)$/i'];
        yield 'keeps an inline (?x)' => ['pattern' => '/^(?x) a b (?:c | d) $/', 'width' => 80, 'expected' => '/^(?x)ab(?:c|d)$/'];
        yield 'keeps a scoped (?x:...)' => ['pattern' => '/^(?x: a b ) c$/', 'width' => 80, 'expected' => '/^(?x:ab) c$/'];
        yield 'drops a comment group' => ['pattern' => '/a(?#c)b/', 'width' => 80, 'expected' => '/ab/'];
        // A line feed is not a space inside braces: no engine reads a repeat.
        yield 'braces holding a line feed under x' => ['pattern' => "/a{ 1\n ,2 }b/x", 'width' => 80, 'expected' => '/a{(?:)1,2}b/'];
        yield 'a brace opened by a line feed under x' => ['pattern' => "/a{\n2}b/x", 'width' => 80, 'expected' => '/a{(?:)2}b/'];
        yield 'keeps the spelling of what x needed escaped' => ['pattern' => '/a\ b [ ] \#/x', 'width' => 80, 'expected' => '/a\ b[ ]\#/'];
        yield 'a backreference before a digit' => ['pattern' => '/(a)\1 0/x', 'width' => 80, 'expected' => '/(a)\1(?:)0/'];
        yield 'ten groups, a backreference before a digit' => ['pattern' => '/^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)\1 0\10(?:x|y)$/x', 'width' => 80, 'expected' => '/^(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)\1(?:)0\10(?:x|y)$/'];
        yield 'a comment inside a brace keeps a separator' => ['pattern' => '/a{(?#c),2}/', 'width' => 80, 'expected' => '/a{(?:),2}/'];
        yield 'a comment between an atom and its quantifier' => ['pattern' => "/a # c\n +/x", 'width' => 80, 'expected' => '/a+/'];
        yield 'a closing \\c\\ keeps the \\E that ends its backslash' => ['pattern' => '/a\c\\\E/', 'width' => 80, 'expected' => '/a\c\\\E/'];
        yield 'a raw line feed is spelled as an escape' => ['pattern' => "/a\nb/", 'width' => 80, 'expected' => '/a\nb/'];
        yield 'a raw carriage return is spelled as an escape' => ['pattern' => "/a\rb/", 'width' => 80, 'expected' => '/a\rb/'];
        // Under x a class keeps its white space: "/[a\nb]/x" matches "\n".
        yield 'a raw line feed in a class under x is spelled as an escape' => ['pattern' => "/[a\nb]/x", 'width' => 80, 'expected' => '/[a\nb]/'];
        yield 'a raw line feed inside (?-x:...) is spelled as an escape' => ['pattern' => "/(?-x:a\nb) c/x", 'width' => 80, 'expected' => '/(?-x:a\nb)c/'];
        yield 'a one-line pattern without x is unchanged' => ['pattern' => '/abc/i', 'width' => 80, 'expected' => '/abc/i'];
        yield 'the width does not matter' => ['pattern' => '/(?:abc)(?:def)/', 'width' => 1, 'expected' => '/(?:abc)(?:def)/'];
        yield 'a quoted subroutine call on a relative group keeps its quotes' => ['pattern' => "/(a)\\g'+1'(b)/", 'width' => 80, 'expected' => "/(a)\\g'+1'(b)/"];
        yield 'a written \\E before a digit is kept' => ['pattern' => '/(a)\1\E0/', 'width' => 80, 'expected' => '/(a)\1\E0/'];
        yield 'a written \\E keeps no white space around it' => ['pattern' => '/(a)\1 \E 0/x', 'width' => 80, 'expected' => '/(a)\1\E0/'];
        // "\\1" is an escaped backslash, then the digit: no reference.
        yield 'an escaped backslash before digits needs no separator' => ['pattern' => '/\\\\1 2/x', 'width' => 80, 'expected' => '/\\\\12/'];
        yield 'two brace delimiters a dropped comment leaves alone are both escaped' => ['pattern' => '{a{2(?#})b{3(?#})}', 'width' => 80, 'expected' => '{a\{2b\{3}'];
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

    /**
     * The running engine, and PCRE2 10.40 as PHP 8.2 ships it.
     *
     * @return array<string, ?string>
     */
    private static function targets(): array
    {
        return ['the running engine' => null, 'PCRE2 10.40' => '10.40'];
    }

    private static function regexFor(?string $pcreVersion): Regex
    {
        return Regex::create(null === $pcreVersion ? ['cache' => new NullCache()] : ['cache' => new NullCache(), 'pcre_version' => $pcreVersion]);
    }

    private static function formatterFor(?string $pcreVersion, int $width = 80): PatternFormatter
    {
        return new PatternFormatter($width, null === $pcreVersion ? null : new PcreTarget(\PHP_VERSION_ID, $pcreVersion));
    }

    /**
     * Read for the target, the output is the input's tree.
     */
    private function assertSameTreeFor(?string $pcreVersion, string $input, string $output): void
    {
        $regex = self::regexFor($pcreVersion);

        $this->assertSame(PatternEquivalence::structure($regex->parse($input)), PatternEquivalence::structure($regex->parse($output)), $output);
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
