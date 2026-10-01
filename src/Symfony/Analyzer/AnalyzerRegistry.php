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

namespace PhpRegex\Symfony\Analyzer;

/**
 * @internal
 */
final readonly class AnalyzerRegistry
{
    /**
     * @var array<int, AnalyzerInterface>
     */
    private array $analyzers;

    /**
     * @param iterable<AnalyzerInterface> $analyzers
     */
    public function __construct(iterable $analyzers)
    {
        $items = \is_array($analyzers) ? $analyzers : iterator_to_array($analyzers, false);
        $sorted = array_values($items);

        usort(
            $sorted,
            static fn (AnalyzerInterface $left, AnalyzerInterface $right): int => $left->getPriority()
                <=> $right->getPriority(),
        );

        $this->analyzers = $sorted;
    }

    /**
     * @return array<int, AnalyzerInterface>
     */
    public function all(): array
    {
        return $this->analyzers;
    }

    /**
     * @param array<int, string> $ids
     *
     * @return array<int, AnalyzerInterface>
     */
    public function filter(array $ids): array
    {
        if ([] === $ids) {
            return $this->analyzers;
        }

        $lookup = array_fill_keys($ids, true);
        $filtered = [];

        foreach ($this->analyzers as $analyzer) {
            if (isset($lookup[$analyzer->getId()])) {
                $filtered[] = $analyzer;
            }
        }

        return $filtered;
    }
}
