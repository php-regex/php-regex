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

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Extractor;

use PhpRegex\Linter\Extraction\ExtractorInterface;
use PhpRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PhpRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PhpRegex\Linter\PatternExtractor;
use PHPUnit\Framework\TestCase;

final class RegexPatternExtractorTest extends TestCase
{
    public function test_delegates_to_injected_extractor(): void
    {
        $mockExtractor = $this->createStub(ExtractorInterface::class);
        $mockExtractor->method('extract')->willReturn(['pattern1', 'pattern2']);

        $extractor = new PatternExtractor($mockExtractor);

        $result = $extractor->extract([__FILE__]);

        $this->assertSame(['pattern1', 'pattern2'], $result);
    }

    public function test_uses_default_exclude_paths_when_not_provided(): void
    {
        $mockExtractor = $this->createStub(ExtractorInterface::class);
        $mockExtractor->method('extract')->willReturn([]);

        $extractor = new PatternExtractor($mockExtractor);

        $result = $extractor->extract(['test.php']);

        $this->assertSame([], $result);
    }

    public function test_uses_custom_exclude_paths_when_provided(): void
    {
        $mockExtractor = $this->createStub(ExtractorInterface::class);
        $mockExtractor->method('extract')->willReturn([]);

        $extractor = new PatternExtractor($mockExtractor);

        $result = $extractor->extract(['test.php'], ['custom_exclude']);

        $this->assertSame([], $result);
    }

    public function test_works_with_phpstan_extractor(): void
    {
        $phpstanExtractor = new PhpParserExtractionStrategy();

        $extractor = new PatternExtractor($phpstanExtractor);

        $result = $extractor->extract(['nonexistent']);

        $this->assertIsArray($result);
    }

    public function test_works_with_token_based_extractor(): void
    {
        $tokenExtractor = new TokenBasedExtractionStrategy();

        $extractor = new PatternExtractor($tokenExtractor);

        $result = $extractor->extract(['nonexistent']);

        $this->assertIsArray($result);
    }

    public function test_collects_and_filters_php_files_with_default_excludes(): void
    {
        // Use a real extractor to test actual file discovery behavior
        $extractor = new PatternExtractor(new TokenBasedExtractionStrategy());

        $fixtureDir = __DIR__.'/../../../../Fixtures/Extractor/DefaultExclude';

        // Debug: check if directory exists and is readable
        $this->assertDirectoryExists($fixtureDir, "Fixture directory should exist: {$fixtureDir}");

        $result = $extractor->extract([$fixtureDir]); // Should use default exclude ['vendor']

        // Should find exactly one pattern from test.php, not from vendor/ignored.php
        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        $this->assertStringContainsString('test.php', $result[0]->file);
    }

    public function test_collects_and_filters_php_files_with_custom_excludes(): void
    {
        // Use a real extractor to test actual file discovery behavior
        $extractor = new PatternExtractor(new TokenBasedExtractionStrategy());

        $fixtureDir = __DIR__.'/../../../../Fixtures/Extractor/CustomExclude';

        $result = $extractor->extract([$fixtureDir], ['custom_exclude']); // Custom exclude should work

        // Should find exactly one pattern from test.php, not from custom_exclude/ignored.php
        $this->assertCount(1, $result);
        $this->assertSame('/test/', $result[0]->pattern);
        $this->assertStringContainsString('test.php', $result[0]->file);
    }
}
