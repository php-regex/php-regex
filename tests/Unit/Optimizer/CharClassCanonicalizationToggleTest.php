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

namespace RegexParser\Tests\Unit\Optimizer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RegexParser\Regex;

final class CharClassCanonicalizationToggleTest extends TestCase
{
    #[DataProvider('provideCanonicalizationCases')]
    public function test_canonicalization_toggle(string $pattern, string $expectedCanonical, string $expectedPreserved): void
    {
        $regex = Regex::create();

        $canonical = $regex->optimize($pattern, [
            'canonicalize_char_classes' => true,
            'digits' => false,
            'word' => false,
        ])->optimized;
        $this->assertSame($expectedCanonical, $canonical);

        $preserved = $regex->optimize($pattern, [
            'canonicalize_char_classes' => false,
            'digits' => false,
            'word' => false,
        ])->optimized;
        $this->assertSame($expectedPreserved, $preserved);
    }

    public static function provideCanonicalizationCases(): \Generator
    {
        yield 'reorders dot and range' => [
            '/[0-9.]/',
            '/[.0-9]/',
            '/[0-9.]/',
        ];
        yield 'deduplicates literals' => [
            '/[aay]/',
            '/[ay]/',
            '/[aay]/',
        ];
        yield 'reorders range with literal' => [
            '/[a0-9]/',
            '/[0-9a]/',
            '/[a0-9]/',
        ];
    }
}
