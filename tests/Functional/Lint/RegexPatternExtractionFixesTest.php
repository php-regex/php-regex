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

namespace PhpRegex\Tests\Functional\Lint;

use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Focused tests for the regex pattern extraction fixes.
 */
final class RegexPatternExtractionFixesTest extends TestCase
{
    private TokenBasedExtractionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TokenBasedExtractionStrategy();
    }

    /**
     * Test that simple regex with flags is extracted correctly.
     */
    public function test_extract_simple_regex_with_m_flag(): void
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/regex_fixes_simple_m_flag.php';

        $results = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(1, $results, 'Should extract exactly one pattern');
        $this->assertSame('/pattern/m', $results[0]->pattern, 'Pattern with /m flag should be preserved');
    }

    /**
     * Test that regex with multiple flags is extracted correctly.
     */
    public function test_extract_regex_with_multiple_flags(): void
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/regex_fixes_multiple_flags.php';

        $results = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(1, $results, 'Should extract exactly one pattern');
        $this->assertSame('/pattern/mx', $results[0]->pattern, 'Pattern with /mx flags should be preserved');
    }

    /**
     * Test that regex without flags works normally.
     */
    public function test_extract_regex_without_flags(): void
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/regex_fixes_no_flags.php';

        $results = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(1, $results, 'Should extract exactly one pattern');
        $this->assertSame('/pattern/', $results[0]->pattern, 'Pattern without flags should work');
    }

    /**
     * Test case from the real-world issue.
     */
    public function test_real_world_case(): void
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/regex_fixes_real_world.php';

        $results = $this->strategy->extract([$fixtureFile]);

        // The extractor now returns a single normalized pattern for this
        // real-world case; just assert that the QUICK_CHECK pattern with /m
        // flag is present.
        $this->assertCount(1, $results, 'Should extract 1 pattern from real world case');
        $this->assertStringContainsString('/QUICK_CHECK = .*;/m', $results[0]->pattern, 'Pattern should have /m flag');
    }
}
