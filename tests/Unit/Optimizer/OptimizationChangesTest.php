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

use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Optimizer\Rewriter;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The changes of an optimization name each kind of rewrite that shaped the
 * result, once, in the order the rewriter first made it: a reader learns
 * why the pattern changed, and a pattern left as written has none.
 */
final class OptimizationChangesTest extends TestCase
{
    /**
     * @param array{possessive?: bool, factorize?: bool} $options
     * @param list<string>                               $changes
     */
    #[Test]
    #[DataProvider('provideRewrites')]
    public function test_each_kind_of_rewrite_is_named_once(string $pattern, array $options, string $optimized, array $changes): void
    {
        $result = Regex::create(['cache' => null])->optimize($pattern, OptimizerOptions::fromArray($options));

        $this->assertSame($optimized, $result->optimized);
        $this->assertSame($changes, $result->changes);
    }

    /**
     * @return iterable<string, array{pattern: string, options: array{possessive?: bool, factorize?: bool}, optimized: string, changes: list<string>}>
     */
    public static function provideRewrites(): iterable
    {
        yield 'nothing to rewrite' => ['pattern' => '/abc/', 'options' => [], 'optimized' => '/abc/', 'changes' => []];
        yield 'digits' => ['pattern' => '/[0-9]+/', 'options' => [], 'optimized' => '/\d+/', 'changes' => [Rewriter::DIGITS]];
        yield 'digits twice' => ['pattern' => '/[0-9]-[0-9]/', 'options' => [], 'optimized' => '/\d-\d/', 'changes' => [Rewriter::DIGITS]];
        yield 'a class sorted' => ['pattern' => '/[cba]/', 'options' => [], 'optimized' => '/[a-c]/', 'changes' => [Rewriter::CLASS_MEMBERS]];
        yield 'a class already in order' => ['pattern' => '/[a-c]x[0-9]/', 'options' => [], 'optimized' => '/[a-c]x\d/', 'changes' => [Rewriter::DIGITS]];
        // The class is then rewritten as any other, in the same pass.
        yield 'an alternation of characters' => ['pattern' => '/a|b|c/', 'options' => [], 'optimized' => '/[a-c]/', 'changes' => [Rewriter::ALTERNATION_TO_CLASS, Rewriter::CLASS_MEMBERS]];
        yield 'adjacent classes in an alternation' => ['pattern' => '/[a-c]|[x-z]|ab/', 'options' => [], 'optimized' => '/[a-cx-z]|ab/', 'changes' => [Rewriter::MERGED_CLASSES]];
        yield 'an alternative written twice' => ['pattern' => '/ab|cd|ab/', 'options' => [], 'optimized' => '/ab|cd/', 'changes' => [Rewriter::DUPLICATE_ALTERNATIVE]];
        yield 'a prefix factored' => ['pattern' => '/abc|abd/', 'options' => ['factorize' => true], 'optimized' => '/ab(?:c|d)/', 'changes' => [Rewriter::FACTORED_PREFIX]];
        yield 'a suffix factored' => ['pattern' => '/(?:a)bc|xbc/', 'options' => ['factorize' => true], 'optimized' => '/(?:a|x)bc/', 'changes' => [Rewriter::GROUP_REMOVED, Rewriter::FACTORED_SUFFIX]];
        yield 'a group that changes nothing' => ['pattern' => '/(?:abc)d/', 'options' => [], 'optimized' => '/abcd/', 'changes' => [Rewriter::GROUP_REMOVED]];
        yield 'a run counted' => ['pattern' => '/aaaa/', 'options' => [], 'optimized' => '/a{4}/', 'changes' => [Rewriter::RUN_COUNTED]];
        yield 'a short quantifier' => ['pattern' => '/a{0,}b{1,}c{0,1}/', 'options' => [], 'optimized' => '/a*b+c?/', 'changes' => [Rewriter::SHORT_QUANTIFIER]];
        yield 'a count of one' => ['pattern' => '/ab{1}c/', 'options' => [], 'optimized' => '/abc/', 'changes' => [Rewriter::COUNT_OF_ONE]];
        yield 'a repeat of zero' => ['pattern' => '/ab{0}c/', 'options' => [], 'optimized' => '/ac/', 'changes' => [Rewriter::REPEAT_OF_ZERO]];
        yield 'a possessive quantifier' => ['pattern' => '/a+b/', 'options' => ['possessive' => true], 'optimized' => '/a++b/', 'changes' => [Rewriter::POSSESSIVE]];
        yield 'a flag nothing reads' => ['pattern' => '/ab/sm', 'options' => [], 'optimized' => '/ab/', 'changes' => [Rewriter::UNUSED_FLAG]];
        yield 'several kinds, in order' => ['pattern' => '/(?:[0-9]){1}x|aaaa|a|b/s', 'options' => [], 'optimized' => '/\dx|a{4}|a|b/', 'changes' => [Rewriter::DIGITS, Rewriter::GROUP_REMOVED, Rewriter::COUNT_OF_ONE, Rewriter::RUN_COUNTED, Rewriter::UNUSED_FLAG]];
        yield 'an escape a rebuilt class does not need' => ['pattern' => '/\(([^\)]+)\)|\[(\d+)\]: /', 'options' => [], 'optimized' => '/\(([^)]+)\)|\[(\d+)\]: /', 'changes' => [Rewriter::RESPELLED]];
        yield 'a possessive rewrite the solver refuses' => ['pattern' => '/(?:ab|a)+c/', 'options' => ['possessive' => true], 'optimized' => '/(?:ab|a)+c/', 'changes' => []];
    }
}
