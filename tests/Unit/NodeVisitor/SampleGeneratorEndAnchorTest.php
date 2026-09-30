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
 * An alternative that ends the subject, as "$" in "(\d+(?:\s|$))", leaves
 * no room for the text the rest of the pattern must still add: drawn before
 * it, the sample cannot match. Every seed must give a sample that does.
 */
final class SampleGeneratorEndAnchorTest extends TestCase
{
    private const SEEDS = 32;

    #[Test]
    #[DataProvider('provideTextAfterTheEnd')]
    public function test_no_alternative_ends_the_subject_before_the_text_that_follows(string $pattern): void
    {
        $tree = Regex::create(['cache' => null])->parse($pattern);
        $generator = new SampleGeneratorNodeVisitor();

        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);

            $this->assertSame(1, preg_match($pattern, $sample), \sprintf('Seed %d gave %s for %s.', $seed, json_encode($sample), $pattern));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideTextAfterTheEnd(): iterable
    {
        yield 'dollar in groups in a row' => ['pattern' => '/(\d+(?:\s|$))(\d+(?:\s|$))(\d+(?:\s|$))/'];
        yield 'dollar in a repeated group' => ['pattern' => '/^(?:\d+(?:,|$)){5}$/'];
        yield 'absolute end' => ['pattern' => '/(?:a|\z)b/'];
        yield 'end before a final newline' => ['pattern' => '/(?:a|\Z)bc/'];
        yield 'one character after an end before a final newline' => ['pattern' => '/(?:a|\Z)b/'];
        yield 'text after an optional part' => ['pattern' => '/(?:a|\z)c?b/'];
        yield 'dollar in a lookahead text overlaps' => ['pattern' => '/^(?=\d+(?:,|$))\d+$/'];
        yield 'dollar ending a longer branch' => ['pattern' => '/(?:x$|y)z/'];
        yield 'dollar in a called group' => ['pattern' => '/(\d(?:-|$))(?1)(?1)/'];
    }

    /**
     * What follows may add nothing: both branches stay drawn.
     */
    #[Test]
    public function test_an_end_before_optional_text_is_still_drawn(): void
    {
        $tree = Regex::create(['cache' => null])->parse('/^(?:a|$)\n?$/');
        $generator = new SampleGeneratorNodeVisitor();

        $samples = [];
        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);
            $this->assertSame(1, preg_match('/^(?:a|$)\n?$/', $sample), json_encode($sample) ?: '');
            $samples[$sample[0] ?? ''] = true;
        }

        $this->assertEqualsCanonicalizing(['a', '', "\n"], array_keys($samples));
    }

    /**
     * Every branch ends the subject: one is drawn all the same, and "$"
     * still lets the final newline follow.
     */
    #[Test]
    public function test_a_branch_is_drawn_when_every_one_ends_the_subject(): void
    {
        $tree = Regex::create(['cache' => null])->parse('/(?:x$|y$)\n/');
        $generator = new SampleGeneratorNodeVisitor();

        $samples = [];
        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $samples[$tree->accept($generator)] = true;
        }

        $this->assertEqualsCanonicalizing(["x\n", "y\n"], array_keys($samples));
    }

    /**
     * Nothing follows the group: the branch that ends the subject is the
     * only one that matches, and it is still found.
     */
    #[Test]
    #[DataProvider('provideEndsNothingFollows')]
    public function test_an_end_nothing_follows_is_still_drawn(string $pattern): void
    {
        $regex = Regex::create(['cache' => null]);

        for ($call = 0; $call < 20; $call++) {
            $this->assertSame('', $regex->generate($pattern));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideEndsNothingFollows(): iterable
    {
        yield 'dollar' => ['pattern' => '/^(?:$|a)(?<!a)$/'];
        yield 'end before a final newline' => ['pattern' => '/^(?:\Z|a)(?<!a)$/'];
        yield 'absolute end' => ['pattern' => '/^(?:\z|a)(?<!a)$/'];
    }

    /**
     * In a scan, "$" ends the capture read, not the subject: the text the
     * pattern adds after the scan leaves it room.
     */
    #[Test]
    public function test_a_scan_ends_its_capture_whatever_follows(): void
    {
        $pattern = '/(\w+)(*scs:(1)\d+(?:,|$))!/';
        $tree = Regex::create(['cache' => null, 'pcre_version' => '10.47'])->parse($pattern);
        $generator = new SampleGeneratorNodeVisitor();
        $compiles = false !== @preg_match($pattern, '');

        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);

            // Before PCRE2 10.47, no engine reads a scan.
            $this->assertSame($compiles ? 1 : false, @preg_match($pattern, $sample), \sprintf('Seed %d gave %s.', $seed, json_encode($sample)));
        }
    }

    /**
     * The last repeat is followed by nothing: it may end the subject.
     */
    #[Test]
    public function test_the_last_repeat_may_end_the_subject(): void
    {
        $tree = Regex::create(['cache' => null])->parse('/^(?:\d(?:,|$)){3}/');
        $generator = new SampleGeneratorNodeVisitor();

        $endings = [];
        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);
            $this->assertMatchesRegularExpression('/^\d,\d,\d,?$/', $sample);
            $endings[str_ends_with($sample, ',') ? 'comma' : 'end'] = true;
        }

        $this->assertEqualsCanonicalizing(['comma', 'end'], array_keys($endings));
    }

    /**
     * Under "m", "$" ends a line, not the subject: text may follow it.
     */
    #[Test]
    public function test_a_line_end_is_drawn_before_more_text(): void
    {
        $tree = Regex::create(['cache' => null])->parse('/(?:a|$)b/m');
        $generator = new SampleGeneratorNodeVisitor();

        $samples = [];
        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $samples[$tree->accept($generator)] = true;
        }

        $this->assertEqualsCanonicalizing(['ab', 'b'], array_keys($samples));
    }
}
