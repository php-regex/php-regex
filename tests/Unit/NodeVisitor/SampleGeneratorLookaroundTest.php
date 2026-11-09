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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A lookahead constrains the text that follows it, a lookbehind the text
 * before it, and an assertion such as "\b" or "(?!^)" the text around it: a
 * sample has to hold each where it stands (PHP decides every match).
 */
final class SampleGeneratorLookaroundTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    #[DataProvider('provideExcessiveLookaheads')]
    public function test_sibling_lookaheads_are_bounded(int $count, int $tail, string $limit, bool $checked): void
    {
        $pattern = '/'.str_repeat('(?=a)', $count).str_repeat('a', $tail).'/';
        $generator = new SampleGenerator();
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains($limit);

        if ($checked) {
            $generator->generate($pattern);
        } else {
            Regex::create(['cache' => null])->parse($pattern)->accept($generator);
        }
    }

    /**
     * Each lookahead draws what follows it again: depth bounds how many are
     * nested, work how much text they copy, 100 levels of a 2,000-character
     * suffix here.
     *
     * @return iterable<string, array{count: int, tail: int, limit: string, checked: bool}>
     */
    public static function provideExcessiveLookaheads(): iterable
    {
        foreach ([false, true] as $checked) {
            $entry = $checked ? 'generate' : 'visitor';
            yield 'depth, '.$entry => ['count' => 200, 'tail' => 1, 'limit' => 'sequence depth', 'checked' => $checked];
            yield 'work, '.$entry => ['count' => 100, 'tail' => 2000, 'limit' => 'sequence work', 'checked' => $checked];
        }
    }

    /**
     * The work is the suffixes the lookaheads copy, twice per level, from
     * the lookahead's place in its sequence on. 64 lookaheads first in their
     * sequence before t characters charge 4,096 + 128t: 992 fit the 131,072
     * allowed exactly, 993 do not. After a character each, a(?=a), they
     * charge 8,128 + 128t: 960 fit, 961 do not.
     */
    #[Test]
    #[DataProvider('provideWorkBoundary')]
    public function test_the_work_limit_counts_every_copied_suffix(string $level, int $tail, bool $built): void
    {
        $pattern = '/'.str_repeat($level, 64).str_repeat('a', $tail).'/';

        try {
            $sample = Regex::create(['cache' => null])->parse($pattern)->accept(new SampleGenerator());
            $this->assertTrue($built, 'Past the limit, no sample is built.');
            $this->assertSame(str_repeat('(?=a)' === $level ? '' : 'a', 64).str_repeat('a', $tail), $sample);
        } catch (SampleGenerationException $e) {
            $this->assertFalse($built, $e->getMessage());
            $this->assertSame('No sample was built: sequence work limit exceeded.', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{level: string, tail: int, built: bool}>
     */
    public static function provideWorkBoundary(): iterable
    {
        yield 'lookaheads first, exactly the limit' => ['level' => '(?=a)', 'tail' => 992, 'built' => true];
        yield 'lookaheads first, one step past it' => ['level' => '(?=a)', 'tail' => 993, 'built' => false];
        yield 'lookaheads after a character, within the limit' => ['level' => 'a(?=a)', 'tail' => 960, 'built' => true];
        yield 'lookaheads after a character, past it' => ['level' => 'a(?=a)', 'tail' => 961, 'built' => false];
    }

    /**
     * A plain repetition copies no suffix: tens of thousands of repeats of a
     * two-character sequence build their sample, as PCRE matches it.
     */
    #[Test]
    public function test_a_long_plain_repetition_is_not_charged_as_lookahead_work(): void
    {
        $sample = (new SampleGenerator(seed: 42, maxRepetition: 40000))->generate('/^(?:a.)+$/');

        $this->assertSame(74658, \strlen($sample));
        // The JIT stack gives up on 74,658 bytes; the interpreter matches.
        $this->assertTrue((new PcreEngine())->match('/^(?:a.)+$/', $sample)->matched);
    }

    #[Test]
    public function test_generator_can_be_reused_after_a_limit(): void
    {
        $generator = new SampleGenerator();

        try {
            $generator->generate('/'.str_repeat('(?=a)', 400).'a/');
            $this->fail('Expected a generation limit.');
        } catch (SampleGenerationException) {
            $this->assertSame('a', $generator->generate('/(?=a)a/'));
        }
    }

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
     * A lookbehind in a group reads the text before the group too: that
     * text may already satisfy it, and is left as it is.
     */
    #[Test]
    #[DataProvider('provideLookbehindsInGroups')]
    public function test_a_lookbehind_in_a_group_reads_the_text_before_it(string $pattern): void
    {
        $tree = Regex::create(['cache' => null])->parse($pattern);
        $generator = new SampleGenerator();

        for ($seed = 0; $seed < 32; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);

            $this->assertSame(1, preg_match($pattern, $sample), \sprintf('Seed %d gave %s for %s.', $seed, json_encode($sample), $pattern));
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideLookbehindsInGroups(): iterable
    {
        yield 'past the start of the group' => ['pattern' => '/^a(b(?<=ab))$/'];
        yield 'past two groups' => ['pattern' => '/^x(y(z(?<=xyz)))$/'];
        yield 'in a branch' => ['pattern' => '/^foo(?:bar|(baz(?<=obaz)))$/'];
        yield 'in a lookbehind' => ['pattern' => '/(?<=ab(cd(?<=...)))./'];
        yield 'past a group that ended' => ['pattern' => '/(ab)(?<=abab)c/'];
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
        $generator = new SampleGenerator();

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
