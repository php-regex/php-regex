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

namespace PHPRegex\Tests\Unit\Generator;

use PHPRegex\Generator\Exception\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A repeat that adds text is bounded by the length of the sample; one that
 * adds none, as a reference to a group that captured nothing, is counted
 * apart: 1,048,576 of them for one sample, as many as the bytes it may
 * hold. "\1{65535}" compiles to one item, so PCRE takes a thousand of them
 * in a row, sixty-five million repeats of nothing.
 */
final class SampleGeneratorEmptyRepeatsTest extends TestCase
{
    use PhpUnitForwardCompat;

    private const REFUSAL = 'No sample was built: more than 1048576 repeats added nothing to it.';

    /**
     * 1,023 references 1,024 times, and each of the 1,024 groups adding
     * nothing either: 1,048,576 repeats, the last one allowed. One repeat
     * more is refused.
     */
    #[Test]
    public function test_repeats_that_add_nothing_stop_past_the_limit(): void
    {
        $atTheLimit = '/()(?:\1{1023}){1024}/';
        $onePast = '/()(?:\1{1023}){1024}\1{1}/';
        $this->assertSame(1, preg_match($atTheLimit, ''));
        $this->assertSame(1, preg_match($onePast, ''));
        $generator = new SampleGenerator();

        $this->assertSame('', $generator->generate($atTheLimit));

        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains(self::REFUSAL);
        $generator->generate($onePast);
    }

    /**
     * Repeats that add text are not counted: 1,024 times 1,024 letters are
     * 1,049,600 repeats, more than the limit, and a sample of 1,048,576
     * bytes; two counted runs of 65,535 are an ordinary one.
     */
    #[Test]
    public function test_repeats_that_add_text_are_not_counted(): void
    {
        $generator = new SampleGenerator();

        $longest = $generator->generate('/^(?:a{1024}){1024}$/');
        $this->assertSame(1_048_576, \strlen($longest));
        $this->assertSame(1, preg_match('/^(?:a{1024}){1024}$/', $longest));

        $ordinary = $generator->generate('/^a{65535}b{65535}$/');
        $this->assertSame(str_repeat('a', 65535).str_repeat('b', 65535), $ordinary);
        $this->assertSame(1, preg_match('/^a{65535}b{65535}$/', $ordinary));
    }

    /**
     * A sample the engine refuses is drawn again, up to 32 times, and the
     * draws of one call share the count: 60,000 repeats of nothing for each
     * draw of a pattern nothing matches pass the limit at the eighteenth.
     */
    #[Test]
    public function test_the_draws_of_one_call_share_the_count(): void
    {
        $pattern = '/()\1{60000}(?!)/';
        $this->assertSame(0, preg_match($pattern, ''));

        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessageIsOrContains(self::REFUSAL);
        (new SampleGenerator())->generate($pattern);
    }

    /**
     * 600 references 999 times are 600,399 repeats of nothing: two calls on
     * one generator pass the limit together, and neither does alone.
     */
    #[Test]
    #[DataProvider('provideEntryPoints')]
    public function test_each_call_counts_from_nothing(string $entry): void
    {
        $generator = new SampleGenerator();
        $pattern = '/()(?:\1{600}){999}/';
        $this->assertSame(1, preg_match($pattern, ''));

        $this->assertSame('', self::sample($entry, $generator, $pattern));
        $this->assertSame('', self::sample($entry, $generator, $pattern));
    }

    /**
     * @return iterable<string, array{entry: string}>
     */
    public static function provideEntryPoints(): iterable
    {
        yield 'generator' => ['entry' => 'generator'];
        yield 'visitor' => ['entry' => 'visitor'];
    }

    private static function sample(string $entry, SampleGenerator $generator, string $pattern): string
    {
        return 'visitor' === $entry ? RegexParser::create()->parse($pattern)->accept($generator) : $generator->generate($pattern);
    }
}
