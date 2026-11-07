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

final class SampleGeneratorWorkBudgetTest extends TestCase
{
    #[DataProvider('provideExpensivePatterns')]
    public function test_empty_output_work_is_bounded(string $pattern, bool $visitor): void
    {
        $generator = new SampleGenerator();
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessage('generation work limit');

        if ($visitor) {
            RegexParser::create()->parse($pattern)->accept($generator);
        } else {
            $generator->generate($pattern);
        }
    }

    /**
     * @return iterable<array{string, bool}>
     */
    public static function provideExpensivePatterns(): iterable
    {
        foreach ([false, true] as $visitor) {
            yield ['/(a{0})(?:\\1{1000}){1000}/', $visitor];
            yield ['/()(?(DEFINE)(?<empty>\\1{1000}))(?&empty){1000}/', $visitor];
        }
    }

    public function test_the_facade_enforces_the_work_limit(): void
    {
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessage('generation work limit');
        Regex::create()->generate('/()(?:\\1{1000}){1000}/');
    }

    public function test_retries_share_the_work_limit(): void
    {
        $this->expectException(SampleGenerationException::class);
        $this->expectExceptionMessage('generation work limit');
        // Each draw is within the limit, but none can pass the assertion.
        (new SampleGenerator())->generate('/()\\1{60000}(?!)/');
    }

    public function test_new_runs_reset_the_work_limit_even_after_exhaustion(): void
    {
        $generator = new SampleGenerator();
        $parser = RegexParser::create();
        foreach ([false, true] as $visitor) {
            try {
                $pattern = '/()(?:\\1{1000}){1000}/';
                $visitor ? $parser->parse($pattern)->accept($generator) : $generator->generate($pattern);
                $this->fail('The work limit was not enforced.');
            } catch (SampleGenerationException $exception) {
                $this->assertStringContainsString('generation work limit', $exception->getMessage());
            }

            for ($run = 0; $run < 2; $run++) {
                $pattern = '/()\\1{60000}/';
                $this->assertSame('', $visitor ? $parser->parse($pattern)->accept($generator) : $generator->generate($pattern));
            }
        }
    }
}
