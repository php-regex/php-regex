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
use PHPRegex\Linter\Source\OverlappingPatternSourceInterface;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A source that may read again what the PHP files hold, as the Symfony
 * validator reads a #[Regex] attribute, drops an occurrence the PHP files
 * already hold: same file, same pattern, whatever the line and whatever the
 * order of the sources. Another pattern, or the same pattern in another
 * file, stays; any other source keeps all it reads.
 */
final class PatternSourceCollectionDuplicateTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_only_the_same_pattern_of_the_same_file_is_dropped(): void
    {
        $project = $this->makeProject(['src/Check.php' => "<?php\n\npreg_match('/read/', \$s);\n"]);
        $file = $project.'/src/Check.php';

        $other = $this->createStub(OverlappingPatternSourceInterface::class);
        $other->method('getName')->willReturn('other');
        $other->method('isSupported')->willReturn(true);
        $other->method('extract')->willReturn([
            new PatternOccurrence('/read/', $project.'/src/../src/Check.php', 40, 'other:same'),
            new PatternOccurrence('/kept/', $file, 41, 'other:another-pattern'),
            new PatternOccurrence('/read/', $project.'/src/Missing.php', 42, 'other:another-file'),
        ]);

        // The other source first: the PHP files win all the same.
        $collection = new PatternSourceCollection([
            $other,
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))),
        ]);

        $read = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->source.' '.$occurrence->pattern,
            array_values(array_filter($collection->collect(new PatternSourceContext([$project.'/src'], [])), static fn (PatternOccurrence $occurrence): bool => null === $occurrence->ignores)),
        );

        $this->assertSame(['other:another-pattern /kept/', 'other:another-file /read/', 'preg_match() /read/'], $read);
    }

    #[Test]
    public function test_a_source_disabled_by_the_run_drops_nothing(): void
    {
        $project = $this->makeProject(['src/Check.php' => "<?php\n\npreg_match('/read/', \$s);\n"]);

        $other = $this->createStub(OverlappingPatternSourceInterface::class);
        $other->method('getName')->willReturn('other');
        $other->method('isSupported')->willReturn(true);
        $other->method('extract')->willReturn([new PatternOccurrence('/read/', $project.'/src/Check.php', 40, 'other:same')]);

        $collection = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))),
            $other,
        ]);

        $read = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->source,
            array_values(array_filter($collection->collect(new PatternSourceContext([$project.'/src'], [], ['php'])), static fn (PatternOccurrence $occurrence): bool => null === $occurrence->ignores)),
        );

        $this->assertSame(['other:same'], $read);
    }

    #[Test]
    public function test_a_source_that_does_not_overlap_the_php_files_keeps_the_same_pattern(): void
    {
        $project = $this->makeProject(['src/Check.php' => "<?php\n\npreg_match('/read/', \$s);\n"]);

        $other = $this->createStub(PatternSourceInterface::class);
        $other->method('getName')->willReturn('other');
        $other->method('isSupported')->willReturn(true);
        $other->method('extract')->willReturn([new PatternOccurrence('/read/', $project.'/src/Check.php', 40, 'other:same')]);

        $collection = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::native()))),
            $other,
        ]);

        $read = array_map(
            static fn (PatternOccurrence $occurrence): string => $occurrence->source,
            array_values(array_filter($collection->collect(new PatternSourceContext([$project.'/src'], [])), static fn (PatternOccurrence $occurrence): bool => null === $occurrence->ignores)),
        );

        $this->assertSame(['preg_match()', 'other:same'], $read);
    }
}
