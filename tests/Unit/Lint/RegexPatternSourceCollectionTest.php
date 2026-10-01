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

namespace PhpRegex\Tests\Unit\Lint;

use PhpRegex\Linter\Source\PatternSourceCollection;
use PhpRegex\Linter\Source\PatternSourceContext;
use PhpRegex\Linter\Source\PatternSourceInterface;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class RegexPatternSourceCollectionTest extends TestCase
{
    #[DoesNotPerformAssertions]
    public function test_construct(): void
    {
        $sources = [];
        $collection = new PatternSourceCollection($sources);
    }

    public function test_collect_with_empty_sources(): void
    {
        $collection = new PatternSourceCollection([]);
        $context = new PatternSourceContext([], []);
        $result = $collection->collect($context);
        $this->assertSame([], $result);
    }

    public function test_collect_filters_disabled_sources(): void
    {
        $source = $this->createStub(PatternSourceInterface::class);
        $source->method('getName')->willReturn('test');
        $source->method('isSupported')->willReturn(true);
        $source->method('extract')->willReturn([]);

        $context = new PatternSourceContext([], [], ['test']);

        $collection = new PatternSourceCollection([$source]);
        $result = $collection->collect($context);
        $this->assertSame([], $result);
    }

    public function test_collect_filters_unsupported_sources(): void
    {
        $source = $this->createStub(PatternSourceInterface::class);
        $source->method('getName')->willReturn('test');
        $source->method('isSupported')->willReturn(false);

        $context = new PatternSourceContext([], []);

        $collection = new PatternSourceCollection([$source]);
        $result = $collection->collect($context);
        $this->assertSame([], $result);
    }

    public function test_collect_aggregates_patterns(): void
    {
        $source1 = $this->createStub(PatternSourceInterface::class);
        $source1->method('getName')->willReturn('test1');
        $source1->method('isSupported')->willReturn(true);
        $source1->method('extract')->willReturn(['pattern1']);

        $source2 = $this->createStub(PatternSourceInterface::class);
        $source2->method('getName')->willReturn('test2');
        $source2->method('isSupported')->willReturn(true);
        $source2->method('extract')->willReturn(['pattern2']);

        $context = new PatternSourceContext([], []);

        $collection = new PatternSourceCollection([$source1, $source2]);
        $result = $collection->collect($context);
        $this->assertSame(['pattern1', 'pattern2'], $result);
    }
}
