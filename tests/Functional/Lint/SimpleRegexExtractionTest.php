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

namespace PHPRegex\Tests\Functional\Lint;

use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Simple test for regex pattern extraction with flags.
 */
final class SimpleRegexExtractionTest extends TestCase
{
    private TokenBasedExtractionStrategy $strategy;

    protected function setUp(): void
    {
        $this->strategy = new TokenBasedExtractionStrategy();
    }

    public function test_extract_regex_with_flags(): void
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/simple_regex_with_flags.php';

        $results = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(1, $results, 'Should extract exactly one pattern');

        // Verify pattern includes flags - our improved extraction should detect when flags are actually part of pattern
        $this->assertStringContainsString('/m', $results[0]->pattern);
    }

    public function test_extract_simple_regex(): void
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/simple_regex_simple.php';

        $results = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(1, $results, 'Should extract exactly one pattern');

        // Verify simple pattern without flags
        $this->assertSame('/pattern/', $results[0]->pattern);
    }

    public function test_extract_includes_column_and_offset(): void
    {
        $fixtureFile = __DIR__.'/../../Fixtures/Lint/tokenbased_column_offset.php';

        $results = $this->strategy->extract([$fixtureFile]);

        $this->assertCount(1, $results, 'Should extract exactly one pattern');

        $content = file_get_contents($fixtureFile);
        $this->assertIsString($content);

        $expectedOffset = strpos($content, "'/foo/'");
        $this->assertIsInt($expectedOffset);

        $this->assertSame(5, $results[0]->line);
        $this->assertSame(16, $results[0]->column);
        $this->assertSame($expectedOffset, $results[0]->fileOffset);
    }
}
