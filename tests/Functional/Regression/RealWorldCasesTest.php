<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Functional\Regression;

use PhpRegex\Linter\Rule\RuleViolation;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RealWorldCasesTest extends TestCase
{
    #[DataProvider('provideRealWorldRegex')]
    public function test_real_world_cases(string $pattern, string $expectedIssueMessage): void
    {
        $report = Regex::new()->analyze($pattern);

        $issueMessages = [];
        foreach ($report->lintIssues as $issue) {
            $this->assertInstanceOf(RuleViolation::class, $issue);
            $issueMessages[] = $issue->message;
        }

        $this->assertContains($expectedIssueMessage, $issueMessages);
    }

    public static function provideRealWorldRegex(): \Iterator
    {
        // Nested quantifiers
        yield ['/(a+)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(b*)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(c?)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(d{2,})*/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(e+)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(f*)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(g?)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(h{3,})*/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(i+)+/', 'Nested quantifiers can cause catastrophic backtracking.'];

        // Flag useless
        yield ['@^0([0-7]+)$@i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/^\s*([^;]*);?/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/\s*(\S*?)="?([^;"]*);?/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/^--\w+=[^ ]/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/123/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/[0-9]+/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/456/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/789/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/000/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/111/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];

        // Alternation overlapping - only flagged when inside an unbounded quantifier
        // These patterns wrap overlapping alternations in quantifiers, creating ReDoS risk
        yield ['/(([0-9]*.[0-9]+)|([0-9]+(.[0-9]*)?))+/', 'Alternation branches have overlapping character sets, which may cause unnecessary backtracking.'];
        yield ['/(([a-f][a-f0-9])|([a-f0-9][a-f]))+/', 'Alternation branches have overlapping character sets, which may cause unnecessary backtracking.'];
        yield ['%^(?:[-]|[-][-]|[-][-]|[-][-]{2}|[-][-]|[-][-]{2}|[-][-]{3}|[-][-]{2})*$%xs', 'Alternation branches have overlapping character sets, which may cause unnecessary backtracking.'];

        // Redundant elements
        yield ['~{args.((?:[^{}}]++|(?R))*)}~', 'Redundant elements detected in character class.'];

        // More nested quantifiers
        yield ['/(j+)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(k*)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(l?)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(m{4,})*/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(n+)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(o*)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(p?)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(q{5,})*/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(r+)+/', 'Nested quantifiers can cause catastrophic backtracking.'];
        yield ['/(s*)+/', 'Nested quantifiers can cause catastrophic backtracking.'];

        // More flag useless
        yield ['/222/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/333/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/444/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/555/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/666/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/777/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/888/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/999/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/000/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];
        yield ['/111/i', "Flag 'i' is useless: the pattern contains no case-sensitive characters."];

        yield ['/\\r\\n|\\r/m', "Flag 'm' is useless: pattern '/\\r\\n|\\r/m' contains no anchors."];

        $html5Pattern = '/^[a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}'
            .'[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/sD';
        yield [$html5Pattern, "Flag 's' is useless: the pattern contains no dots."];

        yield [
            '#^(.*?)[\\\\/]*(([^/\\\\]*?)(\\.([^.\\\\/]+?)|))[\\\\/.]*$#m',
            'Alternation contains an empty alternative.',
        ];
    }
}
