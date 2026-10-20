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

namespace PHPRegex\Tests\Unit\Linter;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleCatalog;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One linter reads one pattern after another: each pattern gets the
 * findings a linter of its own would give it, whatever the linter read
 * before, the rules that ask the automata and keep their answers among
 * them.
 */
final class PatternLinterReuseTest extends TestCase
{
    /**
     * Patterns each rule that keeps something from a pattern reports on.
     */
    private const PATTERNS = [
        '/\x{101}\b\x{AB}/u',
        '/a$\pL/u',
        '/a$\p{Cc}/u',
        '/(?=\x{101})\x{102}/u',
        '/\x{101}*+\x{101}/iu',
        '/.*?;/',
        '/(a+?)b/',
        '/x(?=a{2,6})/',
        '/a+(a*)/',
        '/\x{101}\b\x{AB}\x{A1}\b\x{BB}\x{BF}\b\x{D7}\x{101}\B\x{AB}\x{3B1}\b\x{3B2}/u',
        '/a/',
    ];

    #[Test]
    public function test_one_linter_gives_each_pattern_the_findings_of_a_linter_of_its_own(): void
    {
        $rules = array_fill_keys(RuleCatalog::lintRuleIds(), true);
        $alone = array_map(static fn (string $pattern): array => self::findings(new PatternLinter($rules), $pattern), self::PATTERNS);
        $this->assertNotSame([], array_merge(...$alone));

        foreach ([self::PATTERNS, array_reverse(self::PATTERNS, true)] as $order) {
            $linter = new PatternLinter($rules);
            foreach ($order as $index => $pattern) {
                $this->assertSame($alone[$index], self::findings($linter, $pattern), $pattern);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function findings(PatternLinter $linter, string $pattern): array
    {
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_map(static fn (RuleViolation $issue): string => $issue->id.'@'.($issue->offset ?? '-'), $linter->getIssues());
    }
}
