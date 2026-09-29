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
 * A lookahead constrains the text that follows it, a lookbehind the text
 * before it, and an assertion such as "\b" or "(?!^)" the text around it: a
 * sample has to hold each where it stands (PHP decides every match).
 */
final class SampleGeneratorLookaroundTest extends TestCase
{
    #[Test]
    #[DataProvider('providePatterns')]
    public function test_the_sample_matches(string $pattern): void
    {
        $regex = Regex::create(['cache' => null]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $sample = $regex->generate($pattern);

            $this->assertSame(1, preg_match($pattern, $sample), \sprintf('%s does not match the sample %s.', $pattern, json_encode($sample)));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function providePatterns(): iterable
    {
        yield 'lookahead the next group repeats' => ['pattern' => '/^(?=(ab(cd)))(ab)/'];
        yield 'lookahead on what types match' => ['pattern' => '/a(?=bc)\\w\\wd/'];
        yield 'password rules' => ['pattern' => '/^(?=.*\\d)(?=.*[a-z])\\w{8}$/'];
        yield 'lookbehind over the text before' => ['pattern' => '/x(?<=ax)y/'];
        yield 'not at the start' => ['pattern' => '/(?!^)abc/'];
        yield 'not at a line start' => ['pattern' => '/(?<!^)x/m'];
        yield 'word boundary alone' => ['pattern' => '/\\b/'];
        yield 'inside a word' => ['pattern' => '/\\Ba\\B/'];
    }
}
