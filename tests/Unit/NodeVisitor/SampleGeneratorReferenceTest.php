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

namespace RegexParser\Tests\Unit\NodeVisitor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

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
    }
}
