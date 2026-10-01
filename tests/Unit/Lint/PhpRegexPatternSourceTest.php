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

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\Extraction\ExtractorInterface;
use PhpRegex\Linter\PatternExtractor;
use PhpRegex\Linter\Source\PatternSourceContext;
use PhpRegex\Linter\Source\PhpFilePatternSource;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class PhpRegexPatternSourceTest extends TestCase
{
    private PatternExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PatternExtractor($this->createStub(ExtractorInterface::class));
    }

    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        $source = new PhpFilePatternSource($this->extractor);
    }

    public function test_get_name(): void
    {
        $source = new PhpFilePatternSource($this->extractor);
        $this->assertSame('php', $source->getName());
    }

    public function test_is_supported(): void
    {
        $source = new PhpFilePatternSource($this->extractor);
        $this->assertTrue($source->isSupported());
    }

    public function test_extract_delegates_to_extractor(): void
    {
        $context = new PatternSourceContext(
            ['src/', 'tests/'],
            ['vendor/'],
        );

        $source = new PhpFilePatternSource($this->extractor);
        $result = $source->extract($context);

        $this->assertIsArray($result);
    }

    public function test_extract_with_progress_callback(): void
    {
        $progressCalled = false;
        $progressCallback = static function () use (&$progressCalled): void {
            $progressCalled = true;
        };

        $context = new PatternSourceContext(
            ['src/'],
            [],
            [],
            $progressCallback,
        );

        $source = new PhpFilePatternSource($this->extractor);
        $result = $source->extract($context);

        $this->assertIsArray($result);
    }

    public function test_extract_with_empty_paths(): void
    {
        $context = new PatternSourceContext([], []);

        $source = new PhpFilePatternSource($this->extractor);
        $result = $source->extract($context);

        $this->assertIsArray($result);
    }

    public function test_extract_with_disabled_sources(): void
    {
        $context = new PatternSourceContext(
            ['src/'],
            [],
            ['php'], // php source is disabled
        );

        // Even when disabled, the method should still delegate to extractor
        // (the disabling logic is handled at a higher level)
        $source = new PhpFilePatternSource($this->extractor);
        $result = $source->extract($context);

        $this->assertIsArray($result);
    }
}
