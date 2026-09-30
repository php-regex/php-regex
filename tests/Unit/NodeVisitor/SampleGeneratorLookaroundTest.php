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
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;
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

    /**
     * An item that takes no text, repeated, is that item once, and left out
     * when it may be: "[[:<:]]", as "(?:\b(?=\w))", adds no character of its
     * own ahead of the word it opens.
     */
    #[Test]
    #[DataProvider('provideRepeatedAssertions')]
    public function test_a_repeated_assertion_adds_no_text(string $pattern): void
    {
        $tree = Regex::create(['cache' => null])->parse($pattern);
        $generator = new SampleGeneratorNodeVisitor();

        for ($seed = 0; $seed < 32; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);

            $this->assertSame(1, preg_match($pattern, $sample), \sprintf('Seed %d gave %s for %s.', $seed, json_encode($sample), $pattern));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideRepeatedAssertions(): iterable
    {
        yield 'start of a word, repeated' => ['pattern' => '/^[[:<:]]+red$/'];
        yield 'group of assertions, counted' => ['pattern' => '/^(?:\\b(?=\\w)){3}ab$/'];
        yield 'assertions in another order' => ['pattern' => '/^(?:(?=[a-z])\\b)+red$/'];
        yield 'optional group of assertions' => ['pattern' => '/^(?:\\b(?=\\w))*ab$/'];
        yield 'optional group of one assertion' => ['pattern' => '/^x(?:(?=y))?z$/'];
        yield 'group of assertions that captures' => ['pattern' => '/^(?:(?=(a))\\b)+\\1$/'];
        yield 'group defined for a call, never repeated' => ['pattern' => '/^(?=.{3}(?1))x(\\K){0}...$/'];
    }
}
