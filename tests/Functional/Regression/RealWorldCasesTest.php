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

namespace PHPRegex\Tests\Functional\Regression;

use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RealWorldCasesTest extends TestCase
{
    #[DataProvider('provideRealWorldRegex')]
    public function test_real_world_cases(string $pattern, string $expectedIssueMessage): void
    {
        $report = Regex::create()->analyze($pattern);

        $issueMessages = [];
        foreach ($report->lintIssues as $issue) {
            $this->assertInstanceOf(RuleViolation::class, $issue);
            $issueMessages[] = $issue->message;
        }

        $this->assertContains($expectedIssueMessage, $issueMessages);
    }

    public static function provideRealWorldRegex(): \Iterator
    {
        // Nested quantifiers. Anchored: a loop nothing follows never
        // backtracks (see test_trailing_nested_quantifiers_are_not_reported).
        yield ['pattern' => '/(a+)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(b*)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(d{2,})*$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(e+)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(f*)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(h{3,})*$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(i+)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];

        // Flag useless
        yield ['pattern' => '@^0([0-7]+)$@i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/^\s*([^;]*);?/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/\s*(\S*?)="?([^;"]*);?/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/^--\w+=[^ ]/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/123/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/[0-9]+/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/456/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/789/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/000/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/111/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];

        // Alternation overlapping - only flagged when inside an unbounded quantifier
        // These patterns wrap overlapping alternations in quantifiers, creating ReDoS risk
        yield ['pattern' => '/(([0-9]*.[0-9]+)|([0-9]+(.[0-9]*)?))+/', 'expectedIssueMessage' => 'Alternation branches have overlapping character sets, which may cause unnecessary backtracking.'];
        yield ['pattern' => '/(([a-f][a-f0-9])|([a-f0-9][a-f]))+/', 'expectedIssueMessage' => 'Alternation branches have overlapping character sets, which may cause unnecessary backtracking.'];
        yield ['pattern' => '%^(?:[-]|[-][-]|[-][-]|[-][-]{2}|[-][-]|[-][-]{2}|[-][-]{3}|[-][-]{2})*$%xs', 'expectedIssueMessage' => 'Alternation branches have overlapping character sets, which may cause unnecessary backtracking.'];

        // Redundant elements
        yield ['pattern' => '~{args.((?:[^{}}]++|(?R))*)}~', 'expectedIssueMessage' => 'Redundant elements detected in character class.'];

        // More nested quantifiers, anchored as well.
        yield ['pattern' => '/(j+)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(k*)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(m{4,})*$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(n+)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(o*)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(q{5,})*$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(r+)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['pattern' => '/(s*)+$/', 'expectedIssueMessage' => 'Nested quantifiers can cause catastrophic backtracking.'];

        // More flag useless
        yield ['pattern' => '/222/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/333/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/444/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/555/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/666/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/777/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/888/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/999/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/000/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['pattern' => '/111/i', 'expectedIssueMessage' => "Flag 'i' is useless: the pattern contains no case-sensitive characters."];

        yield ['pattern' => '/\\r\\n|\\r/m', 'expectedIssueMessage' => "Flag 'm' is useless: the pattern contains no ^ or $ anchor."];

        $html5Pattern = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}'
            .'[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/sD';
        yield ['pattern' => $html5Pattern, 'expectedIssueMessage' => "Flag 's' is useless: the pattern contains no unescaped dot outside a character class."];

        yield [
            'pattern' => '#^(.*?)[\\\\/]*(([^/\\\\]*?)(\\.([^.\\\\/]+?)|))[\\\\/.]*$#m',
            'expectedIssueMessage' => 'Alternation contains an empty alternative.',
        ];
    }

    /**
     * The anchored forms of the optional inner loops stay reported. The
     * report is a conservative one: PCRE stops a loop on an empty
     * iteration, and the engine runs each of them linearly on x{n}! (27 ->
     * 51 steps for n 8 -> 16, PCRE2 10.49, JIT off, (*NO_START_OPT)).
     *
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideAnchoredOptionalInnerLoops(): iterable
    {
        yield '/(c?)+$/' => ['pattern' => '/(c?)+$/'];
        yield '/(g?)+$/' => ['pattern' => '/(g?)+$/'];
        yield '/(l?)+$/' => ['pattern' => '/(l?)+$/'];
        yield '/(p?)+$/' => ['pattern' => '/(p?)+$/'];
    }

    #[DataProvider('provideAnchoredOptionalInnerLoops')]
    public function test_anchored_optional_inner_loops_stay_reported(string $pattern): void
    {
        $letter = $pattern[2];
        $short = self::steps($pattern, str_repeat($letter, 8).'!');
        $long = self::steps($pattern, str_repeat($letter, 16).'!');
        $this->assertLessThanOrEqual(3, $long / $short, \sprintf('The engine went superlinear: %d -> %d steps.', $short, $long));

        $messages = [];
        foreach (Regex::create()->analyze($pattern)->lintIssues as $issue) {
            $this->assertInstanceOf(RuleViolation::class, $issue);
            $messages[] = $issue->message;
        }

        $this->assertContains('Nested quantifiers can cause catastrophic backtracking.', $messages);
    }

    /**
     * With nothing at all after the loop, the first way through is the
     * match and the engine never backtracks into it: (a+)+ on a{28}b takes
     * 6 steps (PCRE2 10.49, JIT off, (*NO_START_OPT)).
     */
    #[DataProvider('provideTrailingNestedQuantifiers')]
    public function test_trailing_nested_quantifiers_are_not_reported(string $pattern): void
    {
        $letter = $pattern[2];
        $jit = ini_get('pcre.jit');
        $limit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '1000');

        try {
            $unoptimized = '/(*NO_START_OPT)'.substr($pattern, 1);
            $this->assertNotFalse(@preg_match($unoptimized, str_repeat($letter, 28).'!'), $pattern);
        } finally {
            ini_set('pcre.jit', false === $jit ? '1' : $jit);
            ini_set('pcre.backtrack_limit', false === $limit ? '1000000' : $limit);
        }

        $messages = array_map(
            static fn (RuleViolation $issue): string => $issue->message,
            array_filter(Regex::create()->analyze($pattern)->lintIssues, static fn (mixed $issue): bool => $issue instanceof RuleViolation),
        );

        $this->assertNotContains('Nested quantifiers can cause catastrophic backtracking.', $messages);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideTrailingNestedQuantifiers(): iterable
    {
        yield '/(a+)+/' => ['pattern' => '/(a+)+/'];
        yield '/(b*)+/' => ['pattern' => '/(b*)+/'];
        yield '/(c?)+/' => ['pattern' => '/(c?)+/'];
        yield '/(d{2,})*/' => ['pattern' => '/(d{2,})*/'];
        yield '/(e+)+/' => ['pattern' => '/(e+)+/'];
        yield '/(f*)+/' => ['pattern' => '/(f*)+/'];
        yield '/(g?)+/' => ['pattern' => '/(g?)+/'];
        yield '/(h{3,})*/' => ['pattern' => '/(h{3,})*/'];
        yield '/(i+)+/' => ['pattern' => '/(i+)+/'];
        yield '/(j+)+/' => ['pattern' => '/(j+)+/'];
        yield '/(k*)+/' => ['pattern' => '/(k*)+/'];
        yield '/(l?)+/' => ['pattern' => '/(l?)+/'];
        yield '/(m{4,})*/' => ['pattern' => '/(m{4,})*/'];
        yield '/(n+)+/' => ['pattern' => '/(n+)+/'];
        yield '/(o*)+/' => ['pattern' => '/(o*)+/'];
        yield '/(p?)+/' => ['pattern' => '/(p?)+/'];
        yield '/(q{5,})*/' => ['pattern' => '/(q{5,})*/'];
        yield '/(r+)+/' => ['pattern' => '/(r+)+/'];
        yield '/(s*)+/' => ['pattern' => '/(s*)+/'];
    }

    /**
     * The smallest backtrack limit the match attempt runs under: the
     * engine's count of steps for one start, JIT off, start optimizations
     * off.
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
}
