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

use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "s" lets a dot take a newline and "m" makes "$" the end of a line, set on
 * the pattern or inside it, as "(?s)" or "(?m:...)", up to the end of the
 * group that sets them.
 */
final class SampleGeneratorFlagsTest extends TestCase
{
    private const SEEDS = 32;

    #[Test]
    #[DataProvider('provideDotAll')]
    public function test_a_dot_takes_a_newline_under_s(string $pattern): void
    {
        $characters = $this->characters($pattern);

        $this->assertContains("\n", $characters);
        // Among the other characters it draws.
        $this->assertGreaterThan(2, \count($characters));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideDotAll(): iterable
    {
        yield 'on the pattern' => ['pattern' => '/^.{8}$/s'];
        yield 'set inline' => ['pattern' => '/^(?s).{8}$/'];
        yield 'on a group' => ['pattern' => '/^(?s:.{8})$/'];
        yield 'among other flags' => ['pattern' => '/^(?is).{8}$/'];
    }

    #[Test]
    #[DataProvider('provideNoDotAll')]
    public function test_a_dot_takes_no_newline_without_s(string $pattern): void
    {
        $this->assertNotContains("\n", $this->characters($pattern));
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideNoDotAll(): iterable
    {
        yield 'no flag' => ['pattern' => '/^.{8}$/'];
        yield 'unset inline' => ['pattern' => '/^(?s)(?-s).{8}$/'];
        yield 'unset on a group' => ['pattern' => '/^(?-s:.{8})$/s'];
        yield 'reset' => ['pattern' => '/^(?s)(?^).{8}$/'];
        yield 'set in a group before' => ['pattern' => '/^((?s)a).{8}$/'];
        yield 'set on a group before' => ['pattern' => '/^(?s:a).{8}$/'];
        yield 'set in a plain group before' => ['pattern' => '/^(?:(?s)a).{8}$/'];
        yield 'set alone in a plain group before' => ['pattern' => '/^(?:(?s)).{8}$/'];
    }

    /**
     * The line "(?m)" opens needs the newline a dot under "s" gives.
     */
    #[Test]
    public function test_a_line_start_after_a_dot_under_s_is_reached(): void
    {
        $pattern = '/((?s)^a(.))((?m)^b$)/';
        $tree = Regex::create(['cache' => null])->parse($pattern);
        $generator = new SampleGenerator();

        $matching = [];
        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);
            if (1 === preg_match($pattern, $sample)) {
                $matching[] = $sample;
            }
        }

        $this->assertContains("a\nb", $matching);
    }

    /**
     * Under "m", "$" ends a line: text may follow it, and the branch that
     * holds it is drawn. Outside the group that sets it, "$" ends the subject.
     */
    /**
     * @param list<string> $samples
     */
    #[Test]
    #[DataProvider('provideLineEnds')]
    public function test_a_line_end_leaves_room_where_m_holds(string $pattern, array $samples): void
    {
        $tree = Regex::create(['cache' => null])->parse($pattern);
        $generator = new SampleGenerator();

        $drawn = [];
        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $drawn[$tree->accept($generator)] = true;
        }

        $this->assertEqualsCanonicalizing($samples, array_keys($drawn));
        foreach ($samples as $sample) {
            $this->assertSame(1, preg_match($pattern, $sample), json_encode($sample) ?: '');
        }
    }

    /**
     * @return iterable<string, array{pattern: string, samples: list<string>}>
     */
    public static function provideLineEnds(): iterable
    {
        yield 'set inline' => ['pattern' => '/(?m)(?:a|$)\nb/', 'samples' => ["a\nb", "\nb"]];
        yield 'on a group' => ['pattern' => '/(?m:(?:a|$)\n)b/', 'samples' => ["a\nb", "\nb"]];
        yield 'set in a group before' => ['pattern' => '/((?m)x)(?:a|$)b/', 'samples' => ['xab']];
        yield 'set again' => ['pattern' => '/(?m)(?:a|$)\nb/m', 'samples' => ["a\nb", "\nb"]];
        yield 'another flag set' => ['pattern' => '/(?i)(?:a|$)b/', 'samples' => ['ab']];
        yield 'reset' => ['pattern' => '/(?m)(?^)(?:a|$)b/', 'samples' => ['ab']];
    }

    /**
     * @return list<string> every character the seeds draw
     */
    private function characters(string $pattern): array
    {
        $tree = Regex::create(['cache' => null])->parse($pattern);
        $generator = new SampleGenerator();

        $characters = [];
        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $generator->setSeed($seed);
            $sample = $tree->accept($generator);
            $this->assertSame(1, preg_match($pattern, $sample), json_encode($sample) ?: '');
            array_push($characters, ...str_split($sample));
        }

        return array_values(array_unique($characters));
    }
}
