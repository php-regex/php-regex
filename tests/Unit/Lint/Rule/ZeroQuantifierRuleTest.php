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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repeat of zero copies matches nothing, and is reported as removable
 * only where removing it changes nothing: a group it holds still takes its
 * number and may be called, and an assertion it holds still tells PCRE2
 * where a match may start, as the optimizer guards it.
 */
final class ZeroQuantifierRuleTest extends TestCase
{
    private const ID = 'regex.lint.quantifier.zero';

    #[Test]
    public function test_a_repeat_of_zero_copies_is_reported(): void
    {
        $violation = $this->violation('/ab{0}c/');

        $this->assertInstanceOf(RuleViolation::class, $violation);
        $this->assertSame('Quantifier always repeats zero times; it can be removed.', $violation->message);
    }

    /**
     * Oracle, PHP 8.4.27 / PCRE2 10.49: each pair matches otherwise once the
     * repeat is removed.
     */
    #[Test]
    #[DataProvider('provideRepeatsRemovingChanges')]
    public function test_a_repeat_whose_removal_changes_the_match_is_not_reported(string $pattern, string $removed, string $subject): void
    {
        preg_match($pattern, $subject, $kept);
        @preg_match($removed, $subject, $without);
        $this->assertNotSame($kept, $without, $pattern);

        $this->assertNull($this->violation($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string, removed: string, subject: string}>
     */
    public static function provideRepeatsRemovingChanges(): iterable
    {
        yield 'a group it numbers' => ['pattern' => '/(x){0}(y)/', 'removed' => '/(y)/', 'subject' => 'y'];
        yield 'a group a call reaches' => ['pattern' => '/(?<d>\d){0}(?&d)+/', 'removed' => '/(?&d)+/', 'subject' => '12'];
        yield 'an assertion that gates the start' => ['pattern' => '/(?:x|(?=a)){0}\w/', 'removed' => '/\w/', 'subject' => "\tb"];
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
