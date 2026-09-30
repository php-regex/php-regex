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

namespace RegexParser\Automata\Determinization;

/**
 * Factory for NFA determinization algorithm strategies.
 *
 * @internal
 */
final class DeterminizationAlgorithmFactory
{
    public function create(DeterminizationAlgorithm $algorithm): DeterminizationAlgorithmInterface
    {
        return match ($algorithm) {
            DeterminizationAlgorithm::SUBSET => new SubsetConstruction(),
            DeterminizationAlgorithm::SUBSET_INDEXED => new SubsetConstructionIndexed(),
        };
    }
}
