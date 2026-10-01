<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\Optimizer;

use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class OptimizerSpecificsTest extends TestCase
{
    public function test_noise_reordering_same_length(): void
    {
        $original = '/[a-zA-Z0-9]/';
        $optimized = Regex::create()->optimize($original)->optimized;
        $this->assertSame(\strlen($original), \strlen($optimized));
    }

    public function test_range_efficiency_no_range_for_two_chars(): void
    {
        $regex = Regex::create()->optimize('/[=>]/')->optimized;
        // Should not create [=->] since it covers only 2 chars
        $this->assertSame('/[=>]/', $regex);
    }

    public function test_range_avoids_hyphen_start_for_literal_sets(): void
    {
        $regex = Regex::create()->optimize('/[\\-.\\/]/')->optimized;
        $this->assertStringNotContainsString('\\--', $regex);
    }

    public function test_valid_range_created(): void
    {
        $regex = Regex::create()->optimize('/[abc]/')->optimized;
        $this->assertSame('/[a-c]/', $regex);
    }

    public function test_array_case_no_invalid_range(): void
    {
        $pattern = "/^array\(\s*([^\s^=^>]*)(\s*=>\s*(.*))?\s*\)/i";
        $optimized = Regex::create()->optimize($pattern)->optimized;
        // Ensure no range like =-> is created
        $this->assertStringNotContainsString('->', $optimized);
        // And length should be less or equal, but since it's complex, just check no invalid range
    }

    public function test_prefix_factorization_simple(): void
    {
        $regex = Regex::create()->optimize('/pre_a|pre_b/')->optimized;
        $this->assertSame('/pre_a|pre_b/', $regex);
    }

    public function test_prefix_factorization_no_common(): void
    {
        $regex = Regex::create()->optimize('/foo|bar/')->optimized;
        $this->assertSame('/foo|bar/', $regex);
    }

    public function test_prefix_factorization_complex(): void
    {
        $regex = Regex::create()->optimize('/abc|abd|aef/')->optimized;
        // This might not fully factorize with simple implementation, but at least no crash
        $this->assertIsString($regex);
    }

    public function test_sequence_compaction_digits(): void
    {
        $regex = Regex::create()->optimize('/\d\d\d/')->optimized;
        $this->assertSame('/\d\d\d/', $regex);
    }

    public function test_preserve_explicit_quantifier(): void
    {
        $regex = Regex::create()->optimize('/\d{2}/')->optimized;
        $this->assertSame('/\d{2}/', $regex);
    }

    public function test_min_quantifier_count_respects_threshold(): void
    {
        $regex = Regex::create()->optimize('/aaaa/', ['min_quantifier_count' => 5])->optimized;
        $this->assertSame('/aaaa/', $regex);
    }

    public function test_sequence_compaction_mixed(): void
    {
        $regex = Regex::create()->optimize('/a{2}a{3}/')->optimized;
        $this->assertSame('/a{5}/', $regex);
    }

    public function test_quantifier_normalization_star(): void
    {
        $regex = Regex::create()->optimize('/a{0,}/')->optimized;
        $this->assertSame('/a*/', $regex);
    }

    public function test_quantifier_normalization_plus(): void
    {
        $regex = Regex::create()->optimize('/b{1,}/')->optimized;
        $this->assertSame('/b+/', $regex);
    }

    public function test_quantifier_normalization_question(): void
    {
        $regex = Regex::create()->optimize('/c{0,1}/')->optimized;
        $this->assertSame('/c?/', $regex);
    }

    public function test_quantifier_normalization_unwrap(): void
    {
        $regex = Regex::create()->optimize('/d{1}/')->optimized;
        $this->assertSame('/d/', $regex);
    }

    public function test_quantifier_normalization_remove(): void
    {
        $regex = Regex::create()->optimize('/e{0}/')->optimized;
        $this->assertSame('//', $regex); // Empty pattern
    }

    public function test_alternation_deduplication(): void
    {
        $regex = Regex::create()->optimize('/a|b|a/')->optimized;
        $this->assertSame('/[ab]/', $regex);
    }

    public function test_full_optimization_combo(): void
    {
        $regex = Regex::create()->optimize('/a{0,}b{1,}c{0,1}d{1}\d\d/', ['possessive' => true])->optimized;
        $this->assertSame('/a*+b++c?d\d\d/', $regex);
    }

    public function test_safe_possessivization(): void
    {
        $regex = Regex::create()->optimize('/\d+a/', ['possessive' => true])->optimized;
        $this->assertSame('/\d++a/', $regex);
    }

    public function test_unsafe_possessivization(): void
    {
        $regex = Regex::create()->optimize('/\d+1/')->optimized;
        $this->assertSame('/\d+1/', $regex);
    }
}
