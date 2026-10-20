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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A pattern a later PHP of the range reads into the same tree, yet matches
 * otherwise: the comparison of trees never sees it, the release table does.
 * Each is reported, never rewritten, with the witness the real engines give:
 * pcre2test built from the 10.33, 10.34 and 10.35 tarballs, and preg_match()
 * on php:8.1-cli (PCRE2 10.39) and php:8.2-cli (10.40).
 */
final class AnalysisServiceSilentChangeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideSilentChanges')]
    public function test_a_later_php_matching_the_same_tree_otherwise_gives_one_warning(string $pattern, string $floor, string $later, string $message, string $hint, string $php, string $pcre): void
    {
        $issues = $this->rangeIssues($this->analysis($floor, [$later]), $pattern);

        $this->assertCount(1, $issues, $pattern);
        $this->assertSame('warning', $issues[0]->severity->level());
        $this->assertSame('regex.lint.compat.meaningChanges', $issues[0]->issueId);
        $this->assertSame($message, $issues[0]->message);
        $this->assertSame($hint, $issues[0]->hint);
        $this->assertSame(['php' => $php, 'pcre' => $pcre], $issues[0]->target);
        $this->assertSame(['src/a.php', 3, 17, 42, 'preg_match'], [$issues[0]->file, $issues[0]->line, $issues[0]->column, $issues[0]->fileOffset, $issues[0]->source]);
    }

    /**
     * Each spelling a change reaches is reported, each one that keeps the
     * older reading is not: the release table names the construct, not the
     * pattern.
     */
    #[Test]
    #[DataProvider('provideSpellingsReached')]
    public function test_each_spelling_the_change_reaches_is_reported(string $pattern, string $floor, string $later, bool $reported): void
    {
        $this->assertCount($reported ? 1 : 0, $this->rangeIssues($this->analysis($floor, [$later]), $pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, floor: string, later: string, reported: bool}>
     */
    public static function provideSpellingsReached(): iterable
    {
        yield 'once spelled twice' => ['pattern' => '/(a|ab){1,1}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'once on a branch reset group' => ['pattern' => '/(?|(a)|(ab)){1}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'once on a script run' => ['pattern' => '/(*sr:a|ab){1}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'once on an atomic script run' => ['pattern' => '/(*asr:a|ab){1}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'once on a subroutine call' => ['pattern' => '/(a|ab)(?1){1}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'twice, possessive' => ['pattern' => '/(a|ab){2}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'once or twice, possessive' => ['pattern' => '/(a|ab){1,2}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'once on a lookahead' => ['pattern' => '/(?=a|ab){1}+c/', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'an assertion one or more times' => ['pattern' => '/^(?=(\1?a))+/', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'an assertion any number of times' => ['pattern' => '/^(?=(\1?a)){0,}/', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'a caseless option under (*UCP)' => ['pattern' => '/(*UCP)(?i)\xC9/', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'a caseless group under (*UCP)' => ['pattern' => '/(*UCP)(?i:\xC9)/', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'a caseless option taken off under (*UCP)' => ['pattern' => '/(*UCP)(?-i:\xC9)/', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'a range past ASCII under (*UCP)' => ['pattern' => '/(*UCP)[\x41-\xC9]/i', 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
        yield 'a range up to 0x7F under (*UCP)' => ['pattern' => '/(*UCP)[\x41-\x7F]/i', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'the character 0x7F under (*UCP)' => ['pattern' => '/(*UCP)\x7F/i', 'floor' => '7.4', 'later' => '8.0', 'reported' => false];
        yield 'a raw byte past ASCII under (*UCP)' => ['pattern' => "/(*UCP)\xC9/i", 'floor' => '7.4', 'later' => '8.0', 'reported' => true];
    }

    /**
     * A pattern a later PHP refuses, or parses into another tree, gets that
     * verdict alone: the change of meaning that keeps the tree is not added.
     */
    #[Test]
    public function test_a_refusal_or_another_tree_comes_alone(): void
    {
        $otherTree = $this->rangeIssues($this->analysis('7.4', ['8.4']), '/(a|ab){1}+c{,3}/');
        $this->assertCount(1, $otherTree);
        $message = $otherTree[0]->message;
        $this->assertIsString($message);
        $this->assertStringContainsString('parses differently', (string) $message);

        $refused = $this->rangeIssues($this->analysis('7.4', ['8.5']), '/(?<=a\Kb)(a|ab){1}+c/');
        $this->assertCount(1, $refused);
        $this->assertSame('error', $refused[0]->severity->level());
    }

    /**
     * @return iterable<string, array{pattern: string, floor: string, later: string, message: string, hint: string, php: string, pcre: string}>
     */
    public static function provideSilentChanges(): iterable
    {
        // pcre2test 10.33: "abc"; 10.34: no match.
        yield 'a possessive repeat once on a group' => [
            'pattern' => '/(a|ab){1}+c/',
            'floor' => '7.4',
            'later' => '8.0',
            'message' => 'From PHP 8.0 (PCRE2 10.35) "{1}+" makes the group it repeats atomic, as PCRE2 10.34 (PHP 7.4.6) first did; before, it was ignored as "{1}" is: /(a|ab){1}+c/ finds "abc" in "abc" on PCRE2 10.33 and nothing from 10.34.',
            'hint' => 'Write "{1}" to keep the older reading on every PHP, or an atomic group, "(?>...)", to keep the later one.',
            'php' => '8.0',
            'pcre' => '10.35',
        ];
        // pcre2test 10.34: no match in "abb"; 10.35: "abb".
        yield 'a group holding a reference to itself' => [
            'pattern' => '/^(a|ab|\1x)+b$/',
            'floor' => '7.4',
            'later' => '8.0',
            'message' => 'From PHP 8.0 (PCRE2 10.35) a group that holds a reference to itself may be backtracked into; before, PCRE2 made such a group atomic: /^(a|ab|\1x)+b$/ finds "abb" in "abb" from 10.35 and nothing before.',
            'hint' => 'Wrap the group in an atomic group, "(?>(...))", to keep the older reading on every PHP.',
            'php' => '8.0',
            'pcre' => '10.35',
        ];
        yield 'a named group holding a reference to itself' => [
            'pattern' => '/^(?<g>a|ab|\k<g>x)+b$/',
            'floor' => '7.3',
            'later' => '8.0',
            'message' => 'From PHP 8.0 (PCRE2 10.35) a group that holds a reference to itself may be backtracked into; before, PCRE2 made such a group atomic: /^(a|ab|\1x)+b$/ finds "abb" in "abb" from 10.35 and nothing before.',
            'hint' => 'Wrap the group in an atomic group, "(?>(...))", to keep the older reading on every PHP.',
            'php' => '8.0',
            'pcre' => '10.35',
        ];
        // pcre2test 10.34: group 1 "a" in "aa"; 10.35: "aa".
        yield 'a repeated assertion holding a group' => [
            'pattern' => '/^(?=(\1?a)){2}/',
            'floor' => '7.4',
            'later' => '8.0',
            'message' => 'From PHP 8.0 (PCRE2 10.35) a repeated assertion runs as many times as its count asks, the groups it holds reading each pass; before, PCRE2 ran it once: /^(?=(\1?a)){2}/ captures "aa" in "aa" from 10.35 and "a" before.',
            'hint' => 'Write the assertion once to keep the older reading on every PHP.',
            'php' => '8.0',
            'pcre' => '10.35',
        ];
        // pcre2test 10.33 and 10.34: no match of "\xE9"; 10.35: a match.
        yield 'a caseless character past ASCII under (*UCP) without UTF' => [
            'pattern' => '/(*UCP)\xC9/i',
            'floor' => '7.4',
            'later' => '8.0',
            'message' => 'From PHP 8.0 (PCRE2 10.35) "(*UCP)" without UTF folds the case of the characters past ASCII by their Unicode properties: /(*UCP)\xC9/i matches "\xE9" from 10.35 and not before.',
            'hint' => 'Spell both cases in a class, as "[\xC9\xE9]", to read alike on every PHP.',
            'php' => '8.0',
            'pcre' => '10.35',
        ];
        // PHP 8.1: preg_match('/\p{Katakana}/u', "\u{30FB}") is 0; PHP 8.2: 1.
        yield 'a bare script name' => [
            'pattern' => '/\p{Katakana}+/u',
            'floor' => '8.1',
            'later' => '8.2',
            'message' => 'From PHP 8.2 (PCRE2 10.40) \p{Katakana} holds the characters whose script extensions name the script, not only those of the script, as U+30FB for \p{Katakana}, which PHP 8.2 matches and PHP 8.1 does not.',
            'hint' => 'No spelling reads alike on both: "\p{sc:Katakana}" keeps the script alone from PHP 8.2 on, and PHP 8.1 refuses it.',
            'php' => '8.2',
            'pcre' => '10.40',
        ];
    }

    /**
     * The warning names the first PHP of the range that matches the pattern
     * the new way: PHP 7.4 on PCRE2 10.33 still made the group atomic.
     */
    #[Test]
    public function test_the_warning_names_the_first_php_matching_the_new_way(): void
    {
        $issues = $this->rangeIssues($this->analysis('7.3', ['7.4', '8.0', '8.4']), '/^(a|ab|\1x)+b$/');

        $this->assertCount(1, $issues);
        $this->assertSame(['php' => '8.0', 'pcre' => '10.35'], $issues[0]->target);
    }

    #[Test]
    public function test_the_rules_map_turns_the_warning_off(): void
    {
        $analysis = new AnalysisService(RegexParser::create(['php_version' => '7.4']), lintRules: ['compat.meaningChanges' => false], range: [RegexParser::create(['php_version' => '8.0'])]);

        $this->assertSame([], $this->rangeIssues($analysis, '/(a|ab){1}+c/'));
    }

    /**
     * The same patterns where the range does not cross the release, and the
     * forms that read alike on every release, give no warning.
     */
    #[Test]
    #[DataProvider('provideReadAlike')]
    public function test_a_pattern_every_php_of_the_range_matches_alike_gives_no_warning(string $pattern, string $floor, string $later): void
    {
        $this->assertSame([], $this->rangeIssues($this->analysis($floor, [$later]), $pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, floor: string, later: string}>
     */
    public static function provideReadAlike(): iterable
    {
        yield 'possessive repeat once from PHP 8.0' => ['pattern' => '/(a|ab){1}+c/', 'floor' => '8.0', 'later' => '8.4'];
        yield 'plain repeat once' => ['pattern' => '/(a|ab){1}c/', 'floor' => '7.4', 'later' => '8.4'];
        yield 'possessive repeat once on a character' => ['pattern' => '/a{1}+b/', 'floor' => '7.4', 'later' => '8.4'];
        yield 'possessive repeat once on an atomic group' => ['pattern' => '/(?>a|ab){1}+c/', 'floor' => '7.4', 'later' => '8.4'];
        yield 'a group holding a reference to another' => ['pattern' => '/^(a)(b|\1x)+$/', 'floor' => '7.4', 'later' => '8.4'];
        yield 'an atomic group around the group' => ['pattern' => '/^(?>(a|ab|\1x))+b$/', 'floor' => '7.4', 'later' => '8.4'];
        yield 'a self reference from PHP 8.0' => ['pattern' => '/^(a|ab|\1x)+b$/', 'floor' => '8.0', 'later' => '8.4'];
        yield 'an assertion repeated once' => ['pattern' => '/^(?=(\1?a))/', 'floor' => '7.4', 'later' => '8.4'];
        yield 'an optional assertion' => ['pattern' => '/^(?=(a))*b/', 'floor' => '7.4', 'later' => '8.4'];
        yield 'a repeated assertion holding no group' => ['pattern' => '/(?=a){2}a/', 'floor' => '7.4', 'later' => '8.4'];
        yield '(*UCP) under UTF' => ['pattern' => '/(*UCP)\x{C9}/iu', 'floor' => '7.4', 'later' => '8.4'];
        yield '(*UCP) without a caseless option' => ['pattern' => '/(*UCP)\xC9/', 'floor' => '7.4', 'later' => '8.4'];
        yield '(*UCP) caseless on ASCII only' => ['pattern' => '/(*UCP)abc/i', 'floor' => '7.4', 'later' => '8.4'];
        yield 'a caseless character past ASCII without (*UCP)' => ['pattern' => '/\xC9/i', 'floor' => '7.4', 'later' => '8.4'];
        yield 'a bare script name from PHP 8.2' => ['pattern' => '/\p{Katakana}/u', 'floor' => '8.2', 'later' => '8.4'];
        yield 'a general category' => ['pattern' => '/\p{Lu}/u', 'floor' => '8.1', 'later' => '8.4'];
    }

    /**
     * @param list<string> $range
     */
    private function analysis(string $floor, array $range): AnalysisService
    {
        $parsers = array_map(static fn (string $php): RegexParser => RegexParser::create(['php_version' => $php]), $range);

        return (new AnalysisService(RegexParser::create(['php_version' => $floor])))->withParser(RegexParser::create(['php_version' => $floor]), $parsers);
    }

    /**
     * @return list<LintIssue>
     */
    private function rangeIssues(AnalysisService $analysis, string $pattern): array
    {
        $occurrence = new PatternOccurrence($pattern, 'src/a.php', 3, 'preg_match', column: 17, fileOffset: 42);

        return array_values(array_filter(
            $analysis->lint([$occurrence]),
            static fn (LintIssue $issue): bool => null !== $issue->target,
        ));
    }
}
