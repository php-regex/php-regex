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
use PHPRegex\Linter\Rule\QuantifierConcatenationRule;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a character repeated before a possessive or atomic group
 * that may match nothing possessive, as if the group could not be skipped,
 * wherever the group stands after it: past the group the run is written
 * in, too. Where either of two runs is made so, the second stays as
 * written, a run written as a group among them, and the runs after them
 * are still read.
 */
final class ConcatenationBeforeAPossessiveGroupTest extends TestCase
{
    /**
     * @param list<int> $offsets where each finding of the rule starts
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_a_run_before_a_group_pcre2_reads_as_unskippable_stays(string $pattern, string $rewrite, string $subject, array $offsets): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49, with the JIT and without: the
        // rewrite the hint would ask for answers otherwise.
        $answers = [preg_match($pattern, $subject), preg_match($rewrite, $subject)];
        $this->assertNotSame($answers[0], $answers[1], $pattern.' and '.$rewrite.' on '.$subject);
        $this->assertSame($answers, [preg_match('/(*NO_JIT)'.substr($pattern, 1), $subject), preg_match('/(*NO_JIT)'.substr($rewrite, 1), $subject)]);

        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);
        $found = [];
        foreach ($linter->getIssues() as $issue) {
            if (QuantifierConcatenationRule::ID === $issue->id) {
                $found[] = $issue->offset;
            }
        }

        $this->assertSame($offsets, $found, $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, rewrite: string, subject: string, offsets: list<int>}>
     */
    public static function provideRuns(): iterable
    {
        yield 'an optional letter in a group before the possessive group' => ['pattern' => '/(?:[ab]{2,}a?)(?:b){0,2}+a/', 'rewrite' => '/(?:[ab]{2,})(?:b){0,2}+a/', 'subject' => 'aaa', 'offsets' => []];
        yield 'an optional letter in a capturing group before the possessive group' => ['pattern' => '/([ab]{2,}a?)(?:b){0,2}+a/', 'rewrite' => '/([ab]{2,})(?:b){0,2}+a/', 'subject' => 'aaa', 'offsets' => []];
        yield 'an optional group of a letter after a run made possessive' => ['pattern' => '/[ab]{2,}(?:a)?(?:c){0,2}+a/', 'rewrite' => '/[ab]{2,}(?:c){0,2}+a/', 'subject' => 'aaa', 'offsets' => []];
        yield 'runs after the group, still read' => ['pattern' => '/[ab]{2,}(?:a)?(?:c){0,2}+ax+x?/', 'rewrite' => '/[ab]{2,}(?:c){0,2}+ax+x?/', 'subject' => 'aaax', 'offsets' => [28]];
    }
}
