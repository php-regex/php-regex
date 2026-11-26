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

namespace PHPRegex\Tests\Unit\Redos\Proven;

use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosComplexity;
use PHPRegex\Redos\RedosProof;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PCRE2 makes a repeat of one character possessive when what comes next
 * cannot start with that character. Before an atomic group, or a bounded
 * possessive repeat of a group, that may match nothing, it reads that group
 * as if it could not be skipped, through an alternative before the last
 * one or a group repeated inside it: "\n*" before "(?:y)?+" never gives a
 * newline back, though "[\s\S]" after the group needs one. A sure success
 * the model reads through that newline is not the engine's, and the loop
 * before it doubles the engine's steps with each newline.
 *
 * Engine: PHP 8.4.28 / PCRE2 10.49, the interpreter, pcre.backtrack_limit
 * 1000000, on 26 newlines. Such a group is kept with every way through it
 * where a repeat of one character may stand right before it: no proof then
 * rests on a success read through it.
 */
final class RedosPossessifiedRepeatTest extends TestCase
{
    private string|false $backtrackLimit = false;

    protected function setUp(): void
    {
        $this->backtrackLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');
    }

    protected function tearDown(): void
    {
        if (false !== $this->backtrackLimit) {
            ini_set('pcre.backtrack_limit', $this->backtrackLimit);
        }
    }

    #[Test]
    #[DataProvider('provideGroupsARepeatIsMadePossessiveBefore')]
    public function test_a_repeat_made_possessive_before_a_group_gives_no_success_back(string $pattern): void
    {
        $this->assertFalse(@preg_match(PcreInterpreter::pattern($pattern), str_repeat("\n", 26)), 'Oracle: '.$pattern.' on 26 newlines.');
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, preg_last_error(), 'Oracle: '.$pattern.' on 26 newlines.');
        $written = '/(*NO_JIT)(*NO_AUTO_POSSESS)'.substr($pattern, 1);
        $this->assertSame(1, preg_match($written, str_repeat("\n", 26)), 'Oracle: '.$pattern.' matches without auto-possession.');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertFalse($analysis->isProvenSafe(), $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideGroupsARepeatIsMadePossessiveBefore(): iterable
    {
        yield 'a possessive optional group' => ['pattern' => '/^(?:\n|\n)+\n*(?:y)?+[\s\S]/'];
        yield 'a bounded possessive repeat of a group' => ['pattern' => '/^(?:\n|\n)+\n*(?:y|z){0,3}+[\s\S]/'];
        yield 'an atomic group around a repeated group' => ['pattern' => '/^(?:\n|\n)+\n*(?>(?:y|z)*)[\s\S]/'];
        yield 'an atomic group whose first alternative is empty' => ['pattern' => '/^(?:\n|\n)+\n*(?>|y)[\s\S]/'];
        yield 'an atomic group whose first alternative always matches' => ['pattern' => '/^(?:\n|\n)+\n*(?>[^\n]*|y)[\s\S]/'];
        yield 'the repeat in a group of its own' => ['pattern' => '/^(?:\n|\n)+(?:\n*)(?>(?:y|z)*)[\s\S]/'];
        yield 'the repeat captured' => ['pattern' => '/^(?:\n|\n)+(\n*)(?>(?:y|z)*)[\s\S]/'];
        yield 'an optional character between' => ['pattern' => '/^(?:\n|\n)+\n*y?(?>(?:y|z)*)[\s\S]/'];
        yield 'the group in a later alternative' => ['pattern' => '/^(?:\n|\n)+\n*(?:q|(?>(?:y|z)*))[\s\S]/'];
        yield 'the group in a group of its own' => ['pattern' => '/^(?:\n|\n)+\n*(?:(?>(?:y|z)*))[\s\S]/'];
    }

    /**
     * Where no repeat of one character comes right before it, or the group
     * is one repeat of one character itself, PCRE2 reads the group as it
     * is, and the success after it stays sure: the newline the loop gives
     * back is read by "[\s\S]".
     */
    #[Test]
    #[DataProvider('provideGroupsReadAsWritten')]
    public function test_a_group_no_repeat_is_made_possessive_before_keeps_its_sure_success(string $pattern): void
    {
        $this->assertSame(1, preg_match(PcreInterpreter::pattern($pattern), str_repeat("\n", 26)), 'Oracle: '.$pattern.' on 26 newlines.');

        $analysis = (new RedosAnalyzer())->analyze($pattern);
        $this->assertSame([RedosProof::Proven, RedosComplexity::Linear], [$analysis->proof, $analysis->complexity], $pattern.' is "'.$analysis->headline().'".');
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideGroupsReadAsWritten(): iterable
    {
        yield 'no repeat before the group' => ['pattern' => '/^(?:\n|\n)+(?>(?:y|z)*)[\s\S]/'];
        yield 'a repeat of one character in the group' => ['pattern' => '/^(?:\n|\n)+\n*(?>y*)[\s\S]/'];
        yield 'the repeat in a group repeated itself' => ['pattern' => '/^(?:\n|\n)+(?:\n*)+(?>(?:y|z)*)[\s\S]/'];
        yield 'an unbounded possessive repeat of a group' => ['pattern' => '/^(?:\n|\n)+\n*(?:y|z)*+[\s\S]/'];
        yield 'a character that must be read between' => ['pattern' => '/^(?:\n|\n)+\n*\n(?>(?:y|z)*)[\s\S]/'];
    }
}
