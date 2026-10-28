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

namespace PHPRegex\Tests\Unit\Lint\Rule;

use PHPRegex\Linter\PatternLinter;
use PHPRegex\Linter\Rule\RuleViolation;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "{1}" repeats once, as writing nothing does; "{1}+" is possessive, an
 * atomic group that never gives back, which the optimizer keeps too.
 */
final class UselessQuantifierRuleTest extends TestCase
{
    private const ID = 'regex.lint.quantifier.useless';

    #[Test]
    public function test_a_count_of_one_is_reported(): void
    {
        $this->assertInstanceOf(RuleViolation::class, $this->violation('/ab{1}c/'));
        $this->assertInstanceOf(RuleViolation::class, $this->violation('/(a|ab){1}?ab/'));
    }

    /**
     * Oracle, PHP 8.4.27 / PCRE2 10.49: /(a|ab){1}+ab/ answers 0 on "ababx",
     * /(a|ab)ab/ answers 1.
     */
    #[Test]
    public function test_a_possessive_count_of_one_is_not_reported(): void
    {
        $this->assertSame(0, preg_match('/(a|ab){1}+ab/', 'ababx'));
        $this->assertSame(1, preg_match('/(a|ab)ab/', 'ababx'));

        $this->assertNull($this->violation('/(a|ab){1}+ab/'));
        $this->assertNull($this->violation('/(a|ab){1,1}+ab/'));
    }

    private function violation(string $pattern): ?RuleViolation
    {
        $linter = new PatternLinter();
        Regex::create()->parse($pattern)->accept($linter);

        foreach ($linter->getIssues() as $violation) {
            if (self::ID === $violation->id) {
                return $violation;
            }
        }

        return null;
    }
}
