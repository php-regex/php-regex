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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PatternSourceCollectionTest extends TestCase
{
    use TemporaryProject;

    public function test_construct(): void
    {
        $sources = [];
        $this->assertInstanceOf(PatternSourceCollection::class, new PatternSourceCollection($sources));
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

    /**
     * A file and a pattern are told apart whole: "Check.ph" with "p/read/"
     * is not "Check.php" with "/read/", though both spell the same bytes.
     */
    #[Test]
    public function test_a_file_and_a_pattern_running_together_are_not_taken_for_another_pair(): void
    {
        $project = $this->makeProject(['src/Check.php' => "<?php\n\npreg_match('/read/', \$s);\n"]);

        $other = $this->createStub(PatternSourceInterface::class);
        $other->method('getName')->willReturn('other');
        $other->method('isSupported')->willReturn(true);
        $other->method('extract')->willReturn([new PatternOccurrence('p/read/', $project.'/src/Check.ph', 40, 'other:run-together')]);

        $collection = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))),
            $other,
        ]);

        $read = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->source.' '.$occurrence->pattern,
            $collection->collect(new PatternSourceContext([$project.'/src'], [])),
        );

        $this->assertSame(['preg_match() /read/', 'other:run-together p/read/'], $read);
    }
}
