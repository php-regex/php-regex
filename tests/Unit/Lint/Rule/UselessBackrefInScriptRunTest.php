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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A capturing group inside a script run, "(*sr:...)" or "(*asr:...)", is
 * a group like any other for the references to it.
 */
final class UselessBackrefInScriptRunTest extends TestCase
{
    private const SUBJECTS = ['', 'a', 'aa', 'b', 'ab', 'xb', 'ba', 'bb'];

    #[Test]
    #[DataProvider('provideUselessReferences')]
    public function test_a_useless_reference_to_a_group_in_a_script_run_is_reported(string $pattern, string $equivalent): void
    {
        // Oracle, PHP 8.4.26 / PCRE2 10.49: the reference changes nothing,
        // the pattern matches as the one written without it.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(preg_match($equivalent, $subject), preg_match($pattern, $subject), $pattern.' '.json_encode($subject));
        }

        $this->assertContains('regex.lint.backref.useless', $this->issueIds($pattern), $pattern);
    }

    /**
     * @return iterable<string, array{pattern: string, equivalent: string}>
     */
    public static function provideUselessReferences(): iterable
    {
        yield 'group in another alternative' => ['pattern' => '/(*sr:(a))|\1/', 'equivalent' => '/(*sr:(a))|(*FAIL)/'];
        yield 'group in another alternative of an atomic script run' => ['pattern' => '/(*asr:(a))|\1/', 'equivalent' => '/(*asr:(a))|(*FAIL)/'];
        yield 'named group in another alternative' => ['pattern' => '/(*sr:(?<n>a))|\k<n>/', 'equivalent' => '/(*sr:(?<n>a))|(*FAIL)/'];
        yield 'group that captures nothing' => ['pattern' => '/(*sr:()b)\1/', 'equivalent' => '/(*sr:()b)/'];
        yield 'group not closed yet' => ['pattern' => '/(*sr:\1(a))/', 'equivalent' => '/(*sr:(*FAIL)(a))/'];
    }

    #[Test]
    public function test_a_reference_after_its_group_in_a_script_run_is_not_reported(): void
    {
        $pattern = '/(*sr:(a)\1)/';

        // Oracle, PHP 8.4.26 / PCRE2 10.49: the reference reads a second "a".
        $this->assertSame(1, preg_match($pattern, 'aa'));
        $this->assertSame(0, preg_match($pattern, 'a'));

        $this->assertNotContains('regex.lint.backref.useless', $this->issueIds($pattern));
    }

    /**
     * @return list<string>
     */
    private function issueIds(string $pattern): array
    {
        $linter = new PatternLinter();
        Regex::create(['cache' => null])->parse($pattern)->accept($linter);

        return array_map(static fn ($issue): string => $issue->id, $linter->getIssues());
    }
}
