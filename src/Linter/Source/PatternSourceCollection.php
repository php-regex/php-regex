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
     * Every source's occurrences, in the order of the sources: each source
     * reads patterns of its own.
     *
     * @return array<PatternOccurrence>
     */
    public function collect(PatternSourceContext $context): array
    {
        $patterns = [];
        foreach ($this->sources as $source) {
            if (!$context->isSourceEnabled($source->getName()) || !$source->isSupported()) {
                continue;
            }

            $patterns = [...$patterns, ...$source->extract($context)];
        }

        return $patterns;
    }
}
