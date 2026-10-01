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

use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A generated sample has one job: to match the pattern it came from.
 *
 * The generator picks its characters at random, so each pattern is asked
 * several times — a class it fills from the wrong set fails on one of them.
 */
final class SampleGeneratorMatchesTest extends TestCase
{
    private const ATTEMPTS = 8;

    #[Test]
    #[DataProvider('provideGeneratablePatterns')]
    public function test_a_generated_sample_matches_its_pattern(string $pattern): void
    {
        $regex = Regex::create();

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $sample = $regex->generate($pattern);

            $this->assertMatchesRegularExpression(
                $pattern,
                $sample,
                \sprintf('Generated %s for %s.', var_export($sample, true), $pattern),
            );
        }
    }

    /**
     * @return iterable<string, array{pattern: string}>
     */
    public static function provideGeneratablePatterns(): iterable
    {
        $patterns = [
            'digit' => '/\d/',
            'non-digit' => '/\D/',
            'word' => '/\w/',
            'non-word' => '/\W/',
            'whitespace' => '/\s/',
            'non-whitespace' => '/\S/',
            'horizontal whitespace' => '/\h/',
            'non-horizontal whitespace' => '/\H/',
            'vertical whitespace' => '/\v/',
            'non-vertical whitespace' => '/\V/',
            'posix alnum' => '/[[:alnum:]]/',
            'posix alpha' => '/[[:alpha:]]/',
            'posix digit' => '/[[:digit:]]/',
            'posix lower' => '/[[:lower:]]/',
            'posix upper' => '/[[:upper:]]/',
            'range' => '/[a-f]/',
            'negated class' => '/[^0-9]/',
            'quantified group' => '/(ab){2,3}/',
            'alternation' => '/foo|bar/',
            'anchored sequence' => '/^\w+@\w+\.[a-z]{2,4}$/',
        ];

        foreach ($patterns as $name => $pattern) {
            yield $name => ['pattern' => $pattern];
        }
    }
}
