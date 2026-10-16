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

namespace PHPRegex\Linter\Source;

use PHPRegex\Linter\PatternOccurrence;

/**
 * Aggregates pattern sources into a single extractor.
 *
 * @internal
 */
final class PatternSourceCollection
{
    /**
     * @param iterable<PatternSourceInterface> $sources
     */
    public function __construct(private readonly iterable $sources) {}

    /**
     * Every source's occurrences, in the order of the sources. A source
     * that overlaps the PHP files may read a pattern they already hold, as
     * the validator reads a #[Regex] attribute: its occurrence is dropped
     * when the PHP files gave the same pattern for the same file. The file
     * and the pattern are compared, never the line, which such a source may
     * number its own way. Any other source, a Laravel 'regex:' rule say,
     * keeps all it reads: its literal is a pattern of its own.
     *
     * @return array<PatternOccurrence>
     */
    public function collect(PatternSourceContext $context): array
    {
        /** @var list<array{bool, bool, array<PatternOccurrence>}> $read */
        $read = [];
        $overlapping = false;

        foreach ($this->sources as $source) {
            if (!$context->isSourceEnabled($source->getName())) {
                continue;
            }

            if (!$source->isSupported()) {
                continue;
            }

            $fromFiles = $source instanceof PhpFilePatternSource;
            $overlaps = $source instanceof OverlappingPatternSourceInterface;
            $occurrences = $source->extract($context);
            $read[] = [$fromFiles, $overlaps, $occurrences];
            $overlapping = $overlapping || ($overlaps && [] !== $occurrences);
        }

        $inFiles = [];
        $realPaths = [];
        if ($overlapping) {
            foreach ($read as [$fromFiles, , $occurrences]) {
                if ($fromFiles) {
                    foreach ($occurrences as $occurrence) {
                        $inFiles[self::key($occurrence, $realPaths)] = true;
                    }
                }
            }
        }

        $patterns = [];
        foreach ($read as [, $overlaps, $occurrences]) {
            foreach ($occurrences as $occurrence) {
                if (!$overlaps || [] === $inFiles || !isset($inFiles[self::key($occurrence, $realPaths)])) {
                    $patterns[] = $occurrence;
                }
            }
        }

        return $patterns;
    }

    /**
     * @param array<string, string> $realPaths the files already resolved
     */
    private static function key(PatternOccurrence $occurrence, array &$realPaths): string
    {
        $file = $realPaths[$occurrence->file] ??= (realpath($occurrence->file) ?: $occurrence->file);

        return $file."\0".$occurrence->pattern;
    }
}
