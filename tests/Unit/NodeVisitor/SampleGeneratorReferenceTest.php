<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Generator\SampleGenerator;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Samples for references and escapes the generator used to get wrong: the
 * "\g" spellings of a reference, relative ones included; "\NN" that names no
 * group, which PCRE reads as an octal escape; and a character written by
 * its code, which without UTF mode is a byte (testinput1 of the PCRE2 suite;
 * PHP decides every match).
 */
final class SampleGeneratorReferenceTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_sample_matches(string $pattern): void
    {
        $this->assertNotFalse(@preg_match($pattern, ''), $pattern);

        $regex = Regex::create(['cache' => null]);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $sample = $regex->generate($pattern);

            $this->assertSame(1, preg_match($pattern, $sample), \sprintf('%s does not match the sample %s.', $pattern, json_encode($sample, \JSON_INVALID_UTF8_SUBSTITUTE)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'relative reference' => ['pattern' => '/^(a.)\\g-1Z/'];
        yield 'braced relative reference' => ['pattern' => '/^(a.)\\g{-1}Z/'];
        yield 'every spelling' => ['pattern' => '/^(a(b))\\1\\g1\\g{1}\\g-1\\g{-1}\\g{-2}Z/'];
        yield 'octal escape after a group' => ['pattern' => '/(abc)\\123/'];
        yield 'octal escape of a high byte' => ['pattern' => '/(abc)\\223/'];
        yield 'octal escape then a digit' => ['pattern' => '/(abc)\\1000/'];
        yield 'reference then an octal escape' => ['pattern' => '/(a)(b)(c)(d)(e)(f)(g)(h)(i)(j)(k)\\11\\123/'];
        yield 'NUL by its code' => ['pattern' => '/^A\\x0{2,3}Z$/'];
        yield 'high byte by its code' => ['pattern' => '/^\\xff$/'];
        yield 'code point in UTF mode' => ['pattern' => '/^\\x{e9}$/u'];
        yield 'relative calls back and forward' => ['pattern' => '/(A)(?-1)(?+1)(B)/'];
        yield 'forward call' => ['pattern' => '/xy(?+1)(abc)/'];
        yield 'forward call to a group repeated zero times' => ['pattern' => '/^(?+1)(?<a>x|y){0}z/'];
        yield 'forward call by g' => ['pattern' => '/(?-i:\\g<+1>)(?i:(a))/'];
        yield 'backward call after later groups' => ['pattern' => '/(a)(?-1)(b)(c)/'];
    }

    /**
     * "(?(R)" holds inside a call, "(?(R1)" and "(?(R&name)" inside a call
     * to that group, the latest one: every seed takes the branch PCRE takes.
     */
    #[Test]
    #[DataProvider('provideRecursionConditions')]
    public function test_a_recursion_condition_takes_the_branch_of_the_call(string $pattern, string $sample): void
    {
        $this->assertSame(1, preg_match($pattern, $sample));

        $tree = Regex::create(['cache' => null])->parse($pattern);
        $generator = new SampleGenerator();
        for ($seed = 0; $seed < 16; $seed++) {
            $generator->setSeed($seed);
            $this->assertSame($sample, $tree->accept($generator));
        }
    }

    /**
     * @return iterable<string, array{pattern: string, sample: string}>
     */
    public static function provideRecursionConditions(): iterable
    {
        yield 'any call' => ['pattern' => '/^(a(?(R)b|c))(?1)$/', 'sample' => 'acab'];
        yield 'a call to the group' => ['pattern' => '/^(a(?(R1)b|c))(?1)$/', 'sample' => 'acab'];
        yield 'a call to another group' => ['pattern' => '/^(a(?(R2)b|c))(?1)()$/', 'sample' => 'acac'];
        yield 'a call to the group by name' => ['pattern' => '/^(?<n>a(?(R&n)b|c))(?&n)$/', 'sample' => 'acab'];
        yield 'a call to the whole pattern' => ['pattern' => '/a(?(R)b|c(?R))/', 'sample' => 'acab'];
        yield 'a call to the whole pattern, by number' => ['pattern' => '/a(?(R0)b|c(?R))/', 'sample' => 'acab'];
        yield 'a call to the whole pattern, not to group 1' => ['pattern' => '/a(?(R1)b|(?(R)d|c(?R)))(x){0}/', 'sample' => 'acad'];
        yield 'after the call' => ['pattern' => '/^(a(?(R)b|c))(?1)(?(R)x|y)$/', 'sample' => 'acaby'];
        yield 'a call to another group by name' => ['pattern' => '/^(?<n>a(?(R&n)b|c))(?<m>x(?(R&n)y|z))(?&m)$/', 'sample' => 'acxzxz'];
    }
}
