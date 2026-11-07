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
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SampleGeneratorLimitTest extends TestCase
{
    #[DataProvider('provideEntryPoints')]
    public function test_capture_doubling_is_rejected(string $entry): void
    {
        $pattern = '/'.self::doublingChain(21).'/';
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessage('No sample was built: it would pass 1048576 bytes.');

        match ($entry) {
            'facade' => Regex::create()->generate($pattern),
            'generator' => (new SampleGenerator())->generate($pattern),
            'visitor' => RegexParser::create()->parse($pattern)->accept(new SampleGenerator()),
        };
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideEntryPoints(): iterable
    {
        yield ['facade'];
        yield ['generator'];
        yield ['visitor'];
    }

    public function test_exact_byte_limit_is_allowed(): void
    {
        $pattern = '/'.self::doublingChain(19).'a/';
        $sample = (new SampleGenerator())->generate($pattern);

        $this->assertSame(1048576, \strlen($sample));
        $this->assertSame(1, preg_match($pattern, $sample));
    }

    public function test_one_byte_over_limit_is_rejected_even_before_accept(): void
    {
        $pattern = '/'.self::doublingChain(19).'aa(*ACCEPT)/';
        $this->expectException(SampleGenerationException::class);
        RegexParser::create()->parse($pattern)->accept(new SampleGenerator());
    }

    public function test_oversized_intermediate_lookahead_is_rejected(): void
    {
        $pattern = '/(?='.self::doublingChain(21).')(*ACCEPT)/';
        $this->expectException(SampleGenerationException::class);
        RegexParser::create()->parse($pattern)->accept(new SampleGenerator());
    }

    public function test_limit_counts_bytes_in_utf_mode(): void
    {
        $pattern = '/'.str_replace('(a)', '(é)', self::doublingChain(19)).'/u';
        $this->expectException(SampleGenerationException::class);
        (new SampleGenerator())->generate($pattern);
    }

    private static function doublingChain(int $steps): string
    {
        $pattern = '(a)';
        for ($group = 1; $group <= $steps; $group++) {
            $pattern .= '(\\g{'.$group.'}\\g{'.$group.'})';
        }

        return $pattern;
    }
}
