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

namespace PHPRegex\Tests\Unit\Optimizer;

use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PcreInterpreter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An "s" or an "m" nothing in the pattern reads goes whatever the check
 * says of the rest of the rewrite: the flag moves no bracket and no repeat,
 * and the engine compiles the pattern alike with or without it. The check
 * refuses every rewrite of a pattern that holds a possessive quantifier
 * where a call runs, and the rewriter takes out of "/a+(?:b)?+a/" a group
 * PCRE2 reads to make "a+" possessive: neither keeps the flag in.
 */
final class UselessFlagOfARefusedRewriteTest extends TestCase
{
    private const SUBJECTS = ['', 'a', 'aa', 'aba', 'aab', 'aaba', "a\na", "aa\n", "\naa", '[a]', '[a[b]c]', "[a\n[b]]", '[[a]', 'x[y]z', "é[à]\n", 'Tab[[x]]', 'a1', 'aa1', "aa\n1", 'aa.'];

    #[Test]
    #[DataProvider('provideRefusedRewrites')]
    public function test_a_flag_nothing_reads_goes_where_the_rest_of_the_rewrite_is_refused(string $pattern, string $optimized, bool $possessive, bool $verifyWithAutomata): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the engine writes the same
        // matches with and without the flag, with and without the JIT.
        foreach (self::SUBJECTS as $subject) {
            $this->assertSame(self::matched($pattern, $subject), self::matched($optimized, $subject), \sprintf('Oracle: %s and %s differ on %s.', $pattern, $optimized, var_export($subject, true)));
            $this->assertSame(self::matched(PcreInterpreter::pattern($pattern), $subject), self::matched(PcreInterpreter::pattern($optimized), $subject), \sprintf('Oracle, interpreter: %s and %s differ on %s.', $pattern, $optimized, var_export($subject, true)));
        }

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern, new OptimizerOptions(possessive: $possessive, verifyWithAutomata: $verifyWithAutomata));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame([Rewriter::UNUSED_FLAG], $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, optimized: string, possessive: bool, verifyWithAutomata: bool}>
     */
    public static function provideRefusedRewrites(): iterable
    {
        $patterns = [
            'a possessive repeat of a group that calls the pattern' => ['/(\[(?:[^\]\[]+|(?R))*+\])/m', '/(\[(?:[^\]\[]+|(?R))*+\])/'],
            'the same under i and u' => ['/([\p{L}_\\\\][\p{L}\p{N}\._]+)?(\[(?:[^\]\[]+|(?R))*+\])/miu', '/([\p{L}_\\\\][\p{L}\p{N}\._]+)?(\[(?:[^\]\[]+|(?R))*+\])/iu'],
            'a group PCRE2 reads to make a repeat possessive' => ['/a+(?:b)?+a/m', '/a+(?:b)?+a/'],
            'the same with a dot nothing reads' => ['/a+(?:b)?+a/s', '/a+(?:b)?+a/'],
            'both flags at once' => ['/a+(?:b)?+a/ms', '/a+(?:b)?+a/'],
            'a flag the pattern reads kept beside one it does not' => ['/a+(?:b)?+a./ms', '/a+(?:b)?+a./s'],
            'the other rewrites of the pattern left out' => ['/a+(?:b)?+a[0-9]/m', '/a+(?:b)?+a[0-9]/'],
            'an extended pattern, laid out as written' => ['/a+ (?:b)?+ a/xm', '/a+ (?:b)?+ a/x'],
            'another delimiter' => ['#a+(?:b)?+a#m', '#a+(?:b)?+a#'],
        ];

        foreach ($patterns as $name => [$pattern, $optimized]) {
            foreach ([[false, true], [false, false], [true, true], [true, false]] as [$possessive, $verifyWithAutomata]) {
                yield \sprintf('%s, %s%s', $name, $possessive ? 'possessive' : 'not possessive', $verifyWithAutomata ? ', every rewrite checked' : '') => [
                    'pattern' => $pattern,
                    'optimized' => $optimized,
                    'possessive' => $possessive,
                    'verifyWithAutomata' => $verifyWithAutomata,
                ];
            }
        }
    }

    /**
     * The flag alone goes: the group the rewriter would take out, and the
     * class it would write as a shorthand, stay as written, and the engine
     * tells the unchecked rewrite apart from the pattern.
     */
    #[Test]
    public function test_only_the_flag_goes(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: without the group, "a+" gives
        // back and the pattern finds "aa1".
        $this->assertSame([0, 0, 1], [preg_match('/a+(?:b)?+a[0-9]/m', 'aa1'), preg_match('/a+(?:b)?+a[0-9]/', 'aa1'), preg_match('/a+b?+a\d/', 'aa1')]);

        $unchecked = (new Optimizer(RegexParser::create(['cache' => null])))->optimize('/x(?:b)?+c[0-9]/m', new OptimizerOptions(verifyWithAutomata: false));
        $this->assertSame('/xb?+c\d/', $unchecked->optimized, 'Where no repeat stands before the group, the whole rewrite ships unchecked.');

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize('/a+(?:b)?+a[0-9]/m', new OptimizerOptions(verifyWithAutomata: false));

        $this->assertSame('/a+(?:b)?+a[0-9]/', $result->optimized);
        $this->assertSame([Rewriter::UNUSED_FLAG], $result->changes);
    }

    /**
     * A refused rewrite of a pattern whose flag is read leaves the pattern
     * as written, with no change named.
     */
    #[Test]
    #[DataProvider('provideRefusedRewritesWhoseFlagIsRead')]
    public function test_a_refused_rewrite_keeps_a_flag_the_pattern_reads(string $pattern, string $withoutFlag, string $subject): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the flag changes what the pattern finds.
        $this->assertSame([1, 0], [preg_match($pattern, $subject), preg_match($withoutFlag, $subject)]);

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern);

        $this->assertSame($pattern, $result->optimized);
        $this->assertSame([], $result->changes);
        $this->assertFalse($result->isChanged());
    }

    /**
     * @return iterable<string, array{pattern: string, withoutFlag: string, subject: string}>
     */
    public static function provideRefusedRewritesWhoseFlagIsRead(): iterable
    {
        yield 'a dot that reads s' => ['pattern' => '/a+(?:b)?+a./s', 'withoutFlag' => '/a+(?:b)?+a./', 'subject' => "aba\n"];
        yield 'an anchor that reads m' => ['pattern' => '/^a+(?:b)?+a/m', 'withoutFlag' => '/^a+(?:b)?+a/', 'subject' => "x\naba"];
    }

    /**
     * A refused rewrite of a pattern without flags leaves it as written.
     */
    #[Test]
    public function test_a_refused_rewrite_of_a_pattern_without_flags_leaves_it_as_written(): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the rewrite the check refuses
        // finds "aa", the pattern does not.
        $this->assertSame([0, 1], [preg_match('/a+(?:b)?+a/', 'aa'), preg_match('/a+b?+a/', 'aa')]);

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize('/a+(?:b)?+a/');

        $this->assertSame('/a+(?:b)?+a/', $result->optimized);
        $this->assertSame([], $result->changes);
        $this->assertFalse($result->isChanged());
    }

    /**
     * A pattern PCRE refuses has no matches to keep: it is left as
     * written, its flags too.
     */
    #[Test]
    #[DataProvider('providePatternsPcreRefuses')]
    public function test_a_pattern_pcre_refuses_keeps_its_flags(string $pattern): void
    {
        // Oracle, PHP 8.4.28 / PCRE2 10.49: the engine does not compile it.
        $this->assertFalse(@preg_match($pattern, 'ab'));

        $result = (new Optimizer(RegexParser::create(['cache' => null])))->optimize($pattern);

        $this->assertSame($pattern, $result->optimized);
        $this->assertSame([], $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatternsPcreRefuses(): iterable
    {
        yield 'a lookbehind with no upper bound' => ['pattern' => '/(?<=a+)b/m'];
        yield 'the same beside a group PCRE2 reads' => ['pattern' => '/(?<=a+)a+(?:b)?+a/s'];
    }

    /**
     * What preg_match() answers, with the text and the offset of the match
     * and of each group.
     *
     * @return array{int|false, array<array-key, mixed>}
     */
    private static function matched(string $pattern, string $subject): array
    {
        $result = preg_match($pattern, $subject, $matches, \PREG_OFFSET_CAPTURE);

        return [$result, $matches];
    }
}
