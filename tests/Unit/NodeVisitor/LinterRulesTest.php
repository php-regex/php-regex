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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LinterRulesTest extends TestCase
{
    public function test_nested_quantifier_warning(): void
    {
        // The "$" makes the loop matter: (a+)+$ on a{20}b takes 2 621 440
        // steps, while (a+)+ alone, nothing after it, takes 6.
        $issues = $this->lint('/(a+)+$/');
        $this->assertContains('regex.lint.quantifier.nested', $issues);
    }

    public function test_nested_quantifier_warning_skips_possessive_quantifiers(): void
    {
        $issues = $this->lint('/(?:a*+)+/');
        $this->assertNotContains('regex.lint.quantifier.nested', $issues);

        $issues = $this->lint('/(?:a+)++/');
        $this->assertNotContains('regex.lint.quantifier.nested', $issues);
    }

    #[DataProvider('provideNestedQuantifierPatterns')]
    public function test_nested_quantifier_warning_respects_separators(string $pattern, bool $shouldWarn): void
    {
        $issues = $this->lint($pattern);
        $hasWarning = \in_array('regex.lint.quantifier.nested', $issues, true);

        $this->assertSame($shouldWarn, $hasWarning);
    }

    public function test_dotstar_in_unbounded_quantifier_warning(): void
    {
        $issues = $this->lint('/(?:.*)+/');
        $this->assertContains('regex.lint.dotstar.nested', $issues);
    }

    public function test_redundant_non_capturing_group_warning(): void
    {
        $issues = $this->lint('/(?:a)/');
        $this->assertContains('regex.lint.group.redundant', $issues);
    }

    /**
     * Removing the group would join an escape and the digit after it into
     * another escape: "(a)\1(?:)0" is not "(a)\10", "\01(?:)2" is not the
     * newline "\012". The group is not redundant there.
     */
    #[DataProvider('provideGroupsKeepingAnEscapeFromADigit')]
    public function test_redundant_group_warning_skips_a_group_that_keeps_an_escape_from_a_digit(string $pattern, string $withoutGroup, string $subject): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49.
        $this->assertSame(1, preg_match($pattern, $subject), $pattern);
        $this->assertSame(0, preg_match($withoutGroup, $subject), $withoutGroup);

        $this->assertNotContains('regex.lint.group.redundant', $this->lint($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, withoutGroup: string, subject: string}>
     */
    public static function provideGroupsKeepingAnEscapeFromADigit(): iterable
    {
        yield 'empty group after a reference' => ['pattern' => '/^(a)\\1(?:)0$/', 'withoutGroup' => '/^(a)\\10$/', 'subject' => 'aa0'];
        yield 'empty group after an octal escape' => ['pattern' => '/^\\01(?:)2$/', 'withoutGroup' => '/^\\012$/', 'subject' => "\x012"];
        yield 'group around the digit after a reference' => ['pattern' => '/^(a)\\1(?:0)$/', 'withoutGroup' => '/^(a)\\10$/', 'subject' => 'aa0'];
        yield 'group around the reference before a digit' => ['pattern' => '/^(a)(?:\\1)0$/', 'withoutGroup' => '/^(a)\\10$/', 'subject' => 'aa0'];
        yield 'group around the hex digit after a short hex escape' => ['pattern' => '/^\\xa(?:b)$/', 'withoutGroup' => '/^\\xab$/', 'subject' => "\nb"];
    }

    public function test_redundant_group_warning_still_reports_a_group_beside_an_escape_it_does_not_extend(): void
    {
        // "(a)\1b" reads as "(a)\1(?:b)": the letter extends no escape.
        $this->assertSame(1, preg_match('/^(a)\\1b$/', 'aab'));
        $this->assertContains('regex.lint.group.redundant', $this->lint('/(a)\\1(?:b)/'));
    }

    public function test_alternation_duplicate_warning(): void
    {
        $issues = $this->lint('/(a|a)/');
        $this->assertContains('regex.lint.alternation.duplicateDisjunction', $issues);
    }

    public function test_alternation_overlap_warning(): void
    {
        // Overlapping alternation must be inside an unbounded quantifier to trigger warning
        // (patterns like /(a|aa)/ without quantifier don't pose ReDoS risk)
        $issues = $this->lint('/(a|aa)+/');
        $this->assertContains('regex.lint.alternation.overlap', $issues);
    }

    /**
     * The branches are named by the text they match, not as pattern
     * source: a backslash in that text is doubled, so that it never reads
     * as the start of an escape ("a\" was printed as "a\" before, which
     * reads as an escaped quote), and "\Q" is two plain characters.
     *
     * @return iterable<string, array{pattern: string, first: string, second: string, message: string}>
     */
    public static function provideOverlapMessages(): iterable
    {
        yield 'branches ending with a backslash' => [
            'pattern' => '/(?:a\\\\|a\\\\b)+/',
            'first' => 'a\\',
            'second' => 'a\\b',
            'message' => 'Alternation branches "a\\\\" and "a\\\\b" overlap.',
        ];
        yield 'branches holding a backslash and a Q' => [
            'pattern' => '/(?:a\\\\Q|a\\\\Qb)+/',
            'first' => 'a\\Q',
            'second' => 'a\\Qb',
            'message' => 'Alternation branches "a\\\\Q" and "a\\\\Qb" overlap.',
        ];
    }

    #[DataProvider('provideOverlapMessages')]
    public function test_alternation_overlap_message_spells_the_branch_text(string $pattern, string $first, string $second, string $message): void
    {
        // Oracle: each branch matches its text, and the first one a prefix
        // of the second one's.
        $this->assertSame(1, preg_match('/^(?:'.substr($pattern, 4, -3).')$/', $first));
        $this->assertSame(1, preg_match('/^(?:'.substr($pattern, 4, -3).')$/', $second));
        $this->assertSame($first, substr($second, 0, \strlen($first)));

        $this->assertContains($message, $this->lintMessages($pattern));
    }

    public function test_alternation_duplicate_with_lookarounds(): void
    {
        $issues = $this->lint('/(?=foo)|(?=foo)/');
        $this->assertContains('regex.lint.alternation.duplicateDisjunction', $issues);
    }

    public function test_redundant_char_class_warning(): void
    {
        $issues = $this->lint('/[a-zA-Za-z]/');
        $this->assertContains('regex.lint.charclass.redundant', $issues);
    }

    public function test_suspicious_char_class_range_warning(): void
    {
        $issues = $this->lint('/[A-z]/');
        $this->assertContains('regex.lint.charclass.suspiciousRange', $issues);
    }

    public function test_suspicious_char_class_range_message_uses_ascii_order(): void
    {
        $regex = Regex::create()->parse('/[A-z]/');
        $linter = new PatternLinter();
        $regex->accept($linter);

        $issues = array_values(array_filter(
            $linter->getIssues(),
            static fn ($issue): bool => 'regex.lint.charclass.suspiciousRange' === $issue->id,
        ));

        $this->assertCount(1, $issues);
        $this->assertSame(
            'Suspicious ASCII range "A-z" includes non-letters between "A" and "z" in ASCII order.',
            $issues[0]->message,
        );
    }

    public function test_suspicious_char_class_pipe_warning(): void
    {
        $issues = $this->lint('/[error|failure]/');
        $this->assertContains('regex.lint.charclass.suspiciousPipe', $issues);
    }

    public function test_suspicious_char_class_pipe_ignores_small_sets(): void
    {
        $issues = $this->lint('/[a|b]/');
        $this->assertNotContains('regex.lint.charclass.suspiciousPipe', $issues);
    }

    public function test_inline_flag_redundant_warning(): void
    {
        $issues = $this->lint('/(?i:foo)/i');
        $this->assertContains('regex.lint.flag.redundant', $issues);
    }

    public function test_inline_flag_override_warning(): void
    {
        $issues = $this->lint('/(?-i:foo)/i');
        $this->assertContains('regex.lint.flag.override', $issues);
    }

    public function test_inline_flag_override_respects_preceding_inline_flag(): void
    {
        $issues = $this->lint('/(?i)(?-i:foo)/');
        $this->assertContains('regex.lint.flag.override', $issues);
        $this->assertNotContains('regex.lint.flag.redundant', $issues);
    }

    public function test_inline_flag_redundant_message_names_the_global_modifier(): void
    {
        $messages = $this->lintMessages('/(?i:foo)/i');

        $this->assertContains("Inline flag 'i' is redundant; it is already set globally.", $messages);
    }

    public function test_inline_flag_redundant_message_names_the_preceding_inline_group(): void
    {
        $messages = $this->lintMessages('/(?U)a(?U)b/');

        $this->assertContains("Inline flag 'U' is redundant; it is already set by an earlier inline flag group.", $messages);
    }

    public function test_inline_flag_redundant_ignores_case_of_global_modifier(): void
    {
        $issues = $this->lint('/(?U)a/u');

        $this->assertNotContains('regex.lint.flag.redundant', $issues);
    }

    public function test_suspicious_unicode_escape_warning(): void
    {
        $issues = $this->lint('/\\x{110000}/');
        $this->assertContains('regex.lint.escape.suspicious', $issues);
    }

    /**
     * The rule looks a "\N{name}" up by its name. PCRE2 compiles no
     * "\N{name}" at all (only "\N{U+hhhh}", in UTF mode), and validate()
     * says so too, so the lint command never lints one: this is the rule on
     * a parsed tree, as the linter visitor sees it.
     *
     * @param list<string> $expected
     */
    #[DataProvider('provideUnicodeNamedEscapes')]
    public function test_suspicious_escape_looks_up_the_unicode_character_name(string $pattern, array $expected): void
    {
        if ('/\\N{NO SUCH CHARACTER}/u' === $pattern && !PcreTarget::runtime()->pcreAtLeast('10.45')) {
            $this->markTestSkipped(\sprintf('%s is verified against PCRE2 10.45 and later; PCRE2 %s reports it differently.', $pattern, \PCRE_VERSION));
        }

        $this->assertFalse(@preg_match($pattern, ''), 'Oracle: PCRE2 compiles no \N{name}.');
        $this->assertFalse(Regex::create(['cache' => null])->validate($pattern)->isValid);

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $messages = [];
        foreach ($linter->getIssues() as $issue) {
            if ('regex.lint.escape.suspicious' === $issue->id) {
                $messages[] = $issue->message;
            }
        }

        $this->assertSame($expected, $messages);
    }

    /**
     * @return iterable<string, array{pattern: string, expected: list<string>}>
     */
    public static function provideUnicodeNamedEscapes(): iterable
    {
        yield 'known name' => ['pattern' => '/\\N{LATIN SMALL LETTER A}/u', 'expected' => []];
        yield 'unknown name' => ['pattern' => '/\\N{NO SUCH CHARACTER}/u', 'expected' => ['Unknown Unicode character name "NO SUCH CHARACTER".']];
    }

    public function test_useless_flag_s_warning(): void
    {
        $issues = $this->lint('/no_dot/s');
        $this->assertContains('regex.lint.flag.useless.s', $issues);
    }

    /**
     * @return \Iterator<string, array{pattern: string, shouldWarn: bool}>
     */
    public static function provideNestedQuantifierPatterns(): \Iterator
    {
        yield 'safe dot separator' => ['pattern' => '/([0-9]+(?:\\.[0-9]+)*)/', 'shouldWarn' => false];
        yield 'safe hyphen separator' => ['pattern' => '/(a+(?:-a+)*)/', 'shouldWarn' => false];
        yield 'safe comma separator' => ['pattern' => '/(\\d+(?:,\\d+)*)/', 'shouldWarn' => false];
        // Anchored: with nothing after it, the loop never backtracks
        // (a trailing loop is the "trailing" rows below).
        yield 'unsafe no separator' => ['pattern' => '/(a+(?:a+)*)$/', 'shouldWarn' => true];
        yield 'unsafe overlapping separator' => ['pattern' => '/(\\w+(?:_\\w+)*)$/', 'shouldWarn' => true];
        yield 'unsafe direct overlap' => ['pattern' => '/([0-9]+(?:[0-9]+)*)$/', 'shouldWarn' => true];
        yield 'trailing, no separator' => ['pattern' => '/(a+(?:a+)*)/', 'shouldWarn' => false];
        yield 'trailing, overlapping separator' => ['pattern' => '/(\\w+(?:_\\w+)*)/', 'shouldWarn' => false];
        yield 'trailing, direct overlap' => ['pattern' => '/([0-9]+(?:[0-9]+)*)/', 'shouldWarn' => false];
    }

    /**
     * Each row: pattern, rule id, whether it is reported, and an input on
     * which the engine shows the verdict (a reported pattern exhausts a
     * backtrack limit of 100 000 there; one left alone stays far under it).
     * Measured with (*NO_START_OPT) and JIT off, PCRE2 10.49.
     *
     * @return iterable<string, array{pattern: string, ruleId: string, reported: bool, subject: string}>
     */
    public static function provideRedosHeuristicVerdicts(): iterable
    {
        $dotStar = 'regex.lint.dotstar.nested';
        $nested = 'regex.lint.quantifier.nested';
        $overlap = 'regex.lint.overlap.charset';

        // Without s the dot stops at "\n": the iterations cannot overlap.
        yield 'dot star up to a newline' => ['pattern' => '/(?:.*\n)+x/', 'ruleId' => $dotStar, 'reported' => false, 'subject' => str_repeat("a\n", 40)];
        yield 'dot star up to a newline, nested rule' => ['pattern' => '/(?:.*\n)+x/', 'ruleId' => $nested, 'reported' => false, 'subject' => str_repeat("\n", 40)];
        yield 'dot star up to a newline under s' => ['pattern' => '/(?:.*\n)+x/s', 'ruleId' => $dotStar, 'reported' => true, 'subject' => str_repeat("\n", 20)];
        yield 'dot star up to a newline under inline s' => ['pattern' => '/(?s)(?:.*\n)+x/', 'ruleId' => $dotStar, 'reported' => true, 'subject' => str_repeat("\n", 20)];
        yield 'dot star up to a newline under inline s inside the loop' => ['pattern' => '/(?:(?s).*\n)+x/', 'ruleId' => $dotStar, 'reported' => true, 'subject' => str_repeat("\n", 20)];

        // A bounded outer quantifier, and nothing after it.
        yield 'bounded outer quantifier' => ['pattern' => '/(a+){1,5}/', 'ruleId' => $nested, 'reported' => false, 'subject' => str_repeat('a', 28).'b'];
        // Friedl's unrolled loop: the separator \\. cannot start an iteration
        // of [^"\\]*, so each character has one way in.
        yield 'unrolled quoted string' => ['pattern' => '/"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"/', 'ruleId' => $nested, 'reported' => false, 'subject' => '"'.str_repeat('a\\a', 80)];
        // Nothing at all follows the loop: the first way through matches.
        yield 'trailing nested loop' => ['pattern' => '/(a+)+/', 'ruleId' => $nested, 'reported' => false, 'subject' => str_repeat('a', 28).'b'];
        yield 'trailing loop with an optional separator' => ['pattern' => '/x(\\d+\\.?)+/', 'ruleId' => $nested, 'reported' => false, 'subject' => 'x'.str_repeat('1', 25).'!'];
        // An anchor or a lookahead after the loop can fail and send it back.
        yield 'loop before an end anchor' => ['pattern' => '/(a+)+$/', 'ruleId' => $nested, 'reported' => true, 'subject' => str_repeat('a', 20).'b'];
        yield 'loop before a lookahead' => ['pattern' => '/(?:a+)+(?=b)/', 'ruleId' => $nested, 'reported' => true, 'subject' => str_repeat('a', 20)];
        // The group ends the pattern, but (?1) runs it before the "z".
        yield 'trailing loop a subroutine calls' => ['pattern' => '/(?1)z((a+)+)/', 'ruleId' => $nested, 'reported' => true, 'subject' => str_repeat('a', 20)];
        // The same, called by name before the group (1 572 865 steps on a{20}).
        yield 'trailing loop a named call reaches first' => ['pattern' => '/(?&n)z(?<n>(?:a+)+)/', 'ruleId' => $nested, 'reported' => true, 'subject' => str_repeat('a', 20)];

        // The alternation inside a lookbehind is no branch of the loop.
        yield 'lookbehind alternation inside a loop' => ['pattern' => '/(?:(?<!http:|https:).)*x/', 'ruleId' => $overlap, 'reported' => false, 'subject' => str_repeat('a', 80)];
        yield 'overlapping branches in an anchored loop' => ['pattern' => '/(?:[a-m]|[a-z])+$/', 'ruleId' => $overlap, 'reported' => true, 'subject' => str_repeat('a', 20).'!'];
    }

    #[DataProvider('provideRedosHeuristicVerdicts')]
    public function test_redos_heuristic_verdict_agrees_with_the_engine(string $pattern, string $ruleId, bool $reported, string $subject): void
    {
        $this->assertSame($reported, self::exhaustsBacktrackLimit($pattern, $subject), 'Oracle disagrees with the row.');

        $issues = $this->lint($pattern);

        $reported
            ? $this->assertContains($ruleId, $issues, $pattern)
            : $this->assertNotContains($ruleId, $issues, $pattern);
    }

    /**
     * An outer quantifier with a bound of at most 2 keeps the loop linear;
     * a larger bound is polynomial and stays reported. Steps on a{n}b
     * (JIT off, (*NO_START_OPT)): {1,2} 44 -> 86 for n 14 -> 28, {0,3}
     * 305 -> 1 194, {1,5} 4 035 -> 69 170.
     *
     * @return iterable<string, array{pattern: string, reported: bool}>
     */
    public static function provideBoundedOuterQuantifiers(): iterable
    {
        yield 'at most two iterations' => ['pattern' => '/(a+){1,2}$/', 'reported' => false];
        yield 'zero to two iterations' => ['pattern' => '/(a+){0,2}$/', 'reported' => false];
        yield 'zero to three iterations' => ['pattern' => '/(a+){0,3}$/', 'reported' => true];
        yield 'one to five iterations' => ['pattern' => '/(a+){1,5}$/', 'reported' => true];
    }

    #[DataProvider('provideBoundedOuterQuantifiers')]
    public function test_bounded_outer_quantifier_is_reported_only_beyond_two(string $pattern, bool $reported): void
    {
        // Oracle: doubling the input doubles a linear count of steps; any
        // higher degree at least quadruples it.
        $growth = self::steps($pattern, str_repeat('a', 28).'b') / self::steps($pattern, str_repeat('a', 14).'b');
        $this->assertSame($reported, $growth > 3, \sprintf('Oracle disagrees with the row: growth %.2f.', $growth));

        $issues = $this->lint($pattern);

        $reported
            ? $this->assertContains('regex.lint.quantifier.nested', $issues, $pattern)
            : $this->assertNotContains('regex.lint.quantifier.nested', $issues, $pattern);
    }

    /**
     * Corpus (isU): the lookbehind inside the loop holds the only
     * alternation; nothing overlaps.
     */
    public function test_lookbehind_alternation_in_a_corpus_pattern_is_not_an_overlap(): void
    {
        $pattern = <<<'REGEX'
            /(Microsoft.AlphaImageLoader\s*\([^\)]*src=(?:'|")?)([^\/'"\s\)](?:(?<!http:|https:).)*)\)/isU
            REGEX;

        $this->assertSame(1, preg_match($pattern, 'Microsoft.AlphaImageLoader(src="img/a.png")'));
        $this->assertNotContains('regex.lint.overlap.charset', $this->lint($pattern));
    }

    /**
     * Corpus shapes reported on purpose: a loop bounded at four iterations
     * over items that are themselves bounded, from the start of the subject.
     * The engine cannot blow up here (every quantifier is bounded and "^"
     * fixes the start: 4 to 59 steps per attempt, whatever the length of
     * the subject), so the report is a conservative one. The rule exempts
     * only the measured-linear "(a+){1,2}$" shape of a bounded outer loop
     * and keeps the others; this pins that choice.
     *
     * @return iterable<string, array{pattern: string, units: list<string>}>
     */
    public static function provideBoundedCorpusShapes(): iterable
    {
        yield 'loop of four first' => [
            'pattern' => '/^r?([CR]J?((Z?F)?[N]{0,2})?[ZJ]?G(JN?)?){0,4}[CR]J?((Z?F)?[N]{0,2})?A?((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})?(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2}/',
            'units' => ['C', 'CG', 'CJZFNNGJN'],
        ];
        yield 'loop of four after a prefix' => [
            'pattern' => '/^(RH|r)?((Z?F)?[N]{0,2})?(([ZJ]?G(JN?)?)[CR]J?((Z?F)?[N]{0,2})?){0,4}((([ZJ]?G(JN?)?)|GZ)|(GJ)?([ZJ]{0,3}MN?(H|JHJR)?){0,4})(G([CR]J?((Z?F)?[N]{0,2})?|V))?(SZ?)?[v]{0,2}/',
            'units' => ['C', 'GJCNN', 'JM'],
        ];
    }

    /**
     * @param list<string> $units
     */
    #[DataProvider('provideBoundedCorpusShapes')]
    public function test_bounded_corpus_shape_is_reported_on_purpose(string $pattern, array $units): void
    {
        foreach ($units as $unit) {
            $long = self::steps($pattern, substr(str_repeat($unit, 64), 0, 64).'!');
            $this->assertLessThan(100, $long, \sprintf('Oracle: unit %s takes %d steps.', $unit, $long));
        }

        $this->assertContains('regex.lint.quantifier.nested', $this->lint($pattern));
    }

    /**
     * The smallest backtrack limit the match attempt runs under: the
     * engine's count of steps, JIT off, start optimizations off.
     */
    private static function steps(string $pattern, string $subject): int
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        $unoptimized = $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);

        try {
            $low = 1;
            $high = 1 << 22;
            while ($low < $high) {
                $middle = intdiv($low + $high, 2);
                ini_set('pcre.backtrack_limit', (string) $middle);
                if (false === @preg_match($unoptimized, $subject)) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            return $low;
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
    }

    private static function exhaustsBacktrackLimit(string $pattern, string $subject): bool
    {
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '100000');

        // Start-of-match optimizations would skip the search when a required
        // literal is absent; the measure is the backtracking itself.
        $unoptimized = $pattern[0].'(*NO_START_OPT)'.substr($pattern, 1);

        try {
            $result = @preg_match($unoptimized, $subject);

            return false === $result && \PREG_BACKTRACK_LIMIT_ERROR === preg_last_error();
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }
    }

    /**
     * @return array<string>
     */
    private function lintMessages(string $pattern): array
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        return array_map(static fn ($issue) => $issue->message, $linter->getIssues());
    }

    /**
     * @return array<string>
     */
    private function lint(string $pattern): array
    {
        $regex = Regex::create()->parse($pattern);
        $linter = new PatternLinter();
        $regex->accept($linter);

        return array_map(static fn ($issue) => $issue->id, $linter->getIssues());
    }
}
