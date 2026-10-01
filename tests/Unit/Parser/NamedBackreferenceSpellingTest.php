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

namespace PhpRegex\Tests\Unit\Parser;

use PhpRegex\Parser\Node\BackrefNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\Node\SubroutineNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "\g{name}" is a back reference in PCRE, like "\k{name}"; only "\g<name>"
 * and "\g'name'" call the group. Reading the braced form as a call and
 * compiling it back as "\g<name>" changes what the pattern matches.
 */
final class NamedBackreferenceSpellingTest extends TestCase
{
    #[Test]
    public function test_braced_g_with_a_name_is_a_back_reference(): void
    {
        $pattern = Regex::create()->parse('/(?<n>a|b)\g{n}/')->pattern;

        $this->assertInstanceOf(SequenceNode::class, $pattern);
        $this->assertInstanceOf(BackrefNode::class, $pattern->children[1]);
    }

    #[Test]
    public function test_angle_and_quote_g_with_a_name_stay_calls(): void
    {
        foreach (['/(?<n>a|b)\g<n>/', "/(?<n>a|b)\\g'n'/"] as $regex) {
            $pattern = Regex::create()->parse($regex)->pattern;

            $this->assertInstanceOf(SequenceNode::class, $pattern);
            $this->assertInstanceOf(SubroutineNode::class, $pattern->children[1], $regex);
        }
    }

    #[Test]
    public function test_quoted_g_with_a_number_is_a_call(): void
    {
        // preg_match("/^(a|b)\\g'1'$/", 'ab') === 1: a call, not a back reference.
        foreach (["/(a|b)\\g'1'/", "/(a|b)\\g'-1'/"] as $regex) {
            $pattern = Regex::create()->parse($regex)->pattern;

            $this->assertInstanceOf(SequenceNode::class, $pattern);
            $this->assertInstanceOf(SubroutineNode::class, $pattern->children[1], $regex);
        }
    }

    /**
     * @param list<string> $subjects
     */
    #[Test]
    #[DataProvider('provideNamedReferences')]
    public function test_compiling_a_named_reference_keeps_what_it_matches(string $regex, array $subjects): void
    {
        $compiled = Regex::create()->parse($regex)->accept(new PatternPrinter());

        foreach ($subjects as $subject) {
            $this->assertSame(
                preg_match($regex, $subject, $expected),
                preg_match($compiled, $subject, $actual),
                \sprintf('%s compiled to %s matches "%s" differently', $regex, $compiled, $subject),
            );
            $this->assertSame($expected, $actual);
        }
    }

    /**
     * @return iterable<string, array{regex: string, subjects: list<string>}>
     */
    public static function provideNamedReferences(): iterable
    {
        yield 'braced g back reference' => ['regex' => '/(?<n>a|b)\g{n}/', 'subjects' => ['aa', 'ab', 'ba', 'bb']];
        yield 'braced g back reference to a repeat' => ['regex' => '/(?<n>a+)x\g{n}/', 'subjects' => ['aaxaa', 'aaxa', 'axaa']];
        yield 'angle g call' => ['regex' => '/(?<n>a|b)\g<n>/', 'subjects' => ['aa', 'ab', 'ba', 'bb']];
        yield 'quoted g call by number' => ['regex' => "/^(a|b)\\g'1'$/", 'subjects' => ['aa', 'ab', 'ba']];
        yield 'quoted g call by relative number' => ['regex' => "/^(a|b)\\g'-1'$/", 'subjects' => ['aa', 'ab', 'ba']];
        yield 'braced k back reference' => ['regex' => '/(?<n>a|b)\k{n}/', 'subjects' => ['aa', 'ab']];
    }
}
